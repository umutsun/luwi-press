<?php
/**
 * LuwiPress Abilities API Bridge
 *
 * Mirrors WebMCP tool registry into the WordPress Abilities API
 * (`wp_register_ability`, available WP 6.9+). Lets WP-native AI clients
 * and the WooCommerce MCP adapter discover LuwiPress capabilities through
 * the standard registry instead of (or alongside) our custom WebMCP server.
 *
 * Design:
 *  - Registers on `wp_abilities_api_init` (no-op on WP < 6.9 — function
 *    `wp_register_ability` won't exist, we soft-skip).
 *  - Pulls each WebMCP tool from `LuwiPress_WebMCP::get_tool_registry()`.
 *  - Permission: defaults to `manage_options` for write tools,
 *    `read` for readOnlyHint=true tools. Token-based auth is NOT bridged
 *    here because Abilities API permission_callback receives `mixed $input`,
 *    not a WP_REST_Request — token auth keeps living in WebMCP.
 *  - WooCommerce MCP namespace inclusion via
 *    `woocommerce_mcp_include_ability` filter (default-deny;
 *    operator opts in per ability via meta.mcp.public flag).
 *  - WebMCP companion stays untouched — dual-registry by design.
 *
 * @package    LuwiPress
 * @subpackage Abilities
 * @since      3.1.43
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LuwiPress_Abilities {

    /** @var self|null */
    private static $instance = null;

    const ABILITY_NAMESPACE = 'luwipress';
    const ABILITY_CATEGORY  = 'luwipress';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hook fires on WP 6.9+ only. On older WP this never runs — no harm.
        add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

        // WooCommerce MCP namespace inclusion. Filter exists only if WC MCP
        // adapter is loaded, but registering early is harmless.
        add_filter( 'woocommerce_mcp_include_ability', array( $this, 'filter_wc_mcp_include' ), 10, 2 );
    }

    /**
     * Bridge WebMCP tools into Abilities API.
     *
     * Called on `wp_abilities_api_init`. WebMCP companion may not be active
     * (it's a separate plugin); we soft-fail in that case.
     */
    public function register_abilities() {
        // Hard prerequisite: WP 6.9+ Abilities API. Older WP or absent
        // plugin → silent no-op, never an error.
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        if ( ! class_exists( 'LuwiPress_WebMCP' ) ) {
            // WebMCP companion plugin not installed — nothing to mirror.
            return;
        }

        // Category registration is optional in some Abilities API
        // distributions (the function may not exist on older 6.9 dev
        // builds). Skip on absence; abilities still register fine.
        if ( function_exists( 'wp_register_ability_category' ) ) {
            try {
                wp_register_ability_category( self::ABILITY_CATEGORY, array(
                    'label'       => __( 'LuwiPress', 'luwipress' ),
                    'description' => __( 'AI-powered content, SEO, translation, and store automation tools.', 'luwipress' ),
                ) );
            } catch ( Exception $e ) {
                // Already-registered or invalid category name — non-fatal.
            }
        }

        try {
            $registry = LuwiPress_WebMCP::get_instance()->get_tool_registry();
        } catch ( Exception $e ) {
            return;
        }

        if ( ! is_array( $registry ) ) {
            return;
        }

        foreach ( $registry as $tool_name => $tool ) {
            // Bound try/catch per tool: one bad schema must not abort
            // the whole mirror — we want the other 150 abilities live
            // even if a single tool has a malformed input_schema.
            try {
                $this->register_one_ability( $tool_name, $tool['schema'], $tool['handler'] );
            } catch ( Exception $e ) {
                if ( class_exists( 'LuwiPress_Logger' ) ) {
                    LuwiPress_Logger::log(
                        'Abilities API: failed to register tool ' . $tool_name . ' — ' . $e->getMessage(),
                        'warning',
                        array( 'tool' => $tool_name )
                    );
                }
            }
        }
    }

    /**
     * Register a single MCP tool as an Abilities API ability.
     *
     * @param string   $tool_name MCP tool name (e.g. 'system_status').
     * @param array    $schema    MCP schema (description, inputSchema, annotations).
     * @param callable $handler   The original WebMCP handler.
     */
    private function register_one_ability( $tool_name, $schema, $handler ) {
        // Abilities API requires lowercase a-z, 0-9, dashes only after the
        // namespace slash. Sanitize tool_name to that shape; reject if
        // the result would be empty or invalid.
        $slug = strtolower( str_replace( '_', '-', (string) $tool_name ) );
        $slug = preg_replace( '/[^a-z0-9\-]/', '', $slug );
        if ( '' === $slug ) {
            return;
        }
        $ability_id = self::ABILITY_NAMESPACE . '/' . $slug;

        if ( ! is_callable( $handler ) ) {
            return;
        }
        if ( ! is_array( $schema ) ) {
            $schema = array();
        }

        $description  = isset( $schema['description'] ) ? (string) $schema['description'] : $tool_name;
        $title        = isset( $schema['annotations']['title'] ) ? (string) $schema['annotations']['title'] : $tool_name;
        $is_readonly  = ! empty( $schema['annotations']['readOnlyHint'] );
        $is_destructive = ! empty( $schema['annotations']['destructiveHint'] );
        $is_idempotent  = ! empty( $schema['annotations']['idempotentHint'] );

        $args = array(
            'label'               => $title,
            'description'         => $description,
            'category'            => self::ABILITY_CATEGORY,
            'execute_callback'    => function ( $input = null ) use ( $handler, $tool_name ) {
                return $this->execute_with_handler( $handler, $input, $tool_name );
            },
            'permission_callback' => function ( $input = null ) use ( $is_readonly ) {
                return $this->check_ability_permission( $is_readonly );
            },
            'meta' => array(
                'annotations' => array(
                    'readonly'    => $is_readonly,
                    'destructive' => $is_destructive,
                    'idempotent'  => $is_idempotent,
                ),
                // Default-private. Operator can flip to true via the
                // `wp_register_ability_args` filter or per-tool option.
                'show_in_rest' => $this->is_ability_public( $ability_id, $is_readonly ),
                'mcp'          => array(
                    // Default-private for MCP exposure too — store owner
                    // explicitly opts in. Read-only tools default to public,
                    // write tools default to private.
                    'public' => $this->is_ability_public( $ability_id, $is_readonly ),
                ),
            ),
        );

        // Preserve original input schema if present.
        if ( isset( $schema['inputSchema'] ) && is_array( $schema['inputSchema'] ) ) {
            $args['input_schema'] = $schema['inputSchema'];
        }

        // wp_register_ability may return null / WP_Ability / WP_Error
        // depending on Abilities API version. Suppress notices and accept
        // any outcome — a single failed registration must never bubble.
        $result = @wp_register_ability( $ability_id, $args ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        // PHPDoc declares WP_Ability|null but earlier dev builds returned
        // WP_Error on failure; defensive check kept for runtime safety.
        if ( class_exists( 'LuwiPress_Logger' ) && is_object( $result ) && method_exists( $result, 'get_error_message' ) ) {
            LuwiPress_Logger::log(
                'Abilities API: register failed for ' . $ability_id . ' — ' . $result->get_error_message(),
                'warning',
                array( 'ability_id' => $ability_id )
            );
        }
    }

    /**
     * Execute an MCP-shaped handler from an Abilities API context.
     *
     * Abilities pass `mixed $input` directly. WebMCP handlers expect either
     * an array of args (most tools) or no args (system_status etc). We pass
     * the input through as an array and let the handler decide.
     *
     * @param callable $handler
     * @param mixed    $input
     * @param string   $tool_name
     * @return mixed|WP_Error
     */
    private function execute_with_handler( $handler, $input, $tool_name ) {
        try {
            $args = is_array( $input ) ? $input : array();
            $result = call_user_func( $handler, $args );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            // WP_REST_Response → unwrap to data array (matches WebMCP behavior).
            if ( $result instanceof WP_REST_Response ) {
                return $result->get_data();
            }

            return $result;
        } catch ( Exception $e ) {
            return new WP_Error(
                'luwipress_ability_exception',
                sprintf( 'Ability %s threw: %s', $tool_name, $e->getMessage() )
            );
        }
    }

    /**
     * Capability check for an ability invocation.
     *
     * Abilities API permission_callback receives `mixed $input` (NOT a
     * WP_REST_Request) so we cannot pull a Bearer token from headers here.
     * Token-gated calls keep going through WebMCP. This bridge gates by
     * WP capability only:
     *  - Read-only abilities: `read` (any logged-in user)
     *  - Mutating abilities:  `manage_options`
     *
     * Operators who want broader access can extend via the standard
     * `user_has_cap` filter or by re-registering with custom permission.
     *
     * @param bool $is_readonly
     * @return bool|WP_Error
     */
    private function check_ability_permission( $is_readonly ) {
        if ( $is_readonly ) {
            if ( current_user_can( 'read' ) ) {
                return true;
            }
            return new WP_Error( 'rest_forbidden', __( 'Login required.', 'luwipress' ), array( 'status' => 401 ) );
        }
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        return new WP_Error( 'rest_forbidden', __( 'Administrator access required.', 'luwipress' ), array( 'status' => 403 ) );
    }

    /**
     * Decide whether an ability should be public by default.
     *
     * Default policy:
     *  - Read-only abilities: public (true)
     *  - Mutating abilities:  private (false)
     *
     * Stored override: `luwipress_abilities_public_overrides` option (array
     * of ability_ids → bool). Allows the operator to flip individual tools
     * via Settings UI without code edits.
     *
     * @param string $ability_id
     * @param bool   $is_readonly
     * @return bool
     */
    private function is_ability_public( $ability_id, $is_readonly ) {
        $overrides = get_option( 'luwipress_abilities_public_overrides', array() );
        if ( is_array( $overrides ) && array_key_exists( $ability_id, $overrides ) ) {
            return (bool) $overrides[ $ability_id ];
        }
        return (bool) $is_readonly;
    }

    /**
     * Include LuwiPress abilities in the WooCommerce MCP server.
     *
     * Default policy: include if our ability has `meta.mcp.public = true`
     * (which is itself a function of read-only-ness + operator overrides
     * — see is_ability_public). Otherwise pass through whatever WC's
     * baseline rule decided.
     *
     * Filter signature (per WooCommerce MCP doc Feb 2026):
     *   apply_filters( 'woocommerce_mcp_include_ability', bool, string )
     *
     * @param bool   $include
     * @param string $ability_id
     * @return bool
     */
    public function filter_wc_mcp_include( $include, $ability_id ) {
        if ( ! is_string( $ability_id ) ) {
            return $include;
        }
        if ( strpos( $ability_id, self::ABILITY_NAMESPACE . '/' ) !== 0 ) {
            return $include;
        }

        if ( ! function_exists( 'wp_get_ability' ) ) {
            return $include;
        }

        try {
            $ability = wp_get_ability( $ability_id );
        } catch ( Exception $e ) {
            return $include;
        }
        if ( ! $ability || ! is_object( $ability ) ) {
            return $include;
        }

        // Read meta directly when getter is available; fall back to the
        // raw ability object in case the public API differs.
        $meta = array();
        if ( method_exists( $ability, 'get_meta' ) ) {
            try {
                $meta = (array) $ability->get_meta();
            } catch ( Exception $e ) {
                $meta = array();
            }
        }
        if ( isset( $meta['mcp']['public'] ) && true === $meta['mcp']['public'] ) {
            return true;
        }

        return $include;
    }
}
