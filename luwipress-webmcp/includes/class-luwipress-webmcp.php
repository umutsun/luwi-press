<?php
/**
 * N8nPress WebMCP Server
 *
 * Implements the MCP (Model Context Protocol) Streamable HTTP transport,
 * exposing all LuwiPress REST API endpoints as MCP tools that AI agents
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
     *  2. Bearer token matching the LuwiPress API token
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
     * Server instructions for the AI agent — describes what LuwiPress can do.
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
                'uri'         => 'luwipress://site-config',
                'name'        => 'Site Configuration',
                'description' => 'Full WordPress + WooCommerce + plugin environment snapshot',
                'mimeType'    => 'application/json',
            ),
            array(
                'uri'         => 'luwipress://health',
                'name'        => 'Health Check',
                'description' => 'Server health status (database, filesystem, memory)',
                'mimeType'    => 'application/json',
            ),
            array(
                'uri'         => 'luwipress://aeo-coverage',
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
                'uriTemplate'  => 'luwipress://post/{post_id}',
                'name'         => 'WordPress Post',
                'description'  => 'Read a specific post/product by ID',
                'mimeType'     => 'application/json',
            ),
            array(
                'uriTemplate'  => 'luwipress://seo-meta/{post_id}',
                'name'         => 'SEO Meta',
                'description'  => 'Rank Math / Yoast SEO meta for a post',
                'mimeType'     => 'application/json',
            ),
            array(
                'uriTemplate'  => 'luwipress://translation-status/{post_id}',
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
            case 'luwipress://site-config':
                $config  = LuwiPress_Site_Config::get_instance();
                $request = new WP_REST_Request( 'GET', '/luwipress/v1/site-config' );
                $data    = $config->get_site_config( $request );
                break;

            case 'luwipress://health':
                $api  = LuwiPress_API::get_instance();
                $data = $api->health_check();
                break;

            case 'luwipress://aeo-coverage':
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
     * @param string $uri The resource URI (e.g., 'luwipress://post/42').
     * @return array|null  Resource data, or null if not matched.
     */
    private function resolve_resource_template( $uri ) {
        // luwipress://post/{post_id}
        if ( preg_match( '#^luwipress://post/(\d+)$#', $uri, $m ) ) {
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

        // luwipress://seo-meta/{post_id}
        if ( preg_match( '#^luwipress://seo-meta/(\d+)$#', $uri, $m ) ) {
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

        // luwipress://translation-status/{post_id}
        if ( preg_match( '#^luwipress://translation-status/(\d+)$#', $uri, $m ) ) {
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
     * Each tool wraps an existing LuwiPress REST endpoint.
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
        $this->register_elementor_tools();
        $this->register_admin_tools();
        $this->register_taxonomy_tools();
        $this->register_woo_tools();
        $this->register_media_tools();
        $this->register_comment_tools();
        $this->register_settings_tools();
        $this->register_plugin_theme_tools();
        $this->register_menu_tools();
        $this->register_meta_tools();
        $this->register_search_tools();

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

        $this->register_tool( 'cache_purge', array(
            'description' => 'Purge caches across LiteSpeed, WP Rocket, W3TC, WP Super Cache, Elementor CSS, and WordPress object cache. Use after bulk content or style updates to make new output visible immediately.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'targets' => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string', 'enum' => array( 'all', 'elementor', 'litespeed', 'wp_rocket', 'w3tc', 'super_cache', 'object_cache' ) ),
                        'description' => 'Which caches to clear. Default: ["all"].',
                    ),
                    'post_id' => array( 'type' => 'integer', 'description' => 'Optional post ID for per-post regeneration (Elementor CSS).' ),
                ),
            ),
            'annotations' => array( 'title' => 'Purge Caches', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $api     = LuwiPress_API::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/cache/purge' );
            $request->set_param( 'targets', $args['targets'] ?? array( 'all' ) );
            if ( ! empty( $args['post_id'] ) ) {
                $request->set_param( 'post_id', absint( $args['post_id'] ) );
            }
            $data = $api->handle_cache_purge( $request );
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
            // Switch WPML to all languages so we can find any post regardless of language
            do_action( 'wpml_switch_language', 'all' );
            $post = get_post( $post_id );
            if ( ! $post ) {
                do_action( 'wpml_switch_language', apply_filters( 'wpml_default_language', 'en' ) );
                throw new Exception( 'Post not found' );
            }
            $result = wp_delete_post( $post_id, $force );
            do_action( 'wpml_switch_language', apply_filters( 'wpml_default_language', 'en' ) );
            if ( ! $result ) {
                throw new Exception( 'Failed to delete post' );
            }
            return array( 'id' => $post_id, 'deleted' => true, 'force' => $force, 'title' => $post->post_title );
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
                'post_id'       => intval( $args['post_id'] ),
                'title'         => $args['meta_title'] ?? '',
                'description'   => $args['meta_description'] ?? '',
                'focus_keyword' => $args['focus_keyword'] ?? '',
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
                $request->set_param( 'target_language', $args['language'] );
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
                'product_id'       => intval( $args['post_id'] ),
                'target_languages' => sanitize_text_field( $args['target_language'] ),
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
                $request->set_param( 'target_languages', $args['language'] );
            }
            if ( ! empty( $args['taxonomy'] ) ) {
                $request->set_param( 'taxonomy', $args['taxonomy'] );
            }
            $data = $trans->get_missing_taxonomy_terms_api( $request );
            return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
        } );

        $this->register_tool( 'translation_batch', array(
            'description' => 'Translate N untranslated posts for one or more target languages in a single call. Powers the "Translate N missing products" action on Knowledge Graph language nodes.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'languages' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Target language codes (required)' ),
                    'post_type' => array( 'type' => 'string', 'description' => 'Post type to translate (default: product)' ),
                    'limit'     => array( 'type' => 'integer', 'description' => 'Max posts to translate (default 50, max 200)' ),
                ),
                'required' => array( 'languages' ),
            ),
            'annotations' => array( 'title' => 'Batch Translate', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $trans   = LuwiPress_Translation::get_instance();
            $request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/batch' );
            $request->set_param( 'languages', $args['languages'] ?? array() );
            $request->set_param( 'post_type', $args['post_type'] ?? 'product' );
            $request->set_param( 'limit', $args['limit'] ?? 50 );
            $data = $trans->batch_translate_missing( $request );
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

    /* ───────────────────── Elementor Tools ──────────────────────────── */

    private function register_elementor_tools() {
        if ( ! class_exists( 'LuwiPress_Elementor' ) ) {
            return;
        }

        $this->register_tool( 'elementor_read_page', array(
            'description' => 'Read an Elementor page — returns full structure: sections, columns, and widgets with their text, styles, and element IDs. Use this first to discover element IDs before editing.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'          => 'Read Elementor Page',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem     = LuwiPress_Elementor::get_instance();
            $elements = $elem->get_widget_tree( intval( $args['post_id'] ) );
            if ( is_wp_error( $elements ) ) {
                return array( 'error' => $elements->get_error_message() );
            }
            $post = get_post( intval( $args['post_id'] ) );
            return array(
                'post_id'       => intval( $args['post_id'] ),
                'title'         => $post ? $post->post_title : '',
                'element_count' => count( $elements ),
                'elements'      => $elements,
            );
        } );

        $this->register_tool( 'elementor_page_outline', array(
            'description' => 'Get a compact outline of an Elementor page — lightweight hierarchy with element IDs, types, and text previews. Use this for a quick overview before detailed reads.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'          => 'Elementor Page Outline',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem    = LuwiPress_Elementor::get_instance();
            $outline = $elem->get_page_outline( intval( $args['post_id'] ) );
            if ( is_wp_error( $outline ) ) {
                return array( 'error' => $outline->get_error_message() );
            }
            $post = get_post( intval( $args['post_id'] ) );
            return array(
                'post_id'   => intval( $args['post_id'] ),
                'title'     => $post ? $post->post_title : '',
                'structure' => $outline,
            );
        } );

        $this->register_tool( 'elementor_translate_page', array(
            'description' => 'AI-translate all text in an Elementor page and create a WPML translation copy. Translates headings, text editors, buttons, tabs, accordions, etc.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'         => array( 'type' => 'integer', 'description' => 'Source post/page ID (required)' ),
                    'target_language' => array( 'type' => 'string', 'description' => 'Target language code, e.g. fr, de, ar (required)' ),
                ),
                'required'   => array( 'post_id', 'target_language' ),
            ),
            'annotations' => array(
                'title'           => 'Translate Elementor Page (AI)',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => true,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->translate_page( intval( $args['post_id'] ), sanitize_text_field( $args['target_language'] ) );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return $result;
        } );

        $this->register_tool( 'elementor_get_widget', array(
            'description' => 'Get a specific Elementor element (widget, section, or column) by its ID — returns full settings, text content, and style info',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Elementor element ID — get this from elementor_read_page or elementor_page_outline (required)' ),
                ),
                'required'   => array( 'post_id', 'element_id' ),
            ),
            'annotations' => array(
                'title'          => 'Get Elementor Element',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem     = LuwiPress_Elementor::get_instance();
            $elements = $elem->get_widget_tree( intval( $args['post_id'] ) );
            if ( is_wp_error( $elements ) ) {
                return array( 'error' => $elements->get_error_message() );
            }
            $target_id = sanitize_text_field( $args['element_id'] );
            foreach ( $elements as $el ) {
                if ( $el['id'] === $target_id ) {
                    return $el;
                }
            }
            return array( 'error' => 'Element not found: ' . $target_id );
        } );

        $this->register_tool( 'elementor_set_widget_text', array(
            'description' => 'Update text content of an Elementor widget. Works with heading (title), text-editor (editor), button (text), image-box (title_text, description_text), tabs/accordion (tabs:0:tab_title), etc.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Elementor element ID (required)' ),
                    'texts'      => array(
                        'type'        => 'object',
                        'description' => 'Text fields to update. Examples: {"title": "New Title"}, {"editor": "<p>New content</p>"}, {"text": "Click Me"}',
                    ),
                ),
                'required'   => array( 'post_id', 'element_id', 'texts' ),
            ),
            'annotations' => array(
                'title'           => 'Set Element Text',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->set_widget_text(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['element_id'] ),
                $args['texts']
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'updated', 'post_id' => intval( $args['post_id'] ), 'element_id' => $args['element_id'] );
        } );

        $this->register_tool( 'elementor_set_style', array(
            'description' => 'Update CSS styles on any Elementor element (widget, section, or column). Accepts standard CSS property names which are auto-converted to Elementor format. Examples: {"color": "#ff0000", "font-size": "18px", "background-color": "#000", "padding": "10px 20px", "font-weight": "700", "text-transform": "uppercase", "border-radius": "8px", "margin": "0 0 20px 0"}',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Elementor element ID — widget, section, or column (required)' ),
                    'styles'     => array(
                        'type'        => 'object',
                        'description' => 'CSS or Elementor style properties. CSS names auto-convert: "color" → title_color, "font-size" → typography_font_size, "padding" → _padding, etc.',
                    ),
                ),
                'required'   => array( 'post_id', 'element_id', 'styles' ),
            ),
            'annotations' => array(
                'title'           => 'Set Element Style',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->set_widget_style(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['element_id'] ),
                $args['styles']
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'updated', 'post_id' => intval( $args['post_id'] ), 'element_id' => $args['element_id'] );
        } );

        $this->register_tool( 'elementor_bulk_update', array(
            'description' => 'Batch update multiple Elementor elements in one save. Each change can modify text and/or styles. Styles accept CSS names. Example: [{"widget_id": "abc", "texts": {"title": "New"}, "styles": {"color": "#fff", "font-size": "24px"}}]',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'WordPress post/page ID (required)' ),
                    'changes' => array(
                        'type'        => 'array',
                        'description' => 'Array of element changes with CSS-friendly styles',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'widget_id' => array( 'type' => 'string', 'description' => 'Element ID' ),
                                'texts'     => array( 'type' => 'object', 'description' => 'Text fields to update' ),
                                'styles'    => array( 'type' => 'object', 'description' => 'CSS style properties' ),
                            ),
                            'required'   => array( 'widget_id' ),
                        ),
                    ),
                ),
                'required'   => array( 'post_id', 'changes' ),
            ),
            'annotations' => array(
                'title'           => 'Bulk Update Elements',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->bulk_update( intval( $args['post_id'] ), $args['changes'] );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'completed', 'post_id' => intval( $args['post_id'] ), 'results' => $result );
        } );

        // ── Structural tools ──

        $this->register_tool( 'elementor_add_widget', array(
            'description' => 'Add a new widget inside an Elementor container (section/column). Widget types: heading, text-editor, button, image, image-box, icon-box, video, spacer, divider, google_maps, icon, counter, progress, testimonial, tabs, accordion, toggle, alert, html, shortcode, menu-anchor, sidebar, call-to-action, price-table.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'      => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'container_id' => array( 'type' => 'string', 'description' => 'Parent element ID (section/column/container) — get from elementor_page_outline (required)' ),
                    'widget_type'  => array( 'type' => 'string', 'description' => 'Widget type, e.g. "heading", "button", "text-editor" (required)' ),
                    'settings'     => array( 'type' => 'object', 'description' => 'Widget settings: {"title": "Hello World", "title_color": "#000"}' ),
                    'position'     => array( 'type' => 'integer', 'description' => 'Insert position (0-based, -1 = append at end, default: -1)' ),
                ),
                'required'   => array( 'post_id', 'container_id', 'widget_type' ),
            ),
            'annotations' => array(
                'title'           => 'Add Widget',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->add_widget(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['container_id'] ),
                sanitize_text_field( $args['widget_type'] ),
                $args['settings'] ?? array(),
                intval( $args['position'] ?? -1 )
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return $result;
        } );

        $this->register_tool( 'elementor_add_section', array(
            'description' => 'Add a new section (with one column) to an Elementor page. Returns section_id and column_id for adding widgets into.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'position' => array( 'type' => 'integer', 'description' => 'Insert position among root sections (0-based, -1 = end, default: -1)' ),
                    'settings' => array( 'type' => 'object', 'description' => 'Section settings (background, padding, etc.)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'           => 'Add Section',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->add_section(
                intval( $args['post_id'] ),
                intval( $args['position'] ?? -1 ),
                $args['settings'] ?? array()
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return $result;
        } );

        $this->register_tool( 'elementor_delete_element', array(
            'description' => 'Delete an Elementor element (widget, section, or column) by its ID. Warning: deleting a section removes all its columns and widgets.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Element ID to delete (required)' ),
                ),
                'required'   => array( 'post_id', 'element_id' ),
            ),
            'annotations' => array(
                'title'           => 'Delete Element',
                'readOnlyHint'    => false,
                'destructiveHint' => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->delete_element( intval( $args['post_id'] ), sanitize_text_field( $args['element_id'] ) );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'deleted', 'element_id' => $args['element_id'] );
        } );

        $this->register_tool( 'elementor_move_element', array(
            'description' => 'Move an Elementor element to a new position. Can move within the same parent (reorder) or to a different container.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'       => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'element_id'    => array( 'type' => 'string', 'description' => 'Element ID to move (required)' ),
                    'target_parent' => array( 'type' => 'string', 'description' => 'New parent container ID (empty = root level for sections)' ),
                    'position'      => array( 'type' => 'integer', 'description' => 'New position index (0-based, -1 = end)' ),
                ),
                'required'   => array( 'post_id', 'element_id' ),
            ),
            'annotations' => array(
                'title'           => 'Move Element',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->move_element(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['element_id'] ),
                sanitize_text_field( $args['target_parent'] ?? '' ),
                intval( $args['position'] ?? -1 )
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'moved', 'element_id' => $args['element_id'] );
        } );

        $this->register_tool( 'elementor_clone_element', array(
            'description' => 'Duplicate an Elementor element (widget, section, column) with all its children. The clone is inserted right after the original with new unique IDs.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Element ID to clone (required)' ),
                ),
                'required'   => array( 'post_id', 'element_id' ),
            ),
            'annotations' => array(
                'title'           => 'Clone Element',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->clone_element( intval( $args['post_id'] ), sanitize_text_field( $args['element_id'] ) );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return $result;
        } );

        // ── CSS & Responsive tools ──

        $this->register_tool( 'elementor_custom_css', array(
            'description' => 'Inject custom CSS on a specific element or the entire page. Use "selector" as the CSS selector for the element\'s wrapper. Example: "selector .elementor-heading-title { text-shadow: 2px 2px #000; }" — omit element_id to apply page-level CSS.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Element ID (optional — omit for page-level CSS)' ),
                    'css'        => array( 'type' => 'string', 'description' => 'CSS code. Use "selector" for element wrapper. Example: "selector { box-shadow: 0 4px 8px rgba(0,0,0,0.1); }" (required)' ),
                ),
                'required'   => array( 'post_id', 'css' ),
            ),
            'annotations' => array(
                'title'           => 'Custom CSS',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem       = LuwiPress_Elementor::get_instance();
            $element_id = sanitize_text_field( $args['element_id'] ?? '' );
            $css        = $args['css'];

            if ( empty( $element_id ) ) {
                $result = $elem->set_page_css( intval( $args['post_id'] ), $css );
            } else {
                $result = $elem->set_custom_css( intval( $args['post_id'] ), $element_id, $css );
            }

            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'updated', 'target' => $element_id ?: 'page' );
        } );

        $this->register_tool( 'elementor_responsive_style', array(
            'description' => 'Set device-specific style overrides (mobile or tablet). CSS property names accepted. Example: set font-size to 14px on mobile only.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'    => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'element_id' => array( 'type' => 'string', 'description' => 'Element ID (required)' ),
                    'device'     => array( 'type' => 'string', 'description' => '"mobile" or "tablet" (required)', 'enum' => array( 'mobile', 'tablet' ) ),
                    'styles'     => array( 'type' => 'object', 'description' => 'CSS styles for that device. Example: {"font-size": "14px", "padding": "5px 10px"}' ),
                ),
                'required'   => array( 'post_id', 'element_id', 'device', 'styles' ),
            ),
            'annotations' => array(
                'title'           => 'Responsive Style',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->set_responsive_style(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['element_id'] ),
                sanitize_text_field( $args['device'] ),
                $args['styles']
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return array( 'status' => 'updated', 'element_id' => $args['element_id'], 'device' => $args['device'] );
        } );

        $this->register_tool( 'elementor_global_style', array(
            'description' => 'Apply a style to ALL widgets of a given type on a page. Example: make all headings blue, all buttons larger, etc. CSS property names accepted.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'     => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'widget_type' => array( 'type' => 'string', 'description' => 'Widget type: heading, button, text-editor, image, etc. (required)' ),
                    'styles'      => array( 'type' => 'object', 'description' => 'CSS styles to apply. Example: {"color": "#0000ff", "font-weight": "700"}' ),
                ),
                'required'   => array( 'post_id', 'widget_type', 'styles' ),
            ),
            'annotations' => array(
                'title'           => 'Global Widget Style',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->apply_global_style(
                intval( $args['post_id'] ),
                sanitize_text_field( $args['widget_type'] ),
                $args['styles']
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return $result;
        } );

        // ── Sync, Audit, Revision tools ──

        $this->register_tool( 'elementor_sync_styles', array(
            'description' => 'Copy styles from a source Elementor page to its translation pages (WPML auto-detected). Only syncs visual styles — text content is untouched. Creates a backup snapshot before syncing. Omit target_ids to auto-sync to all WPML translations.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'source_id'  => array( 'type' => 'integer', 'description' => 'Source page ID with the correct styles (required)' ),
                    'target_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Target page IDs to sync to (optional — auto-discovers WPML translations if omitted)' ),
                    'include'    => array( 'type' => 'string', 'description' => 'What to sync: "all", "colors", "typography", "spacing", or comma-separated combo (default: "all")' ),
                ),
                'required'   => array( 'source_id' ),
            ),
            'annotations' => array(
                'title'           => 'Sync Styles to Translations',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem   = LuwiPress_Elementor::get_instance();
            $result = $elem->sync_styles(
                intval( $args['source_id'] ),
                $args['target_ids'] ?? array(),
                array( 'include' => $args['include'] ?? 'all' )
            );
            if ( is_wp_error( $result ) ) {
                return array( 'error' => $result->get_error_message() );
            }
            return $result;
        } );

        $this->register_tool( 'elementor_audit_spacing', array(
            'description' => 'Audit an Elementor page for spacing/padding issues — detects excessive values, asymmetric padding, inconsistent patterns, and missing mobile overrides. Returns actionable issue list.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Page/post ID to audit (required)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'          => 'Audit Spacing',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            return $elem->audit_spacing( intval( $args['post_id'] ) );
        } );

        $this->register_tool( 'elementor_audit_responsive', array(
            'description' => 'Audit an Elementor page for responsive design issues — large fonts without mobile overrides, oversized padding, narrow columns, fixed line-heights. Returns issues with fix suggestions.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Page/post ID to audit (required)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'          => 'Audit Responsive',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            return $elem->audit_responsive( intval( $args['post_id'] ) );
        } );

        $this->register_tool( 'elementor_auto_fix', array(
            'description' => 'Auto-fix common spacing/responsive issues on an Elementor page. Creates a backup snapshot before fixing. Caps excessive padding/margin, adds mobile overrides for large values. Safe — always backs up first, and you can rollback.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Page/post ID to fix (required)' ),
                    'options' => array(
                        'type'       => 'object',
                        'description' => 'Fix options: fix_excessive (bool), fix_mobile (bool), max_padding (int, default 80), mobile_ratio (float, default 0.5)',
                    ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'           => 'Auto-Fix Spacing',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            return $elem->auto_fix_spacing( intval( $args['post_id'] ), $args['options'] ?? array() );
        } );

        $this->register_tool( 'elementor_snapshot', array(
            'description' => 'Create a snapshot/backup of an Elementor page before editing. Max 10 snapshots per page. Use this before any risky changes — you can rollback anytime.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'label'   => array( 'type' => 'string', 'description' => 'Snapshot label/description (optional)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'           => 'Create Snapshot',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => false,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            return $elem->create_snapshot( intval( $args['post_id'] ), sanitize_text_field( $args['label'] ?? '' ) );
        } );

        $this->register_tool( 'elementor_rollback', array(
            'description' => 'Rollback an Elementor page to a previous snapshot. Creates a backup of current state before rolling back. Omit snapshot_id to rollback to the most recent snapshot.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'     => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                    'snapshot_id' => array( 'type' => 'string', 'description' => 'Snapshot ID to restore (optional — omit for most recent)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'           => 'Rollback to Snapshot',
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            return $elem->rollback_snapshot( intval( $args['post_id'] ), sanitize_text_field( $args['snapshot_id'] ?? '' ) );
        } );

        $this->register_tool( 'elementor_snapshots', array(
            'description' => 'List all available snapshots for an Elementor page — shows IDs, labels, timestamps.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Page/post ID (required)' ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'annotations' => array(
                'title'          => 'List Snapshots',
                'readOnlyHint'   => true,
                'idempotentHint' => true,
                'openWorldHint'  => false,
            ),
        ), function ( $args ) {
            $elem = LuwiPress_Elementor::get_instance();
            return $elem->list_snapshots( intval( $args['post_id'] ) );
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
        do_action( 'wpml_switch_language', 'all' );
        if ( ! $post_id || ! get_post( $post_id ) ) {
            do_action( 'wpml_switch_language', apply_filters( 'wpml_default_language', 'en' ) );
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
        do_action( 'wpml_switch_language', apply_filters( 'wpml_default_language', 'en' ) );

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

    /* ───────────────────── Admin / User Tools ──────────────────────── */

    private function register_admin_tools() {

        $this->register_tool( 'admin_list_users', array(
            'description' => 'List WordPress users with filtering by role, search, and pagination',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'role'     => array( 'type' => 'string', 'description' => 'Filter by role (administrator, editor, author, subscriber, customer, shop_manager)' ),
                    'search'   => array( 'type' => 'string', 'description' => 'Search by name or email' ),
                    'per_page' => array( 'type' => 'integer', 'description' => 'Results per page (max 100, default 20)' ),
                    'page'     => array( 'type' => 'integer', 'description' => 'Page number (default 1)' ),
                ),
            ),
            'annotations' => array( 'title' => 'List Users', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $query_args = array(
                'number' => min( absint( $args['per_page'] ?? 20 ), 100 ),
                'paged'  => max( 1, absint( $args['page'] ?? 1 ) ),
            );
            if ( ! empty( $args['role'] ) ) {
                $query_args['role'] = sanitize_text_field( $args['role'] );
            }
            if ( ! empty( $args['search'] ) ) {
                $query_args['search'] = '*' . sanitize_text_field( $args['search'] ) . '*';
            }
            $query = new WP_User_Query( $query_args );
            $users = array();
            foreach ( $query->get_results() as $user ) {
                $users[] = array(
                    'id'           => $user->ID,
                    'username'     => $user->user_login,
                    'email'        => $user->user_email,
                    'display_name' => $user->display_name,
                    'roles'        => $user->roles,
                    'registered'   => $user->user_registered,
                );
            }
            return array( 'users' => $users, 'total' => $query->get_total(), 'page' => $query_args['paged'] );
        } );

        $this->register_tool( 'admin_get_user', array(
            'description' => 'Get a single user profile with full details',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'user_id' => array( 'type' => 'integer', 'description' => 'WordPress user ID (required)' ),
                ),
                'required' => array( 'user_id' ),
            ),
            'annotations' => array( 'title' => 'Get User', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $user = get_userdata( intval( $args['user_id'] ) );
            if ( ! $user ) {
                throw new Exception( 'User not found' );
            }
            $data = array(
                'id'           => $user->ID,
                'username'     => $user->user_login,
                'email'        => $user->user_email,
                'display_name' => $user->display_name,
                'first_name'   => $user->first_name,
                'last_name'    => $user->last_name,
                'roles'        => $user->roles,
                'registered'   => $user->user_registered,
                'url'          => $user->user_url,
            );
            if ( function_exists( 'wc_get_customer_order_count' ) ) {
                $data['order_count'] = wc_get_customer_order_count( $user->ID );
                $data['total_spent'] = wc_get_customer_total_spent( $user->ID );
            }
            return $data;
        } );

        $this->register_tool( 'admin_create_user', array(
            'description' => 'Create a new WordPress user with role assignment',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'username'     => array( 'type' => 'string', 'description' => 'Username (required)' ),
                    'email'        => array( 'type' => 'string', 'description' => 'Email address (required)' ),
                    'password'     => array( 'type' => 'string', 'description' => 'Password (auto-generated if omitted)' ),
                    'display_name' => array( 'type' => 'string', 'description' => 'Display name' ),
                    'first_name'   => array( 'type' => 'string', 'description' => 'First name' ),
                    'last_name'    => array( 'type' => 'string', 'description' => 'Last name' ),
                    'role'         => array( 'type' => 'string', 'description' => 'Role (default: subscriber)' ),
                ),
                'required' => array( 'username', 'email' ),
            ),
            'annotations' => array( 'title' => 'Create User', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $user_data = array(
                'user_login'   => sanitize_user( $args['username'] ),
                'user_email'   => sanitize_email( $args['email'] ),
                'user_pass'    => $args['password'] ?? wp_generate_password( 16 ),
                'display_name' => sanitize_text_field( $args['display_name'] ?? $args['username'] ),
                'first_name'   => sanitize_text_field( $args['first_name'] ?? '' ),
                'last_name'    => sanitize_text_field( $args['last_name'] ?? '' ),
                'role'         => sanitize_text_field( $args['role'] ?? 'subscriber' ),
            );
            $user_id = wp_insert_user( $user_data );
            if ( is_wp_error( $user_id ) ) {
                throw new Exception( $user_id->get_error_message() );
            }
            return array( 'id' => $user_id, 'username' => $user_data['user_login'], 'email' => $user_data['user_email'], 'role' => $user_data['role'] );
        } );

        $this->register_tool( 'admin_update_user', array(
            'description' => 'Update user profile fields (display name, email, role, meta)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'user_id'      => array( 'type' => 'integer', 'description' => 'User ID (required)' ),
                    'email'        => array( 'type' => 'string', 'description' => 'New email' ),
                    'display_name' => array( 'type' => 'string', 'description' => 'New display name' ),
                    'first_name'   => array( 'type' => 'string', 'description' => 'New first name' ),
                    'last_name'    => array( 'type' => 'string', 'description' => 'New last name' ),
                    'role'         => array( 'type' => 'string', 'description' => 'New role' ),
                ),
                'required' => array( 'user_id' ),
            ),
            'annotations' => array( 'title' => 'Update User', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $user_id = intval( $args['user_id'] );
            if ( ! get_userdata( $user_id ) ) {
                throw new Exception( 'User not found' );
            }
            $user_data = array( 'ID' => $user_id );
            if ( isset( $args['email'] ) ) $user_data['user_email'] = sanitize_email( $args['email'] );
            if ( isset( $args['display_name'] ) ) $user_data['display_name'] = sanitize_text_field( $args['display_name'] );
            if ( isset( $args['first_name'] ) ) $user_data['first_name'] = sanitize_text_field( $args['first_name'] );
            if ( isset( $args['last_name'] ) ) $user_data['last_name'] = sanitize_text_field( $args['last_name'] );
            if ( isset( $args['role'] ) ) $user_data['role'] = sanitize_text_field( $args['role'] );
            $result = wp_update_user( $user_data );
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            return array( 'id' => $user_id, 'updated' => true );
        } );

        $this->register_tool( 'admin_delete_user', array(
            'description' => 'Delete a WordPress user with optional content reassignment',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'user_id'     => array( 'type' => 'integer', 'description' => 'User ID to delete (required)' ),
                    'reassign_to' => array( 'type' => 'integer', 'description' => 'Reassign content to this user ID (recommended)' ),
                ),
                'required' => array( 'user_id' ),
            ),
            'annotations' => array( 'title' => 'Delete User', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $user_id = intval( $args['user_id'] );
            if ( ! get_userdata( $user_id ) ) {
                throw new Exception( 'User not found' );
            }
            $reassign = isset( $args['reassign_to'] ) ? intval( $args['reassign_to'] ) : null;
            $result = wp_delete_user( $user_id, $reassign );
            if ( ! $result ) {
                throw new Exception( 'Failed to delete user' );
            }
            return array( 'id' => $user_id, 'deleted' => true, 'reassigned_to' => $reassign );
        } );

        $this->register_tool( 'admin_list_roles', array(
            'description' => 'List all registered WordPress roles with their capabilities',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'List Roles', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $wp_roles = wp_roles();
            $roles = array();
            foreach ( $wp_roles->roles as $slug => $role ) {
                $count = count_users();
                $roles[] = array(
                    'slug'         => $slug,
                    'name'         => $role['name'],
                    'capabilities' => array_keys( array_filter( $role['capabilities'] ) ),
                    'user_count'   => $count['avail_roles'][ $slug ] ?? 0,
                );
            }
            return array( 'roles' => $roles );
        } );
    }

    /* ───────────────────── Taxonomy Tools ──────────────────────────── */

    private function register_taxonomy_tools() {

        $this->register_tool( 'taxonomy_list_taxonomies', array(
            'description' => 'List all registered taxonomies (built-in and custom) with their configuration',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'List Taxonomies', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
            $list = array();
            foreach ( $taxonomies as $tax ) {
                $count = wp_count_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false ) );
                $list[] = array(
                    'name'         => $tax->name,
                    'label'        => $tax->label,
                    'hierarchical' => $tax->hierarchical,
                    'post_types'   => (array) $tax->object_type,
                    'term_count'   => is_wp_error( $count ) ? 0 : absint( $count ),
                );
            }
            return array( 'taxonomies' => $list );
        } );

        $this->register_tool( 'taxonomy_list_terms', array(
            'description' => 'List terms for any taxonomy (category, post_tag, product_cat, product_tag, pa_*) with hierarchy',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'taxonomy'  => array( 'type' => 'string', 'description' => 'Taxonomy slug (required, e.g. category, product_cat, pa_color)' ),
                    'parent'    => array( 'type' => 'integer', 'description' => 'Filter by parent term ID (0 for top-level)' ),
                    'search'    => array( 'type' => 'string', 'description' => 'Search term name' ),
                    'per_page'  => array( 'type' => 'integer', 'description' => 'Results per page (max 200, default 50)' ),
                    'hide_empty' => array( 'type' => 'boolean', 'description' => 'Hide terms with no posts (default false)' ),
                ),
                'required' => array( 'taxonomy' ),
            ),
            'annotations' => array( 'title' => 'List Terms', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $taxonomy = sanitize_text_field( $args['taxonomy'] );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                throw new Exception( "Taxonomy '{$taxonomy}' does not exist" );
            }
            $query = array(
                'taxonomy'   => $taxonomy,
                'number'     => min( absint( $args['per_page'] ?? 50 ), 200 ),
                'hide_empty' => ! empty( $args['hide_empty'] ),
            );
            if ( isset( $args['parent'] ) ) {
                $query['parent'] = absint( $args['parent'] );
            }
            if ( ! empty( $args['search'] ) ) {
                $query['search'] = sanitize_text_field( $args['search'] );
            }
            $terms = get_terms( $query );
            if ( is_wp_error( $terms ) ) {
                throw new Exception( $terms->get_error_message() );
            }
            $list = array();
            foreach ( $terms as $term ) {
                $list[] = array(
                    'id'       => $term->term_id,
                    'name'     => $term->name,
                    'slug'     => $term->slug,
                    'parent'   => $term->parent,
                    'count'    => $term->count,
                    'description' => $term->description,
                );
            }
            return array( 'taxonomy' => $taxonomy, 'terms' => $list, 'total' => count( $list ) );
        } );

        $this->register_tool( 'taxonomy_create_term', array(
            'description' => 'Create a new term in any taxonomy with parent, description, and slug',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'taxonomy'    => array( 'type' => 'string', 'description' => 'Taxonomy slug (required)' ),
                    'name'        => array( 'type' => 'string', 'description' => 'Term name (required)' ),
                    'slug'        => array( 'type' => 'string', 'description' => 'Term slug (auto-generated if omitted)' ),
                    'parent'      => array( 'type' => 'integer', 'description' => 'Parent term ID (for hierarchical taxonomies)' ),
                    'description' => array( 'type' => 'string', 'description' => 'Term description' ),
                ),
                'required' => array( 'taxonomy', 'name' ),
            ),
            'annotations' => array( 'title' => 'Create Term', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $taxonomy = sanitize_text_field( $args['taxonomy'] );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                throw new Exception( "Taxonomy '{$taxonomy}' does not exist" );
            }
            $term_args = array();
            if ( isset( $args['slug'] ) ) $term_args['slug'] = sanitize_title( $args['slug'] );
            if ( isset( $args['parent'] ) ) $term_args['parent'] = absint( $args['parent'] );
            if ( isset( $args['description'] ) ) $term_args['description'] = sanitize_textarea_field( $args['description'] );

            $result = wp_insert_term( sanitize_text_field( $args['name'] ), $taxonomy, $term_args );
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            return array( 'term_id' => $result['term_id'], 'term_taxonomy_id' => $result['term_taxonomy_id'], 'taxonomy' => $taxonomy, 'name' => $args['name'] );
        } );

        $this->register_tool( 'taxonomy_update_term', array(
            'description' => 'Update an existing term name, slug, description, or parent',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'term_id'     => array( 'type' => 'integer', 'description' => 'Term ID (required)' ),
                    'taxonomy'    => array( 'type' => 'string', 'description' => 'Taxonomy slug (required)' ),
                    'name'        => array( 'type' => 'string', 'description' => 'New name' ),
                    'slug'        => array( 'type' => 'string', 'description' => 'New slug' ),
                    'parent'      => array( 'type' => 'integer', 'description' => 'New parent term ID' ),
                    'description' => array( 'type' => 'string', 'description' => 'New description' ),
                ),
                'required' => array( 'term_id', 'taxonomy' ),
            ),
            'annotations' => array( 'title' => 'Update Term', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $term_id  = absint( $args['term_id'] );
            $taxonomy = sanitize_text_field( $args['taxonomy'] );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                throw new Exception( "Taxonomy '{$taxonomy}' does not exist" );
            }
            $term_args = array();
            if ( isset( $args['name'] ) ) $term_args['name'] = sanitize_text_field( $args['name'] );
            if ( isset( $args['slug'] ) ) $term_args['slug'] = sanitize_title( $args['slug'] );
            if ( isset( $args['parent'] ) ) $term_args['parent'] = absint( $args['parent'] );
            if ( isset( $args['description'] ) ) $term_args['description'] = sanitize_textarea_field( $args['description'] );

            $result = wp_update_term( $term_id, $taxonomy, $term_args );
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            return array( 'term_id' => $term_id, 'taxonomy' => $taxonomy, 'updated' => true );
        } );

        $this->register_tool( 'taxonomy_delete_term', array(
            'description' => 'Delete a term from any taxonomy',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'term_id'  => array( 'type' => 'integer', 'description' => 'Term ID to delete (required)' ),
                    'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy slug (required)' ),
                ),
                'required' => array( 'term_id', 'taxonomy' ),
            ),
            'annotations' => array( 'title' => 'Delete Term', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $term_id  = absint( $args['term_id'] );
            $taxonomy = sanitize_text_field( $args['taxonomy'] );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                throw new Exception( "Taxonomy '{$taxonomy}' does not exist" );
            }
            $result = wp_delete_term( $term_id, $taxonomy );
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            if ( $result === false ) {
                throw new Exception( 'Term not found or is default term' );
            }
            return array( 'term_id' => $term_id, 'taxonomy' => $taxonomy, 'deleted' => true );
        } );
    }

    /* ───────────────────── WooCommerce Tools ───────────────────────── */

    private function register_woo_tools() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $this->register_tool( 'woo_list_orders', array(
            'description' => 'List WooCommerce orders with filtering by status, date, customer',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'status'      => array( 'type' => 'string', 'description' => 'Order status: processing, completed, on-hold, cancelled, refunded, pending, failed, any (default: any)' ),
                    'customer_id' => array( 'type' => 'integer', 'description' => 'Filter by customer user ID' ),
                    'per_page'    => array( 'type' => 'integer', 'description' => 'Results per page (max 50, default 20)' ),
                    'page'        => array( 'type' => 'integer', 'description' => 'Page number' ),
                    'date_after'  => array( 'type' => 'string', 'description' => 'Orders after this date (YYYY-MM-DD)' ),
                    'date_before' => array( 'type' => 'string', 'description' => 'Orders before this date (YYYY-MM-DD)' ),
                ),
            ),
            'annotations' => array( 'title' => 'List Orders', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $query = array(
                'limit'  => min( absint( $args['per_page'] ?? 20 ), 50 ),
                'page'   => max( 1, absint( $args['page'] ?? 1 ) ),
                'return' => 'objects',
            );
            $status = $args['status'] ?? 'any';
            if ( $status !== 'any' ) {
                $query['status'] = sanitize_text_field( $status );
            }
            if ( ! empty( $args['customer_id'] ) ) {
                $query['customer_id'] = absint( $args['customer_id'] );
            }
            if ( ! empty( $args['date_after'] ) ) {
                $query['date_created'] = '>' . sanitize_text_field( $args['date_after'] );
            }
            $orders = wc_get_orders( $query );
            $list = array();
            foreach ( $orders as $order ) {
                $list[] = array(
                    'id'              => $order->get_id(),
                    'number'          => $order->get_order_number(),
                    'status'          => $order->get_status(),
                    'total'           => $order->get_total(),
                    'currency'        => $order->get_currency(),
                    'customer_id'     => $order->get_customer_id(),
                    'billing_email'   => $order->get_billing_email(),
                    'billing_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'item_count'      => $order->get_item_count(),
                    'payment_method'  => $order->get_payment_method_title(),
                    'date_created'    => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
                );
            }
            return array( 'orders' => $list, 'count' => count( $list ) );
        } );

        $this->register_tool( 'woo_get_order', array(
            'description' => 'Get full order details: items, billing, shipping, notes, totals',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'order_id' => array( 'type' => 'integer', 'description' => 'Order ID (required)' ),
                ),
                'required' => array( 'order_id' ),
            ),
            'annotations' => array( 'title' => 'Get Order', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $order = wc_get_order( intval( $args['order_id'] ) );
            if ( ! $order ) {
                throw new Exception( 'Order not found' );
            }
            $items = array();
            foreach ( $order->get_items() as $item ) {
                $items[] = array(
                    'name'       => $item->get_name(),
                    'product_id' => $item->get_product_id(),
                    'quantity'   => $item->get_quantity(),
                    'subtotal'   => $item->get_subtotal(),
                    'total'      => $item->get_total(),
                    'sku'        => $item->get_product() ? $item->get_product()->get_sku() : '',
                );
            }
            $notes = wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 10 ) );
            $note_list = array();
            foreach ( $notes as $note ) {
                $note_list[] = array( 'content' => $note->content, 'date' => $note->date_created->format( 'Y-m-d H:i:s' ), 'author' => $note->added_by );
            }
            return array(
                'id'              => $order->get_id(),
                'number'          => $order->get_order_number(),
                'status'          => $order->get_status(),
                'total'           => $order->get_total(),
                'subtotal'        => $order->get_subtotal(),
                'tax_total'       => $order->get_total_tax(),
                'shipping_total'  => $order->get_shipping_total(),
                'discount_total'  => $order->get_discount_total(),
                'currency'        => $order->get_currency(),
                'payment_method'  => $order->get_payment_method_title(),
                'customer_id'     => $order->get_customer_id(),
                'billing'         => array(
                    'first_name' => $order->get_billing_first_name(),
                    'last_name'  => $order->get_billing_last_name(),
                    'email'      => $order->get_billing_email(),
                    'phone'      => $order->get_billing_phone(),
                    'address_1'  => $order->get_billing_address_1(),
                    'city'       => $order->get_billing_city(),
                    'country'    => $order->get_billing_country(),
                ),
                'shipping'        => array(
                    'first_name' => $order->get_shipping_first_name(),
                    'last_name'  => $order->get_shipping_last_name(),
                    'address_1'  => $order->get_shipping_address_1(),
                    'city'       => $order->get_shipping_city(),
                    'country'    => $order->get_shipping_country(),
                ),
                'items'           => $items,
                'notes'           => $note_list,
                'date_created'    => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
                'date_modified'   => $order->get_date_modified() ? $order->get_date_modified()->format( 'Y-m-d H:i:s' ) : null,
            );
        } );

        $this->register_tool( 'woo_update_order_status', array(
            'description' => 'Change order status (processing, completed, on-hold, cancelled)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'order_id' => array( 'type' => 'integer', 'description' => 'Order ID (required)' ),
                    'status'   => array( 'type' => 'string', 'description' => 'New status: processing, completed, on-hold, cancelled, refunded (required)' ),
                    'note'     => array( 'type' => 'string', 'description' => 'Optional order note explaining the change' ),
                ),
                'required' => array( 'order_id', 'status' ),
            ),
            'annotations' => array( 'title' => 'Update Order Status', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $order = wc_get_order( intval( $args['order_id'] ) );
            if ( ! $order ) {
                throw new Exception( 'Order not found' );
            }
            $old_status = $order->get_status();
            $new_status = sanitize_text_field( $args['status'] );
            $note       = sanitize_text_field( $args['note'] ?? 'Status updated via LuwiPress MCP' );
            $order->update_status( $new_status, $note );
            return array( 'order_id' => $order->get_id(), 'old_status' => $old_status, 'new_status' => $new_status );
        } );

        $this->register_tool( 'woo_list_coupons', array(
            'description' => 'List WooCommerce coupons with filtering',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'search'   => array( 'type' => 'string', 'description' => 'Search coupon code' ),
                    'per_page' => array( 'type' => 'integer', 'description' => 'Results per page (max 50, default 20)' ),
                ),
            ),
            'annotations' => array( 'title' => 'List Coupons', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $query = new WP_Query( array(
                'post_type'      => 'shop_coupon',
                'posts_per_page' => min( absint( $args['per_page'] ?? 20 ), 50 ),
                'post_status'    => 'publish',
                's'              => sanitize_text_field( $args['search'] ?? '' ),
            ) );
            $coupons = array();
            foreach ( $query->posts as $post ) {
                $coupon = new WC_Coupon( $post->ID );
                $coupons[] = array(
                    'id'               => $coupon->get_id(),
                    'code'             => $coupon->get_code(),
                    'discount_type'    => $coupon->get_discount_type(),
                    'amount'           => $coupon->get_amount(),
                    'usage_count'      => $coupon->get_usage_count(),
                    'usage_limit'      => $coupon->get_usage_limit(),
                    'expiry_date'      => $coupon->get_date_expires() ? $coupon->get_date_expires()->format( 'Y-m-d' ) : null,
                    'minimum_amount'   => $coupon->get_minimum_amount(),
                    'free_shipping'    => $coupon->get_free_shipping(),
                );
            }
            return array( 'coupons' => $coupons, 'total' => $query->found_posts );
        } );

        $this->register_tool( 'woo_create_coupon', array(
            'description' => 'Create a WooCommerce coupon (percentage, fixed, free shipping)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'code'           => array( 'type' => 'string', 'description' => 'Coupon code (required)' ),
                    'discount_type'  => array( 'type' => 'string', 'enum' => array( 'percent', 'fixed_cart', 'fixed_product' ), 'description' => 'Discount type (default: percent)' ),
                    'amount'         => array( 'type' => 'number', 'description' => 'Discount amount (required)' ),
                    'usage_limit'    => array( 'type' => 'integer', 'description' => 'Total usage limit' ),
                    'expiry_date'    => array( 'type' => 'string', 'description' => 'Expiry date YYYY-MM-DD' ),
                    'minimum_amount' => array( 'type' => 'number', 'description' => 'Minimum order amount' ),
                    'free_shipping'  => array( 'type' => 'boolean', 'description' => 'Enable free shipping' ),
                    'individual_use' => array( 'type' => 'boolean', 'description' => 'Cannot be combined with other coupons' ),
                ),
                'required' => array( 'code', 'amount' ),
            ),
            'annotations' => array( 'title' => 'Create Coupon', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $coupon = new WC_Coupon();
            $coupon->set_code( sanitize_text_field( $args['code'] ) );
            $coupon->set_discount_type( sanitize_text_field( $args['discount_type'] ?? 'percent' ) );
            $coupon->set_amount( floatval( $args['amount'] ) );
            if ( isset( $args['usage_limit'] ) ) $coupon->set_usage_limit( absint( $args['usage_limit'] ) );
            if ( isset( $args['expiry_date'] ) ) $coupon->set_date_expires( sanitize_text_field( $args['expiry_date'] ) );
            if ( isset( $args['minimum_amount'] ) ) $coupon->set_minimum_amount( floatval( $args['minimum_amount'] ) );
            if ( isset( $args['free_shipping'] ) ) $coupon->set_free_shipping( (bool) $args['free_shipping'] );
            if ( isset( $args['individual_use'] ) ) $coupon->set_individual_use( (bool) $args['individual_use'] );
            $coupon->save();
            return array( 'id' => $coupon->get_id(), 'code' => $coupon->get_code(), 'discount_type' => $coupon->get_discount_type(), 'amount' => $coupon->get_amount() );
        } );

        $this->register_tool( 'woo_get_shipping_zones', array(
            'description' => 'List shipping zones with their methods and rates',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Shipping Zones', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $zones_raw = WC_Shipping_Zones::get_zones();
            $zones = array();
            foreach ( $zones_raw as $zone_data ) {
                $zone = new WC_Shipping_Zone( $zone_data['id'] );
                $methods = array();
                foreach ( $zone->get_shipping_methods() as $method ) {
                    $methods[] = array(
                        'id'      => $method->id,
                        'title'   => $method->get_title(),
                        'enabled' => $method->is_enabled(),
                        'cost'    => $method->get_option( 'cost', '' ),
                    );
                }
                $zones[] = array(
                    'id'        => $zone->get_id(),
                    'name'      => $zone->get_zone_name(),
                    'locations' => count( $zone_data['zone_locations'] ?? array() ),
                    'methods'   => $methods,
                );
            }
            return array( 'zones' => $zones );
        } );

        $this->register_tool( 'woo_get_tax_rates', array(
            'description' => 'List tax rates by class',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'class' => array( 'type' => 'string', 'description' => 'Tax class slug (default: standard)' ),
                ),
            ),
            'annotations' => array( 'title' => 'Tax Rates', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            global $wpdb;
            $class = sanitize_text_field( $args['class'] ?? '' );
            $rates = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = %s ORDER BY tax_rate_order",
                $class
            ) );
            $list = array();
            foreach ( $rates as $rate ) {
                $list[] = array(
                    'id'       => absint( $rate->tax_rate_id ),
                    'country'  => $rate->tax_rate_country,
                    'state'    => $rate->tax_rate_state,
                    'rate'     => $rate->tax_rate,
                    'name'     => $rate->tax_rate_name,
                    'priority' => $rate->tax_rate_priority,
                    'shipping' => $rate->tax_rate_shipping,
                );
            }
            return array( 'tax_class' => $class ?: 'standard', 'rates' => $list );
        } );
    }

    /* ───────────────────── Media Library Tools ─────────────────────── */

    private function register_media_tools() {

        $this->register_tool( 'media_list', array(
            'description' => 'List media items with filtering by type, date, search',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'mime_type' => array( 'type' => 'string', 'description' => 'Filter by MIME type (image, video, application/pdf)' ),
                    'search'    => array( 'type' => 'string', 'description' => 'Search by title' ),
                    'per_page'  => array( 'type' => 'integer', 'description' => 'Results per page (max 50, default 20)' ),
                    'page'      => array( 'type' => 'integer', 'description' => 'Page number' ),
                ),
            ),
            'annotations' => array( 'title' => 'List Media', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $query_args = array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => min( absint( $args['per_page'] ?? 20 ), 50 ),
                'paged'          => max( 1, absint( $args['page'] ?? 1 ) ),
            );
            if ( ! empty( $args['mime_type'] ) ) {
                $query_args['post_mime_type'] = sanitize_text_field( $args['mime_type'] );
            }
            if ( ! empty( $args['search'] ) ) {
                $query_args['s'] = sanitize_text_field( $args['search'] );
            }
            $query = new WP_Query( $query_args );
            $items = array();
            foreach ( $query->posts as $post ) {
                $meta = wp_get_attachment_metadata( $post->ID );
                $items[] = array(
                    'id'         => $post->ID,
                    'title'      => $post->post_title,
                    'url'        => wp_get_attachment_url( $post->ID ),
                    'mime_type'  => $post->post_mime_type,
                    'alt_text'   => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
                    'width'      => $meta['width'] ?? null,
                    'height'     => $meta['height'] ?? null,
                    'filesize'   => $meta['filesize'] ?? null,
                    'date'       => $post->post_date,
                );
            }
            return array( 'items' => $items, 'total' => $query->found_posts, 'page' => $query_args['paged'] );
        } );

        $this->register_tool( 'media_get', array(
            'description' => 'Get media item details (URL, dimensions, alt text, file size, usage)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'attachment_id' => array( 'type' => 'integer', 'description' => 'Attachment ID (required)' ),
                ),
                'required' => array( 'attachment_id' ),
            ),
            'annotations' => array( 'title' => 'Get Media', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $id   = intval( $args['attachment_id'] );
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== 'attachment' ) {
                throw new Exception( 'Attachment not found' );
            }
            $meta = wp_get_attachment_metadata( $id );
            $sizes = array();
            if ( ! empty( $meta['sizes'] ) ) {
                foreach ( $meta['sizes'] as $size => $data ) {
                    $sizes[ $size ] = array( 'width' => $data['width'], 'height' => $data['height'], 'file' => $data['file'] );
                }
            }
            return array(
                'id'        => $id,
                'title'     => $post->post_title,
                'caption'   => $post->post_excerpt,
                'description' => $post->post_content,
                'alt_text'  => get_post_meta( $id, '_wp_attachment_image_alt', true ),
                'url'       => wp_get_attachment_url( $id ),
                'mime_type' => $post->post_mime_type,
                'width'     => $meta['width'] ?? null,
                'height'    => $meta['height'] ?? null,
                'filesize'  => $meta['filesize'] ?? null,
                'file'      => $meta['file'] ?? null,
                'sizes'     => $sizes,
                'parent_id' => $post->post_parent,
                'date'      => $post->post_date,
            );
        } );

        $this->register_tool( 'media_upload_from_url', array(
            'description' => 'Download and import a media file from an external URL into the WordPress media library',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'url'      => array( 'type' => 'string', 'description' => 'External file URL (required)' ),
                    'title'    => array( 'type' => 'string', 'description' => 'Attachment title' ),
                    'alt_text' => array( 'type' => 'string', 'description' => 'Image alt text' ),
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Attach to this post ID' ),
                ),
                'required' => array( 'url' ),
            ),
            'annotations' => array( 'title' => 'Upload from URL', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true ),
        ), function ( $args ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $url     = esc_url_raw( $args['url'] );
            $post_id = absint( $args['post_id'] ?? 0 );

            $tmp = download_url( $url, 30 );
            if ( is_wp_error( $tmp ) ) {
                throw new Exception( 'Download failed: ' . $tmp->get_error_message() );
            }
            $file_array = array(
                'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
                'tmp_name' => $tmp,
            );
            $attachment_id = media_handle_sideload( $file_array, $post_id );
            if ( is_wp_error( $attachment_id ) ) {
                @unlink( $tmp );
                throw new Exception( 'Upload failed: ' . $attachment_id->get_error_message() );
            }
            if ( ! empty( $args['title'] ) ) {
                wp_update_post( array( 'ID' => $attachment_id, 'post_title' => sanitize_text_field( $args['title'] ) ) );
            }
            if ( ! empty( $args['alt_text'] ) ) {
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt_text'] ) );
            }
            return array( 'id' => $attachment_id, 'url' => wp_get_attachment_url( $attachment_id ) );
        } );

        $this->register_tool( 'media_update', array(
            'description' => 'Update media alt text, title, caption, or description',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'attachment_id' => array( 'type' => 'integer', 'description' => 'Attachment ID (required)' ),
                    'title'         => array( 'type' => 'string', 'description' => 'New title' ),
                    'alt_text'      => array( 'type' => 'string', 'description' => 'New alt text' ),
                    'caption'       => array( 'type' => 'string', 'description' => 'New caption' ),
                    'description'   => array( 'type' => 'string', 'description' => 'New description' ),
                ),
                'required' => array( 'attachment_id' ),
            ),
            'annotations' => array( 'title' => 'Update Media', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $id = intval( $args['attachment_id'] );
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== 'attachment' ) {
                throw new Exception( 'Attachment not found' );
            }
            $update = array( 'ID' => $id );
            if ( isset( $args['title'] ) ) $update['post_title'] = sanitize_text_field( $args['title'] );
            if ( isset( $args['caption'] ) ) $update['post_excerpt'] = sanitize_text_field( $args['caption'] );
            if ( isset( $args['description'] ) ) $update['post_content'] = wp_kses_post( $args['description'] );
            wp_update_post( $update );
            if ( isset( $args['alt_text'] ) ) {
                update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt_text'] ) );
            }
            return array( 'id' => $id, 'updated' => true );
        } );

        $this->register_tool( 'media_delete', array(
            'description' => 'Delete a media item (with option to force-delete the file)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'attachment_id' => array( 'type' => 'integer', 'description' => 'Attachment ID (required)' ),
                    'force'         => array( 'type' => 'boolean', 'description' => 'Permanently delete file (default: true)' ),
                ),
                'required' => array( 'attachment_id' ),
            ),
            'annotations' => array( 'title' => 'Delete Media', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $id = intval( $args['attachment_id'] );
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== 'attachment' ) {
                throw new Exception( 'Attachment not found' );
            }
            $force = $args['force'] ?? true;
            $result = wp_delete_attachment( $id, $force );
            if ( ! $result ) {
                throw new Exception( 'Failed to delete attachment' );
            }
            return array( 'id' => $id, 'deleted' => true );
        } );
    }

    /* ───────────────────── Comment Tools ───────────────────────────── */

    private function register_comment_tools() {

        $this->register_tool( 'comment_list', array(
            'description' => 'List comments with filtering by post, status, type',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id'  => array( 'type' => 'integer', 'description' => 'Filter by post ID' ),
                    'status'   => array( 'type' => 'string', 'enum' => array( 'approve', 'hold', 'spam', 'trash', 'all' ), 'description' => 'Comment status (default: all)' ),
                    'per_page' => array( 'type' => 'integer', 'description' => 'Results per page (max 100, default 20)' ),
                ),
            ),
            'annotations' => array( 'title' => 'List Comments', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $query = array(
                'number' => min( absint( $args['per_page'] ?? 20 ), 100 ),
                'status' => sanitize_text_field( $args['status'] ?? 'all' ),
            );
            if ( ! empty( $args['post_id'] ) ) {
                $query['post_id'] = absint( $args['post_id'] );
            }
            $comments = get_comments( $query );
            $list = array();
            foreach ( $comments as $c ) {
                $list[] = array(
                    'id'           => $c->comment_ID,
                    'post_id'      => $c->comment_post_ID,
                    'author'       => $c->comment_author,
                    'author_email' => $c->comment_author_email,
                    'content'      => wp_strip_all_tags( $c->comment_content ),
                    'status'       => $c->comment_approved,
                    'date'         => $c->comment_date,
                    'parent'       => $c->comment_parent,
                    'type'         => $c->comment_type ?: 'comment',
                );
            }
            return array( 'comments' => $list, 'count' => count( $list ) );
        } );

        $this->register_tool( 'comment_moderate', array(
            'description' => 'Approve, unapprove, spam, or trash a comment',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'comment_id' => array( 'type' => 'integer', 'description' => 'Comment ID (required)' ),
                    'status'     => array( 'type' => 'string', 'enum' => array( 'approve', 'hold', 'spam', 'trash' ), 'description' => 'New status (required)' ),
                ),
                'required' => array( 'comment_id', 'status' ),
            ),
            'annotations' => array( 'title' => 'Moderate Comment', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $comment_id = absint( $args['comment_id'] );
            if ( ! get_comment( $comment_id ) ) {
                throw new Exception( 'Comment not found' );
            }
            $result = wp_set_comment_status( $comment_id, sanitize_text_field( $args['status'] ) );
            if ( ! $result ) {
                throw new Exception( 'Failed to update comment status' );
            }
            return array( 'comment_id' => $comment_id, 'status' => $args['status'] );
        } );

        $this->register_tool( 'comment_reply', array(
            'description' => 'Reply to a comment (creates a child comment from admin)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'comment_id' => array( 'type' => 'integer', 'description' => 'Parent comment ID (required)' ),
                    'content'    => array( 'type' => 'string', 'description' => 'Reply content (required)' ),
                ),
                'required' => array( 'comment_id', 'content' ),
            ),
            'annotations' => array( 'title' => 'Reply to Comment', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $parent = get_comment( absint( $args['comment_id'] ) );
            if ( ! $parent ) {
                throw new Exception( 'Parent comment not found' );
            }
            $current_user = wp_get_current_user();
            $reply_id = wp_insert_comment( array(
                'comment_post_ID'  => $parent->comment_post_ID,
                'comment_parent'   => $parent->comment_ID,
                'comment_content'  => wp_kses_post( $args['content'] ),
                'comment_author'   => $current_user->display_name ?: 'Admin',
                'comment_author_email' => $current_user->user_email ?: get_option( 'admin_email' ),
                'user_id'          => $current_user->ID ?: 0,
                'comment_approved' => 1,
            ) );
            if ( ! $reply_id ) {
                throw new Exception( 'Failed to create reply' );
            }
            return array( 'reply_id' => $reply_id, 'parent_id' => $parent->comment_ID, 'post_id' => $parent->comment_post_ID );
        } );

        $this->register_tool( 'comment_delete', array(
            'description' => 'Permanently delete a comment',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'comment_id' => array( 'type' => 'integer', 'description' => 'Comment ID (required)' ),
                ),
                'required' => array( 'comment_id' ),
            ),
            'annotations' => array( 'title' => 'Delete Comment', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $comment_id = absint( $args['comment_id'] );
            if ( ! get_comment( $comment_id ) ) {
                throw new Exception( 'Comment not found' );
            }
            $result = wp_delete_comment( $comment_id, true );
            if ( ! $result ) {
                throw new Exception( 'Failed to delete comment' );
            }
            return array( 'comment_id' => $comment_id, 'deleted' => true );
        } );
    }

    /* ───────────────────── Settings Tools ──────────────────────────── */

    private function register_settings_tools() {

        // Whitelist of safe option keys
        $safe_wp_keys = array(
            'blogname', 'blogdescription', 'timezone_string', 'date_format', 'time_format',
            'posts_per_page', 'permalink_structure', 'default_comment_status',
            'default_ping_status', 'show_on_front', 'page_on_front', 'page_for_posts',
            'blog_public', 'WPLANG',
        );

        $this->register_tool( 'settings_get', array(
            'description' => 'Read WordPress settings (general, reading, writing, discussion, permalinks)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'keys' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Specific option keys to read (omit for all safe settings)' ),
                ),
            ),
            'annotations' => array( 'title' => 'Get Settings', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) use ( $safe_wp_keys ) {
            $keys = ! empty( $args['keys'] ) ? array_intersect( $args['keys'], $safe_wp_keys ) : $safe_wp_keys;
            $settings = array();
            foreach ( $keys as $key ) {
                $settings[ $key ] = get_option( $key );
            }
            return array( 'settings' => $settings );
        } );

        $this->register_tool( 'settings_update', array(
            'description' => 'Update a whitelisted WordPress setting (cannot change siteurl, home, or admin_email for security)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'key'   => array( 'type' => 'string', 'description' => 'Option key (required)' ),
                    'value' => array( 'type' => 'string', 'description' => 'New value (required)' ),
                ),
                'required' => array( 'key', 'value' ),
            ),
            'annotations' => array( 'title' => 'Update Setting', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) use ( $safe_wp_keys ) {
            $key = sanitize_text_field( $args['key'] );
            if ( ! in_array( $key, $safe_wp_keys, true ) ) {
                throw new Exception( "Setting '{$key}' is not in the allowed whitelist" );
            }
            $old = get_option( $key );
            update_option( $key, sanitize_text_field( $args['value'] ) );
            return array( 'key' => $key, 'old_value' => $old, 'new_value' => $args['value'], 'updated' => true );
        } );

        $this->register_tool( 'settings_get_woo', array(
            'description' => 'Read WooCommerce settings (store address, currency, tax, shipping defaults)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Get WooCommerce Settings', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! class_exists( 'WooCommerce' ) ) {
                return array( 'error' => 'WooCommerce not active' );
            }
            return array(
                'currency'          => get_woocommerce_currency(),
                'currency_symbol'   => get_woocommerce_currency_symbol(),
                'store_address'     => get_option( 'woocommerce_store_address' ),
                'store_city'        => get_option( 'woocommerce_store_city' ),
                'store_country'     => get_option( 'woocommerce_default_country' ),
                'store_postcode'    => get_option( 'woocommerce_store_postcode' ),
                'calc_taxes'        => get_option( 'woocommerce_calc_taxes' ),
                'prices_include_tax' => get_option( 'woocommerce_prices_include_tax' ),
                'tax_display_shop'  => get_option( 'woocommerce_tax_display_shop' ),
                'weight_unit'       => get_option( 'woocommerce_weight_unit' ),
                'dimension_unit'    => get_option( 'woocommerce_dimension_unit' ),
                'enable_reviews'    => get_option( 'woocommerce_enable_reviews' ),
                'manage_stock'      => get_option( 'woocommerce_manage_stock' ),
                'registration'      => get_option( 'woocommerce_enable_myaccount_registration' ),
            );
        } );

        $safe_woo_keys = array(
            'woocommerce_store_address', 'woocommerce_store_city', 'woocommerce_store_postcode',
            'woocommerce_default_country', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax',
            'woocommerce_weight_unit', 'woocommerce_dimension_unit', 'woocommerce_enable_reviews',
        );

        $this->register_tool( 'settings_update_woo', array(
            'description' => 'Update a whitelisted WooCommerce setting',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'key'   => array( 'type' => 'string', 'description' => 'WooCommerce option key (required)' ),
                    'value' => array( 'type' => 'string', 'description' => 'New value (required)' ),
                ),
                'required' => array( 'key', 'value' ),
            ),
            'annotations' => array( 'title' => 'Update WooCommerce Setting', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) use ( $safe_woo_keys ) {
            $key = sanitize_text_field( $args['key'] );
            if ( ! in_array( $key, $safe_woo_keys, true ) ) {
                throw new Exception( "WooCommerce setting '{$key}' is not in the allowed whitelist" );
            }
            $old = get_option( $key );
            update_option( $key, sanitize_text_field( $args['value'] ) );
            return array( 'key' => $key, 'old_value' => $old, 'new_value' => $args['value'], 'updated' => true );
        } );

        /* LuwiPress module settings — thin proxies to /<module>/settings REST endpoints */

        $module_settings = array(
            'enrich'      => array( 'class' => 'LuwiPress_AI_Content',         'route' => '/enrich/settings',      'title' => 'Enrichment Settings' ),
            'translation' => array( 'class' => 'LuwiPress_Translation',        'route' => '/translation/settings', 'title' => 'Translation Settings' ),
            'chat'        => array( 'class' => 'LuwiPress_Customer_Chat',      'route' => '/chat/settings',        'title' => 'Customer Chat Settings' ),
            'schedule'    => array( 'class' => 'LuwiPress_Content_Scheduler',  'route' => '/schedule/settings',    'title' => 'Content Scheduler Settings' ),
        );

        foreach ( $module_settings as $module => $cfg ) {
            $class = $cfg['class'];
            $route = $cfg['route'];
            $title = $cfg['title'];

            $this->register_tool( "{$module}_settings_get", array(
                'description' => "Read the current {$module} module settings. Returns the same keys the POST endpoint accepts.",
                'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
                'annotations' => array( 'title' => "Get {$title}", 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
            ), function () use ( $class, $route ) {
                if ( ! class_exists( $class ) ) {
                    return array( 'error' => "{$class} not active" );
                }
                $instance = call_user_func( array( $class, 'get_instance' ) );
                $request  = new WP_REST_Request( 'GET', "/luwipress/v1{$route}" );
                $data     = $instance->handle_get_settings( $request );
                return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
            } );

            $this->register_tool( "{$module}_settings_set", array(
                'description' => "Update the {$module} module settings. Partial update — only keys present in the request body are written; other keys stay unchanged.",
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'settings' => array( 'type' => 'object', 'description' => 'Settings object; keys match the module shape.' ),
                    ),
                    'required' => array( 'settings' ),
                ),
                'annotations' => array( 'title' => "Update {$title}", 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
            ), function ( $args ) use ( $class, $route ) {
                if ( ! class_exists( $class ) ) {
                    return array( 'error' => "{$class} not active" );
                }
                $instance = call_user_func( array( $class, 'get_instance' ) );
                $request  = new WP_REST_Request( 'POST', "/luwipress/v1{$route}" );
                $request->set_body_params( (array) ( $args['settings'] ?? array() ) );
                $data = $instance->handle_set_settings( $request );
                return ( $data instanceof WP_REST_Response ) ? $data->get_data() : $data;
            } );
        }
    }

    /* ───────────────────── Plugin & Theme Tools ────────────────────── */

    private function register_plugin_theme_tools() {

        $this->register_tool( 'plugins_list', array(
            'description' => 'List all installed plugins with status, version, update availability',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'List Plugins', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all     = get_plugins();
            $updates = get_site_transient( 'update_plugins' );
            $list = array();
            foreach ( $all as $file => $data ) {
                $list[] = array(
                    'file'           => $file,
                    'name'           => $data['Name'],
                    'version'        => $data['Version'],
                    'active'         => is_plugin_active( $file ),
                    'update_available' => isset( $updates->response[ $file ] ),
                    'new_version'    => $updates->response[ $file ]->new_version ?? null,
                    'author'         => $data['AuthorName'] ?? '',
                );
            }
            return array( 'plugins' => $list, 'total' => count( $list ), 'active' => count( array_filter( $list, function( $p ) { return $p['active']; } ) ) );
        } );

        $this->register_tool( 'plugins_activate', array(
            'description' => 'Activate an installed plugin by file path',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'plugin' => array( 'type' => 'string', 'description' => 'Plugin file path e.g. "akismet/akismet.php" (required)' ),
                ),
                'required' => array( 'plugin' ),
            ),
            'annotations' => array( 'title' => 'Activate Plugin', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $plugin = sanitize_text_field( $args['plugin'] );
            $result = activate_plugin( $plugin );
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            return array( 'plugin' => $plugin, 'activated' => true );
        } );

        $this->register_tool( 'plugins_deactivate', array(
            'description' => 'Deactivate an active plugin by file path',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'plugin' => array( 'type' => 'string', 'description' => 'Plugin file path e.g. "akismet/akismet.php" (required)' ),
                ),
                'required' => array( 'plugin' ),
            ),
            'annotations' => array( 'title' => 'Deactivate Plugin', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $plugin = sanitize_text_field( $args['plugin'] );
            deactivate_plugins( $plugin );
            return array( 'plugin' => $plugin, 'deactivated' => true );
        } );

        // ── Plugin Search (WordPress.org repository) ──

        $this->register_tool( 'plugins_search', array(
            'description' => 'Search WordPress.org plugin repository by keyword',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'search'   => array( 'type' => 'string', 'description' => 'Search keyword (required)' ),
                    'per_page' => array( 'type' => 'integer', 'description' => 'Results per page (default 10, max 30)' ),
                ),
                'required' => array( 'search' ),
            ),
            'annotations' => array( 'title' => 'Search Plugins (WP.org)', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => true ),
        ), function ( $args ) {
            if ( ! function_exists( 'plugins_api' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }
            $per_page = min( absint( $args['per_page'] ?? 10 ), 30 );
            $response = plugins_api( 'query_plugins', array(
                'search'   => sanitize_text_field( $args['search'] ),
                'per_page' => $per_page,
                'fields'   => array(
                    'short_description' => true,
                    'icons'             => false,
                    'banners'           => false,
                    'sections'          => false,
                    'tested'            => true,
                    'active_installs'   => true,
                    'rating'            => true,
                ),
            ) );
            if ( is_wp_error( $response ) ) {
                throw new Exception( $response->get_error_message() );
            }
            $results = array();
            foreach ( $response->plugins as $p ) {
                $results[] = array(
                    'name'              => $p->name,
                    'slug'              => $p->slug,
                    'version'           => $p->version,
                    'author'            => wp_strip_all_tags( $p->author ),
                    'rating'            => $p->rating,
                    'active_installs'   => $p->active_installs,
                    'tested'            => $p->tested ?? null,
                    'short_description' => $p->short_description,
                );
            }
            return array( 'query' => $args['search'], 'results' => $results, 'total' => $response->info['results'] ?? count( $results ) );
        } );

        // ── Plugin Install (from WordPress.org by slug) ──

        $this->register_tool( 'plugins_install', array(
            'description' => 'Install a plugin from WordPress.org by slug (does NOT activate — use plugins_activate after)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'slug' => array( 'type' => 'string', 'description' => 'Plugin slug from WordPress.org e.g. "google-listings-and-ads" (required)' ),
                ),
                'required' => array( 'slug' ),
            ),
            'annotations' => array( 'title' => 'Install Plugin', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true ),
        ), function ( $args ) {
            if ( ! function_exists( 'plugins_api' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $slug = sanitize_text_field( $args['slug'] );

            // Check if already installed
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $installed = get_plugins();
            foreach ( $installed as $file => $data ) {
                if ( strpos( $file, $slug . '/' ) === 0 || $file === $slug . '.php' ) {
                    return array( 'slug' => $slug, 'installed' => true, 'already_installed' => true, 'plugin_file' => $file );
                }
            }

            // Fetch plugin info from WP.org
            $api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
            if ( is_wp_error( $api ) ) {
                throw new Exception( 'Plugin not found on WordPress.org: ' . $api->get_error_message() );
            }

            // Install
            $skin     = new WP_Ajax_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader( $skin );
            $result   = $upgrader->install( $api->download_link );

            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            if ( ! $result ) {
                $errors = $skin->get_errors();
                $msg    = is_wp_error( $errors ) ? $errors->get_error_message() : 'Installation failed — check filesystem permissions';
                throw new Exception( $msg );
            }

            // Find the installed plugin file
            $plugin_file = $upgrader->plugin_info();

            LuwiPress_Logger::log( "Plugin installed via WebMCP: {$slug}", 'info', 'webmcp' );

            return array( 'slug' => $slug, 'installed' => true, 'plugin_file' => $plugin_file, 'name' => $api->name, 'version' => $api->version );
        } );

        // ── Plugin Delete (uninstall) ──

        $this->register_tool( 'plugins_delete', array(
            'description' => 'Delete (uninstall) an inactive plugin. Plugin must be deactivated first.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'plugin' => array( 'type' => 'string', 'description' => 'Plugin file path e.g. "akismet/akismet.php" (required)' ),
                ),
                'required' => array( 'plugin' ),
            ),
            'annotations' => array( 'title' => 'Delete Plugin', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $plugin = sanitize_text_field( $args['plugin'] );

            if ( is_plugin_active( $plugin ) ) {
                throw new Exception( 'Cannot delete an active plugin — deactivate it first' );
            }

            $all = get_plugins();
            if ( ! isset( $all[ $plugin ] ) ) {
                throw new Exception( "Plugin '{$plugin}' not found" );
            }

            $result = delete_plugins( array( $plugin ) );
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }

            LuwiPress_Logger::log( "Plugin deleted via WebMCP: {$plugin}", 'warning', 'webmcp' );

            return array( 'plugin' => $plugin, 'deleted' => true );
        } );

        // ── Plugin Update ──

        $this->register_tool( 'plugins_update', array(
            'description' => 'Update an installed plugin to its latest version from WordPress.org',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'plugin' => array( 'type' => 'string', 'description' => 'Plugin file path e.g. "akismet/akismet.php" (required)' ),
                ),
                'required' => array( 'plugin' ),
            ),
            'annotations' => array( 'title' => 'Update Plugin', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true ),
        ), function ( $args ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $plugin = sanitize_text_field( $args['plugin'] );

            $all = get_plugins();
            if ( ! isset( $all[ $plugin ] ) ) {
                throw new Exception( "Plugin '{$plugin}' not found" );
            }

            $old_version = $all[ $plugin ]['Version'];

            $skin     = new WP_Ajax_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader( $skin );
            $result   = $upgrader->upgrade( $plugin );

            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }

            // Re-read to get new version
            $updated  = get_plugins();
            $new_ver  = isset( $updated[ $plugin ] ) ? $updated[ $plugin ]['Version'] : $old_version;

            LuwiPress_Logger::log( "Plugin updated via WebMCP: {$plugin} ({$old_version} → {$new_ver})", 'info', 'webmcp' );

            return array( 'plugin' => $plugin, 'old_version' => $old_version, 'new_version' => $new_ver, 'updated' => $old_version !== $new_ver );
        } );

        $this->register_tool( 'themes_list', array(
            'description' => 'List installed themes with active status',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'List Themes', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $themes = wp_get_themes();
            $active = get_stylesheet();
            $list = array();
            foreach ( $themes as $slug => $theme ) {
                $list[] = array(
                    'slug'      => $slug,
                    'name'      => $theme->get( 'Name' ),
                    'version'   => $theme->get( 'Version' ),
                    'active'    => $slug === $active,
                    'parent'    => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
                    'author'    => $theme->get( 'Author' ),
                );
            }
            return array( 'themes' => $list, 'active_theme' => $active );
        } );

        $this->register_tool( 'themes_activate', array(
            'description' => 'Switch the active theme',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'theme' => array( 'type' => 'string', 'description' => 'Theme slug (required)' ),
                ),
                'required' => array( 'theme' ),
            ),
            'annotations' => array( 'title' => 'Activate Theme', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $theme_slug = sanitize_text_field( $args['theme'] );
            $theme = wp_get_theme( $theme_slug );
            if ( ! $theme->exists() ) {
                throw new Exception( "Theme '{$theme_slug}' not found" );
            }
            switch_theme( $theme_slug );
            return array( 'theme' => $theme_slug, 'activated' => true );
        } );
    }

    /* ───────────────────── Menu Tools ──────────────────────────────── */

    private function register_menu_tools() {

        $this->register_tool( 'menu_list', array(
            'description' => 'List all navigation menus with their item count and assigned locations',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'List Menus', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $menus     = wp_get_nav_menus();
            $locations = get_nav_menu_locations();
            $loc_names = get_registered_nav_menus();
            $loc_map = array();
            foreach ( $locations as $loc => $menu_id ) {
                $loc_map[ $menu_id ][] = $loc_names[ $loc ] ?? $loc;
            }
            $list = array();
            foreach ( $menus as $menu ) {
                $list[] = array(
                    'id'         => $menu->term_id,
                    'name'       => $menu->name,
                    'slug'       => $menu->slug,
                    'item_count' => $menu->count,
                    'locations'  => $loc_map[ $menu->term_id ] ?? array(),
                );
            }
            return array( 'menus' => $list, 'registered_locations' => array_values( $loc_names ) );
        } );

        $this->register_tool( 'menu_get_items', array(
            'description' => 'Get all items in a menu (hierarchical, with URLs, types)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'menu_id' => array( 'type' => 'integer', 'description' => 'Menu ID or term_id (required)' ),
                ),
                'required' => array( 'menu_id' ),
            ),
            'annotations' => array( 'title' => 'Get Menu Items', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $items = wp_get_nav_menu_items( intval( $args['menu_id'] ) );
            if ( ! $items ) {
                throw new Exception( 'Menu not found or empty' );
            }
            $list = array();
            foreach ( $items as $item ) {
                $list[] = array(
                    'id'        => absint( $item->ID ),
                    'title'     => $item->title,
                    'url'       => $item->url,
                    'type'      => $item->type,
                    'object'    => $item->object,
                    'object_id' => absint( $item->object_id ),
                    'parent'    => absint( $item->menu_item_parent ),
                    'position'  => absint( $item->menu_order ),
                    'classes'   => array_filter( $item->classes ),
                );
            }
            return array( 'menu_id' => intval( $args['menu_id'] ), 'items' => $list );
        } );

        $this->register_tool( 'menu_create', array(
            'description' => 'Create a new navigation menu',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'name' => array( 'type' => 'string', 'description' => 'Menu name (required)' ),
                ),
                'required' => array( 'name' ),
            ),
            'annotations' => array( 'title' => 'Create Menu', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $menu_id = wp_create_nav_menu( sanitize_text_field( $args['name'] ) );
            if ( is_wp_error( $menu_id ) ) {
                throw new Exception( $menu_id->get_error_message() );
            }
            return array( 'menu_id' => $menu_id, 'name' => $args['name'] );
        } );

        $this->register_tool( 'menu_add_item', array(
            'description' => 'Add an item to a navigation menu (page, post, custom URL, category)',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'menu_id'   => array( 'type' => 'integer', 'description' => 'Menu ID (required)' ),
                    'title'     => array( 'type' => 'string', 'description' => 'Menu item title (required)' ),
                    'url'       => array( 'type' => 'string', 'description' => 'URL for custom link items' ),
                    'object_id' => array( 'type' => 'integer', 'description' => 'Post/page/category ID for content items' ),
                    'object'    => array( 'type' => 'string', 'description' => 'Object type: page, post, category, custom' ),
                    'parent'    => array( 'type' => 'integer', 'description' => 'Parent menu item ID for submenus' ),
                ),
                'required' => array( 'menu_id', 'title' ),
            ),
            'annotations' => array( 'title' => 'Add Menu Item', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
        ), function ( $args ) {
            $menu_id = intval( $args['menu_id'] );
            $item_data = array(
                'menu-item-title'  => sanitize_text_field( $args['title'] ),
                'menu-item-status' => 'publish',
            );
            if ( ! empty( $args['url'] ) ) {
                $item_data['menu-item-url']  = esc_url_raw( $args['url'] );
                $item_data['menu-item-type'] = 'custom';
            } elseif ( ! empty( $args['object_id'] ) ) {
                $item_data['menu-item-object-id'] = absint( $args['object_id'] );
                $obj = sanitize_text_field( $args['object'] ?? 'page' );
                $item_data['menu-item-object'] = $obj;
                $item_data['menu-item-type'] = in_array( $obj, array( 'category', 'post_tag', 'product_cat' ), true ) ? 'taxonomy' : 'post_type';
            }
            if ( ! empty( $args['parent'] ) ) {
                $item_data['menu-item-parent-id'] = absint( $args['parent'] );
            }
            $item_id = wp_update_nav_menu_item( $menu_id, 0, $item_data );
            if ( is_wp_error( $item_id ) ) {
                throw new Exception( $item_id->get_error_message() );
            }
            return array( 'item_id' => $item_id, 'menu_id' => $menu_id, 'title' => $args['title'] );
        } );

        $this->register_tool( 'menu_remove_item', array(
            'description' => 'Remove an item from a navigation menu',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'item_id' => array( 'type' => 'integer', 'description' => 'Menu item post ID (required)' ),
                ),
                'required' => array( 'item_id' ),
            ),
            'annotations' => array( 'title' => 'Remove Menu Item', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $item_id = absint( $args['item_id'] );
            $result = wp_delete_post( $item_id, true );
            if ( ! $result ) {
                throw new Exception( 'Failed to remove menu item' );
            }
            return array( 'item_id' => $item_id, 'removed' => true );
        } );
    }

    /* ───────────────────── Custom Field / Meta Tools ──────────────── */

    private function register_meta_tools() {

        $this->register_tool( 'meta_get', array(
            'description' => 'Get all or specific custom field values for a post/product',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'key'     => array( 'type' => 'string', 'description' => 'Specific meta key (omit for all public meta)' ),
                ),
                'required' => array( 'post_id' ),
            ),
            'annotations' => array( 'title' => 'Get Meta', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $post_id = intval( $args['post_id'] );
            if ( ! get_post( $post_id ) ) {
                throw new Exception( 'Post not found' );
            }
            if ( ! empty( $args['key'] ) ) {
                $value = get_post_meta( $post_id, sanitize_text_field( $args['key'] ), true );
                return array( 'post_id' => $post_id, 'key' => $args['key'], 'value' => $value );
            }
            $all_meta = get_post_meta( $post_id );
            $filtered = array();
            foreach ( $all_meta as $key => $values ) {
                if ( substr( $key, 0, 1 ) !== '_' ) {
                    $filtered[ $key ] = count( $values ) === 1 ? $values[0] : $values;
                }
            }
            return array( 'post_id' => $post_id, 'meta' => $filtered );
        } );

        $this->register_tool( 'meta_set', array(
            'description' => 'Set a custom field value for a post/product',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'key'     => array( 'type' => 'string', 'description' => 'Meta key (required)' ),
                    'value'   => array( 'type' => 'string', 'description' => 'Meta value (required)' ),
                ),
                'required' => array( 'post_id', 'key', 'value' ),
            ),
            'annotations' => array( 'title' => 'Set Meta', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $post_id = intval( $args['post_id'] );
            if ( ! get_post( $post_id ) ) {
                throw new Exception( 'Post not found' );
            }
            $key = sanitize_text_field( $args['key'] );
            update_post_meta( $post_id, $key, sanitize_text_field( $args['value'] ) );
            return array( 'post_id' => $post_id, 'key' => $key, 'updated' => true );
        } );

        $this->register_tool( 'meta_delete', array(
            'description' => 'Delete a custom field from a post/product',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'description' => 'Post ID (required)' ),
                    'key'     => array( 'type' => 'string', 'description' => 'Meta key to delete (required)' ),
                ),
                'required' => array( 'post_id', 'key' ),
            ),
            'annotations' => array( 'title' => 'Delete Meta', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $post_id = intval( $args['post_id'] );
            if ( ! get_post( $post_id ) ) {
                throw new Exception( 'Post not found' );
            }
            $key = sanitize_text_field( $args['key'] );
            $result = delete_post_meta( $post_id, $key );
            return array( 'post_id' => $post_id, 'key' => $key, 'deleted' => $result );
        } );
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

    /* ───────────────────── Search Tools ──────────────────────────── */

    private function register_search_tools() {
        if ( ! class_exists( 'LuwiPress_Search_Index' ) ) {
            return;
        }

        $this->register_tool( 'search_products', array(
            'description' => 'Search products using BM25 ranking — searches title, description, categories, tags, attributes, SKU, and FAQ content with relevance scoring',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'query' => array( 'type' => 'string', 'description' => 'Search query (required)' ),
                    'limit' => array( 'type' => 'integer', 'description' => 'Max results (default 10)' ),
                ),
                'required' => array( 'query' ),
            ),
            'annotations' => array( 'title' => 'BM25 Product Search', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function ( $args ) {
            $index = LuwiPress_Search_Index::get_instance();
            if ( ! $index->is_indexed() ) {
                return array( 'error' => 'Search index not built yet. Run reindex first.', 'indexed' => false );
            }
            $results = $index->search( sanitize_text_field( $args['query'] ), absint( $args['limit'] ?? 10 ) );
            return array( 'query' => $args['query'], 'results' => $results, 'count' => count( $results ) );
        } );

        $this->register_tool( 'search_reindex', array(
            'description' => 'Rebuild the BM25 search index for all products — indexes title, description, categories, tags, attributes, SKU, and FAQ content',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Rebuild Search Index', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $index = LuwiPress_Search_Index::get_instance();
            $count = $index->reindex_all();
            $stats = $index->get_stats();
            return array( 'reindexed' => $count, 'stats' => $stats );
        } );

        $this->register_tool( 'search_stats', array(
            'description' => 'Get BM25 search index statistics — total documents, unique tokens, average document length',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => new stdClass(),
            ),
            'annotations' => array( 'title' => 'Search Index Stats', 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
        ), function () {
            $index = LuwiPress_Search_Index::get_instance();
            return $index->get_stats();
        } );
    }

    /**
     * Check if WebMCP is enabled via settings.
     */
    public static function is_enabled() {
        return (bool) get_option( 'luwipress_webmcp_enabled', true );
    }
}
