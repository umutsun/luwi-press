<?php
/**
 * N8nPress WebMCP Server
 *
 * Implements the MCP (Model Context Protocol) Streamable HTTP transport,
 * exposing all n8npress REST API endpoints as MCP tools that AI agents
 * can discover and invoke over a single HTTP endpoint.
 *
 * Spec: https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http
 *
 * @package    N8nPress
 * @subpackage WebMCP
 * @since      1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LuwiPress_WebMCP {

    /* ──────────────────────────── Singleton ──────────────────────────── */

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ──────────────────────────── Constants ──────────────────────────── */

    const MCP_PROTOCOL_VERSION = '2025-03-26';
    const SERVER_NAME          = 'luwipress-webmcp';
    const SERVER_VERSION       = '2.0.1';
    const ENDPOINT_PATH        = '/luwipress/v1/mcp';

    /* ──────────────────────────── Properties ────────────────────────── */

    /** @var array Registered MCP tool definitions, keyed by tool name. */
    private $tools = array();

    /** @var array Map of tool name → internal handler callable. */
    private $handlers = array();

    /** @var array Resource template definitions. */
    private $resource_templates = array();

    /** @var string|null Active session ID for Streamable HTTP session management. */
    private $session_id = null;

    /* ──────────────────────────── Bootstrap ──────────────────────────── */

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_mcp_endpoint' ) );
        $this->register_all_tools();
    }

    /**
     * Register the single MCP endpoint that handles POST, GET, and DELETE.
     */
    public function register_mcp_endpoint() {
        // POST — client sends JSON-RPC messages
        register_rest_route( 'luwipress/v1', '/mcp', array(
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_post' ),
                'permission_callback' => array( $this, 'check_mcp_permission' ),
            ),
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_get' ),
                'permission_callback' => array( $this, 'check_mcp_permission' ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'handle_delete' ),
                'permission_callback' => array( $this, 'check_mcp_permission' ),
            ),
        ) );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  PERMISSION CHECK
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Validate MCP request authentication.
     *
     * Accepts:
     *  1. WordPress admin cookie (for browser-based clients)
     *  2. Bearer token matching the n8npress API token
     *  3. X-n8nPress-Token header
     *
     * Also validates Origin header to prevent DNS rebinding (per MCP spec).
     */
    public function check_mcp_permission( $request ) {
        // Origin validation (MCP spec security requirement — DNS rebinding protection)
        if ( ! $this->validate_origin( $request ) ) {
            return new WP_Error( 'origin_denied', 'Origin not allowed', array( 'status' => 403 ) );
        }

        // Admin cookie or API token
        if ( LuwiPress_Permission::check_token_or_admin( $request ) ) {
            return true;
        }

        return new WP_Error( 'mcp_unauthorized', 'Invalid credentials', array( 'status' => 401 ) );
    }

    /**
     * Validate Origin header to prevent DNS rebinding attacks.
     */
    private function validate_origin( $request ) {
        $origin = $request->get_header( 'origin' );
        if ( empty( $origin ) ) {
            return true; // No origin = server-to-server (CLI, n8n, etc.)
        }

        $allowed = array(
            home_url(),
            site_url(),
            admin_url(),
        );

        // Allow configured additional origins
        $extra = get_option( 'luwipress_webmcp_allowed_origins', '' );
        if ( ! empty( $extra ) ) {
            $allowed = array_merge( $allowed, array_map( 'trim', explode( "\n", $extra ) ) );
        }

        foreach ( $allowed as $allowed_origin ) {
            $allowed_host = wp_parse_url( $allowed_origin, PHP_URL_HOST );
            $origin_host  = wp_parse_url( $origin, PHP_URL_HOST );
            if ( $allowed_host && $origin_host && $allowed_host === $origin_host ) {
                return true;
            }
        }

        return false;
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  HTTP HANDLERS (Streamable HTTP Transport)
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * POST handler — receives JSON-RPC messages from client.
     *
     * Per MCP spec: returns either application/json or text/event-stream.
     * We use application/json for simplicity (valid per spec).
     */
    public function handle_post( $request ) {
        $body = $request->get_json_params();

        if ( empty( $body ) ) {
            return $this->jsonrpc_error( null, -32700, 'Parse error' );
        }

        // Batch request (array of messages)
        if ( isset( $body[0] ) && is_array( $body[0] ) ) {
            $responses = array();
            foreach ( $body as $message ) {
                $response = $this->dispatch_jsonrpc( $message, $request );
                if ( null !== $response ) {
                    $responses[] = $response;
                }
            }
            // All notifications → 202
            if ( empty( $responses ) ) {
                return new WP_REST_Response( null, 202 );
            }
            return rest_ensure_response( $responses );
        }

        // Single message
        $response = $this->dispatch_jsonrpc( $body, $request );

        // Notification or response → 202
        if ( null === $response ) {
            return new WP_REST_Response( null, 202 );
        }

        // Attach session header if we have one
        $rest_response = rest_ensure_response( $response );
        if ( $this->session_id ) {
            $rest_response->header( 'Mcp-Session-Id', $this->session_id );
        }

        return $rest_response;
    }

    /**
     * GET handler — opens SSE stream for server-initiated messages.
     *
     * For now returns 405 since we don't push server-initiated messages.
     * This can be extended later for real-time notifications.
     */
    public function handle_get( $request ) {
        $accept = $request->get_header( 'accept' );

        // MCP spec: if server doesn't offer SSE, return 405
        if ( strpos( $accept, 'text/event-stream' ) !== false ) {
            return new WP_REST_Response(
                array( 'error' => 'SSE streaming not yet supported. Use POST for all interactions.' ),
                405
            );
        }

        // Non-SSE GET: return server info
        return rest_ensure_response( array(
            'name'             => self::SERVER_NAME,
            'version'          => self::SERVER_VERSION,
            'protocolVersion'  => self::MCP_PROTOCOL_VERSION,
            'status'           => 'ready',
            'tools_count'      => count( $this->tools ),
            'endpoint'         => rest_url( self::ENDPOINT_PATH ),
        ) );
    }

    /**
     * DELETE handler — terminates session.
     */
    public function handle_delete( $request ) {
        $session = $request->get_header( 'mcp-session-id' );
        if ( $session ) {
            delete_transient( 'luwipress_mcp_session_' . $session );
        }
        return new WP_REST_Response( null, 204 );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  JSON-RPC DISPATCHER
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Route a single JSON-RPC message to the appropriate handler.
     *
     * @return array|null  JSON-RPC response, or null for notifications.
     */
    private function dispatch_jsonrpc( $message, $request ) {
        $method = $message['method'] ?? '';
        $id     = $message['id'] ?? null;
        $params = $message['params'] ?? array();

        // Notifications and responses have no 'id' expectation
        if ( null === $id && ! isset( $message['method'] ) ) {
            return null; // Response from client — acknowledge
        }
        if ( null === $id ) {
            // Notification — process but don't respond
            $this->handle_notification( $method, $params );
            return null;
        }

        switch ( $method ) {
            case 'initialize':
                return $this->handle_initialize( $id, $params, $request );

            case 'tools/list':
                return $this->handle_tools_list( $id, $params );

            case 'tools/call':
                return $this->handle_tools_call( $id, $params );

            case 'resources/list':
                return $this->handle_resources_list( $id );

            case 'resources/read':
                return $this->handle_resources_read( $id, $params );

            case 'resources/templates/list':
                return $this->handle_resource_templates_list( $id, $params );

            case 'completion/complete':
                return $this->handle_completion( $id, $params );

            case 'ping':
                return $this->jsonrpc_result( $id, new stdClass() );

            default:
                return $this->jsonrpc_error( $id, -32601, "Method not found: {$method}" );
        }
    }

    /**
     * Handle notifications (no response expected).
     */
    private function handle_notification( $method, $params ) {
        switch ( $method ) {
            case 'notifications/initialized':
                LuwiPress_Logger::log( 'WebMCP client initialized', 'info' );
                break;

            case 'notifications/cancelled':
                // Client cancelled a request — no action needed for sync responses
                break;
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  MCP LIFECYCLE METHODS
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Handle `initialize` — first message from client.
     */
    private function handle_initialize( $id, $params, $request ) {
        $client_info    = $params['clientInfo'] ?? array();
        $client_version = $params['protocolVersion'] ?? self::MCP_PROTOCOL_VERSION;

        // Accept client's protocol version if we support it (backward compat)
        $supported = array( '2025-03-26', '2025-11-25', '2024-11-05' );
        $negotiated_version = in_array( $client_version, $supported, true )
            ? $client_version
            : self::MCP_PROTOCOL_VERSION;

        // Generate session ID
        $this->session_id = wp_generate_uuid4();
        set_transient(
            'luwipress_mcp_session_' . $this->session_id,
            array(
                'client'     => $client_info,
                'created_at' => current_time( 'mysql' ),
                'ip'         => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ),
            ),
            HOUR_IN_SECONDS
        );

        LuwiPress_Logger::log( 'WebMCP session started', 'info', array(
            'session_id' => $this->session_id,
            'client'     => $client_info['name'] ?? 'unknown',
        ) );

        $result = array(
            'protocolVersion' => $negotiated_version,
            'capabilities'    => array(
                'tools'     => array(
                    'listChanged' => false,  // Static tool list — no runtime changes
                ),
                'resources' => array(
                    'subscribe'   => false,  // No real-time resource subscriptions yet
                    'listChanged' => false,
                ),
                'logging'     => new stdClass(),  // Server can emit log messages
                'completions' => new stdClass(),  // Supports argument autocompletion
            ),
            'serverInfo'      => array(
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ),
            'instructions'    => $this->get_server_instructions(),
        );

        $response = $this->jsonrpc_result( $id, $result );

        // Session ID is attached via header in handle_post()
        return $response;
    }

    /**
     * Server instructions for the AI agent — describes what n8npress can do.
     */
    private function get_server_instructions() {
        $site_name = get_bloginfo( 'name' );
        return "You are connected to {$site_name} via LuwiPress WebMCP. "
             . 'LuwiPress is AI-powered automation for WooCommerce that provides: '
             . 'content enrichment, SEO optimization (Rank Math/Yoast), AEO (FAQ/HowTo/Speakable schema), '
             . 'translation management (WPML/Polylang), CRM analytics, email sending, '
             . 'and AI-powered content generation. '
             . 'Use tools/list to discover available operations, then tools/call to execute them.';
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  TOOLS — LIST & CALL
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Handle `tools/list` — return all registered tool schemas.
     *
     * Supports cursor-based pagination per MCP spec.
     */
    private function handle_tools_list( $id, $params ) {
        $cursor     = $params['cursor'] ?? null;
        $page_size  = 20;
        $tool_names = array_keys( $this->tools );

        // Cursor = base64-encoded offset
        $offset = 0;
        if ( $cursor ) {
            $decoded = intval( base64_decode( $cursor ) );
            if ( $decoded > 0 ) {
                $offset = $decoded;
            }
        }

        $slice       = array_slice( $tool_names, $offset, $page_size );
        $tools_page  = array();
        foreach ( $slice as $name ) {
            $tools_page[] = $this->tools[ $name ];
        }

        $result = array( 'tools' => $tools_page );

        // Next cursor if more tools remain
        $next_offset = $offset + $page_size;
        if ( $next_offset < count( $tool_names ) ) {
            $result['nextCursor'] = base64_encode( (string) $next_offset );
        }

        return $this->jsonrpc_result( $id, $result );
    }

    /**
     * Handle `tools/call` — execute a tool and return the result.
     */
    private function handle_tools_call( $id, $params ) {
        $tool_name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? array();

        if ( ! isset( $this->handlers[ $tool_name ] ) ) {
            return $this->jsonrpc_error( $id, -32602, "Unknown tool: {$tool_name}" );
        }

        $start = microtime( true );

        try {
            $result = call_user_func( $this->handlers[ $tool_name ], $arguments );
            $ms     = round( ( microtime( true ) - $start ) * 1000, 2 );

            LuwiPress_Logger::log( "WebMCP tool called: {$tool_name}", 'info', array(
                'tool'           => $tool_name,
                'execution_ms'   => $ms,
            ) );

            // Normalize result to MCP content format
            return $this->jsonrpc_result( $id, array(
                'content' => array(
                    array(
                        'type' => 'text',
                        'text' => is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
                    ),
                ),
            ) );
        } catch ( Exception $e ) {
            LuwiPress_Logger::log( "WebMCP tool error: {$tool_name} — {$e->getMessage()}", 'error' );

            return $this->jsonrpc_result( $id, array(
                'content' => array(
                    array(
                        'type' => 'text',
                        'text' => wp_json_encode( array( 'error' => $e->getMessage() ) ),
                    ),
                ),
                'isError' => true,
            ) );
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  RESOURCES — LIST & READ
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Handle `resources/list` — expose key WordPress resources.
     */
    private function handle_resources_list( $id ) {
        $resources = array(
            array(
                'uri'         => 'n8npress://site-config',
                'name'        => 'Site Configuration',
                'description' => 'Full WordPress + WooCommerce + plugin environment snapshot',
                'mimeType'    => 'application/json',
            ),
            array(
                'uri'         => 'n8npress://health',
                'name'        => 'Health Check',
                'description' => 'Server health status (database, filesystem, memory)',
                'mimeType'    => 'application/json',
            ),
            array(
                'uri'         => 'n8npress://aeo-coverage',
                'name'        => 'AEO Coverage Report',
                'description' => 'FAQ/HowTo/Speakable schema coverage across products',
                'mimeType'    => 'application/json',
            ),
        );

        return $this->jsonrpc_result( $id, array( 'resources' => $resources ) );
    }

    /**
     * Handle `resources/templates/list` — parameterized resource URIs.
     */
    private function handle_resource_templates_list( $id, $params ) {
        $templates = array(
            array(
                'uriTemplate'  => 'n8npress://post/{post_id}',
                'name'         => 'WordPress Post',
                'description'  => 'Read a specific post/product by ID',
                'mimeType'     => 'application/json',
            ),
            array(
                'uriTemplate'  => 'n8npress://seo-meta/{post_id}',
                'name'         => 'SEO Meta',
                'description'  => 'Rank Math / Yoast SEO meta for a post',
                'mimeType'     => 'application/json',
            ),
            array(
                'uriTemplate'  => 'n8npress://translation-status/{post_id}',
                'name'         => 'Translation Status',
                'description'  => 'Translation status for a post across all languages',
                'mimeType'     => 'application/json',
            ),
        );

        return $this->jsonrpc_result( $id, array( 'resourceTemplates' => $templates ) );
    }

    /**
     * Handle `completion/complete` — argument autocompletion for resource templates.
     */
    private function handle_completion( $id, $params ) {
        $ref      = $params['ref'] ?? array();
        $argument = $params['argument'] ?? array();
        $arg_name = $argument['name'] ?? '';
        $arg_val  = $argument['value'] ?? '';

        // Autocomplete post_id by searching post titles
        if ( 'post_id' === $arg_name && strlen( $arg_val ) >= 2 ) {
            $query = new WP_Query( array(
                's'              => sanitize_text_field( $arg_val ),
                'post_type'      => array( 'post', 'page', 'product' ),
                'posts_per_page' => 10,
                'fields'         => 'ids',
            ) );

            $values = array();
            foreach ( $query->posts as $pid ) {
                $values[] = array(
                    'value'       => (string) $pid,
                    'description' => get_the_title( $pid ),
                );
            }

            return $this->jsonrpc_result( $id, array(
                'completion' => array(
                    'values'  => $values,
                    'hasMore' => $query->found_posts > 10,
                ),
            ) );
        }

        return $this->jsonrpc_result( $id, array(
            'completion' => array( 'values' => array() ),
        ) );
    }

    /**
     * Handle `resources/read` — return resource content.
     */
    private function handle_resources_read( $id, $params ) {
        $uri = $params['uri'] ?? '';

        switch ( $uri ) {
            case 'n8npress://site-config':
                $config  = LuwiPress_Site_Config::get_instance();
                $request = new WP_REST_Request( 'GET', '/luwipress/v1/site-config' );
                $data    = $config->get_site_config( $request );
                break;

            case 'n8npress://health':
                $api  = LuwiPress_API::get_instance();
                $data = $api->health_check();
                break;

            case 'n8npress://aeo-coverage':
                if ( class_exists( 'LuwiPress_AEO' ) ) {
                    $aeo     = LuwiPress_AEO::get_instance();
                    $request = new WP_REST_Request( 'GET', '/luwipress/v1/aeo/coverage' );
                    $data    = $aeo->get_aeo_coverage( $request );
                } else {
                    $data = array( 'error' => 'AEO module not loaded' );
                }
                break;

            default:
                // Handle parameterized resource templates
                $data = $this->resolve_resource_template( $uri );
                if ( null === $data ) {
                    return $this->jsonrpc_error( $id, -32002, "Resource not found: {$uri}" );
                }
        }

        // Unwrap WP_REST_Response if needed
        if ( $data instanceof WP_REST_Response ) {
            $data = $data->get_data();
        }

        return $this->jsonrpc_result( $id, array(
            'contents' => array(
                array(
                    'uri'      => $uri,
                    'mimeType' => 'application/json',
                    'text'     => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
                ),
            ),
        ) );
    }

    /**
     * Resolve a parameterized resource URI template.
     *
     * @param string $uri The resource URI (e.g., 'n8npress://post/42').
     * @return array|null  Resource data, or null if not matched.
     */
    private function resolve_resource_template( $uri ) {
        // n8npress://post/{post_id}
        if ( preg_match( '#^n8npress://post/(\d+)$#', $uri, $m ) ) {
            $post = get_post( intval( $m[1] ) );
            if ( ! $post ) {
                return null;
            }
            return array(
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'content'  => $post->post_content,
                'excerpt'  => $post->post_excerpt,
                'status'   => $post->post_status,
                'type'     => $post->post_type,
                'date'     => $post->post_date,
                'modified' => $post->post_modified,
                'url'      => get_permalink( $post->ID ),
                'author'   => get_the_author_meta( 'display_name', $post->post_author ),
            );
        }

        // n8npress://seo-meta/{post_id}
        if ( preg_match( '#^n8npress://seo-meta/(\d+)$#', $uri, $m ) ) {
            $post_id = intval( $m[1] );
            if ( ! get_post( $post_id ) ) {
                return null;
            }
            $keys = array(
                'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword',
                '_yoast_wpseo_title', '_yoast_wpseo_metadesc',
                '_luwipress_schema', '_luwipress_faq', '_luwipress_howto',
            );
            $meta = array();
            foreach ( $keys as $key ) {
                $val = get_post_meta( $post_id, $key, true );
                if ( '' !== $val && false !== $val ) {
                    $meta[ $key ] = $val;
                }
            }
            return array( 'post_id' => $post_id, 'seo_meta' => $meta );
        }

        // n8npress://translation-status/{post_id}
        if ( preg_match( '#^n8npress://translation-status/(\d+)$#', $uri, $m ) ) {
            $post_id = intval( $m[1] );
            if ( ! get_post( $post_id ) ) {
                return null;
            }
            if ( class_exists( 'LuwiPress_Translation' ) ) {
                $trans   = LuwiPress_Translation::get_instance();
                $request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/status' );
                $request->set_param( 'post_id', $post_id );
                $data = $trans->get_translation_status( $request );
                return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
            }
            return array( 'post_id' => $post_id, 'error' => 'Translation module not loaded' );
        }

        return null;
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  TOOL REGISTRATION
     * ═══════════════════════════════════════════════════════════════════
     *
     * Each tool wraps an existing n8npress REST endpoint.
     * Tools are grouped by domain: content, seo, aeo, translation,
     * crm, email, system, claw, chatwoot, workflow.
     */

    private function register_all_tools() {
        $this->register_system_tools();
        $this->register_content_tools();
        $this->register_seo_tools();
        $this->register_aeo_tools();
        $this->register_translation_tools();
        $this->register_crm_tools();
        $this->register_email_tools();
        $this->register_claw_tools();
        $this->register_chatwoot_tools();
        $this->register_workflow_tools();
        $this->register_token_tools();
        $this->register_review_tools();
        $this->register_linker_tools();
        $this->register_knowledge_graph_tools();

        /**
         * Allow third-party extensions to register additional MCP tools.
         *
         * @param LuwiPress_WebMCP $webmcp  The WebMCP instance.
         */
        do_action( 'luwipress_webmcp_register_tools', $this );
    }

    /**
     * Public API: register a custom MCP tool.
     *
     * @param string   $name        Tool name (e.g., 'my_custom_tool').
     * @param array    $schema      MCP tool schema with keys:
     *                              - description (string, required)
     *                              - inputSchema (object, required)
     *                              - annotations (array, optional) — MCP 2025-03-26 tool hints:
     *                                  - title (string) — human-readable display name
     *                                  - readOnlyHint (bool) — tool doesn't modify state
     *                                  - destructiveHint (bool) — tool may delete/destroy data
     *                                  - idempotentHint (bool) — safe to call multiple times
     *                                  - openWorldHint (bool) — tool interacts with external entities
     * @param callable $handler     Function that receives arguments array and returns data.
     */
    public function register_tool( $name, $schema, $handler ) {
        $schema['name']          = $name;
        $this->tools[ $name ]    = $schema;
        $this->handlers[ $name ] = $handler;
    }

    /* ───────────────────── System Tools ─────────────────────────────── */

    private function register_system_tools() {
        $this->register_tool( 'system_status', array(
            'description' => 'Get n8nPress plugin status, version, and server info',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array(
                'title'           => 'System Status',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function () {
            $api = LuwiPress_API::get_instance();
            return $api->get_status();
        } );

        $this->register_tool( 'system_health', array(
            'description' => 'Run health check (database, filesystem, memory)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array(
                'title'           => 'Health Check',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function () {
            $api = LuwiPress_API::get_instance();
            return $api->health_check();
        } );

        $this->register_tool( 'system_logs', array(
            'description' => 'Retrieve recent log entries from n8nPress',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit' => array( 'type' => 'integer', 'description' => 'Max entries (default 50, max 200)' ),
                    'level' => array( 'type' => 'string', 'enum' => array( 'info', 'warning', 'error' ), 'description' => 'Filter by level' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'View Logs',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/logs' );
            $request->set_param( 'limit', $args['limit'] ?? 50 );
            $request->set_param( 'level', $args['level'] ?? '' );
            $api = LuwiPress_API::get_instance();
            return $api->get_recent_logs( $request );
        } );

        $this->register_tool( 'site_config', array(
            'description' => 'Get full WordPress + WooCommerce + plugin environment snapshot. Returns detected plugins, languages, SEO settings, WC config, etc.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array(
                'title'           => 'Site Configuration',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function () {
            $config  = LuwiPress_Site_Config::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/site-config' );
            $data    = $config->get_site_config( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── Content Tools ────────────────────────────── */

    private function register_content_tools() {
        $this->register_tool( 'content_get_posts', array(
            'description' => 'Search and retrieve WordPress posts/products with filtering',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_type' => array( 'type' => 'string', 'enum' => array( 'post', 'page', 'product' ), 'description' => 'Post type (default: post)' ),
                    'status'    => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'any' ), 'description' => 'Post status (default: any)' ),
                    'search'    => array( 'type' => 'string', 'description' => 'Search query for title/content' ),
                    'per_page'  => array( 'type' => 'integer', 'description' => 'Results per page (max 100)' ),
                    'page'      => array( 'type' => 'integer', 'description' => 'Page number' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Search Posts',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $api = LuwiPress_API::get_instance();
            $data = array(
                'action'     => 'get_posts',
                'auth_token' => 'internal',
                'request_id' => uniqid( 'mcp_' ),
                'post_type'  => $args['post_type'] ?? 'post',
                'status'     => $args['status'] ?? 'any',
                'search'     => $args['search'] ?? '',
                'per_page'   => $args['per_page'] ?? 10,
                'page'       => $args['page'] ?? 1,
            );
            // Use WP_Query directly for cleaner MCP access
            return $this->query_posts( $data );
        } );

        $this->register_tool( 'content_create_post', array(
            'description' => 'Create a new WordPress post or page',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'title'     => array( 'type' => 'string', 'description' => 'Post title (required)' ),
                    'content'   => array( 'type' => 'string', 'description' => 'Post content (HTML)' ),
                    'excerpt'   => array( 'type' => 'string', 'description' => 'Post excerpt' ),
                    'status'    => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'private', 'pending' ), 'description' => 'Post status' ),
                    'post_type' => array( 'type' => 'string', 'enum' => array( 'post', 'page', 'product' ), 'description' => 'Post type' ),
                    'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Category IDs' ),
                    'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Tag names' ),
                ),
                'required'   => array( 'title' ),
            ),
            'annotations' => array(
                'title'            => 'Create Post',
                'readOnlyHint'     => false,
                'destructiveHint'  => false,
                'idempotentHint'   => false,
                'openWorldHint'    => false,
            ),
        ), function ( $args ) {
            return $this->create_post_internal( $args );
        } );

        $this->register_tool( 'content_update_post', array(
            'description' => 'Update an existing WordPress post',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'      => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'title'   => array( 'type' => 'string', 'description' => 'New title' ),
                    'content' => array( 'type' => 'string', 'description' => 'New content (HTML)' ),
                    'excerpt' => array( 'type' => 'string', 'description' => 'New excerpt' ),
                    'status'  => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'private', 'pending' ), 'description' => 'New status' ),
                ),
                'required'   => array( 'id' ),
            ),
            'annotations' => array(
                'title'            => 'Update Post',
                'readOnlyHint'     => false,
                'destructiveHint'  => false,
                'idempotentHint'   => true,
                'openWorldHint'    => false,
            ),
        ), function ( $args ) {
            return $this->update_post_internal( $args );
        } );

        $this->register_tool( 'content_delete_post', array(
            'description' => 'Delete a WordPress post (moves to trash by default)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'           => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'force_delete' => array( 'type' => 'boolean', 'description' => 'Permanently delete instead of trashing (default: false)' ),
                ),
                'required'   => array( 'id' ),
            ),
            'annotations' => array(
                'title'            => 'Delete Post',
                'readOnlyHint'     => false,
                'destructiveHint'  => true,
                'idempotentHint'   => true,
                'openWorldHint'    => false,
            ),
        ), function ( $args ) {
            $post_id = intval( $args['id'] );
            $force   = ! empty( $args['force_delete'] );
            $post    = get_post( $post_id );
            if ( ! $post ) {
                throw new Exception( 'Post not found' );
            }
            $result = wp_delete_post( $post_id, $force );
            if ( ! $result ) {
                throw new Exception( 'Failed to delete post' );
            }
            return array( 'id' => $post_id, 'deleted' => true, 'force' => $force );
        } );

        $this->register_tool( 'content_opportunities', array(
            'description' => 'Find content gaps: missing SEO meta, thin content, stale content, missing translations, missing alt text',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit' => array( 'type' => 'integer', 'description' => 'Max items per category (default 50)' ),
                ),
            ),
            'annotations' => array(
                'title'           => 'Content Opportunities',
                'readOnlyHint'    => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $config  = LuwiPress_Site_Config::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/content/opportunities' );
            $request->set_param( 'limit', $args['limit'] ?? 50 );
            $data = $config->get_content_opportunities( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'content_stale', array(
            'description' => 'List stale content that needs refreshing',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $ai      = LuwiPress_AI_Content::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/content/stale' );
            $data    = $ai->handle_stale_content( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── SEO / Enrichment Tools ──────────────────── */

    private function register_seo_tools() {
        $this->register_tool( 'seo_enrich_product', array(
            'description' => 'Trigger AI enrichment for a WooCommerce product (generates descriptions, meta, FAQ, schema via n8n workflow)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'WooCommerce product ID (required)' ),
                ),
                'required'   => array( 'product_id' ),
            ),
            'annotations' => array(
                'title'            => 'Enrich Product (AI)',
                'readOnlyHint'     => false,
                'destructiveHint'  => false,
                'idempotentHint'   => true,
                'openWorldHint'    => true,  // Triggers n8n workflow + AI API call
            ),
        ), function ( $args ) {
            $ai      = LuwiPress_AI_Content::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/product/enrich' );
            $request->set_body_params( array( 'product_id' => intval( $args['product_id'] ) ) );
            $data = $ai->handle_enrich_request( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'seo_enrich_batch', array(
            'description' => 'Trigger AI enrichment for multiple products at once',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_ids' => array(
                        'type'  => 'array',
                        'items' => array( 'type' => 'integer' ),
                        'description' => 'Array of product IDs to enrich',
                    ),
                ),
                'required'   => array( 'product_ids' ),
            ),
        ), function ( $args ) {
            $ai      = LuwiPress_AI_Content::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/product/enrich-batch' );
            $request->set_body_params( array( 'product_ids' => array_map( 'intval', $args['product_ids'] ) ) );
            $data = $ai->handle_batch_enrich_request( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'seo_batch_status', array(
            'description' => 'Check status of a batch enrichment job',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $ai      = LuwiPress_AI_Content::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/product/enrich-batch/status' );
            $data    = $ai->handle_batch_status( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'seo_rank_math_meta', array(
            'description' => 'Get Rank Math / Yoast SEO meta fields for a post',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Post/product ID (required)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
        ), function ( $args ) {
            $api     = LuwiPress_API::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/test/rank-math' );
            $request->set_param( 'post_id', intval( $args['post_id'] ) );
            $data = $api->test_rank_math_meta( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'seo_write_meta', array(
            'description' => 'Write SEO meta fields (title, description, focus keyword) for a post via detected plugin (Rank Math, Yoast, AIOSEO)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'          => array( 'type' => 'integer', 'description' => 'Post/product ID (required)' ),
                    'meta_title'       => array( 'type' => 'string', 'description' => 'SEO title' ),
                    'meta_description' => array( 'type' => 'string', 'description' => 'SEO meta description' ),
                    'focus_keyword'    => array( 'type' => 'string', 'description' => 'Focus keyword' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'            => 'Write SEO Meta',
                'readOnlyHint'     => false,
                'idempotentHint'   => true,
                'openWorldHint'    => false,
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_API', 'handle_set_seo_meta', array(
                'post_id'          => intval( $args['post_id'] ),
                'meta_title'       => $args['meta_title'] ?? '',
                'meta_description' => $args['meta_description'] ?? '',
                'focus_keyword'    => $args['focus_keyword'] ?? '',
            ) );
        } );
    }

    /* ───────────────────── AEO Tools ───────────────────────────────── */

    private function register_aeo_tools() {
        $this->register_tool( 'aeo_generate_faq', array(
            'description' => 'Trigger AI FAQ generation for a product (sends to n8n workflow)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'Product ID (required)' ),
                ),
                'required'   => array( 'product_id' ),
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_AEO', 'trigger_faq_generation', array(
                'product_id' => intval( $args['product_id'] ),
            ) );
        } );

        $this->register_tool( 'aeo_generate_howto', array(
            'description' => 'Trigger AI HowTo schema generation for a product',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'Product ID (required)' ),
                ),
                'required'   => array( 'product_id' ),
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_AEO', 'trigger_howto_generation', array(
                'product_id' => intval( $args['product_id'] ),
            ) );
        } );

        $this->register_tool( 'aeo_coverage', array(
            'description' => 'Get AEO coverage report: which products have FAQ, HowTo, Speakable schema',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_AEO' ) ) {
                throw new Exception( 'AEO module not loaded' );
            }
            $aeo     = LuwiPress_AEO::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/aeo/coverage' );
            $data    = $aeo->get_aeo_coverage( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'aeo_save_faq', array(
            'description' => 'Save FAQ schema data for a product (JSON-LD FAQPage)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'Product ID (required)' ),
                    'faqs'       => array( 'type' => 'array', 'description' => 'Array of {question, answer} objects', 'items' => array( 'type' => 'object' ) ),
                ),
                'required'   => array( 'product_id', 'faqs' ),
            ),
            'annotations' => array( 'title' => 'Save FAQ Schema', 'readOnlyHint' => false, 'idempotentHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_AEO', 'save_faq_data', $args );
        } );

        $this->register_tool( 'aeo_save_howto', array(
            'description' => 'Save HowTo schema data for a product',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'Product ID (required)' ),
                    'name'       => array( 'type' => 'string', 'description' => 'HowTo title' ),
                    'steps'      => array( 'type' => 'array', 'description' => 'Array of {name, text} step objects', 'items' => array( 'type' => 'object' ) ),
                ),
                'required'   => array( 'product_id', 'name', 'steps' ),
            ),
            'annotations' => array( 'title' => 'Save HowTo Schema', 'readOnlyHint' => false, 'idempotentHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_AEO', 'save_howto_data', $args );
        } );

        $this->register_tool( 'aeo_save_speakable', array(
            'description' => 'Save Speakable schema data for a product',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id'     => array( 'type' => 'integer', 'description' => 'Product ID (required)' ),
                    'css_selectors'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'CSS selectors for speakable content' ),
                ),
                'required'   => array( 'product_id' ),
            ),
            'annotations' => array( 'title' => 'Save Speakable Schema', 'readOnlyHint' => false, 'idempotentHint' => true ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_AEO', 'save_speakable_data', $args );
        } );
    }

    /* ───────────────────── Translation Tools ───────────────────────── */

    private function register_translation_tools() {
        $this->register_tool( 'translation_missing', array(
            'description' => 'Get products/posts missing translations for a specific language',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'language'  => array( 'type' => 'string', 'description' => 'Target language code (e.g., fr, de, ar)' ),
                    'post_type' => array( 'type' => 'string', 'description' => 'Post type to check (default: product)' ),
                ),
            ),
        ), function ( $args ) {
            $trans   = LuwiPress_Translation::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/missing' );
            if ( ! empty( $args['language'] ) ) {
                $request->set_param( 'language', $args['language'] );
            }
            if ( ! empty( $args['post_type'] ) ) {
                $request->set_param( 'post_type', $args['post_type'] );
            }
            $data = $trans->get_missing_translations( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'translation_missing_all', array(
            'description' => 'Get all missing translations across all languages',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $trans   = LuwiPress_Translation::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/missing-all' );
            $data    = $trans->get_missing_translations_all( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'translation_request', array(
            'description' => 'Request AI translation for a post/product to a target language (triggers n8n workflow)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'         => array( 'type' => 'integer', 'description' => 'Source post ID (required)' ),
                    'target_language' => array( 'type' => 'string', 'description' => 'Target language code, e.g. fr, de, ar (required)' ),
                ),
                'required'   => array( 'post_id', 'target_language' ),
            ),
            'annotations' => array(
                'title'            => 'Request Translation (AI)',
                'readOnlyHint'     => false,
                'destructiveHint'  => false,
                'idempotentHint'   => true,
                'openWorldHint'    => true,  // Triggers n8n workflow + AI API
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Translation', 'request_translation', array(
                'post_id'         => intval( $args['post_id'] ),
                'target_language' => sanitize_text_field( $args['target_language'] ),
            ) );
        } );

        $this->register_tool( 'translation_status', array(
            'description' => 'Get translation status for a post across all languages',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Post ID to check' ),
                ),
            ),
        ), function ( $args ) {
            $trans   = LuwiPress_Translation::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/status' );
            if ( ! empty( $args['post_id'] ) ) {
                $request->set_param( 'post_id', $args['post_id'] );
            }
            $data = $trans->get_translation_status( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'translation_quality_check', array(
            'description' => 'Trigger quality check on existing translation',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Translated post ID (required)' ),
                    'language' => array( 'type' => 'string', 'description' => 'Language code of the translation' ),
                ),
                'required'   => array( 'post_id' ),
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Translation', 'trigger_quality_check', $args );
        } );

        $this->register_tool( 'translation_taxonomy', array(
            'description' => 'Request translation for taxonomy terms (categories, tags, attributes)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'taxonomy'        => array( 'type' => 'string', 'description' => 'Taxonomy name (e.g., product_cat)' ),
                    'target_language' => array( 'type' => 'string', 'description' => 'Target language code (required)' ),
                ),
                'required'   => array( 'target_language' ),
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Translation', 'request_taxonomy_translation', $args );
        } );

        $this->register_tool( 'translation_taxonomy_missing', array(
            'description' => 'Get taxonomy terms missing translations',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'language' => array( 'type' => 'string', 'description' => 'Target language code' ),
                    'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy to check' ),
                ),
            ),
        ), function ( $args ) {
            $trans   = LuwiPress_Translation::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/taxonomy-missing' );
            if ( ! empty( $args['language'] ) ) {
                $request->set_param( 'language', $args['language'] );
            }
            if ( ! empty( $args['taxonomy'] ) ) {
                $request->set_param( 'taxonomy', $args['taxonomy'] );
            }
            $data = $trans->get_missing_taxonomy_terms_api( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── CRM Tools ───────────────────────────────── */

    private function register_crm_tools() {
        if ( ! class_exists( 'LuwiPress_CRM_Bridge' ) ) {
            return;
        }

        $this->register_tool( 'crm_overview', array(
            'description' => 'Get CRM overview: total customers, segments, revenue stats',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $crm     = LuwiPress_CRM_Bridge::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/crm/overview' );
            $data    = $crm->handle_overview( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'crm_segments', array(
            'description' => 'List customer segments (VIP, at-risk, new, churned, etc.)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $crm     = LuwiPress_CRM_Bridge::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/crm/segments' );
            $data    = $crm->handle_segments( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'crm_segment_customers', array(
            'description' => 'Get customers in a specific segment',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'segment' => array( 'type' => 'string', 'description' => 'Segment slug: vip, at_risk, new, churned, etc. (required)' ),
                ),
                'required'   => array( 'segment' ),
            ),
        ), function ( $args ) {
            $crm     = LuwiPress_CRM_Bridge::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/crm/segment/' . sanitize_text_field( $args['segment'] ) );
            $data    = $crm->handle_segment_customers( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'crm_customer_profile', array(
            'description' => 'Get detailed customer profile with purchase history and analytics',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'customer_id' => array( 'type' => 'integer', 'description' => 'Customer/user ID (required)' ),
                ),
                'required'   => array( 'customer_id' ),
            ),
        ), function ( $args ) {
            $crm     = LuwiPress_CRM_Bridge::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/crm/customer/' . intval( $args['customer_id'] ) );
            $data    = $crm->handle_customer_profile( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'crm_lifecycle_queue', array(
            'description' => 'Get pending CRM lifecycle events (welcome, review request, win-back emails queued for sending)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Lifecycle Queue', 'readOnlyHint' => true, 'idempotentHint' => true ),
        ), function () {
            $crm     = LuwiPress_CRM_Bridge::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/crm/lifecycle-queue' );
            $data    = $crm->handle_lifecycle_queue( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── Email Tools ──────────────────────────────── */

    private function register_email_tools() {
        $this->register_tool( 'send_email', array(
            'description' => 'Send an email via WordPress wp_mail() using the site\'s configured SMTP. Supports plain text and WooCommerce HTML template.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'to'       => array( 'type' => 'string', 'format' => 'email', 'description' => 'Recipient email (required)' ),
                    'subject'  => array( 'type' => 'string', 'description' => 'Email subject (required)' ),
                    'body'     => array( 'type' => 'string', 'description' => 'Email body — HTML or plain text (required)' ),
                    'template' => array( 'type' => 'string', 'enum' => array( 'plain', 'woocommerce' ), 'description' => 'Email template (default: plain)' ),
                ),
                'required'   => array( 'to', 'subject', 'body' ),
            ),
            'annotations' => array(
                'title'            => 'Send Email',
                'readOnlyHint'     => false,
                'destructiveHint'  => false,
                'idempotentHint'   => false,
                'openWorldHint'    => true,   // Sends real email to external recipient
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Email_Proxy', 'send_email', $args );
        } );
    }

    /* ───────────────────── Open Claw (AI Agent) Tools ──────────────── */

    private function register_claw_tools() {
        if ( ! class_exists( 'LuwiPress_Open_Claw' ) ) {
            return;
        }

        $this->register_tool( 'claw_execute', array(
            'description' => 'Execute an Open Claw AI action (sends prompt to n8n AI workflow for autonomous task execution)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'action'  => array( 'type' => 'string', 'description' => 'Action type to execute (required)' ),
                    'prompt'  => array( 'type' => 'string', 'description' => 'AI prompt / instruction' ),
                    'context' => array( 'type' => 'object', 'description' => 'Additional context data' ),
                ),
                'required'   => array( 'action' ),
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Open_Claw', 'handle_execute_action', $args );
        } );

        $this->register_tool( 'claw_channels', array(
            'description' => 'List available Open Claw communication channels',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $claw    = LuwiPress_Open_Claw::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/claw/channels' );
            $data    = $claw->handle_get_channels( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── Chatwoot Tools ──────────────────────────── */

    private function register_chatwoot_tools() {
        if ( ! class_exists( 'LuwiPress_Chatwoot' ) ) {
            return;
        }

        $this->register_tool( 'chatwoot_customer_lookup', array(
            'description' => 'Look up a customer in Chatwoot by email or ID, cross-referenced with WooCommerce data',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'email'       => array( 'type' => 'string', 'description' => 'Customer email to look up' ),
                    'customer_id' => array( 'type' => 'integer', 'description' => 'WooCommerce customer ID' ),
                ),
            ),
        ), function ( $args ) {
            $chat    = LuwiPress_Chatwoot::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/chatwoot/customer-lookup' );
            if ( ! empty( $args['email'] ) ) {
                $request->set_param( 'email', $args['email'] );
            }
            if ( ! empty( $args['customer_id'] ) ) {
                $request->set_param( 'customer_id', $args['customer_id'] );
            }
            $data = $chat->customer_lookup( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'chatwoot_send_message', array(
            'description' => 'Send a message to a Chatwoot conversation',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'conversation_id' => array( 'type' => 'integer', 'description' => 'Chatwoot conversation ID (required)' ),
                    'message'         => array( 'type' => 'string', 'description' => 'Message content (required)' ),
                    'private'         => array( 'type' => 'boolean', 'description' => 'Send as private note (default false)' ),
                ),
                'required'   => array( 'conversation_id', 'message' ),
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Chatwoot', 'send_message', $args );
        } );

        $this->register_tool( 'chatwoot_status', array(
            'description' => 'Get Chatwoot integration status',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $chat    = LuwiPress_Chatwoot::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/chatwoot/status' );
            $data    = $chat->get_status( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── Workflow Tools ──────────────────────────── */

    private function register_workflow_tools() {
        $this->register_tool( 'workflow_report_result', array(
            'description' => 'Report a workflow execution result back to n8nPress (status, message, token usage)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'workflow'     => array( 'type' => 'string', 'description' => 'Workflow name (required)' ),
                    'status'       => array( 'type' => 'string', 'description' => 'success, error, warning' ),
                    'message'      => array( 'type' => 'string', 'description' => 'Result message (required)' ),
                    'execution_id' => array( 'type' => 'string', 'description' => 'n8n execution ID' ),
                    'token_usage'  => array( 'type' => 'object', 'description' => 'Token usage data: provider, model, input_tokens, output_tokens' ),
                ),
                'required'   => array( 'workflow', 'message' ),
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_API', 'handle_workflow_result', $args );
        } );

        $this->register_tool( 'content_schedule_list', array(
            'description' => 'Get scheduled content items',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            if ( ! class_exists( 'LuwiPress_Content_Scheduler' ) ) {
                throw new Exception( 'Content Scheduler not loaded' );
            }
            $sched   = LuwiPress_Content_Scheduler::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/schedule/list' );
            $data    = $sched->get_schedule_list( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── Token Usage Tools ──────────────────────── */

    private function register_token_tools() {
        $this->register_tool( 'token_usage_stats', array(
            'description' => 'Get AI token usage statistics (cost, providers, models breakdown)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'days' => array( 'type' => 'integer', 'description' => 'Number of days to report (default 30)' ),
                ),
            ),
        ), function ( $args ) {
            $api     = LuwiPress_API::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/token-usage' );
            $request->set_param( 'days', $args['days'] ?? 30 );
            return $api->handle_get_token_usage( $request );
        } );

        $this->register_tool( 'token_limit_check', array(
            'description' => 'Check if daily AI token spending limit allows more API calls',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $api     = LuwiPress_API::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/token-usage/check' );
            return $api->handle_check_limit( $request );
        } );

        $this->register_tool( 'token_recent_calls', array(
            'description' => 'Get recent AI API calls with cost breakdown',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $api     = LuwiPress_API::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/token-recent' );
            return $api->handle_get_token_recent( $request );
        } );
    }

    /* ───────────────────── Review Analytics Tools ──────────────────── */

    private function register_review_tools() {
        if ( ! class_exists( 'LuwiPress_Review_Analytics' ) ) {
            return;
        }

        $this->register_tool( 'review_analytics', array(
            'description' => 'Get review analytics: sentiment distribution, average rating, response rates',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $ra      = LuwiPress_Review_Analytics::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/review/analytics' );
            $data    = $ra->handle_get_analytics( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'review_summary', array(
            'description' => 'Get AI-generated review summary for a product or across all products',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array( 'type' => 'integer', 'description' => 'Product ID (optional — omit for all)' ),
                ),
            ),
        ), function ( $args ) {
            $ra      = LuwiPress_Review_Analytics::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/review/summary' );
            if ( ! empty( $args['product_id'] ) ) {
                $request->set_param( 'product_id', intval( $args['product_id'] ) );
            }
            $data = $ra->handle_get_summary( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── Internal Linker Tools ──────────────────── */

    private function register_linker_tools() {
        if ( ! class_exists( 'LuwiPress_Internal_Linker' ) ) {
            return;
        }

        $this->register_tool( 'linker_resolve', array(
            'description' => 'Request AI to find internal linking opportunities for a post',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Post ID to analyze (required)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
        ), function ( $args ) {
            return $this->proxy_rest_post( 'LuwiPress_Internal_Linker', 'handle_resolve_links', array(
                'post_id' => intval( $args['post_id'] ),
            ) );
        } );

        $this->register_tool( 'linker_unresolved', array(
            'description' => 'List posts with unresolved internal linking suggestions',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $linker  = LuwiPress_Internal_Linker::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/content/unresolved-links' );
            $data    = $linker->handle_get_unresolved( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ───────────────────── Knowledge Graph Tools ──────────────────── */

    private function register_knowledge_graph_tools() {
        if ( ! class_exists( 'LuwiPress_Knowledge_Graph' ) ) {
            return;
        }

        $this->register_tool( 'knowledge_graph', array(
            'description' => 'Get the site knowledge graph — entity relationships, product connections, and semantic structure',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
        ), function () {
            $kg      = LuwiPress_Knowledge_Graph::get_instance();
            $request = new WP_REST_Request( 'GET', '/luwipress/v1/knowledge-graph' );
            $data    = $kg->handle_knowledge_graph( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  INTERNAL HELPERS
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Proxy a POST request to an existing singleton's REST handler.
     *
     * @param string $class_name  Singleton class name.
     * @param string $method      Method to call on the instance.
     * @param array  $body_params POST body parameters.
     * @return mixed
     */
    private function proxy_rest_post( $class_name, $method, $body_params ) {
        if ( ! class_exists( $class_name ) ) {
            throw new Exception( "{$class_name} not loaded" );
        }
        $instance = $class_name::get_instance();
        $request  = new WP_REST_Request( 'POST', '' );
        $request->set_body_params( $body_params );
        $data = $instance->$method( $request );
        return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
    }

    /**
     * Internal post query (bypasses webhook auth for MCP context).
     */
    private function query_posts( $args ) {
        $query_args = array(
            'post_type'      => sanitize_text_field( $args['post_type'] ?? 'post' ),
            'post_status'    => sanitize_text_field( $args['status'] ?? 'any' ),
            'posts_per_page' => min( intval( $args['per_page'] ?? 10 ), 100 ),
            'paged'          => intval( $args['page'] ?? 1 ),
        );

        if ( ! empty( $args['search'] ) ) {
            $query_args['s'] = sanitize_text_field( $args['search'] );
        }

        $query = new WP_Query( $query_args );
        $posts = array();

        foreach ( $query->posts as $post ) {
            $posts[] = array(
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'status'  => $post->post_status,
                'date'    => $post->post_date,
                'url'     => get_permalink( $post->ID ),
                'excerpt' => wp_trim_words( $post->post_content, 30 ),
            );
        }

        return array(
            'posts'      => $posts,
            'total'      => $query->found_posts,
            'pages'      => $query->max_num_pages,
            'page'       => $query_args['paged'],
        );
    }

    /**
     * Internal post creation (MCP context — already authenticated).
     */
    private function create_post_internal( $args ) {
        if ( empty( $args['title'] ) ) {
            throw new Exception( 'Title is required' );
        }

        $post_data = array(
            'post_title'   => sanitize_text_field( $args['title'] ),
            'post_content' => wp_kses_post( $args['content'] ?? '' ),
            'post_excerpt' => sanitize_text_field( $args['excerpt'] ?? '' ),
            'post_status'  => sanitize_text_field( $args['status'] ?? 'draft' ),
            'post_type'    => sanitize_text_field( $args['post_type'] ?? 'post' ),
            'post_author'  => get_current_user_id() ?: 1,
            'meta_input'   => array(
                '_luwipress_created_via' => 'webmcp',
            ),
        );

        if ( ! empty( $args['categories'] ) ) {
            $post_data['post_category'] = array_map( 'intval', (array) $args['categories'] );
        }

        $post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $post_id ) ) {
            throw new Exception( 'Failed to create post: ' . $post_id->get_error_message() );
        }

        if ( ! empty( $args['tags'] ) ) {
            wp_set_post_tags( $post_id, (array) $args['tags'] );
        }

        return array(
            'id'     => $post_id,
            'title'  => get_the_title( $post_id ),
            'url'    => get_permalink( $post_id ),
            'status' => get_post_status( $post_id ),
        );
    }

    /**
     * Internal post update.
     */
    private function update_post_internal( $args ) {
        $post_id = intval( $args['id'] ?? 0 );
        if ( ! $post_id || ! get_post( $post_id ) ) {
            throw new Exception( 'Post not found' );
        }

        $update = array( 'ID' => $post_id );
        if ( isset( $args['title'] ) ) {
            $update['post_title'] = sanitize_text_field( $args['title'] );
        }
        if ( isset( $args['content'] ) ) {
            $update['post_content'] = wp_kses_post( $args['content'] );
        }
        if ( isset( $args['excerpt'] ) ) {
            $update['post_excerpt'] = sanitize_text_field( $args['excerpt'] );
        }
        if ( isset( $args['status'] ) ) {
            $update['post_status'] = sanitize_text_field( $args['status'] );
        }

        $result = wp_update_post( $update, true );
        if ( is_wp_error( $result ) ) {
            throw new Exception( 'Failed to update: ' . $result->get_error_message() );
        }

        update_post_meta( $post_id, '_luwipress_last_updated_via', 'webmcp' );

        return array(
            'id'      => $post_id,
            'updated' => true,
            'url'     => get_permalink( $post_id ),
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  JSON-RPC HELPERS
     * ═══════════════════════════════════════════════════════════════════ */

    private function jsonrpc_result( $id, $result ) {
        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        );
    }

    private function jsonrpc_error( $id, $code, $message, $data = null ) {
        $error = array(
            'code'    => $code,
            'message' => $message,
        );
        if ( null !== $data ) {
            $error['data'] = $data;
        }
        return array(
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => $error,
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  PUBLIC API — Introspection
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Get the count of registered tools.
     */
    public function get_tool_count() {
        return count( $this->tools );
    }

    /**
     * Get all tool names grouped by category.
     */
    public function get_tool_catalog() {
        $catalog = array();
        foreach ( $this->tools as $name => $schema ) {
            $parts    = explode( '_', $name, 2 );
            $category = $parts[0];
            if ( ! isset( $catalog[ $category ] ) ) {
                $catalog[ $category ] = array();
            }
            $catalog[ $category ][] = array(
                'name'        => $name,
                'description' => $schema['description'] ?? '',
            );
        }
        return $catalog;
    }

    /**
     * Check if WebMCP is enabled via settings.
     */
    public static function is_enabled() {
        return (bool) get_option( 'luwipress_webmcp_enabled', true );
    }
}
