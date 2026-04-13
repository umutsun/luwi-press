<?php
/**
 * LuwiPress Elementor Integration
 *
 * Read, translate, and modify Elementor page content and styles.
 * Parses _elementor_data JSON, provides widget-level operations,
 * and integrates with WPML for page translation.
 *
 * @package    LuwiPress
 * @subpackage Elementor
 * @since      2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LuwiPress_Elementor {

    /* ──────────────────────────── Singleton ──────────────────────────── */

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );

        // Background job: translate Elementor pages one at a time via wp_cron
        add_action( 'luwipress_elementor_translate_single', array( $this, 'cron_translate_single' ), 10, 2 );
    }

    /**
     * WP Cron handler: translate a single Elementor page in the background.
     *
     * @param int    $source_id       Source post ID.
     * @param string $target_language Target language code.
     */
    public function cron_translate_single( $source_id, $target_language ) {
        // Increase execution time for background processing
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        // Track translation status for progress polling
        update_post_meta( $source_id, '_luwipress_translation_status', wp_json_encode( array(
            'status'   => 'translating',
            'language' => $target_language,
            'started'  => current_time( 'mysql' ),
        ) ) );

        LuwiPress_Logger::log( 'Cron: translating Elementor page #' . $source_id . ' → ' . $target_language, 'info' );

        $result = $this->translate_page( $source_id, $target_language );

        if ( is_wp_error( $result ) ) {
            update_post_meta( $source_id, '_luwipress_translation_status', wp_json_encode( array(
                'status'   => 'failed',
                'language' => $target_language,
                'error'    => $result->get_error_message(),
                'finished' => current_time( 'mysql' ),
            ) ) );
            LuwiPress_Logger::log( 'Cron: Elementor translation FAILED #' . $source_id . ' → ' . $target_language . ': ' . $result->get_error_message(), 'error' );
        } else {
            update_post_meta( $source_id, '_luwipress_translation_status', wp_json_encode( array(
                'status'        => 'completed',
                'language'      => $target_language,
                'translated_id' => $result['translated_id'] ?? 0,
                'finished'      => current_time( 'mysql' ),
            ) ) );
            LuwiPress_Logger::log( 'Cron: Elementor translation completed #' . $source_id . ' → #' . ( $result['translated_id'] ?? '?' ) . ' (' . $target_language . ')', 'info' );
        }
    }

    /* ──────────────────────────── Constants ──────────────────────────── */

    /**
     * Widget types and their translatable text fields.
     */
    const TRANSLATABLE_WIDGETS = array(
        'heading'         => array( 'title' ),
        'text-editor'     => array( 'editor' ),
        'button'          => array( 'text' ),
        'image-box'       => array( 'title_text', 'description_text' ),
        'icon-box'        => array( 'title_text', 'description_text' ),
        'testimonial'     => array( 'testimonial_content', 'testimonial_name', 'testimonial_job' ),
        'call-to-action'  => array( 'title', 'description', 'button_text' ),
        'price-table'     => array( 'heading', 'sub_heading' ),
        'tabs'            => array(),  // handled specially (items array)
        'accordion'       => array(),  // handled specially (items array)
        'toggle'          => array(),  // handled specially (items array)
        'alert'           => array( 'alert_title', 'alert_description' ),
        'counter'         => array( 'title', 'suffix', 'prefix' ),
        'progress'        => array( 'title', 'inner_text' ),
        'icon-list'       => array(),  // handled specially (items array)
        'animated-headline' => array( 'before_text', 'highlighted_text', 'after_text', 'rotating_text' ),
        // ElementsKit widgets
        'elementskit-heading' => array( 'ekit_heading_title', 'ekit_heading_sub_title', 'ekit_heading_extra_title', 'ekit_heading_description' ),
    );

    /**
     * Repeater-based widget types and their item text fields.
     */
    const REPEATER_WIDGETS = array(
        'tabs'      => array( 'items_key' => 'tabs', 'fields' => array( 'tab_title', 'tab_content' ) ),
        'accordion' => array( 'items_key' => 'tabs', 'fields' => array( 'tab_title', 'tab_content' ) ),
        'toggle'    => array( 'items_key' => 'tabs', 'fields' => array( 'tab_title', 'tab_content' ) ),
        'icon-list' => array( 'items_key' => 'icon_list', 'fields' => array( 'text' ) ),
        'price-table' => array( 'items_key' => 'features_list', 'fields' => array( 'item_text' ) ),
    );

    /**
     * CSS property → Elementor setting key map.
     * Allows MCP clients to use natural CSS names like "font-size" instead of "typography_font_size".
     *
     * Format types:
     *   'string'    → simple value (color hex, font name, etc.)
     *   'size_unit' → {size, unit} object (font-size, line-height, etc.)
     *   'dimension' → {top, right, bottom, left, unit, isLinked} object (padding, margin, etc.)
     */
    const CSS_MAP = array(
        // Colors
        'color'               => array( 'key' => 'title_color',              'format' => 'string' ),
        'text-color'          => array( 'key' => 'title_color',              'format' => 'string' ),
        'background-color'    => array( 'key' => 'background_color',         'format' => 'string' ),
        'background'          => array( 'key' => 'background_color',         'format' => 'string' ),
        'border-color'        => array( 'key' => 'border_color',             'format' => 'string' ),

        // Typography
        'font-size'           => array( 'key' => 'typography_font_size',     'format' => 'size_unit' ),
        'font-weight'         => array( 'key' => 'typography_font_weight',   'format' => 'string' ),
        'font-family'         => array( 'key' => 'typography_font_family',   'format' => 'string' ),
        'line-height'         => array( 'key' => 'typography_line_height',   'format' => 'size_unit' ),
        'letter-spacing'      => array( 'key' => 'typography_letter_spacing','format' => 'size_unit' ),
        'text-transform'      => array( 'key' => 'typography_text_transform','format' => 'string' ),
        'font-style'          => array( 'key' => 'typography_font_style',    'format' => 'string' ),
        'text-decoration'     => array( 'key' => 'typography_text_decoration','format' => 'string' ),
        'word-spacing'        => array( 'key' => 'typography_word_spacing',  'format' => 'size_unit' ),

        // Spacing
        'padding'             => array( 'key' => '_padding',                 'format' => 'dimension' ),
        'margin'              => array( 'key' => '_margin',                  'format' => 'dimension' ),

        // Border
        'border-radius'       => array( 'key' => 'border_radius',           'format' => 'dimension' ),
        'border-width'        => array( 'key' => 'border_width',            'format' => 'dimension' ),
        'border-style'        => array( 'key' => 'border_border',           'format' => 'string' ),

        // Sizing
        'width'               => array( 'key' => '_element_width',          'format' => 'string' ),
        'text-align'          => array( 'key' => 'align',                   'format' => 'string' ),
        'opacity'             => array( 'key' => '_opacity',                'format' => 'string' ),
    );

    /**
     * Widget-specific color key overrides.
     * Different widgets use different keys for their text color.
     */
    const WIDGET_COLOR_KEYS = array(
        'heading'        => 'title_color',
        'text-editor'    => 'color',
        'button'         => 'button_text_color',
        'icon-box'       => 'title_color',
        'image-box'      => 'title_color',
        'counter'        => 'title_color',
        'progress'       => 'title_color',
        'alert'          => 'alert_title_color',
        'testimonial'    => 'content_content_color',
    );

    /**
     * Widget-specific background color key overrides.
     */
    const WIDGET_BG_KEYS = array(
        'button'  => 'button_background_color',
        'section' => 'background_color',
        'column'  => 'background_color',
    );

    /**
     * Language name map for prompts.
     */
    const LANG_NAMES = array(
        'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French',
        'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch',
        'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'pt-pt' => 'Portuguese',
        'ko' => 'Korean', 'pl' => 'Polish', 'sv' => 'Swedish', 'da' => 'Danish',
        'fi' => 'Finnish', 'no' => 'Norwegian', 'el' => 'Greek', 'he' => 'Hebrew',
    );

    /* ──────────────────────── REST Endpoints ────────────────────────── */

    public function register_endpoints() {
        $ns = 'luwipress/v1';

        register_rest_route( $ns, '/elementor/page/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_page_data' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/translate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_translate_page' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Queue Elementor translations as background jobs (no timeout)
        register_rest_route( $ns, '/elementor/translate-queue', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_queue_translations' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/widget', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_set_widget_text' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/style', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_set_widget_style' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/bulk-update', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_bulk_update' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/outline/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_page_outline' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Structural operations
        register_rest_route( $ns, '/elementor/add-widget', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_add_widget' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/add-section', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_add_section' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/delete', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_delete_element' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/move', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_move_element' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/clone', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_clone_element' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/custom-css', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_set_custom_css' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/responsive', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_set_responsive' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/global-style', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_global_style' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Sync & Audit
        register_rest_route( $ns, '/elementor/sync-styles', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_sync_styles' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/audit/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_audit_spacing' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        // Revision
        register_rest_route( $ns, '/elementor/snapshot', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_create_snapshot' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/rollback', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_rollback' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/snapshots/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_list_snapshots' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/auto-fix', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_auto_fix' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );

        register_rest_route( $ns, '/elementor/responsive-audit/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_responsive_audit' ),
            'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
        ) );
    }

    /* ──────────────────────── READ Operations ───────────────────────── */

    /**
     * Get raw Elementor data for a post.
     *
     * @param int $post_id Post ID.
     * @return array|WP_Error Parsed elementor data array or error.
     */
    public function get_elementor_data( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
        }

        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( empty( $raw ) ) {
            return new WP_Error( 'no_elementor', 'No Elementor data for this post', array( 'status' => 404 ) );
        }

        $data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'parse_error', 'Failed to parse Elementor JSON', array( 'status' => 500 ) );
        }

        return $data;
    }

    /**
     * Get a flat list of all elements (sections, columns, widgets) from Elementor data.
     *
     * Returns every element with its id, type, parent info, and relevant
     * settings — giving the MCP client a full picture of the page structure.
     *
     * @param int  $post_id      Post ID.
     * @param bool $widgets_only If true, only return widgets (legacy compat).
     * @return array|WP_Error Array of element info or error.
     */
    public function get_widget_tree( $post_id, $widgets_only = false ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $elements = array();
        $this->walk_elements_with_path( $data, '', function ( $element, $parent_id ) use ( &$elements, $widgets_only ) {
            $el_type = $element['elType'] ?? '';

            // In widgets_only mode, skip sections/columns
            if ( $widgets_only && $el_type !== 'widget' ) {
                return;
            }

            $settings    = $element['settings'] ?? array();
            $widget_type = $element['widgetType'] ?? null;

            $info = array(
                'id'        => $element['id'],
                'el_type'   => $el_type,
                'parent_id' => $parent_id ?: null,
            );

            if ( $widget_type ) {
                $info['widget_type'] = $widget_type;
            }

            // For sections/columns, extract key visual settings
            if ( $el_type === 'section' || $el_type === 'column' || $el_type === 'container' ) {
                $info['style_summary'] = $this->summarize_styles( $settings, $el_type );
            }

            // For widgets, extract text + style summary
            if ( $el_type === 'widget' ) {
                $texts = $this->extract_widget_texts( $widget_type, $settings );
                if ( ! empty( $texts ) ) {
                    $info['texts'] = $texts;
                }
                $info['style_summary'] = $this->summarize_styles( $settings, $widget_type );
            }

            // Always include full settings for detailed inspection
            $info['settings'] = $settings;

            $elements[] = $info;
        } );

        return $elements;
    }

    /**
     * Get a condensed page structure — just element IDs, types, hierarchy and text.
     * Much lighter output for quick page overview without full settings.
     *
     * @param int $post_id Post ID.
     * @return array|WP_Error
     */
    public function get_page_outline( $post_id ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        return $this->build_outline( $data );
    }

    /**
     * Recursively build a compact outline of the page structure.
     */
    private function build_outline( array $elements ) {
        $outline = array();
        foreach ( $elements as $element ) {
            $el_type     = $element['elType'] ?? '';
            $settings    = $element['settings'] ?? array();
            $widget_type = $element['widgetType'] ?? null;

            $node = array(
                'id'      => $element['id'],
                'el_type' => $el_type,
            );

            if ( $widget_type ) {
                $node['widget_type'] = $widget_type;

                // Include text preview (first 80 chars)
                $texts = $this->extract_widget_texts( $widget_type, $settings );
                if ( ! empty( $texts ) ) {
                    $first_text = reset( $texts );
                    $node['text_preview'] = mb_substr( wp_strip_all_tags( $first_text ), 0, 80 );
                }
            }

            if ( ! empty( $element['elements'] ) ) {
                $node['children'] = $this->build_outline( $element['elements'] );
            }

            $outline[] = $node;
        }
        return $outline;
    }

    /**
     * Extract all translatable text from a page.
     *
     * @param int $post_id Post ID.
     * @return array|WP_Error Keyed array: widget_id => ['type' => ..., 'texts' => [...]]
     */
    public function extract_translatable_text( $post_id ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $translatable = array();
        $this->walk_elements( $data, function ( $element ) use ( &$translatable ) {
            if ( 'widget' !== ( $element['elType'] ?? '' ) ) {
                return;
            }

            $widget_type = $element['widgetType'] ?? '';
            $settings    = $element['settings'] ?? array();

            if ( ! isset( self::TRANSLATABLE_WIDGETS[ $widget_type ] ) ) {
                return;
            }

            $texts = $this->extract_widget_texts( $widget_type, $settings );
            if ( ! empty( $texts ) ) {
                $translatable[ $element['id'] ] = array(
                    'type'  => $widget_type,
                    'texts' => $texts,
                );
            }
        } );

        return $translatable;
    }

    /* ──────────────────────── WRITE Operations ──────────────────────── */

    /**
     * Update text in a specific widget.
     *
     * @param int    $post_id   Post ID.
     * @param string $widget_id Widget element ID.
     * @param array  $new_texts Associative array of field => new text.
     * @return true|WP_Error
     */
    public function set_widget_text( $post_id, $widget_id, array $new_texts ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $found = false;
        $data  = $this->walk_elements_modify( $data, function ( &$element ) use ( $widget_id, $new_texts, &$found ) {
            if ( ( $element['id'] ?? '' ) !== $widget_id ) {
                return;
            }
            $found = true;

            foreach ( $new_texts as $field => $value ) {
                $element['settings'][ sanitize_text_field( $field ) ] = wp_kses_post( $value );
            }
        } );

        if ( ! $found ) {
            return new WP_Error( 'widget_not_found', 'Widget ID not found: ' . $widget_id, array( 'status' => 404 ) );
        }

        return $this->save_elementor_data( $post_id, $data );
    }

    /**
     * Update style properties on any Elementor element (widget, section, or column).
     *
     * Accepts both CSS-friendly properties ("font-size": "18px") and
     * Elementor-native keys ("typography_font_size": {"size": 18, "unit": "px"}).
     *
     * @param int    $post_id    Post ID.
     * @param string $element_id Element ID (widget, section, or column).
     * @param array  $styles     CSS or Elementor style properties.
     * @return true|WP_Error
     */
    public function set_widget_style( $post_id, $element_id, array $styles ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $found = false;
        $data  = $this->walk_elements_modify( $data, function ( &$element ) use ( $element_id, $styles, &$found ) {
            if ( ( $element['id'] ?? '' ) !== $element_id ) {
                return;
            }
            $found = true;

            $el_type     = $element['elType'] ?? 'widget';
            $widget_type = $element['widgetType'] ?? $el_type;

            // Normalize CSS properties to Elementor keys
            $normalized = $this->normalize_styles( $styles, $widget_type, $el_type );

            foreach ( $normalized as $key => $value ) {
                $element['settings'][ $key ] = $value;
            }
        } );

        if ( ! $found ) {
            return new WP_Error( 'element_not_found', 'Element ID not found: ' . $element_id, array( 'status' => 404 ) );
        }

        return $this->save_elementor_data( $post_id, $data );
    }

    /**
     * Bulk update multiple widgets' text and/or styles.
     *
     * @param int   $post_id Post ID.
     * @param array $changes Array of changes: [['widget_id' => ..., 'texts' => [...], 'styles' => [...]], ...]
     * @return array|WP_Error Results per widget.
     */
    public function bulk_update( $post_id, array $changes ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $change_map = array();
        foreach ( $changes as $change ) {
            $wid = $change['widget_id'] ?? '';
            if ( $wid ) {
                $change_map[ $wid ] = $change;
            }
        }

        $results = array();
        $data = $this->walk_elements_modify( $data, function ( &$element ) use ( $change_map, &$results ) {
            $wid = $element['id'] ?? '';
            if ( ! isset( $change_map[ $wid ] ) ) {
                return;
            }

            $change = $change_map[ $wid ];

            // Apply text changes
            if ( ! empty( $change['texts'] ) && is_array( $change['texts'] ) ) {
                foreach ( $change['texts'] as $field => $value ) {
                    $element['settings'][ sanitize_text_field( $field ) ] = wp_kses_post( $value );
                }
            }

            // Apply style changes (CSS-friendly or Elementor-native)
            if ( ! empty( $change['styles'] ) && is_array( $change['styles'] ) ) {
                $el_type     = $element['elType'] ?? 'widget';
                $widget_type = $element['widgetType'] ?? $el_type;
                $normalized  = $this->normalize_styles( $change['styles'], $widget_type, $el_type );
                foreach ( $normalized as $key => $value ) {
                    $element['settings'][ $key ] = $value;
                }
            }

            $results[ $wid ] = 'updated';
        } );

        // Check for missing widgets
        foreach ( $change_map as $wid => $change ) {
            if ( ! isset( $results[ $wid ] ) ) {
                $results[ $wid ] = 'not_found';
            }
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        return $results;
    }

    /* ──────────────────── TRANSLATE Operation ────────────────────────── */

    /**
     * Translate all Elementor page content to a target language.
     *
     * Extracts all translatable text, sends to AI Engine, creates
     * (or updates) WPML translation post with translated Elementor data.
     *
     * @param int    $post_id         Source post ID.
     * @param string $target_language Target language code.
     * @return array|WP_Error Translation result.
     */
    public function translate_page( $post_id, $target_language ) {
        // Validate Elementor data exists
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        // Auto-snapshot before translation for rollback support
        $this->create_snapshot( $post_id, sprintf( 'Pre-translation backup (%s)', $target_language ) );

        // Save pre-translation revision
        $revision_id = wp_save_post_revision( $post_id );
        if ( $revision_id ) {
            update_post_meta( $post_id, '_luwipress_pre_translation_revision', $revision_id );
        }

        // Extract translatable texts
        $translatable = $this->extract_translatable_text( $post_id );
        if ( is_wp_error( $translatable ) ) {
            return $translatable;
        }
        if ( empty( $translatable ) ) {
            return new WP_Error( 'no_text', 'No translatable text found on this page', array( 'status' => 400 ) );
        }

        // Build a flat structure for AI translation
        $texts_for_ai = array();
        $long_texts   = array(); // Texts > 3000 chars — translated individually via chunking
        foreach ( $translatable as $widget_id => $info ) {
            // Get the widget's title to detect duplicate <h1> in extra_title
            $widget_title = $info['texts']['ekit_heading_title'] ?? '';

            foreach ( $info['texts'] as $field => $text ) {
                if ( ! empty( trim( strip_tags( $text ) ) ) ) {
                    // Strip leading <h1> from ekit_heading_extra_title if it duplicates ekit_heading_title
                    if ( 'ekit_heading_extra_title' === $field && ! empty( $widget_title ) ) {
                        $text = preg_replace( '/^\s*<h1[^>]*>.*?<\/h1>\s*/is', '', $text, 1 );
                    }

                    if ( strlen( $text ) > 3000 ) {
                        $long_texts[] = array(
                            'widget_id' => $widget_id,
                            'field'     => $field,
                            'text'      => $text,
                        );
                    } else {
                        $texts_for_ai[] = array(
                            'widget_id' => $widget_id,
                            'field'     => $field,
                            'text'      => $text,
                        );
                    }
                }
            }
        }

        if ( empty( $texts_for_ai ) && empty( $long_texts ) ) {
            return new WP_Error( 'no_text', 'No non-empty translatable text found', array( 'status' => 400 ) );
        }

        // Get source language
        $source_language = apply_filters( 'wpml_default_language', get_locale() );
        $source_name     = self::LANG_NAMES[ $source_language ] ?? ucfirst( $source_language );
        $target_name     = self::LANG_NAMES[ $target_language ] ?? ucfirst( $target_language );

        $trans_map = array();

        // ── Short texts: batch translate via JSON ──
        if ( ! empty( $texts_for_ai ) ) {
            $prompt   = $this->build_translation_prompt( $texts_for_ai, $source_name, $target_name );
            $messages = LuwiPress_AI_Engine::build_messages( $prompt );

            $estimated_tokens = max( 4096, intval( array_sum( array_map( function ( $t ) { return strlen( $t['text'] ); }, $texts_for_ai ) ) / 3 ) );
            $max_tokens = min( $estimated_tokens, 16000 );

            $ai_result = LuwiPress_AI_Engine::dispatch_json( 'elementor-translation', $messages, array(
                'max_tokens' => $max_tokens,
            ) );

            if ( is_wp_error( $ai_result ) ) {
                LuwiPress_Logger::log( 'Elementor batch translation failed: ' . $ai_result->get_error_message(), 'error', array(
                    'post_id'  => $post_id,
                    'language' => $target_language,
                ) );
                // Don't return — still try long texts
            } else {
                $translations = $ai_result['translations'] ?? $ai_result;
                if ( is_array( $translations ) ) {
                    foreach ( $translations as $item ) {
                        $wid   = $item['widget_id'] ?? '';
                        $field = $item['field'] ?? '';
                        $text  = $item['text'] ?? '';
                        if ( $wid && $field && $text ) {
                            $trans_map[ $wid ][ $field ] = $text;
                        }
                    }
                }
            }
        }

        // ── Long texts: chunked translation (plain HTML, not JSON) ──
        foreach ( $long_texts as $lt ) {
            $translated = $this->translate_long_html( $lt['text'], $source_name, $target_name );
            if ( ! is_wp_error( $translated ) && ! empty( $translated ) ) {
                $trans_map[ $lt['widget_id'] ][ $lt['field'] ] = $translated;
                LuwiPress_Logger::log( sprintf( 'Elementor long text translated: widget=%s field=%s len=%d→%d', $lt['widget_id'], $lt['field'], strlen( $lt['text'] ), strlen( $translated ) ), 'info' );
            } else {
                LuwiPress_Logger::log( sprintf( 'Elementor long text FAILED: widget=%s field=%s: %s', $lt['widget_id'], $lt['field'], is_wp_error( $translated ) ? $translated->get_error_message() : 'empty' ), 'warning' );
            }
        }

        // If all AI calls failed, revert and bail
        if ( empty( $trans_map ) ) {
            $this->revert_to_pre_translation_revision( $post_id );
            return new WP_Error( 'translation_failed', 'All AI translation calls failed — reverted to pre-translation state', array( 'status' => 500 ) );
        }

        // Apply translations to a copy of the Elementor data
        $translated_data = $this->walk_elements_modify( $data, function ( &$element ) use ( $trans_map ) {
            $wid = $element['id'] ?? '';
            if ( ! isset( $trans_map[ $wid ] ) ) {
                return;
            }

            $widget_type = $element['widgetType'] ?? '';

            foreach ( $trans_map[ $wid ] as $field => $translated_text ) {
                // Handle repeater items (tabs, accordion, etc.)
                if ( strpos( $field, ':' ) !== false ) {
                    list( $items_key, $index, $sub_field ) = explode( ':', $field, 3 );
                    $index = intval( $index );
                    if ( isset( $element['settings'][ $items_key ][ $index ] ) ) {
                        $element['settings'][ $items_key ][ $index ][ $sub_field ] = $translated_text;
                    }
                } else {
                    $element['settings'][ $field ] = $translated_text;
                }
            }
        } );

        // Extract translated title from widget texts (for post_title update)
        $translated_title = '';
        $title_fields = array( 'title', 'ekit_heading_title', 'heading_title' );
        foreach ( $trans_map as $wid => $fields ) {
            foreach ( $title_fields as $tf ) {
                if ( ! empty( $fields[ $tf ] ) ) {
                    $translated_title = strip_tags( $fields[ $tf ] );
                    break 2;
                }
            }
        }

        // Create or update WPML translation
        $result = $this->save_translated_page( $post_id, $target_language, $translated_data );
        if ( is_wp_error( $result ) ) {
            $this->revert_to_pre_translation_revision( $post_id );
            return $result;
        }

        // Update post_title with translated title (for blog listings, SEO, etc.)
        if ( ! empty( $translated_title ) && ! empty( $result['translated_id'] ) ) {
            wp_update_post( array( 'ID' => $result['translated_id'], 'post_title' => $translated_title ) );
        }

        $count = count( $texts_for_ai ) + count( $long_texts );
        LuwiPress_Logger::log(
            sprintf( 'Elementor page #%d translated to %s (%d texts)', $post_id, strtoupper( $target_language ), $count ),
            'info',
            array( 'post_id' => $post_id, 'language' => $target_language, 'text_count' => $count )
        );

        return array(
            'status'          => 'completed',
            'post_id'         => $post_id,
            'translated_id'   => $result['translated_id'],
            'target_language' => $target_language,
            'texts_translated' => $count,
        );
    }

    /* ──────────────────── REST Callbacks ─────────────────────────────── */

    /**
     * GET /elementor/page/{post_id}
     */
    public function rest_get_page_data( $request ) {
        $post_id = intval( $request['post_id'] );
        $widgets = $this->get_widget_tree( $post_id );
        if ( is_wp_error( $widgets ) ) {
            return $widgets;
        }

        $post = get_post( $post_id );
        return rest_ensure_response( array(
            'post_id'    => $post_id,
            'title'      => $post->post_title,
            'post_type'  => $post->post_type,
            'widget_count' => count( $widgets ),
            'widgets'    => $widgets,
        ) );
    }

    /**
     * GET /elementor/outline/{post_id} — compact hierarchy with text previews.
     */
    public function rest_get_page_outline( $request ) {
        $post_id = intval( $request['post_id'] );
        $outline = $this->get_page_outline( $post_id );
        if ( is_wp_error( $outline ) ) {
            return $outline;
        }

        $post = get_post( $post_id );
        return rest_ensure_response( array(
            'post_id'   => $post_id,
            'title'     => $post->post_title,
            'post_type' => $post->post_type,
            'structure' => $outline,
        ) );
    }

    /**
     * POST /elementor/translate
     */
    public function rest_translate_page( $request ) {
        $post_id         = intval( $request->get_param( 'post_id' ) );
        $target_language = sanitize_text_field( $request->get_param( 'target_language' ) );

        if ( ! $post_id || ! $target_language ) {
            return new WP_Error( 'missing_params', 'post_id and target_language required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->translate_page( $post_id, $target_language ) );
    }

    /**
     * POST /elementor/translate-queue
     * Queue multiple Elementor pages for background translation.
     *
     * Body: { "post_ids": [31329, 31691, ...], "target_language": "fr" }
     *   or: { "post_type": "post", "target_language": "fr" } to auto-discover
     */
    public function rest_queue_translations( $request ) {
        $action = sanitize_text_field( $request->get_param( 'action' ) ?: '' );

        // Cancel all pending jobs
        if ( 'cancel' === $action ) {
            $cleared = wp_unschedule_hook( 'luwipress_elementor_translate_single' );
            LuwiPress_Logger::log( 'Elementor translate queue cancelled: ' . $cleared . ' jobs removed', 'info' );
            return rest_ensure_response( array( 'status' => 'cancelled', 'jobs_removed' => $cleared ) );
        }

        $target_language = sanitize_text_field( $request->get_param( 'target_language' ) );
        if ( ! $target_language ) {
            return new WP_Error( 'missing_params', 'target_language required', array( 'status' => 400 ) );
        }

        $post_ids  = $request->get_param( 'post_ids' );
        $post_type = sanitize_text_field( $request->get_param( 'post_type' ) ?: '' );

        if ( ! empty( $post_ids ) && is_array( $post_ids ) ) {
            $source_ids = array_map( 'absint', $post_ids );
        } elseif ( $post_type ) {
            // Auto-discover: find source-language Elementor posts that need translation
            $default_lang = apply_filters( 'wpml_default_language', get_locale() );
            $posts = get_posts( array(
                'post_type'   => $post_type,
                'post_status' => 'publish',
                'numberposts' => 100,
                'meta_key'    => '_elementor_data',
                'suppress_filters' => false,
            ) );
            $source_ids = array();
            foreach ( $posts as $p ) {
                // Check if translation already exists with own _elementor_data
                $translated_id = apply_filters( 'wpml_object_id', $p->ID, $post_type, false, $target_language );
                if ( $translated_id && $translated_id !== $p->ID ) {
                    $has_own = get_post_meta( $translated_id, '_luwipress_elementor_translated', true );
                    if ( $has_own ) {
                        continue; // Already translated
                    }
                }
                $source_ids[] = $p->ID;
            }
        } else {
            return new WP_Error( 'missing_params', 'post_ids array or post_type required', array( 'status' => 400 ) );
        }

        // Schedule each as a separate wp_cron event with staggered timing
        $queued = 0;
        $delay  = 5; // seconds between jobs
        foreach ( $source_ids as $source_id ) {
            $timestamp = time() + ( $queued * $delay );
            $scheduled = wp_schedule_single_event( $timestamp, 'luwipress_elementor_translate_single', array( $source_id, $target_language ) );
            if ( false !== $scheduled ) {
                $queued++;
            }
        }

        LuwiPress_Logger::log( 'Elementor translate queue: ' . $queued . ' jobs scheduled for ' . $target_language, 'info' );

        return rest_ensure_response( array(
            'status'          => 'queued',
            'queued'          => $queued,
            'target_language' => $target_language,
            'source_ids'      => $source_ids,
        ) );
    }

    /**
     * POST /elementor/widget
     */
    public function rest_set_widget_text( $request ) {
        $post_id   = intval( $request->get_param( 'post_id' ) );
        $widget_id = sanitize_text_field( $request->get_param( 'widget_id' ) );
        $texts     = $request->get_param( 'texts' );

        if ( ! $post_id || ! $widget_id || ! is_array( $texts ) ) {
            return new WP_Error( 'missing_params', 'post_id, widget_id, and texts required', array( 'status' => 400 ) );
        }

        $result = $this->set_widget_text( $post_id, $widget_id, $texts );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'status'    => 'updated',
            'post_id'   => $post_id,
            'widget_id' => $widget_id,
        ) );
    }

    /**
     * POST /elementor/style
     */
    public function rest_set_widget_style( $request ) {
        $post_id   = intval( $request->get_param( 'post_id' ) );
        $widget_id = sanitize_text_field( $request->get_param( 'widget_id' ) );
        $styles    = $request->get_param( 'styles' );

        if ( ! $post_id || ! $widget_id || ! is_array( $styles ) ) {
            return new WP_Error( 'missing_params', 'post_id, widget_id, and styles required', array( 'status' => 400 ) );
        }

        $result = $this->set_widget_style( $post_id, $widget_id, $styles );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'status'    => 'updated',
            'post_id'   => $post_id,
            'widget_id' => $widget_id,
        ) );
    }

    /**
     * POST /elementor/bulk-update
     */
    public function rest_bulk_update( $request ) {
        $post_id = intval( $request->get_param( 'post_id' ) );
        $changes = $request->get_param( 'changes' );

        if ( ! $post_id || ! is_array( $changes ) ) {
            return new WP_Error( 'missing_params', 'post_id and changes array required', array( 'status' => 400 ) );
        }

        $result = $this->bulk_update( $post_id, $changes );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'status'  => 'completed',
            'post_id' => $post_id,
            'results' => $result,
        ) );
    }

    /* ──────────── REST Callbacks — Structural Operations ───────────── */

    public function rest_add_widget( $request ) {
        $post_id      = intval( $request->get_param( 'post_id' ) );
        $container_id = sanitize_text_field( $request->get_param( 'container_id' ) );
        $widget_type  = sanitize_text_field( $request->get_param( 'widget_type' ) );
        $settings     = $request->get_param( 'settings' ) ?: array();
        $position     = intval( $request->get_param( 'position' ) ?? -1 );

        if ( ! $post_id || ! $container_id || ! $widget_type ) {
            return new WP_Error( 'missing_params', 'post_id, container_id, and widget_type required', array( 'status' => 400 ) );
        }

        $result = $this->add_widget( $post_id, $container_id, $widget_type, $settings, $position );
        return rest_ensure_response( is_wp_error( $result ) ? $result : $result );
    }

    public function rest_add_section( $request ) {
        $post_id  = intval( $request->get_param( 'post_id' ) );
        $position = intval( $request->get_param( 'position' ) ?? -1 );
        $settings = $request->get_param( 'settings' ) ?: array();

        if ( ! $post_id ) {
            return new WP_Error( 'missing_params', 'post_id required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->add_section( $post_id, $position, $settings ) );
    }

    public function rest_delete_element( $request ) {
        $post_id    = intval( $request->get_param( 'post_id' ) );
        $element_id = sanitize_text_field( $request->get_param( 'element_id' ) );

        if ( ! $post_id || ! $element_id ) {
            return new WP_Error( 'missing_params', 'post_id and element_id required', array( 'status' => 400 ) );
        }

        $result = $this->delete_element( $post_id, $element_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array( 'status' => 'deleted', 'element_id' => $element_id ) );
    }

    public function rest_move_element( $request ) {
        $post_id       = intval( $request->get_param( 'post_id' ) );
        $element_id    = sanitize_text_field( $request->get_param( 'element_id' ) );
        $target_parent = sanitize_text_field( $request->get_param( 'target_parent' ) ?? '' );
        $position      = intval( $request->get_param( 'position' ) ?? -1 );

        if ( ! $post_id || ! $element_id ) {
            return new WP_Error( 'missing_params', 'post_id and element_id required', array( 'status' => 400 ) );
        }

        $result = $this->move_element( $post_id, $element_id, $target_parent, $position );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array( 'status' => 'moved', 'element_id' => $element_id ) );
    }

    public function rest_clone_element( $request ) {
        $post_id    = intval( $request->get_param( 'post_id' ) );
        $element_id = sanitize_text_field( $request->get_param( 'element_id' ) );

        if ( ! $post_id || ! $element_id ) {
            return new WP_Error( 'missing_params', 'post_id and element_id required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->clone_element( $post_id, $element_id ) );
    }

    public function rest_set_custom_css( $request ) {
        $post_id    = intval( $request->get_param( 'post_id' ) );
        $element_id = sanitize_text_field( $request->get_param( 'element_id' ) ?? '' );
        $css        = $request->get_param( 'css' );

        if ( ! $post_id || ! $css ) {
            return new WP_Error( 'missing_params', 'post_id and css required', array( 'status' => 400 ) );
        }

        // Page-level CSS if no element_id
        if ( empty( $element_id ) ) {
            $result = $this->set_page_css( $post_id, $css );
        } else {
            $result = $this->set_custom_css( $post_id, $element_id, $css );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array( 'status' => 'updated', 'target' => $element_id ?: 'page' ) );
    }

    public function rest_set_responsive( $request ) {
        $post_id    = intval( $request->get_param( 'post_id' ) );
        $element_id = sanitize_text_field( $request->get_param( 'element_id' ) );
        $device     = sanitize_text_field( $request->get_param( 'device' ) );
        $styles     = $request->get_param( 'styles' );

        if ( ! $post_id || ! $element_id || ! $device || ! is_array( $styles ) ) {
            return new WP_Error( 'missing_params', 'post_id, element_id, device (mobile/tablet), and styles required', array( 'status' => 400 ) );
        }

        $result = $this->set_responsive_style( $post_id, $element_id, $device, $styles );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array( 'status' => 'updated', 'element_id' => $element_id, 'device' => $device ) );
    }

    public function rest_global_style( $request ) {
        $post_id     = intval( $request->get_param( 'post_id' ) );
        $widget_type = sanitize_text_field( $request->get_param( 'widget_type' ) );
        $styles      = $request->get_param( 'styles' );

        if ( ! $post_id || ! $widget_type || ! is_array( $styles ) ) {
            return new WP_Error( 'missing_params', 'post_id, widget_type, and styles required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->apply_global_style( $post_id, $widget_type, $styles ) );
    }

    /* ──────────── REST Callbacks — Sync, Audit, Revision ────────────── */

    public function rest_sync_styles( $request ) {
        $source_id  = intval( $request->get_param( 'source_id' ) );
        $target_ids = $request->get_param( 'target_ids' );
        $options    = $request->get_param( 'options' ) ?: array();

        if ( ! $source_id ) {
            return new WP_Error( 'missing_params', 'source_id required', array( 'status' => 400 ) );
        }

        // Auto-discover targets if not provided
        if ( empty( $target_ids ) ) {
            $translations = $this->get_translation_ids( $source_id );
            if ( empty( $translations ) ) {
                return new WP_Error( 'no_targets', 'No translation pages found and no target_ids provided', array( 'status' => 400 ) );
            }
            $target_ids = array_values( $translations );
        }

        if ( ! is_array( $target_ids ) ) {
            $target_ids = array( intval( $target_ids ) );
        }

        // Auto-snapshot targets before sync
        foreach ( $target_ids as $tid ) {
            $this->create_snapshot( intval( $tid ), 'Pre-sync backup' );
        }

        return rest_ensure_response( $this->sync_styles( $source_id, $target_ids, $options ) );
    }

    public function rest_audit_spacing( $request ) {
        $post_id = intval( $request['post_id'] );
        return rest_ensure_response( $this->audit_spacing( $post_id ) );
    }

    public function rest_create_snapshot( $request ) {
        $post_id = intval( $request->get_param( 'post_id' ) );
        $label   = sanitize_text_field( $request->get_param( 'label' ) ?? '' );

        if ( ! $post_id ) {
            return new WP_Error( 'missing_params', 'post_id required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->create_snapshot( $post_id, $label ) );
    }

    public function rest_rollback( $request ) {
        $post_id     = intval( $request->get_param( 'post_id' ) );
        $snapshot_id = sanitize_text_field( $request->get_param( 'snapshot_id' ) ?? '' );

        if ( ! $post_id ) {
            return new WP_Error( 'missing_params', 'post_id required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->rollback_snapshot( $post_id, $snapshot_id ) );
    }

    public function rest_list_snapshots( $request ) {
        $post_id = intval( $request['post_id'] );
        return rest_ensure_response( $this->list_snapshots( $post_id ) );
    }

    public function rest_auto_fix( $request ) {
        $post_id = intval( $request->get_param( 'post_id' ) );
        $options = $request->get_param( 'options' ) ?: array();

        if ( ! $post_id ) {
            return new WP_Error( 'missing_params', 'post_id required', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $this->auto_fix_spacing( $post_id, $options ) );
    }

    public function rest_responsive_audit( $request ) {
        $post_id = intval( $request['post_id'] );
        return rest_ensure_response( $this->audit_responsive( $post_id ) );
    }

    /* ──────────────────── Private Helpers ────────────────────────────── */

    /**
     * Recursively walk Elementor element tree and call a callback on each element.
     *
     * @param array    $elements Array of Elementor elements.
     * @param callable $callback Function receiving each element.
     */
    private function walk_elements( array $elements, callable $callback ) {
        foreach ( $elements as $element ) {
            $callback( $element );

            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $this->walk_elements( $element['elements'], $callback );
            }
        }
    }

    /**
     * Walk elements with parent tracking.
     *
     * @param array    $elements  Elements array.
     * @param string   $parent_id Parent element ID.
     * @param callable $callback  Function(element, parent_id).
     */
    private function walk_elements_with_path( array $elements, $parent_id, callable $callback ) {
        foreach ( $elements as $element ) {
            $callback( $element, $parent_id );

            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $this->walk_elements_with_path( $element['elements'], $element['id'] ?? '', $callback );
            }
        }
    }

    /**
     * Extract a human-readable style summary from settings.
     * Returns only the visually significant properties.
     *
     * @param array  $settings    Element settings.
     * @param string $widget_type Widget or element type.
     * @return array Summary of active styles.
     */
    private function summarize_styles( array $settings, $widget_type = '' ) {
        $summary = array();

        // Color keys to check (widget-specific + generic)
        $color_key = self::WIDGET_COLOR_KEYS[ $widget_type ] ?? 'title_color';
        if ( ! empty( $settings[ $color_key ] ) ) {
            $summary['color'] = $settings[ $color_key ];
        } elseif ( ! empty( $settings['color'] ) ) {
            $summary['color'] = $settings['color'];
        }

        // Background
        $bg_key = self::WIDGET_BG_KEYS[ $widget_type ] ?? 'background_color';
        if ( ! empty( $settings[ $bg_key ] ) ) {
            $summary['background'] = $settings[ $bg_key ];
        } elseif ( ! empty( $settings['background_color'] ) ) {
            $summary['background'] = $settings['background_color'];
        }

        // Typography
        $typo_keys = array(
            'font-size'      => 'typography_font_size',
            'font-weight'    => 'typography_font_weight',
            'font-family'    => 'typography_font_family',
            'text-transform' => 'typography_text_transform',
        );
        foreach ( $typo_keys as $css_name => $el_key ) {
            if ( ! empty( $settings[ $el_key ] ) ) {
                $val = $settings[ $el_key ];
                if ( is_array( $val ) && isset( $val['size'] ) ) {
                    $summary[ $css_name ] = $val['size'] . ( $val['unit'] ?? 'px' );
                } else {
                    $summary[ $css_name ] = $val;
                }
            }
        }

        // Spacing
        foreach ( array( '_padding' => 'padding', '_margin' => 'margin' ) as $el_key => $css_name ) {
            if ( ! empty( $settings[ $el_key ] ) && is_array( $settings[ $el_key ] ) ) {
                $d    = $settings[ $el_key ];
                $unit = $d['unit'] ?? 'px';
                $summary[ $css_name ] = ( $d['top'] ?? '0' ) . $unit . ' ' . ( $d['right'] ?? '0' ) . $unit . ' ' . ( $d['bottom'] ?? '0' ) . $unit . ' ' . ( $d['left'] ?? '0' ) . $unit;
            }
        }

        // Alignment
        if ( ! empty( $settings['align'] ) ) {
            $summary['text-align'] = $settings['align'];
        }

        return $summary;
    }

    /**
     * Recursively walk and modify Elementor element tree.
     *
     * @param array    $elements Array of Elementor elements.
     * @param callable $callback Function receiving element by reference.
     * @return array Modified elements.
     */
    private function walk_elements_modify( array $elements, callable $callback ) {
        foreach ( $elements as &$element ) {
            $callback( $element );

            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = $this->walk_elements_modify( $element['elements'], $callback );
            }
        }
        unset( $element );
        return $elements;
    }

    /**
     * Extract text fields from a widget's settings.
     *
     * @param string $widget_type Widget type name.
     * @param array  $settings    Widget settings.
     * @return array Associative array of field => text.
     */
    private function extract_widget_texts( $widget_type, array $settings ) {
        $texts = array();

        // Simple text fields
        $fields = self::TRANSLATABLE_WIDGETS[ $widget_type ] ?? array();
        foreach ( $fields as $field ) {
            if ( ! empty( $settings[ $field ] ) ) {
                $texts[ $field ] = $settings[ $field ];
            }
        }

        // Repeater items
        if ( isset( self::REPEATER_WIDGETS[ $widget_type ] ) ) {
            $config    = self::REPEATER_WIDGETS[ $widget_type ];
            $items_key = $config['items_key'];
            $sub_fields = $config['fields'];

            if ( ! empty( $settings[ $items_key ] ) && is_array( $settings[ $items_key ] ) ) {
                foreach ( $settings[ $items_key ] as $idx => $item ) {
                    foreach ( $sub_fields as $sf ) {
                        if ( ! empty( $item[ $sf ] ) ) {
                            // Use colon-separated key: items_key:index:field
                            $texts[ $items_key . ':' . $idx . ':' . $sf ] = $item[ $sf ];
                        }
                    }
                }
            }
        }

        return $texts;
    }

    /**
     * Normalize CSS-friendly style properties to Elementor setting keys.
     *
     * Accepts input like:
     *   {"font-size": "18px", "color": "#ff0000", "padding": "10px 20px"}
     * And converts to:
     *   {"typography_font_size": {"size": 18, "unit": "px"}, "title_color": "#ff0000", "_padding": {...}}
     *
     * @param array  $styles      Input styles (CSS or Elementor keys).
     * @param string $widget_type Widget type for context-aware key resolution.
     * @param string $el_type     Element type: 'widget', 'section', 'column'.
     * @return array Elementor-native settings.
     */
    public function normalize_styles( array $styles, $widget_type = '', $el_type = 'widget' ) {
        $normalized = array();

        foreach ( $styles as $key => $value ) {
            $css_key = strtolower( trim( $key ) );

            // Check CSS map
            if ( isset( self::CSS_MAP[ $css_key ] ) ) {
                $map    = self::CSS_MAP[ $css_key ];
                $el_key = $map['key'];

                // Widget-specific color overrides
                if ( $css_key === 'color' || $css_key === 'text-color' ) {
                    $el_key = self::WIDGET_COLOR_KEYS[ $widget_type ] ?? $el_key;
                } elseif ( $css_key === 'background-color' || $css_key === 'background' ) {
                    $el_key = self::WIDGET_BG_KEYS[ $widget_type ] ?? self::WIDGET_BG_KEYS[ $el_type ] ?? $el_key;
                }

                // Convert value based on format
                switch ( $map['format'] ) {
                    case 'size_unit':
                        $normalized[ $el_key ] = $this->parse_size_unit( $value );
                        break;
                    case 'dimension':
                        $normalized[ $el_key ] = $this->parse_dimension( $value );
                        break;
                    default:
                        $normalized[ $el_key ] = sanitize_text_field( $value );
                        break;
                }
            } elseif ( is_array( $value ) ) {
                // Already an Elementor-native complex value
                $normalized[ sanitize_text_field( $key ) ] = $this->sanitize_style_value( $value );
            } else {
                // Pass through as-is (already an Elementor key)
                $normalized[ sanitize_text_field( $key ) ] = sanitize_text_field( $value );
            }
        }

        return $normalized;
    }

    /**
     * Parse a CSS size value like "18px", "1.5em", or 18 into Elementor {size, unit} format.
     *
     * @param mixed $value CSS value string or number or already-array.
     * @return array {size: number, unit: string}
     */
    private function parse_size_unit( $value ) {
        if ( is_array( $value ) ) {
            return $this->sanitize_style_value( $value );
        }

        $value = trim( $value );

        if ( preg_match( '/^([\d.]+)\s*(px|em|rem|vw|vh|%)?$/i', $value, $m ) ) {
            return array(
                'size' => floatval( $m[1] ),
                'unit' => ! empty( $m[2] ) ? strtolower( $m[2] ) : 'px',
            );
        }

        // Numeric only
        if ( is_numeric( $value ) ) {
            return array( 'size' => floatval( $value ), 'unit' => 'px' );
        }

        // Fallback: pass as-is
        return array( 'size' => $value, 'unit' => 'px' );
    }

    /**
     * Parse a CSS dimension value like "10px", "10px 20px", "10px 20px 10px 20px"
     * into Elementor {top, right, bottom, left, unit, isLinked} format.
     *
     * @param mixed $value CSS shorthand or already-array.
     * @return array Elementor dimension object.
     */
    private function parse_dimension( $value ) {
        if ( is_array( $value ) ) {
            return $this->sanitize_style_value( $value );
        }

        $value = trim( $value );

        // Parse CSS shorthand: "10px" or "10px 20px" or "10px 20px 10px 20px"
        $parts = preg_split( '/\s+/', $value );
        $unit  = 'px';
        $nums  = array();

        foreach ( $parts as $part ) {
            if ( preg_match( '/^([\d.]+)\s*(px|em|rem|%|vw|vh)?$/i', $part, $m ) ) {
                $nums[] = $m[1];
                if ( ! empty( $m[2] ) ) {
                    $unit = strtolower( $m[2] );
                }
            } else {
                $nums[] = $part;
            }
        }

        $count = count( $nums );
        if ( $count === 1 ) {
            return array( 'top' => $nums[0], 'right' => $nums[0], 'bottom' => $nums[0], 'left' => $nums[0], 'unit' => $unit, 'isLinked' => true );
        } elseif ( $count === 2 ) {
            return array( 'top' => $nums[0], 'right' => $nums[1], 'bottom' => $nums[0], 'left' => $nums[1], 'unit' => $unit, 'isLinked' => false );
        } elseif ( $count === 3 ) {
            return array( 'top' => $nums[0], 'right' => $nums[1], 'bottom' => $nums[2], 'left' => $nums[1], 'unit' => $unit, 'isLinked' => false );
        } else {
            return array( 'top' => $nums[0] ?? '0', 'right' => $nums[1] ?? '0', 'bottom' => $nums[2] ?? '0', 'left' => $nums[3] ?? '0', 'unit' => $unit, 'isLinked' => false );
        }
    }

    /**
     * Sanitize a complex style value (e.g., {size: 18, unit: 'px'}).
     *
     * @param array $value Style value array.
     * @return array Sanitized value.
     */
    private function sanitize_style_value( array $value ) {
        $sanitized = array();
        foreach ( $value as $k => $v ) {
            $safe_key = sanitize_text_field( $k );
            if ( is_numeric( $v ) ) {
                $sanitized[ $safe_key ] = floatval( $v );
            } else {
                $sanitized[ $safe_key ] = sanitize_text_field( $v );
            }
        }
        return $sanitized;
    }

    /**
     * Save Elementor data back to post meta and trigger CSS regeneration.
     *
     * @param int   $post_id Post ID.
     * @param array $data    Elementor data array.
     * @return true|WP_Error
     */
    private function save_elementor_data( $post_id, array $data ) {
        $json = wp_json_encode( $data );
        if ( false === $json ) {
            return new WP_Error( 'json_error', 'Failed to encode Elementor data', array( 'status' => 500 ) );
        }

        update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );

        // Clear Elementor CSS cache so it regenerates
        $this->regenerate_css( $post_id );

        return true;
    }

    /**
     * Trigger Elementor CSS regeneration for a post.
     *
     * @param int $post_id Post ID.
     */
    public function regenerate_css( $post_id ) {
        // Delete cached CSS so Elementor rebuilds on next load
        delete_post_meta( $post_id, '_elementor_css' );
        delete_post_meta( $post_id, '_elementor_page_assets' );

        // If Elementor plugin is active, use its API to clear CSS
        if ( class_exists( '\Elementor\Plugin' ) ) {
            try {
                // Elementor 3.x: Post_CSS class
                if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
                    $post_css = new \Elementor\Core\Files\CSS\Post( $post_id );
                    $post_css->delete();
                } elseif ( method_exists( \Elementor\Plugin::$instance, 'files_manager' ) ) {
                    // Fallback: files_manager API (may vary by version)
                    $post_css = \Elementor\Plugin::$instance->files_manager->get( 'post', array( 'post_id' => $post_id ) );
                    if ( $post_css ) {
                        $post_css->delete();
                    }
                }
            } catch ( \Throwable $e ) {
                // Silently fail — CSS will regenerate on next page load anyway
                LuwiPress_Logger::log( 'Elementor CSS clear error: ' . $e->getMessage(), 'debug' );
            }

            // Also clear global cache
            try {
                if ( method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }
            } catch ( \Throwable $e ) {
                // Silently fail
            }
        }

        LuwiPress_Logger::log( 'Elementor CSS regenerated for post #' . $post_id, 'debug' );
    }

    /**
     * Build AI translation prompt for Elementor texts.
     *
     * @param array  $texts_for_ai Array of {widget_id, field, text}.
     * @param string $source_name  Source language name.
     * @param string $target_name  Target language name.
     * @return array ['system' => string, 'user' => string]
     */
    private function build_translation_prompt( array $texts_for_ai, $source_name, $target_name ) {
        $system = sprintf(
            'You are an expert web content translator. Translate Elementor page content from %1$s to %2$s.

RULES:
- Preserve ALL HTML tags, classes, and attributes exactly as they are.
- Preserve brand names and proper nouns as-is.
- Keep the same tone and style as the original.
- For button text, keep it concise and action-oriented.
- Do NOT translate URLs, CSS classes, or technical attributes.
- Return ONLY valid JSON.',
            $source_name,
            $target_name
        );

        $texts_json = wp_json_encode( $texts_for_ai, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

        $user = sprintf(
            'Translate the following Elementor widget texts from %1$s to %2$s.

Input (array of widget texts):
%3$s

Respond ONLY with valid JSON:
{"translations": [{"widget_id": "...", "field": "...", "text": "translated text"}, ...]}

IMPORTANT: Return exactly the same number of items. Keep widget_id and field values unchanged. Only translate the "text" values.',
            $source_name,
            $target_name,
            $texts_json
        );

        return apply_filters( 'luwipress_prompt_elementor_translation', array(
            'system' => $system,
            'user'   => $user,
        ), $texts_for_ai, $source_name, $target_name );
    }

    /**
     * Save translated Elementor page via WPML.
     *
     * Creates or updates a WPML translation post with the translated
     * _elementor_data and registers it in icl_translations.
     *
     * @param int    $source_id       Source post ID.
     * @param string $language        Target language code.
     * @param array  $translated_data Translated Elementor data array.
     * @return array|WP_Error Array with 'translated_id' or error.
     */

    /**
     * Translate a long HTML string by splitting into chunks at heading boundaries.
     *
     * @param string $html        Full HTML content.
     * @param string $source_lang Source language name.
     * @param string $target_lang Target language name.
     * @return string|WP_Error Translated HTML.
     */
    private function translate_long_html( $html, $source_lang, $target_lang ) {
        $chunks = $this->split_html_by_headings( $html, 3000 );

        LuwiPress_Logger::log( sprintf( 'Elementor long text: %d chunks from %d chars', count( $chunks ), strlen( $html ) ), 'info' );

        $translated_chunks = array();
        foreach ( $chunks as $i => $chunk ) {
            $prompt   = LuwiPress_Prompts::elementor_html_translation( $chunk, $source_lang, $target_lang );
            $messages = LuwiPress_AI_Engine::build_messages( $prompt );

            $max_tokens = max( 2048, intval( strlen( $chunk ) / 2 ) );
            $max_tokens = min( $max_tokens, 16000 );

            $result = LuwiPress_AI_Engine::dispatch( 'elementor-translation', $messages, array(
                'max_tokens' => $max_tokens,
                'timeout'    => 120,
            ) );

            if ( is_wp_error( $result ) ) {
                LuwiPress_Logger::log( 'Chunk ' . $i . ' failed: ' . $result->get_error_message(), 'warning' );
                $translated_chunks[] = $chunk; // Keep original on failure
            } else {
                $text = $result['content'] ?? '';
                $text = preg_replace( '/^```(?:html)?\s*/i', '', $text );
                $text = preg_replace( '/\s*```\s*$/', '', $text );
                $translated_chunks[] = trim( $text );
            }
        }

        return implode( '', $translated_chunks );
    }

    /**
     * Split HTML content at heading boundaries.
     *
     * @param string $html      Full HTML.
     * @param int    $max_chars Max chars per chunk.
     * @return array Chunks.
     */
    private function split_html_by_headings( $html, $max_chars = 3000 ) {
        $parts = preg_split( '/(?=<h[23][^>]*>)/i', $html );

        if ( empty( $parts ) || count( $parts ) <= 1 ) {
            $parts = preg_split( '/(?=<p[^>]*>)/i', $html );
        }

        if ( empty( $parts ) || count( $parts ) <= 1 ) {
            return array( $html );
        }

        $chunks  = array();
        $current = '';

        foreach ( $parts as $part ) {
            if ( strlen( $current ) + strlen( $part ) > $max_chars && ! empty( $current ) ) {
                $chunks[] = $current;
                $current  = $part;
            } else {
                $current .= $part;
            }
        }

        if ( ! empty( $current ) ) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function save_translated_page( $source_id, $language, array $translated_data ) {
        $source_post = get_post( $source_id );
        if ( ! $source_post ) {
            return new WP_Error( 'not_found', 'Source post not found', array( 'status' => 404 ) );
        }

        $post_type    = $source_post->post_type;
        $element_type = 'post_' . $post_type;
        $default_lang = apply_filters( 'wpml_default_language', get_locale() );

        // Check if WPML is available
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            // Without WPML, just save as a new post with a language suffix
            return $this->save_translated_page_standalone( $source_id, $language, $translated_data );
        }

        $trid = apply_filters( 'wpml_element_trid', null, $source_id, $element_type );
        if ( ! $trid ) {
            LuwiPress_Logger::log( 'No WPML trid for post #' . $source_id, 'warning' );
            return $this->save_translated_page_standalone( $source_id, $language, $translated_data );
        }

        // Check for existing translation — switch WPML context to find it
        do_action( 'wpml_switch_language', $language );
        $translated_id = apply_filters( 'wpml_object_id', $source_id, $post_type, false, $language );
        do_action( 'wpml_switch_language', $default_lang );

        // Also try direct DB lookup (WPML filter sometimes fails in cron context)
        if ( ! $translated_id || $translated_id === $source_id ) {
            global $wpdb;
            $translated_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT element_id FROM {$wpdb->prefix}icl_translations
                 WHERE trid = %d AND language_code = %s AND element_id != %d",
                $trid, $language, $source_id
            ) );
            $translated_id = $translated_id ? absint( $translated_id ) : null;
        }

        $translated_json = wp_json_encode( $translated_data );

        if ( $translated_id && $translated_id !== $source_id ) {
            // Update existing translation — don't overwrite title (keep translated title)
            $existing = get_post( $translated_id );
            $update_data = array(
                'ID'          => $translated_id,
                'post_status' => 'publish',
            );
            // Only set title if the existing post has no title or same as source (not yet translated)
            if ( ! $existing || empty( $existing->post_title ) || $existing->post_title === $source_post->post_title ) {
                $update_data['post_title'] = $source_post->post_title;
            }
            wp_update_post( $update_data );

            update_post_meta( $translated_id, '_elementor_data', wp_slash( $translated_json ) );
            update_post_meta( $translated_id, '_elementor_edit_mode', 'builder' );
            $this->regenerate_css( $translated_id );

            LuwiPress_Logger::log( 'Elementor WPML translation updated: #' . $translated_id, 'info' );
        } else {
            // Create new translation post
            $translated_id = wp_insert_post( array(
                'post_title'   => $source_post->post_title,
                'post_content' => '',
                'post_type'    => $post_type,
                'post_status'  => $source_post->post_status,
                'post_author'  => $source_post->post_author,
            ) );

            if ( is_wp_error( $translated_id ) ) {
                return $translated_id;
            }

            // Save Elementor data
            update_post_meta( $translated_id, '_elementor_data', wp_slash( $translated_json ) );
            update_post_meta( $translated_id, '_elementor_edit_mode', 'builder' );
            update_post_meta( $translated_id, '_elementor_version', get_post_meta( $source_id, '_elementor_version', true ) );

            // Copy _elementor_page_settings if present
            $page_settings = get_post_meta( $source_id, '_elementor_page_settings', true );
            if ( $page_settings ) {
                update_post_meta( $translated_id, '_elementor_page_settings', $page_settings );
            }

            // Register with WPML
            do_action( 'wpml_set_element_language_details', array(
                'element_id'           => $translated_id,
                'element_type'         => $element_type,
                'trid'                 => $trid,
                'language_code'        => $language,
                'source_language_code' => $default_lang,
            ) );

            // SQL fallback verification (same pattern as LuwiPress_Translation)
            global $wpdb;
            $linked = $wpdb->get_var( $wpdb->prepare(
                "SELECT translation_id FROM {$wpdb->prefix}icl_translations
                 WHERE element_id = %d AND element_type = %s",
                $translated_id,
                $element_type
            ) );

            if ( ! $linked ) {
                $wpdb->insert(
                    $wpdb->prefix . 'icl_translations',
                    array(
                        'element_type'         => $element_type,
                        'element_id'           => $translated_id,
                        'trid'                 => $trid,
                        'language_code'        => $language,
                        'source_language_code' => $default_lang,
                    ),
                    array( '%s', '%d', '%d', '%s', '%s' )
                );

                LuwiPress_Logger::log(
                    sprintf( 'WPML SQL fallback: Elementor post #%d registered (trid=%d, lang=%s)', $translated_id, $trid, $language ),
                    'warning'
                );
            }

            // Force publish
            wp_update_post( array( 'ID' => $translated_id, 'post_status' => 'publish' ) );

            $this->regenerate_css( $translated_id );

            LuwiPress_Logger::log( 'Elementor WPML translation created: #' . $translated_id, 'info' );
        }

        // Copy featured image from source
        $thumb_id = get_post_thumbnail_id( $source_id );
        if ( $thumb_id ) {
            set_post_thumbnail( $translated_id, $thumb_id );
        }

        // Mark as LuwiPress Elementor translated — prevents bypass filter
        update_post_meta( $translated_id, '_luwipress_elementor_translated', '1' );

        return array( 'translated_id' => $translated_id );
    }

    /**
     * Standalone save (no WPML) — creates a copy with language suffix.
     *
     * @param int    $source_id       Source post ID.
     * @param string $language        Target language code.
     * @param array  $translated_data Translated Elementor data.
     * @return array|WP_Error
     */
    private function save_translated_page_standalone( $source_id, $language, array $translated_data ) {
        $source_post = get_post( $source_id );

        $new_id = wp_insert_post( array(
            'post_title'   => $source_post->post_title . ' [' . strtoupper( $language ) . ']',
            'post_content' => '',
            'post_type'    => $source_post->post_type,
            'post_status'  => 'draft',
            'post_author'  => $source_post->post_author,
            'post_name'    => $source_post->post_name . '-' . $language,
        ) );

        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }

        $translated_json = wp_json_encode( $translated_data );
        update_post_meta( $new_id, '_elementor_data', wp_slash( $translated_json ) );
        update_post_meta( $new_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $new_id, '_elementor_version', get_post_meta( $source_id, '_elementor_version', true ) );
        update_post_meta( $new_id, '_luwipress_elementor_source', $source_id );
        update_post_meta( $new_id, '_luwipress_elementor_language', $language );

        $this->regenerate_css( $new_id );

        LuwiPress_Logger::log(
            sprintf( 'Elementor standalone translation created: #%d (%s, no WPML)', $new_id, strtoupper( $language ) ),
            'info'
        );

        return array( 'translated_id' => $new_id );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  STRUCTURAL OPERATIONS — Add / Delete / Move / Clone
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Add a new widget inside a target container (section, column, or container).
     *
     * @param int    $post_id      Post ID.
     * @param string $container_id Parent element ID to insert into.
     * @param string $widget_type  Widget type (heading, text-editor, button, image, etc.).
     * @param array  $settings     Widget settings (text + style).
     * @param int    $position     Insert position (0-based index, -1 = end).
     * @return array|WP_Error      New widget info or error.
     */
    public function add_widget( $post_id, $container_id, $widget_type, array $settings = array(), $position = -1 ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $new_id = $this->generate_element_id();

        $new_widget = array(
            'id'         => $new_id,
            'elType'     => 'widget',
            'widgetType' => sanitize_text_field( $widget_type ),
            'settings'   => $settings,
            'elements'   => array(),
        );

        $found = false;
        $data  = $this->walk_elements_modify( $data, function ( &$element ) use ( $container_id, $new_widget, $position, &$found ) {
            if ( ( $element['id'] ?? '' ) !== $container_id ) {
                return;
            }
            $found = true;

            if ( ! isset( $element['elements'] ) || ! is_array( $element['elements'] ) ) {
                $element['elements'] = array();
            }

            if ( $position < 0 || $position >= count( $element['elements'] ) ) {
                $element['elements'][] = $new_widget;
            } else {
                array_splice( $element['elements'], $position, 0, array( $new_widget ) );
            }
        } );

        if ( ! $found ) {
            return new WP_Error( 'container_not_found', 'Container element not found: ' . $container_id, array( 'status' => 404 ) );
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        LuwiPress_Logger::log(
            sprintf( 'Elementor: widget "%s" added to container %s in post #%d', $widget_type, $container_id, $post_id ),
            'info'
        );

        return array(
            'status'       => 'created',
            'widget_id'    => $new_id,
            'widget_type'  => $widget_type,
            'container_id' => $container_id,
            'position'     => $position,
        );
    }

    /**
     * Add a new section with a column to the page.
     *
     * @param int   $post_id  Post ID.
     * @param int   $position Insert position in root (-1 = end).
     * @param array $settings Section settings.
     * @return array|WP_Error New section + column info.
     */
    public function add_section( $post_id, $position = -1, array $settings = array() ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $section_id = $this->generate_element_id();
        $column_id  = $this->generate_element_id();

        $new_section = array(
            'id'       => $section_id,
            'elType'   => 'section',
            'settings' => $settings,
            'elements' => array(
                array(
                    'id'       => $column_id,
                    'elType'   => 'column',
                    'settings' => array( '_column_size' => 100 ),
                    'elements' => array(),
                ),
            ),
        );

        if ( $position < 0 || $position >= count( $data ) ) {
            $data[] = $new_section;
        } else {
            array_splice( $data, $position, 0, array( $new_section ) );
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        LuwiPress_Logger::log( sprintf( 'Elementor: section added to post #%d', $post_id ), 'info' );

        return array(
            'status'     => 'created',
            'section_id' => $section_id,
            'column_id'  => $column_id,
        );
    }

    /**
     * Delete an element (widget, section, or column) by its ID.
     *
     * @param int    $post_id    Post ID.
     * @param string $element_id Element ID to remove.
     * @return true|WP_Error
     */
    public function delete_element( $post_id, $element_id ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $found = false;
        $data  = $this->remove_element_recursive( $data, $element_id, $found );

        if ( ! $found ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_id, array( 'status' => 404 ) );
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        LuwiPress_Logger::log( sprintf( 'Elementor: element %s deleted from post #%d', $element_id, $post_id ), 'info' );
        return true;
    }

    /**
     * Move an element to a new position within its parent, or to a different container.
     *
     * @param int    $post_id        Post ID.
     * @param string $element_id     Element to move.
     * @param string $target_parent  New parent container ID (empty = same parent).
     * @param int    $position       New position index (0-based, -1 = end).
     * @return true|WP_Error
     */
    public function move_element( $post_id, $element_id, $target_parent = '', $position = -1 ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        // Step 1: Extract the element (remove from current position)
        $extracted = null;
        $data      = $this->extract_element_recursive( $data, $element_id, $extracted );

        if ( ! $extracted ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_id, array( 'status' => 404 ) );
        }

        // Step 2: Insert at new position
        if ( empty( $target_parent ) ) {
            // Move within root level (for sections)
            if ( $position < 0 || $position >= count( $data ) ) {
                $data[] = $extracted;
            } else {
                array_splice( $data, $position, 0, array( $extracted ) );
            }
        } else {
            $inserted = false;
            $data = $this->walk_elements_modify( $data, function ( &$element ) use ( $target_parent, $extracted, $position, &$inserted ) {
                if ( ( $element['id'] ?? '' ) !== $target_parent ) {
                    return;
                }
                $inserted = true;
                if ( ! isset( $element['elements'] ) ) {
                    $element['elements'] = array();
                }
                if ( $position < 0 || $position >= count( $element['elements'] ) ) {
                    $element['elements'][] = $extracted;
                } else {
                    array_splice( $element['elements'], $position, 0, array( $extracted ) );
                }
            } );

            if ( ! $inserted ) {
                return new WP_Error( 'target_not_found', 'Target parent not found: ' . $target_parent, array( 'status' => 404 ) );
            }
        }

        return $this->save_elementor_data( $post_id, $data );
    }

    /**
     * Clone (duplicate) an element with new IDs.
     *
     * @param int    $post_id    Post ID.
     * @param string $element_id Element to clone.
     * @return array|WP_Error Cloned element info.
     */
    public function clone_element( $post_id, $element_id ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $source  = null;
        $parent_id = null;
        $source_index = null;
        $this->find_element_with_context( $data, $element_id, $source, $parent_id, $source_index );

        if ( ! $source ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_id, array( 'status' => 404 ) );
        }

        // Deep clone with new IDs
        $clone = $this->deep_clone_element( $source );

        // Insert right after the source
        if ( $parent_id === null ) {
            // Root-level element (section)
            array_splice( $data, $source_index + 1, 0, array( $clone ) );
        } else {
            $data = $this->walk_elements_modify( $data, function ( &$element ) use ( $parent_id, $source_index, $clone ) {
                if ( ( $element['id'] ?? '' ) !== $parent_id ) {
                    return;
                }
                array_splice( $element['elements'], $source_index + 1, 0, array( $clone ) );
            } );
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        LuwiPress_Logger::log( sprintf( 'Elementor: element %s cloned as %s in post #%d', $element_id, $clone['id'], $post_id ), 'info' );

        return array(
            'status'    => 'cloned',
            'source_id' => $element_id,
            'clone_id'  => $clone['id'],
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  CUSTOM CSS & RESPONSIVE OVERRIDES
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Set custom CSS on an element (widget, section, or column).
     *
     * Uses Elementor's native custom_css setting. CSS selector `selector`
     * refers to the element's main wrapper.
     *
     * @param int    $post_id    Post ID.
     * @param string $element_id Element ID.
     * @param string $css        CSS rules (e.g., "selector { color: red; }")
     * @return true|WP_Error
     */
    public function set_custom_css( $post_id, $element_id, $css ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $found = false;
        $data  = $this->walk_elements_modify( $data, function ( &$element ) use ( $element_id, $css, &$found ) {
            if ( ( $element['id'] ?? '' ) !== $element_id ) {
                return;
            }
            $found = true;
            $element['settings']['custom_css'] = wp_strip_all_tags( $css );
        } );

        if ( ! $found ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_id, array( 'status' => 404 ) );
        }

        return $this->save_elementor_data( $post_id, $data );
    }

    /**
     * Set page-level custom CSS.
     *
     * @param int    $post_id Post ID.
     * @param string $css     Full CSS code.
     * @return true|WP_Error
     */
    public function set_page_css( $post_id, $css ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
        }

        $page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
        if ( ! is_array( $page_settings ) ) {
            $page_settings = array();
        }

        $page_settings['custom_css'] = wp_strip_all_tags( $css );
        update_post_meta( $post_id, '_elementor_page_settings', $page_settings );

        $this->regenerate_css( $post_id );

        LuwiPress_Logger::log( sprintf( 'Elementor: page CSS updated for post #%d', $post_id ), 'info' );
        return true;
    }

    /**
     * Set responsive style overrides for an element.
     *
     * @param int    $post_id    Post ID.
     * @param string $element_id Element ID.
     * @param string $device     Device: 'mobile' or 'tablet'.
     * @param array  $styles     CSS-friendly styles for that device.
     * @return true|WP_Error
     */
    public function set_responsive_style( $post_id, $element_id, $device, array $styles ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $suffix = ( $device === 'tablet' ) ? '_tablet' : '_mobile';

        $found = false;
        $data  = $this->walk_elements_modify( $data, function ( &$element ) use ( $element_id, $styles, $suffix, &$found ) {
            if ( ( $element['id'] ?? '' ) !== $element_id ) {
                return;
            }
            $found = true;

            $el_type     = $element['elType'] ?? 'widget';
            $widget_type = $element['widgetType'] ?? $el_type;

            // Normalize CSS to Elementor keys
            $normalized = $this->normalize_styles( $styles, $widget_type, $el_type );

            // Apply with device suffix
            foreach ( $normalized as $key => $value ) {
                $element['settings'][ $key . $suffix ] = $value;
            }
        } );

        if ( ! $found ) {
            return new WP_Error( 'element_not_found', 'Element not found: ' . $element_id, array( 'status' => 404 ) );
        }

        return $this->save_elementor_data( $post_id, $data );
    }

    /**
     * Apply a style to ALL widgets of a given type on a page.
     *
     * Example: "Make all headings blue" → apply_global_style($id, 'heading', ['color' => '#0000ff'])
     *
     * @param int    $post_id     Post ID.
     * @param string $widget_type Widget type to target (heading, button, text-editor, etc.).
     * @param array  $styles      CSS-friendly styles to apply.
     * @return array|WP_Error Results with count of affected widgets.
     */
    public function apply_global_style( $post_id, $widget_type, array $styles ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $count = 0;
        $data  = $this->walk_elements_modify( $data, function ( &$element ) use ( $widget_type, $styles, &$count ) {
            if ( 'widget' !== ( $element['elType'] ?? '' ) ) {
                return;
            }
            if ( ( $element['widgetType'] ?? '' ) !== $widget_type ) {
                return;
            }

            $normalized = $this->normalize_styles( $styles, $widget_type, 'widget' );
            foreach ( $normalized as $key => $value ) {
                $element['settings'][ $key ] = $value;
            }
            $count++;
        } );

        if ( $count === 0 ) {
            return new WP_Error( 'no_match', 'No widgets of type "' . $widget_type . '" found on this page', array( 'status' => 404 ) );
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        LuwiPress_Logger::log(
            sprintf( 'Elementor: global style applied to %d "%s" widgets in post #%d', $count, $widget_type, $post_id ),
            'info'
        );

        return array(
            'status'        => 'updated',
            'widget_type'   => $widget_type,
            'affected_count' => $count,
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  SYNC & AUDIT — Cross-page style sync, spacing audit
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Sync styles from a source Elementor page to one or more target pages.
     *
     * Matches widgets by position in the tree (same structure assumed —
     * e.g., source page and its WPML translations share the same layout).
     * Only style settings are copied; text content is left untouched.
     *
     * @param int       $source_id  Source post ID.
     * @param array|int $target_ids Target post ID(s).
     * @param array     $options    Options:
     *                              - 'include' => ['colors','typography','spacing','all'] (default: 'all')
     *                              - 'widget_types' => ['heading','button',...] (default: all types)
     * @return array|WP_Error Sync results.
     */
    public function sync_styles( $source_id, $target_ids, array $options = array() ) {
        if ( ! is_array( $target_ids ) ) {
            $target_ids = array( $target_ids );
        }

        // Read source page structure
        $source_data = $this->get_elementor_data( $source_id );
        if ( is_wp_error( $source_data ) ) {
            return $source_data;
        }

        // Build source style map: position path → style settings
        $include      = $options['include'] ?? 'all';
        $widget_types = $options['widget_types'] ?? array();
        $source_styles = array();
        $this->extract_styles_by_position( $source_data, '', $source_styles, $include, $widget_types );

        if ( empty( $source_styles ) ) {
            return new WP_Error( 'no_styles', 'No style settings found on source page', array( 'status' => 400 ) );
        }

        $results = array();

        foreach ( $target_ids as $target_id ) {
            $target_id = intval( $target_id );
            $target_data = $this->get_elementor_data( $target_id );
            if ( is_wp_error( $target_data ) ) {
                $results[ $target_id ] = array( 'error' => $target_data->get_error_message() );
                continue;
            }

            // Build target position map
            $target_positions = array();
            $this->map_positions( $target_data, '', $target_positions );

            // Apply source styles to target by matching positions
            $applied = 0;
            $target_data = $this->walk_elements_modify( $target_data, function ( &$element ) use ( $source_styles, $target_positions, &$applied ) {
                $el_id = $element['id'] ?? '';
                // Find this element's position path
                $pos_path = $target_positions[ $el_id ] ?? null;
                if ( ! $pos_path || ! isset( $source_styles[ $pos_path ] ) ) {
                    return;
                }

                // Merge source styles into target (preserving target text)
                foreach ( $source_styles[ $pos_path ] as $key => $value ) {
                    $element['settings'][ $key ] = $value;
                }
                $applied++;
            } );

            if ( $applied > 0 ) {
                $save = $this->save_elementor_data( $target_id, $target_data );
                if ( is_wp_error( $save ) ) {
                    $results[ $target_id ] = array( 'error' => $save->get_error_message() );
                    continue;
                }
            }

            $results[ $target_id ] = array(
                'status'  => 'synced',
                'applied' => $applied,
            );
        }

        LuwiPress_Logger::log(
            sprintf( 'Elementor: styles synced from #%d to %d target(s)', $source_id, count( $target_ids ) ),
            'info',
            array( 'source' => $source_id, 'targets' => $target_ids )
        );

        return array(
            'status'    => 'completed',
            'source_id' => $source_id,
            'results'   => $results,
        );
    }

    /**
     * Auto-discover WPML translation post IDs for a source page.
     *
     * @param int $source_id Source post ID.
     * @return array Array of [lang_code => post_id].
     */
    public function get_translation_ids( $source_id ) {
        $post      = get_post( $source_id );
        $post_type = $post ? $post->post_type : 'page';

        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            // Fallback: check our standalone translation meta
            global $wpdb;
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = '_luwipress_elementor_source' AND meta_value = %d",
                $source_id
            ) );
            $map = array();
            foreach ( $rows as $row ) {
                $lang = get_post_meta( $row->post_id, '_luwipress_elementor_language', true );
                if ( $lang ) {
                    $map[ $lang ] = intval( $row->post_id );
                }
            }
            return $map;
        }

        // WPML: get all translations
        $element_type = 'post_' . $post_type;
        $trid = apply_filters( 'wpml_element_trid', null, $source_id, $element_type );
        if ( ! $trid ) {
            return array();
        }

        $default_lang = apply_filters( 'wpml_default_language', get_locale() );

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT element_id, language_code FROM {$wpdb->prefix}icl_translations
             WHERE trid = %d AND element_type = %s AND language_code != %s AND element_id IS NOT NULL",
            $trid,
            $element_type,
            $default_lang
        ) );

        $map = array();
        foreach ( $rows as $row ) {
            $map[ $row->language_code ] = intval( $row->element_id );
        }
        return $map;
    }

    /**
     * Audit spacing consistency on an Elementor page.
     *
     * Checks all elements for padding/margin values and reports
     * inconsistencies, extreme values, and common issues.
     *
     * @param int $post_id Post ID.
     * @return array|WP_Error Audit report.
     */
    public function audit_spacing( $post_id ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $issues   = array();
        $all_spacings = array();

        $this->walk_elements( $data, function ( $element ) use ( &$issues, &$all_spacings ) {
            $el_id   = $element['id'] ?? '';
            $el_type = $element['elType'] ?? '';
            $wtype   = $element['widgetType'] ?? $el_type;
            $settings = $element['settings'] ?? array();

            foreach ( array( '_padding' => 'padding', '_margin' => 'margin' ) as $el_key => $css_name ) {
                // Check desktop, tablet, mobile
                foreach ( array( '' => 'desktop', '_tablet' => 'tablet', '_mobile' => 'mobile' ) as $suffix => $device ) {
                    $key = $el_key . $suffix;
                    if ( ! isset( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) {
                        continue;
                    }

                    $d    = $settings[ $key ];
                    $unit = $d['unit'] ?? 'px';
                    $vals = array(
                        'top'    => floatval( $d['top'] ?? 0 ),
                        'right'  => floatval( $d['right'] ?? 0 ),
                        'bottom' => floatval( $d['bottom'] ?? 0 ),
                        'left'   => floatval( $d['left'] ?? 0 ),
                    );

                    $record = array(
                        'element_id' => $el_id,
                        'el_type'    => $wtype,
                        'property'   => $css_name,
                        'device'     => $device,
                        'values'     => $vals,
                        'unit'       => $unit,
                    );
                    $all_spacings[] = $record;

                    // Check for issues
                    foreach ( $vals as $side => $v ) {
                        // Negative margins (intentional but worth flagging)
                        if ( $css_name === 'margin' && $v < 0 ) {
                            $issues[] = array(
                                'type'       => 'negative_margin',
                                'severity'   => 'warning',
                                'element_id' => $el_id,
                                'el_type'    => $wtype,
                                'device'     => $device,
                                'detail'     => sprintf( '%s-%s: %s%s', $css_name, $side, $v, $unit ),
                            );
                        }

                        // Excessive padding/margin (>100px)
                        if ( $unit === 'px' && abs( $v ) > 100 ) {
                            $issues[] = array(
                                'type'       => 'excessive_spacing',
                                'severity'   => 'warning',
                                'element_id' => $el_id,
                                'el_type'    => $wtype,
                                'device'     => $device,
                                'detail'     => sprintf( '%s-%s: %s%s (>100px)', $css_name, $side, $v, $unit ),
                            );
                        }
                    }

                    // Asymmetric horizontal padding (left != right)
                    if ( $css_name === 'padding' && $vals['left'] !== $vals['right'] ) {
                        $issues[] = array(
                            'type'       => 'asymmetric_padding',
                            'severity'   => 'info',
                            'element_id' => $el_id,
                            'el_type'    => $wtype,
                            'device'     => $device,
                            'detail'     => sprintf( 'left: %s%s, right: %s%s', $vals['left'], $unit, $vals['right'], $unit ),
                        );
                    }
                }
            }

            // Check for missing responsive spacing
            if ( isset( $settings['_padding'] ) && ! isset( $settings['_padding_mobile'] ) ) {
                $d = $settings['_padding'];
                $top = floatval( $d['top'] ?? 0 );
                $has_large = ( $d['unit'] ?? 'px' ) === 'px' && $top > 40;
                if ( $has_large ) {
                    $issues[] = array(
                        'type'       => 'missing_mobile_override',
                        'severity'   => 'suggestion',
                        'element_id' => $el_id,
                        'el_type'    => $wtype,
                        'device'     => 'mobile',
                        'detail'     => sprintf( 'Desktop padding %spx but no mobile override', $top ),
                    );
                }
            }
        } );

        // Collect spacing patterns for consistency check
        $padding_values = array();
        foreach ( $all_spacings as $s ) {
            if ( $s['property'] === 'padding' && $s['device'] === 'desktop' && $s['unit'] === 'px' ) {
                $key = $s['values']['top'] . '/' . $s['values']['right'] . '/' . $s['values']['bottom'] . '/' . $s['values']['left'];
                $padding_values[ $key ][] = $s['element_id'];
            }
        }

        // Flag outliers (uncommon padding patterns)
        $total_padded = array_sum( array_map( 'count', $padding_values ) );
        foreach ( $padding_values as $pattern => $ids ) {
            if ( count( $ids ) === 1 && $total_padded > 3 ) {
                $issues[] = array(
                    'type'       => 'inconsistent_padding',
                    'severity'   => 'info',
                    'element_id' => $ids[0],
                    'el_type'    => '',
                    'device'     => 'desktop',
                    'detail'     => sprintf( 'Unique padding pattern: %s (only element with this padding)', str_replace( '/', 'px ', $pattern ) . 'px' ),
                );
            }
        }

        // Sort by severity
        $severity_order = array( 'warning' => 0, 'suggestion' => 1, 'info' => 2 );
        usort( $issues, function ( $a, $b ) use ( $severity_order ) {
            return ( $severity_order[ $a['severity'] ] ?? 9 ) - ( $severity_order[ $b['severity'] ] ?? 9 );
        } );

        return array(
            'post_id'         => $post_id,
            'total_elements'  => count( $all_spacings ),
            'issue_count'     => count( $issues ),
            'issues'          => $issues,
            'spacing_summary' => $this->summarize_spacing_patterns( $padding_values ),
        );
    }

    /**
     * Extract style-only settings from elements, keyed by position path.
     */
    private function extract_styles_by_position( array $elements, $prefix, array &$styles, $include, array $widget_types ) {
        foreach ( $elements as $idx => $element ) {
            $pos_path = $prefix . '/' . $idx;
            $el_type  = $element['elType'] ?? '';
            $wtype    = $element['widgetType'] ?? '';
            $settings = $element['settings'] ?? array();

            // Filter by widget type if specified
            if ( ! empty( $widget_types ) && $el_type === 'widget' && ! in_array( $wtype, $widget_types, true ) ) {
                // Still recurse children
                if ( ! empty( $element['elements'] ) ) {
                    $this->extract_styles_by_position( $element['elements'], $pos_path, $styles, $include, $widget_types );
                }
                continue;
            }

            // Extract only style settings (skip text content)
            $style_settings = $this->filter_style_settings( $settings, $include );
            if ( ! empty( $style_settings ) ) {
                $styles[ $pos_path ] = $style_settings;
            }

            if ( ! empty( $element['elements'] ) ) {
                $this->extract_styles_by_position( $element['elements'], $pos_path, $styles, $include, $widget_types );
            }
        }
    }

    /**
     * Build position path map for all elements: element_id → position_path.
     */
    private function map_positions( array $elements, $prefix, array &$map ) {
        foreach ( $elements as $idx => $element ) {
            $pos_path = $prefix . '/' . $idx;
            $map[ $element['id'] ?? '' ] = $pos_path;

            if ( ! empty( $element['elements'] ) ) {
                $this->map_positions( $element['elements'], $pos_path, $map );
            }
        }
    }

    /**
     * Filter settings to only include style-related keys.
     *
     * @param array  $settings Raw element settings.
     * @param string $include  Filter: 'all', 'colors', 'typography', 'spacing'.
     * @return array Style-only settings.
     */
    private function filter_style_settings( array $settings, $include = 'all' ) {
        // Style key prefixes/patterns
        $style_patterns = array(
            'colors'     => array( 'color', 'background', '_background', 'border_color', 'overlay' ),
            'typography' => array( 'typography_', 'align', 'text_shadow' ),
            'spacing'    => array( '_padding', '_margin', 'border_radius', 'border_width', 'border_border' ),
        );

        // Text content keys to always exclude
        $text_keys = array( 'title', 'editor', 'text', 'tab_title', 'tab_content',
            'testimonial_content', 'testimonial_name', 'testimonial_job',
            'description', 'button_text', 'title_text', 'description_text',
            'heading', 'sub_heading', 'alert_title', 'alert_description',
            'inner_text', 'prefix', 'suffix', 'before_text', 'after_text',
            'highlighted_text', 'rotating_text', 'item_text', 'custom_css' );

        $selected_prefixes = array();
        if ( $include === 'all' ) {
            foreach ( $style_patterns as $group ) {
                $selected_prefixes = array_merge( $selected_prefixes, $group );
            }
        } elseif ( isset( $style_patterns[ $include ] ) ) {
            $selected_prefixes = $style_patterns[ $include ];
        } else {
            // Comma-separated
            foreach ( explode( ',', $include ) as $group ) {
                $group = trim( $group );
                if ( isset( $style_patterns[ $group ] ) ) {
                    $selected_prefixes = array_merge( $selected_prefixes, $style_patterns[ $group ] );
                }
            }
        }

        $filtered = array();
        foreach ( $settings as $key => $value ) {
            // Skip text keys
            if ( in_array( $key, $text_keys, true ) ) {
                continue;
            }
            // Skip repeater arrays (tabs, icon_list, features_list)
            if ( is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) ) {
                continue;
            }

            // Check if key matches any style prefix
            foreach ( $selected_prefixes as $prefix ) {
                if ( strpos( $key, $prefix ) === 0 || $key === $prefix ) {
                    $filtered[ $key ] = $value;
                    break;
                }
            }
        }

        return $filtered;
    }

    /**
     * Summarize padding patterns for the audit report.
     */
    private function summarize_spacing_patterns( array $padding_values ) {
        $summary = array();
        foreach ( $padding_values as $pattern => $ids ) {
            $parts = explode( '/', $pattern );
            $summary[] = array(
                'pattern'  => sprintf( '%spx %spx %spx %spx', $parts[0], $parts[1], $parts[2], $parts[3] ),
                'count'    => count( $ids ),
                'elements' => $ids,
            );
        }
        // Sort by count descending
        usort( $summary, function ( $a, $b ) { return $b['count'] - $a['count']; } );
        return $summary;
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  REVISION SYSTEM — Snapshot / Rollback
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Create a snapshot of the current Elementor data for a post.
     *
     * Stored as post meta: _luwipress_elementor_snapshots (serialized array).
     * Max 10 snapshots per post (oldest auto-pruned).
     *
     * @param int    $post_id Post ID.
     * @param string $label   Optional label/reason for the snapshot.
     * @return array Snapshot info.
     */
    public function create_snapshot( $post_id, $label = '' ) {
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( empty( $raw ) ) {
            return new WP_Error( 'no_data', 'No Elementor data to snapshot', array( 'status' => 400 ) );
        }

        $snapshots = get_post_meta( $post_id, '_luwipress_elementor_snapshots', true );
        if ( ! is_array( $snapshots ) ) {
            $snapshots = array();
        }

        $snapshot_id = substr( md5( wp_generate_uuid4() ), 0, 8 );
        $snapshot = array(
            'id'        => $snapshot_id,
            'label'     => $label ?: 'Snapshot',
            'timestamp' => current_time( 'c' ),
            'user'      => get_current_user_id(),
            'data'      => $raw, // Store raw JSON string
        );

        // Prepend (newest first)
        array_unshift( $snapshots, $snapshot );

        // Keep max 10
        if ( count( $snapshots ) > 10 ) {
            $snapshots = array_slice( $snapshots, 0, 10 );
        }

        update_post_meta( $post_id, '_luwipress_elementor_snapshots', $snapshots );

        LuwiPress_Logger::log(
            sprintf( 'Elementor: snapshot created for post #%d (%s)', $post_id, $label ?: $snapshot_id ),
            'info'
        );

        return array(
            'status'      => 'created',
            'snapshot_id' => $snapshot_id,
            'label'       => $snapshot['label'],
            'timestamp'   => $snapshot['timestamp'],
        );
    }

    /**
     * Rollback to a previous snapshot.
     *
     * @param int    $post_id     Post ID.
     * @param string $snapshot_id Snapshot ID (empty = most recent).
     * @return array|WP_Error Rollback result.
     */
    public function rollback_snapshot( $post_id, $snapshot_id = '' ) {
        $snapshots = get_post_meta( $post_id, '_luwipress_elementor_snapshots', true );
        if ( ! is_array( $snapshots ) || empty( $snapshots ) ) {
            return new WP_Error( 'no_snapshots', 'No snapshots available for this post', array( 'status' => 404 ) );
        }

        // Create a snapshot of current state before rolling back
        $this->create_snapshot( $post_id, 'Pre-rollback backup' );

        // Find the target snapshot
        $target = null;
        if ( empty( $snapshot_id ) ) {
            // Most recent (after the backup we just created, the original most recent is at index 1)
            $target = $snapshots[0] ?? null;
        } else {
            foreach ( $snapshots as $snap ) {
                if ( $snap['id'] === $snapshot_id ) {
                    $target = $snap;
                    break;
                }
            }
        }

        if ( ! $target || empty( $target['data'] ) ) {
            return new WP_Error( 'snapshot_not_found', 'Snapshot not found: ' . $snapshot_id, array( 'status' => 404 ) );
        }

        // Restore the data
        update_post_meta( $post_id, '_elementor_data', wp_slash( $target['data'] ) );
        $this->regenerate_css( $post_id );

        LuwiPress_Logger::log(
            sprintf( 'Elementor: rolled back post #%d to snapshot %s', $post_id, $target['id'] ),
            'info'
        );

        return array(
            'status'      => 'rolled_back',
            'snapshot_id' => $target['id'],
            'label'       => $target['label'],
            'timestamp'   => $target['timestamp'],
        );
    }

    /**
     * List available snapshots for a post.
     *
     * @param int $post_id Post ID.
     * @return array Snapshot list (without data payload).
     */
    public function list_snapshots( $post_id ) {
        $snapshots = get_post_meta( $post_id, '_luwipress_elementor_snapshots', true );
        if ( ! is_array( $snapshots ) ) {
            return array( 'post_id' => $post_id, 'snapshots' => array() );
        }

        // Return without the heavy data field
        $list = array();
        foreach ( $snapshots as $snap ) {
            $list[] = array(
                'id'        => $snap['id'],
                'label'     => $snap['label'],
                'timestamp' => $snap['timestamp'],
                'user'      => $snap['user'],
            );
        }

        return array( 'post_id' => $post_id, 'snapshots' => $list );
    }

    /**
     * Revert a post to its pre-translation revision.
     *
     * Uses the WP revision saved before translate_page() ran.
     *
     * @param int $post_id Post ID.
     * @return bool True if reverted, false if no revision found.
     */
    public function revert_to_pre_translation_revision( $post_id ) {
        $revision_id = get_post_meta( $post_id, '_luwipress_pre_translation_revision', true );
        if ( ! $revision_id ) {
            return false;
        }

        wp_restore_post_revision( $revision_id );
        delete_post_meta( $post_id, '_luwipress_pre_translation_revision' );

        LuwiPress_Logger::log( sprintf( 'Reverted post #%d to pre-translation revision #%d', $post_id, $revision_id ), 'info' );
        return true;
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  AUTO-FIX — Automatic spacing/style issue resolution
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Auto-fix common spacing/styling issues on a page.
     *
     * Runs audit, then automatically fixes what it can:
     * - Excessive spacing (>100px) → cap at reasonable values
     * - Missing mobile overrides for large paddings → add proportional mobile values
     * - Asymmetric horizontal padding → optionally equalize
     *
     * Always creates a snapshot before making changes.
     *
     * @param int   $post_id Post ID.
     * @param array $options Fix options:
     *                       - 'fix_excessive' => bool (default: true) — cap extreme values
     *                       - 'fix_mobile'    => bool (default: true) — add mobile overrides
     *                       - 'max_padding'   => int (default: 80) — max padding in px
     *                       - 'mobile_ratio'  => float (default: 0.5) — mobile = desktop * ratio
     * @return array|WP_Error Fix results.
     */
    public function auto_fix_spacing( $post_id, array $options = array() ) {
        $fix_excessive = $options['fix_excessive'] ?? true;
        $fix_mobile    = $options['fix_mobile'] ?? true;
        $max_padding   = intval( $options['max_padding'] ?? 80 );
        $mobile_ratio  = floatval( $options['mobile_ratio'] ?? 0.5 );

        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        // Snapshot before fixing
        $this->create_snapshot( $post_id, 'Pre-autofix backup' );

        $fixes = array();

        $data = $this->walk_elements_modify( $data, function ( &$element ) use (
            $fix_excessive, $fix_mobile, $max_padding, $mobile_ratio, &$fixes
        ) {
            $el_id   = $element['id'] ?? '';
            $settings = &$element['settings'];

            foreach ( array( '_padding', '_margin' ) as $prop ) {
                if ( ! isset( $settings[ $prop ] ) || ! is_array( $settings[ $prop ] ) ) {
                    continue;
                }

                $d    = &$settings[ $prop ];
                $unit = $d['unit'] ?? 'px';

                if ( $unit !== 'px' ) {
                    continue;
                }

                $changed = false;

                // Fix excessive values
                if ( $fix_excessive ) {
                    foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
                        $val = floatval( $d[ $side ] ?? 0 );
                        if ( abs( $val ) > $max_padding ) {
                            $old = $d[ $side ];
                            $d[ $side ] = strval( $val > 0 ? $max_padding : -$max_padding );
                            $changed = true;
                            $fixes[] = array(
                                'element_id' => $el_id,
                                'fix'        => 'capped_' . $prop,
                                'detail'     => sprintf( '%s: %s→%s', $side, $old, $d[ $side ] ),
                            );
                        }
                    }
                }

                // Add mobile overrides if desktop padding is large
                if ( $fix_mobile && $prop === '_padding' && ! isset( $settings['_padding_mobile'] ) ) {
                    $top = floatval( $d['top'] ?? 0 );
                    if ( $top > 30 ) {
                        $settings['_padding_mobile'] = array(
                            'top'      => strval( round( floatval( $d['top'] ?? 0 ) * $mobile_ratio ) ),
                            'right'    => strval( round( floatval( $d['right'] ?? 0 ) * $mobile_ratio ) ),
                            'bottom'   => strval( round( floatval( $d['bottom'] ?? 0 ) * $mobile_ratio ) ),
                            'left'     => strval( round( floatval( $d['left'] ?? 0 ) * $mobile_ratio ) ),
                            'unit'     => 'px',
                            'isLinked' => $d['isLinked'] ?? false,
                        );
                        $fixes[] = array(
                            'element_id' => $el_id,
                            'fix'        => 'added_mobile_padding',
                            'detail'     => sprintf(
                                'mobile: %spx %spx %spx %spx (%.0f%% of desktop)',
                                $settings['_padding_mobile']['top'],
                                $settings['_padding_mobile']['right'],
                                $settings['_padding_mobile']['bottom'],
                                $settings['_padding_mobile']['left'],
                                $mobile_ratio * 100
                            ),
                        );
                    }
                }
            }
        } );

        if ( empty( $fixes ) ) {
            return array(
                'status'    => 'no_changes',
                'post_id'   => $post_id,
                'message'   => 'No spacing issues found that need auto-fixing',
                'fix_count' => 0,
            );
        }

        $save = $this->save_elementor_data( $post_id, $data );
        if ( is_wp_error( $save ) ) {
            return $save;
        }

        LuwiPress_Logger::log(
            sprintf( 'Elementor: auto-fixed %d spacing issues in post #%d', count( $fixes ), $post_id ),
            'info'
        );

        return array(
            'status'    => 'fixed',
            'post_id'   => $post_id,
            'fix_count' => count( $fixes ),
            'fixes'     => $fixes,
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  RESPONSIVE AUDIT — Typography, spacing, overflow detection
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Audit responsive design issues on an Elementor page.
     *
     * Checks:
     * - Large font sizes without mobile overrides
     * - Fixed widths that could cause horizontal overflow
     * - Large padding/margin without mobile scaling
     * - Images without responsive sizing
     *
     * @param int $post_id Post ID.
     * @return array|WP_Error Responsive audit report.
     */
    public function audit_responsive( $post_id ) {
        $data = $this->get_elementor_data( $post_id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $issues = array();

        $this->walk_elements( $data, function ( $element ) use ( &$issues ) {
            $el_id   = $element['id'] ?? '';
            $el_type = $element['elType'] ?? '';
            $wtype   = $element['widgetType'] ?? $el_type;
            $s       = $element['settings'] ?? array();

            // 1. Large font-size without mobile override
            if ( ! empty( $s['typography_font_size'] ) && is_array( $s['typography_font_size'] ) ) {
                $size = floatval( $s['typography_font_size']['size'] ?? 0 );
                $unit = $s['typography_font_size']['unit'] ?? 'px';
                if ( $unit === 'px' && $size > 28 && empty( $s['typography_font_size_mobile'] ) ) {
                    $issues[] = array(
                        'type'       => 'large_font_no_mobile',
                        'severity'   => 'warning',
                        'element_id' => $el_id,
                        'el_type'    => $wtype,
                        'detail'     => sprintf( 'font-size: %spx — no mobile override, will be too large on phones', $size ),
                        'suggestion' => sprintf( '{"font-size": "%dpx"}', max( 16, round( $size * 0.6 ) ) ),
                    );
                }
            }

            // 2. Large padding without mobile override (reuse from spacing audit)
            if ( ! empty( $s['_padding'] ) && is_array( $s['_padding'] ) && empty( $s['_padding_mobile'] ) ) {
                $p = $s['_padding'];
                if ( ( $p['unit'] ?? 'px' ) === 'px' ) {
                    $max_val = max( floatval( $p['top'] ?? 0 ), floatval( $p['bottom'] ?? 0 ) );
                    if ( $max_val > 40 ) {
                        $issues[] = array(
                            'type'       => 'large_padding_no_mobile',
                            'severity'   => 'warning',
                            'element_id' => $el_id,
                            'el_type'    => $wtype,
                            'detail'     => sprintf( 'padding top/bottom: %spx — no mobile override', $max_val ),
                            'suggestion' => sprintf( '{"padding": "%dpx"}', round( $max_val * 0.5 ) ),
                        );
                    }
                }
            }

            // 3. Large margin without mobile override
            if ( ! empty( $s['_margin'] ) && is_array( $s['_margin'] ) && empty( $s['_margin_mobile'] ) ) {
                $m = $s['_margin'];
                if ( ( $m['unit'] ?? 'px' ) === 'px' ) {
                    $max_val = max( floatval( $m['top'] ?? 0 ), floatval( $m['bottom'] ?? 0 ) );
                    if ( $max_val > 50 ) {
                        $issues[] = array(
                            'type'       => 'large_margin_no_mobile',
                            'severity'   => 'info',
                            'element_id' => $el_id,
                            'el_type'    => $wtype,
                            'detail'     => sprintf( 'margin top/bottom: %spx — no mobile override', $max_val ),
                        );
                    }
                }
            }

            // 4. Fixed column width on sections (non-responsive)
            if ( $el_type === 'column' && ! empty( $s['_inline_size'] ) ) {
                $col_size = floatval( $s['_inline_size'] );
                if ( $col_size > 0 && $col_size < 50 && empty( $s['_inline_size_mobile'] ) ) {
                    $issues[] = array(
                        'type'       => 'narrow_column_no_mobile',
                        'severity'   => 'warning',
                        'element_id' => $el_id,
                        'el_type'    => 'column',
                        'detail'     => sprintf( 'Column width: %s%% — will be cramped on mobile without override', $col_size ),
                        'suggestion' => 'Set mobile column width to 100%',
                    );
                }
            }

            // 5. Heading/text with very large line-height (can cause mobile issues)
            if ( ! empty( $s['typography_line_height'] ) && is_array( $s['typography_line_height'] ) ) {
                $lh = $s['typography_line_height'];
                if ( ( $lh['unit'] ?? '' ) === 'px' && floatval( $lh['size'] ?? 0 ) > 60 && empty( $s['typography_line_height_mobile'] ) ) {
                    $issues[] = array(
                        'type'       => 'large_lineheight_no_mobile',
                        'severity'   => 'info',
                        'element_id' => $el_id,
                        'el_type'    => $wtype,
                        'detail'     => sprintf( 'line-height: %spx — no mobile override', $lh['size'] ),
                    );
                }
            }
        } );

        // Sort by severity
        $severity_order = array( 'warning' => 0, 'info' => 1 );
        usort( $issues, function ( $a, $b ) use ( $severity_order ) {
            return ( $severity_order[ $a['severity'] ] ?? 9 ) - ( $severity_order[ $b['severity'] ] ?? 9 );
        } );

        return array(
            'post_id'     => $post_id,
            'issue_count' => count( $issues ),
            'issues'      => $issues,
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  REST + Structural Helpers
     * ═══════════════════════════════════════════════════════════════════ */

    /* ──────────────────── Structural Helpers ─────────────────────────── */

    /**
     * Recursively remove an element from the tree.
     */
    private function remove_element_recursive( array $elements, $target_id, &$found ) {
        $result = array();
        foreach ( $elements as $element ) {
            if ( ( $element['id'] ?? '' ) === $target_id ) {
                $found = true;
                continue; // skip = delete
            }
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = $this->remove_element_recursive( $element['elements'], $target_id, $found );
            }
            $result[] = $element;
        }
        return $result;
    }

    /**
     * Extract (remove + return) an element from the tree.
     */
    private function extract_element_recursive( array $elements, $target_id, &$extracted ) {
        $result = array();
        foreach ( $elements as $element ) {
            if ( ( $element['id'] ?? '' ) === $target_id ) {
                $extracted = $element;
                continue;
            }
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = $this->extract_element_recursive( $element['elements'], $target_id, $extracted );
            }
            $result[] = $element;
        }
        return $result;
    }

    /**
     * Find an element and its parent context.
     */
    private function find_element_with_context( array $elements, $target_id, &$found, &$parent_id, &$index, $current_parent = null ) {
        foreach ( $elements as $idx => $element ) {
            if ( ( $element['id'] ?? '' ) === $target_id ) {
                $found     = $element;
                $parent_id = $current_parent;
                $index     = $idx;
                return;
            }
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $this->find_element_with_context( $element['elements'], $target_id, $found, $parent_id, $index, $element['id'] );
                if ( $found ) {
                    return;
                }
            }
        }
    }

    /**
     * Deep clone an element tree, assigning new unique IDs.
     */
    private function deep_clone_element( array $element ) {
        $element['id'] = $this->generate_element_id();

        if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
            foreach ( $element['elements'] as &$child ) {
                $child = $this->deep_clone_element( $child );
            }
            unset( $child );
        }

        return $element;
    }

    /**
     * Generate a unique Elementor element ID (7-char hex like Elementor uses).
     */
    private function generate_element_id() {
        return substr( md5( wp_generate_uuid4() ), 0, 7 );
    }

    /* ──────────────────── Static Helpers ─────────────────────────────── */

    /**
     * Check if a post has Elementor data.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public static function is_elementor_page( $post_id ) {
        return ! empty( get_post_meta( $post_id, '_elementor_data', true ) );
    }

    /**
     * Check if Elementor plugin is active.
     *
     * @return bool
     */
    public static function is_elementor_active() {
        return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' );
    }
}
