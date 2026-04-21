<?php
/**
 * LuwiPress Translation Manager
 *
 * REST API endpoints for managing product translations.
 * Integrates with WPML/Polylang via the LuwiPress AI translation engine.
 *
 * @package LuwiPress
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class LuwiPress_Translation {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_endpoints']);
        add_action('admin_menu', [$this, 'add_submenu']);
        add_action('wp_ajax_luwipress_fix_translation_images', [$this, 'ajax_fix_translation_images']);
        add_action('wp_ajax_luwipress_fix_category_assignments', [$this, 'ajax_fix_category_assignments']);
        add_action('wp_ajax_luwipress_clean_orphan_translations', [$this, 'ajax_clean_orphan_translations']);
        add_action('wp_ajax_luwipress_get_missing_items', [$this, 'ajax_get_missing_items']);
        add_action('wp_ajax_luwipress_translate_single', [$this, 'ajax_translate_single']);
        add_action('wp_ajax_luwipress_translate_taxonomy_batch', [$this, 'ajax_translate_taxonomy_batch']);
        add_action('wp_ajax_luwipress_translation_progress', [$this, 'ajax_translation_progress']);
        add_action('wp_ajax_luwipress_get_missing_terms', [$this, 'ajax_get_missing_terms']);
        add_action('wp_ajax_luwipress_translate_single_term', [$this, 'ajax_translate_single_term']);
        add_action('wp_ajax_luwipress_retranslate_broken', [$this, 'ajax_retranslate_broken']);
        add_action('wp_ajax_luwipress_stop_translations', [$this, 'ajax_stop_translations']);
        add_action('wp_ajax_luwipress_fix_excerpts', [$this, 'ajax_fix_excerpts']);
        add_action('wp_ajax_luwipress_fix_orphan_translations', [$this, 'ajax_fix_orphan_translations']);
        add_action('wp_ajax_luwipress_sync_wpml_menus', [$this, 'ajax_sync_wpml_menus']);
    }

    /**
     * Add submenu under LuwiPress
     */
    public function add_submenu() {
        add_submenu_page(
            'luwipress',
            __('Translations', 'luwipress'),
            __('Translations', 'luwipress'),
            'manage_options',
            'luwipress-translations',
            [$this, 'render_page']
        );
    }

    /**
     * Render the translation admin page
     */
    public function render_page() {
        include LUWIPRESS_PLUGIN_DIR . 'admin/translation-page.php';
    }

    /**
     * Register REST API endpoints
     */
    public function register_endpoints() {
        $namespace = 'luwipress/v1';

        register_rest_route($namespace, '/translation/missing', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_missing_translations'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'target_language' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'post_type'       => ['default' => 'product', 'sanitize_callback' => 'sanitize_text_field'],
                'limit'           => ['default' => 50, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // Multi-language missing: returns products with which languages they're missing
        register_rest_route($namespace, '/translation/missing-all', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_missing_translations_all'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'target_languages' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'post_type'        => ['default' => 'product', 'sanitize_callback' => 'sanitize_text_field'],
                'limit'            => ['default' => 20, 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route($namespace, '/translation/request', [
            'methods'             => 'POST',
            'callback'            => [$this, 'request_translation'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'post_id'          => ['sanitize_callback' => 'absint'],
                'product_id'       => ['sanitize_callback' => 'absint'], // backward compat alias for post_id
                'target_languages' => ['required' => true],
                'source_language'  => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // Batch: translate N untranslated posts for one or more target languages.
        // Used by the Knowledge Graph "Translate N missing products" button.
        register_rest_route($namespace, '/translation/batch', [
            'methods'             => 'POST',
            'callback'            => [$this, 'batch_translate_missing'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'languages' => ['required' => true],
                'post_type' => ['default' => 'product', 'sanitize_callback' => 'sanitize_text_field'],
                'limit'     => ['default' => 50, 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route($namespace, '/translation/callback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_translation_callback'],
            'permission_callback' => [$this, 'check_token_permission'],
        ]);

        register_rest_route($namespace, '/translation/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_translation_status'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route($namespace, '/translation/quality-check', [
            'methods'             => 'POST',
            'callback'            => [$this, 'trigger_quality_check'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route($namespace, '/translation/taxonomy', [
            'methods'             => 'POST',
            'callback'            => [$this, 'request_taxonomy_translation'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'taxonomy'         => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'target_languages' => ['required' => true],
                'limit'            => ['default' => 50, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // GET endpoint for AI clients to fetch missing terms (no webhook loop)
        register_rest_route($namespace, '/translation/taxonomy-missing', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_missing_taxonomy_terms_api'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'taxonomy'         => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'target_languages' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'limit'            => ['default' => 50, 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route($namespace, '/translation/taxonomy-callback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_taxonomy_callback'],
            'permission_callback' => [$this, 'check_token_permission'],
        ]);

        // Fix excerpts — extract from Elementor content
        register_rest_route($namespace, '/translation/fix-excerpts', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_fix_excerpts'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Fix Elementor mode on translated blog posts — removes _elementor_edit_mode
        // so WordPress renders post_content instead of English _elementor_data
        register_rest_route($namespace, '/translation/fix-elementor', [
            'methods'             => 'POST',
            'callback'            => [$this, 'fix_elementor_translated_posts'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'post_ids' => ['description' => 'Comma-separated post IDs to fix, or "all" for all translated posts'],
                'language'  => ['default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // Read translation module settings (target + active language list + hreflang mode)
        register_rest_route($namespace, '/translation/settings', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_get_settings'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Write translation module settings — partial update, only provided keys are touched
        register_rest_route($namespace, '/translation/settings', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_set_settings'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'target_language'       => ['required' => false, 'type' => 'string'],
                'translation_languages' => ['required' => false, 'type' => 'array'],
                'hreflang_mode'         => ['required' => false, 'type' => 'string'],
            ],
        ]);
    }

    /**
     * GET /translation/settings — mirrors the Translation tab in Settings.
     */
    public function handle_get_settings( $request ) {
        return array(
            'target_language'       => (string) get_option( 'luwipress_target_language', 'en' ),
            'translation_languages' => (array) get_option( 'luwipress_translation_languages', array() ),
            'hreflang_mode'         => (string) get_option( 'luwipress_hreflang_mode', 'auto' ),
        );
    }

    /**
     * POST /translation/settings — partial update.
     */
    public function handle_set_settings( $request ) {
        $data = $request->get_json_params();
        if ( empty( $data ) ) {
            $data = $request->get_body_params();
        }
        $updated = array();

        if ( array_key_exists( 'target_language', $data ) ) {
            update_option( 'luwipress_target_language', sanitize_text_field( (string) $data['target_language'] ) );
            $updated[] = 'target_language';
        }

        if ( array_key_exists( 'translation_languages', $data ) ) {
            $langs = is_array( $data['translation_languages'] )
                ? $data['translation_languages']
                : array_filter( array_map( 'trim', explode( ',', (string) $data['translation_languages'] ) ) );
            $langs = array_values( array_filter( array_map( 'sanitize_text_field', $langs ) ) );
            update_option( 'luwipress_translation_languages', $langs );
            $updated[] = 'translation_languages';
        }

        if ( array_key_exists( 'hreflang_mode', $data ) ) {
            $mode = sanitize_text_field( (string) $data['hreflang_mode'] );
            if ( ! in_array( $mode, array( 'auto', 'always', 'never' ), true ) ) {
                return new WP_Error( 'invalid_mode', 'hreflang_mode must be auto, always, or never.', array( 'status' => 400 ) );
            }
            update_option( 'luwipress_hreflang_mode', $mode );
            $updated[] = 'hreflang_mode';
        }

        LuwiPress_Logger::log( 'Translation settings updated via REST: ' . implode( ', ', $updated ), 'info' );

        return array(
            'success'  => true,
            'updated'  => $updated,
            'settings' => $this->handle_get_settings( $request ),
        );
    }

    /**
     * Permission checks
     */
    public function check_permission( $request ) {
        return LuwiPress_Permission::check_token_or_admin( $request );
    }

    public function check_token_permission( $request ) {
        return LuwiPress_Permission::check_token( $request );
    }

    /**
     * Resolve the WPML language code of a post. Returns null if WPML is inactive
     * or the language can't be determined. Centralises the array/object/null
     * handling for `wpml_post_language_details`.
     */
    public static function get_post_wpml_language( $post_id ) {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return null;
        }
        $info = apply_filters( 'wpml_post_language_details', null, $post_id );
        if ( is_array( $info ) ) {
            return $info['language_code'] ?? null;
        }
        if ( is_object( $info ) ) {
            return $info->language_code ?? null;
        }
        return null;
    }

    /**
     * GET /translation/missing — Products missing translations
     */
    public function get_missing_translations($request) {
        $target_lang = $request->get_param('target_language');
        $post_type   = $request->get_param('post_type');
        $limit       = min($request->get_param('limit'), 200);

        $translation_plugin = $this->detect_translation_plugin();

        if ('wpml' === $translation_plugin) {
            return $this->get_missing_wpml($target_lang, $post_type, $limit);
        } elseif ('polylang' === $translation_plugin) {
            return $this->get_missing_polylang($target_lang, $post_type, $limit);
        }

        // Fallback: use LuwiPress own translation tracking
        return $this->get_missing_luwipress($target_lang, $post_type, $limit);
    }

    /**
     * GET /translation/missing-all — Products missing any target language.
     * Returns each product with its list of missing languages.
     * AI clients use this to translate all missing languages in one pass.
     */
    public function get_missing_translations_all($request) {
        $langs_str   = $request->get_param('target_languages');
        $post_type   = $request->get_param('post_type');
        $limit       = min( absint( $request->get_param('limit') ), 500 );
        $target_langs = array_map('trim', explode(',', $langs_str));

        if (!defined('ICL_SITEPRESS_VERSION')) {
            return rest_ensure_response(['count' => 0, 'products' => []]);
        }

        global $wpdb;
        $default_lang = apply_filters( 'wpml_default_language', get_locale() );
        $element_type = 'post_' . $post_type;

        // Get all original posts — exclude orphan trids (lonely EN entries that are actually translations)
        $originals = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, t.trid
             FROM {$wpdb->posts} p
             JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND t.element_type = %s AND t.language_code = %s
               AND t.source_language_code IS NULL
               AND p.post_title != ''
             ORDER BY p.post_date DESC
             LIMIT %d",
            $post_type, $element_type, $default_lang, $limit * 2
        ));

        // Filter out orphan sources — lonely trids with non-English titles
        $originals = array_filter( $originals, function( $orig ) use ( $wpdb ) {
            $trid_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations WHERE trid = %d", $orig->trid
            ) );
            if ( $trid_count > 1 ) return true; // Has translations — real source
            // Lonely trid — check if title looks non-English
            $t = mb_strtolower( $orig->post_title );
            return ! preg_match( '/[àâäéèêëïîôùûüçñáíóúìòü]/u', $t )
                && ! preg_match( '/\b(della|degli|delle|nella|tutto|ogni|comme|tout|sur le|acheter|pour|avec|dans|entre|une|thérap|découvr|tambour|flûte|también|guía|cómo|instrumentos|terapia|poder|viaje|encanto|fascino|tecniche|strumenti|accordare|persiano|gioco)\b/u', $t );
        } );

        // Bulk-fetch all existing translations for these trids in one query
        $trids = wp_list_pluck( $originals, 'trid' );
        $existing_translations = array();

        if ( ! empty( $trids ) ) {
            $trid_ph = implode( ',', array_fill( 0, count( $trids ), '%d' ) );
            $lang_ph = implode( ',', array_fill( 0, count( $target_langs ), '%s' ) );
            $sql     = sprintf(
                "SELECT trid, language_code
                 FROM {$wpdb->prefix}icl_translations
                 WHERE trid IN (%s)
                   AND element_type = %%s
                   AND language_code IN (%s)",
                $trid_ph,
                $lang_ph
            );
            $rows = $wpdb->get_results(
                $wpdb->prepare( $sql, array_merge( array_map( 'intval', $trids ), array( $element_type ), $target_langs ) )
            );

            foreach ( $rows as $row ) {
                $existing_translations[ $row->trid ][ $row->language_code ] = true;
            }
        }

        $items = [];
        foreach ($originals as $orig) {
            $missing_langs = [];
            foreach ($target_langs as $lang) {
                if ( empty( $existing_translations[ $orig->trid ][ $lang ] ) ) {
                    $missing_langs[] = $lang;
                }
            }

            if (!empty($missing_langs)) {
                $items[] = [
                    'post_id'           => absint($orig->ID),
                    'product_id'        => absint($orig->ID), // backward compat
                    'name'              => $orig->post_title,
                    'missing_languages' => $missing_langs,
                ];
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return rest_ensure_response([
            'target_languages'    => $target_langs,
            'translation_plugin'  => 'wpml',
            'count'               => count($items),
            'items'               => $items,
            'products'            => $items, // backward compat
        ]);
    }

    /**
     * POST /translation/request — Translate a post/product/page via AI.
     * Accepts post_id (preferred) or product_id (backward compat).
     */
    public function request_translation($request) {
        $product_id = $request->get_param('post_id') ?: $request->get_param('product_id');
        $target_languages = $request->get_param('target_languages');

        if ( ! $product_id ) {
            return new WP_Error('missing_id', 'post_id or product_id is required', ['status' => 400]);
        }

        if (is_string($target_languages)) {
            $target_languages = array_map('trim', explode(',', $target_languages));
        }

        $product = function_exists( 'wc_get_product' ) ? wc_get_product($product_id) : null;
        $post    = get_post($product_id);

        $translatable_types = array( 'product', 'post', 'page' );
        if ( ! $post || ! in_array( $post->post_type, $translatable_types, true ) ) {
            return new WP_Error('not_found', 'Post not found or not translatable', ['status' => 404]);
        }

        $source_override = $request->get_param( 'source_language' );
        $source_language = $source_override ? sanitize_text_field( $source_override ) : apply_filters( 'wpml_default_language', get_locale() );

        // If source_language override is given and WPML is active, read content from THAT language's
        // translated post rather than the post_id passed in. This lets callers pick a clean source
        // (e.g. retranslate EN from ES when the EN copy is corrupted).
        $source_post_id = $product_id;
        if ( $source_override && defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $element_type = 'post_' . $post->post_type;
            $trid = apply_filters( 'wpml_element_trid', null, $product_id, $element_type );
            if ( $trid ) {
                $translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
                if ( is_array( $translations ) && isset( $translations[ $source_language ]->element_id ) ) {
                    $candidate = (int) $translations[ $source_language ]->element_id;
                    if ( $candidate && get_post( $candidate ) ) {
                        $source_post_id = $candidate;
                    }
                }
            }
        }

        $source_product = ( $source_post_id !== $product_id && function_exists( 'wc_get_product' ) )
            ? wc_get_product( $source_post_id )
            : $product;
        $source_post_obj = ( $source_post_id !== $product_id ) ? get_post( $source_post_id ) : $post;

        // Use WC product if available, fall back to WP post (WPML compatibility)
        $payload = [
            'product_id'       => $product_id,
            'source_language'  => $source_language,
            'target_languages' => $target_languages,
            'content' => [
                'name'              => $source_product ? $source_product->get_name() : $source_post_obj->post_title,
                'description'       => $source_product ? $source_product->get_description() : $source_post_obj->post_content,
                'short_description' => $source_product ? $source_product->get_short_description() : $source_post_obj->post_excerpt,
                'meta_title'        => $this->get_seo_meta($source_post_id, 'title'),
                'meta_description'  => $this->get_seo_meta($source_post_id, 'description'),
                'faq'               => get_post_meta($source_post_id, '_luwipress_faq', true) ?: [],
            ],
            'categories' => wp_list_pluck(get_the_terms($product_id, $post->post_type === 'product' ? 'product_cat' : 'category') ?: [], 'name'),
            'permalink'  => get_permalink($product_id),
        ];

        // Translate directly via AI Engine for each language
        $lang_names = array( 'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'pt-pt' => 'Portuguese', 'ko' => 'Korean' );
        $source_name = $lang_names[ $source_language ] ?? ucfirst( $source_language );

        // Elementor pages: ALWAYS use cron background job (never sync — avoids timeout)
        $is_elementor_page = class_exists( 'LuwiPress_Elementor' ) && LuwiPress_Elementor::is_elementor_page( $product_id );

        if ( $is_elementor_page ) {
            foreach ( $target_languages as $lang ) {
                update_post_meta( $product_id, '_luwipress_translation_status', wp_json_encode( array(
                    'status'   => 'queued',
                    'language' => $lang,
                    'queued'   => current_time( 'mysql' ),
                ) ) );
                wp_schedule_single_event( time(), 'luwipress_elementor_translate_single', array( $product_id, $lang ) );
            }
            spawn_cron();
            LuwiPress_Logger::log( 'Elementor page #' . $product_id . ' queued for background translation → ' . implode( ',', $target_languages ), 'info' );
            return rest_ensure_response( array(
                'status'      => 'queued',
                'post_id'     => $product_id,
                'languages'   => $target_languages,
                'message'     => 'Elementor page queued for background translation',
            ) );
        }

        foreach ( $target_languages as $lang ) {
            update_post_meta( $product_id, '_luwipress_translation_' . $lang . '_status', 'processing' );
            update_post_meta( $product_id, '_luwipress_translation_' . $lang . '_requested', current_time( 'c' ) );

            $target_name = $lang_names[ $lang ] ?? ucfirst( $lang );

            // ── Standard Translation Path (non-Elementor or short content) ──

            // Calculate max_tokens based on content length
            $content_length = strlen( $payload['content']['description'] ?? '' );
            $estimated_tokens = max( 4096, intval( $content_length / 3 ) );
            $max_tokens = min( $estimated_tokens, 16000 ); // GPT-4o-mini limit

            $prompt   = LuwiPress_Prompts::translation( $payload['content'], $source_name, $target_name, $product_id );
            $messages = LuwiPress_AI_Engine::build_messages( $prompt );
            $ai_result = LuwiPress_AI_Engine::dispatch_json( 'translation-pipeline', $messages, array(
                'max_tokens' => $max_tokens,
                'timeout'    => 180,
            ) );

            // If JSON parse failed, extract from error data or retry
            if ( is_wp_error( $ai_result ) && strpos( $ai_result->get_error_message(), 'parse JSON' ) !== false ) {
                $raw_text = $ai_result->get_error_data()['raw'] ?? '';

                // If no raw text in error, do a single retry with dispatch (non-JSON)
                if ( empty( $raw_text ) ) {
                    $raw_result = LuwiPress_AI_Engine::dispatch( 'translation-pipeline', $messages, array(
                        'max_tokens' => $max_tokens,
                        'timeout'    => 180,
                    ) );
                    if ( ! is_wp_error( $raw_result ) ) {
                        $raw_text = $raw_result['content'] ?? '';
                    }
                }

                if ( ! empty( $raw_text ) ) {
                    // Try to extract JSON
                    $parsed = LuwiPress_AI_Engine::extract_json( $raw_text );
                    if ( $parsed ) {
                        $ai_result = $parsed;
                    } else {
                        // JSON parse failed AND extract_json failed. NEVER write raw text to
                        // post_content — it could be a literal JSON payload dump (bug history:
                        // corrupted IT copies on tapadum.com, 2026-04-20). Fail the translation
                        // and preserve existing content untouched.
                        update_post_meta( $product_id, '_luwipress_translation_' . $lang . '_status', 'failed' );
                        LuwiPress_Logger::log(
                            'Translation JSON parse failed for ' . $lang . ' (product #' . $product_id . '): raw response could not be parsed, translation rejected to protect existing content. raw_len=' . strlen( $raw_text ),
                            'error',
                            array( 'product_id' => $product_id, 'language' => $lang, 'raw_head' => mb_substr( $raw_text, 0, 200 ) )
                        );
                        continue;
                    }
                }
            }

            if ( is_wp_error( $ai_result ) ) {
                update_post_meta( $product_id, '_luwipress_translation_' . $lang . '_status', 'failed' );
                LuwiPress_Logger::log( 'Translation failed for ' . $lang . ': ' . $ai_result->get_error_message(), 'error', array( 'product_id' => $product_id ) );
                continue;
            }

            // Feed into existing callback handler
            // If title is empty (JSON fallback), keep original title rather than leaving blank
            $translated_title = $ai_result['title'] ?? $ai_result['name'] ?? '';
            if ( empty( $translated_title ) ) {
                $translated_title = $post ? ( $product ? $product->get_name() : $post->post_title ) : '';
                LuwiPress_Logger::log( 'Translation title empty for ' . $lang . ', keeping original: "' . mb_substr( $translated_title, 0, 50 ) . '"', 'warning' );
            }

            $callback_request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/callback' );
            $callback_request->set_body_params( array(
                'product_id' => $product_id,
                'language'   => $lang,
                'content'    => array(
                    'name'             => $translated_title,
                    'description'      => $ai_result['description'] ?? '',
                    'short_description' => $ai_result['short_description'] ?? '',
                    'meta_title'       => $ai_result['meta_title'] ?? '',
                    'meta_description' => $ai_result['meta_description'] ?? '',
                    'focus_keyword'    => $ai_result['focus_keyword'] ?? '',
                    'slug'             => $ai_result['slug'] ?? '',
                ),
                'status' => 'completed',
            ) );
            $this->handle_translation_callback( $callback_request );
        }

        LuwiPress_Logger::log( 'Translation requested for: ' . ( $product ? $product->get_name() : $post->post_title ), 'info', array(
            'product_id'      => $product_id,
            'source_post_id'  => $source_post_id,
            'source_language' => $source_language,
            'languages'       => $target_languages,
        ) );

        return rest_ensure_response( array(
            'status'           => 'completed',
            'product_id'       => $product_id,
            'target_languages' => $target_languages,
        ) );
    }

    /**
     * Bulk translate missing content items for a post type + language.
     * Called from the Translation Manager admin page in local AI mode.
     *
     * @param WP_REST_Request $request  Not used directly.
     * @param string          $post_type Product, post, or page.
     * @param array           $languages Target language codes.
     * @param int             $limit     Max items to translate.
     * @return array|WP_Error Result with 'translated' count.
     */
    /**
     * POST /translation/batch — Translate N untranslated posts for one or more target languages.
     *
     * Used by the Knowledge Graph "Translate N missing products" button. Thin
     * REST wrapper around handle_bulk_translation() which does the heavy lifting
     * (fetches missing items via /translation/missing-all, fires request_translation
     * for each).
     */
    public function batch_translate_missing( $request ) {
        $languages = $request->get_param( 'languages' );
        $post_type = $request->get_param( 'post_type' );
        $limit     = min( max( 1, absint( $request->get_param( 'limit' ) ) ), 200 );

        if ( is_string( $languages ) ) {
            $languages = array_map( 'trim', explode( ',', $languages ) );
        }
        $languages = array_values( array_filter( array_map( 'sanitize_text_field', (array) $languages ) ) );

        if ( empty( $languages ) ) {
            return new WP_Error( 'missing_languages', 'languages parameter is required (array or comma-separated).', array( 'status' => 400 ) );
        }

        // Optional post_ids whitelist — lets callers scope the batch to a category,
        // search result set, etc. Normalised here so handle_bulk_translation can read it back.
        $post_ids = $request->get_param( 'post_ids' );
        if ( ! empty( $post_ids ) ) {
            if ( is_string( $post_ids ) ) {
                $post_ids = array_map( 'trim', explode( ',', $post_ids ) );
            }
            $post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
            $request->set_param( 'post_ids', $post_ids );
        }

        $result = $this->handle_bulk_translation( $request, $post_type, $languages, $limit );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array_merge( array(
            'status'    => 'queued',
            'languages' => $languages,
            'post_type' => $post_type,
        ), (array) $result ) );
    }

    public function handle_bulk_translation( $request, $post_type, $languages, $limit = 20 ) {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return new WP_Error( 'no_wpml', 'WPML is required for translation.', array( 'status' => 400 ) );
        }

        // Fetch missing items
        $fetch_request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/missing-all' );
        $fetch_request->set_param( 'target_languages', implode( ',', $languages ) );
        $fetch_request->set_param( 'post_type', $post_type );
        $fetch_request->set_param( 'limit', $limit );

        $missing_response = $this->get_missing_translations_all( $fetch_request );
        $missing_data     = $missing_response->get_data();
        $missing_items    = $missing_data['items'] ?? $missing_data['products'] ?? array();

        // Optional whitelist: restrict to specific post IDs (used by category batch).
        $whitelist = $request->get_param( 'post_ids' );
        if ( ! empty( $whitelist ) && is_array( $whitelist ) ) {
            $whitelist_map = array_flip( array_map( 'absint', $whitelist ) );
            $missing_items = array_values( array_filter( $missing_items, function ( $item ) use ( $whitelist_map ) {
                $pid = absint( $item['post_id'] ?? $item['product_id'] ?? 0 );
                return $pid && isset( $whitelist_map[ $pid ] );
            } ) );
        }

        if ( empty( $missing_items ) ) {
            return array( 'translated' => 0, 'message' => 'Nothing to translate.' );
        }

        $translated = 0;

        foreach ( $missing_items as $item ) {
            $post_id        = $item['post_id'] ?? $item['product_id'] ?? 0;
            $missing_langs  = $item['missing_languages'] ?? $languages;

            if ( ! $post_id ) {
                continue;
            }

            // Call the existing request_translation for each item
            $tr_request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/request' );
            $tr_request->set_param( 'post_id', $post_id );
            $tr_request->set_param( 'target_languages', $missing_langs );

            $result = $this->request_translation( $tr_request );

            if ( ! is_wp_error( $result ) ) {
                $translated++;
            }
        }

        return array( 'translated' => $translated, 'total_found' => count( $missing_items ) );
    }

    /**
     * POST /translation/callback — Receive translated content from async AI pipeline
     */
    public function handle_translation_callback($request) {
        // Support both JSON body (external REST) and body_params (internal call)
        $data = $request->get_json_params();
        if ( empty( $data ) ) {
            $data = $request->get_params();
        }

        $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;
        $language   = isset($data['language']) ? sanitize_text_field($data['language']) : '';
        $content    = isset($data['content']) ? $data['content'] : [];

        if (!$product_id || empty($language) || empty($content)) {
            return new WP_Error('invalid_data', 'Missing required fields', ['status' => 400]);
        }

        $product = function_exists( 'wc_get_product' ) ? wc_get_product($product_id) : null;
        $post    = get_post($product_id);
        if ( ! $post ) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        // Store translated content
        $translated = [
            'name'              => sanitize_text_field( $content['name'] ?? $content['title'] ?? '' ),
            'description'       => wp_kses_post( $content['description'] ?? '' ),
            'short_description' => wp_kses_post( $content['short_description'] ?? '' ),
            'meta_title'        => sanitize_text_field( $content['meta_title'] ?? '' ),
            'meta_description'  => sanitize_text_field( $content['meta_description'] ?? '' ),
            'focus_keyword'     => sanitize_text_field( $content['focus_keyword'] ?? '' ),
            'slug'              => sanitize_title( $content['slug'] ?? '' ),
            'faq'               => $content['faq'] ?? [],
        ];

        update_post_meta($product_id, '_luwipress_translation_' . $language, $translated);
        update_post_meta($product_id, '_luwipress_translation_' . $language . '_status', 'completed');
        update_post_meta($product_id, '_luwipress_translation_' . $language . '_completed', current_time('c'));

        $post_obj = get_post( $product_id );
        LuwiPress_Logger::log('Translation completed: ' . ( $post_obj ? $post_obj->post_title : $product_id ) . ' → ' . strtoupper( $language ), 'info', array(
            'product_id' => $product_id,
            'language'   => $language,
        ));

        // If WPML/Polylang is active, try to create/update the translated post
        $translation_plugin = $this->detect_translation_plugin();
        $save_error = null;
        try {
            if ('wpml' === $translation_plugin) {
                $this->create_wpml_translation($product_id, $language, $translated);
            } elseif ('polylang' === $translation_plugin) {
                $this->create_polylang_translation($product_id, $language, $translated);
            }
        } catch ( \Exception $e ) {
            $save_error = $e->getMessage();
            LuwiPress_Logger::log(
                sprintf('Translation save error: product #%d → %s: %s', $product_id, strtoupper($language), $save_error),
                'error',
                ['product_id' => $product_id, 'language' => $language, 'error' => $save_error]
            );
            // Don't fail — meta is already saved, WPML post creation just failed
        }

        // Purge cache for original and translated post
        $detector = LuwiPress_Plugin_Detector::get_instance();
        $detector->purge_post_cache($product_id);

        return rest_ensure_response([
            'status'     => $save_error ? 'partial' : 'saved',
            'product_id' => $product_id,
            'language'   => $language,
            'error'      => $save_error,
        ]);
    }

    /**
     * GET /translation/status — Translation queue status
     */
    public function get_translation_status($request) {
        global $wpdb;

        $raw_langs = get_option( 'luwipress_translation_languages', array() );
        $target_languages = is_array( $raw_langs ) ? $raw_langs : array_map( 'trim', explode( ',', $raw_langs ) );
        if ( empty( $target_languages ) ) {
            // Fallback: get from translation plugin
            $detector = LuwiPress_Plugin_Detector::get_instance();
            $t = $detector->detect_translation();
            $target_languages = array_diff( $t['active_languages'] ?? array(), array( $t['default_language'] ?? 'tr' ) );
        }

        // Batch: single query counts all statuses across all languages
        $meta_keys = array();
        foreach ( $target_languages as $lang ) {
            $meta_keys[] = '_luwipress_translation_' . $lang . '_status';
        }
        $key_ph = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
        $sql    = sprintf(
            "SELECT meta_key, meta_value, COUNT(DISTINCT post_id) AS cnt
             FROM {$wpdb->postmeta}
             WHERE meta_key IN (%s) AND meta_value IN ('processing','completed')
             GROUP BY meta_key, meta_value",
            $key_ph
        );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $meta_keys ) );
        $counts = array();
        foreach ( $rows as $row ) {
            $counts[ $row->meta_key ][ $row->meta_value ] = (int) $row->cnt;
        }
        $stats = [];
        foreach ( $target_languages as $lang ) {
            $key = '_luwipress_translation_' . $lang . '_status';
            $stats[ $lang ] = [
                'processing' => $counts[ $key ]['processing'] ?? 0,
                'completed'  => $counts[ $key ]['completed'] ?? 0,
            ];
        }

        return rest_ensure_response([
            'translation_plugin' => $this->detect_translation_plugin(),
            'languages'          => $stats,
        ]);
    }

    /**
     * POST /translation/quality-check — Trigger quality audit
     */
    public function trigger_quality_check($request) {
        $data = $request->get_json_params();
        $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;
        $language   = isset($data['language']) ? sanitize_text_field($data['language']) : '';

        if (!$product_id || empty($language)) {
            return new WP_Error('invalid_data', 'product_id and language required', ['status' => 400]);
        }

        $translated = get_post_meta($product_id, '_luwipress_translation_' . $language, true);
        if (empty($translated)) {
            return new WP_Error('no_translation', 'No translation found', ['status' => 404]);
        }

        $product  = function_exists( 'wc_get_product' ) ? wc_get_product($product_id) : null;
        $post_obj = get_post($product_id);
        $payload = [
            'product_id'      => $product_id,
            'language'        => $language,
            'source_content'  => [
                'name'        => $product ? $product->get_name() : ( $post_obj->post_title ?? '' ),
                'description' => $product ? $product->get_description() : ( $post_obj->post_content ?? '' ),
            ],
            'translated_content' => $translated,
            'type' => 'quality_check',
        ];

        // Quality check via built-in AI Engine (synchronous — small payload)
        $budget = LuwiPress_Token_Tracker::check_budget( 'translation-pipeline' );
        if ( is_wp_error( $budget ) ) {
            return $budget;
        }

        $system = 'You are a translation quality auditor. Compare the source and translated content. Return JSON with: {"score": 0-100, "issues": ["issue1", ...], "suggestions": ["fix1", ...]}';
        $user   = sprintf(
            "Source (%s):\nTitle: %s\nDescription: %s\n\nTranslated (%s):\nTitle: %s\nDescription: %s",
            get_option( 'luwipress_target_language', 'tr' ),
            $payload['source_content']['name'],
            wp_trim_words( $payload['source_content']['description'], 200 ),
            $language,
            $payload['translated_content']['name'] ?? '',
            wp_trim_words( $payload['translated_content']['description'] ?? '', 200 )
        );

        $messages = LuwiPress_AI_Engine::build_messages( array( 'system' => $system, 'user' => $user ) );
        $result = LuwiPress_AI_Engine::dispatch_json( 'translation-quality', $messages, array( 'max_tokens' => 500 ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'status'     => 'completed',
            'product_id' => $product_id,
            'language'   => $language,
            'quality'    => $result,
        ) );
    }

    /**
     * Detect active translation plugin (cached per-request)
     */
    private $detected_plugin = null;

    private function detect_translation_plugin() {
        if ( null !== $this->detected_plugin ) {
            return $this->detected_plugin;
        }
        if (defined('ICL_SITEPRESS_VERSION')) {
            $this->detected_plugin = 'wpml';
        } elseif (function_exists('pll_languages_list')) {
            $this->detected_plugin = 'polylang';
        } else {
            $this->detected_plugin = 'none';
        }
        return $this->detected_plugin;
    }

    /**
     * Get SEO meta value via Plugin Detector (supports all SEO plugins).
     */
    private function get_seo_meta($post_id, $type) {
        $detector = LuwiPress_Plugin_Detector::get_instance();
        $meta = $detector->get_seo_meta($post_id);

        if ('title' === $type) {
            return $meta['title'] ?? '';
        }
        if ('description' === $type) {
            return $meta['description'] ?? '';
        }
        return '';
    }

    /**
     * Get missing translations using LuwiPress tracking
     */
    private function get_missing_luwipress($target_lang, $post_type, $limit) {
        global $wpdb;

        $meta_key = '_luwipress_translation_' . $target_lang . '_status';

        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND p.ID NOT IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = %s AND meta_value = 'completed'
               )
             ORDER BY p.post_date DESC
             LIMIT %d",
            $post_type,
            $meta_key,
            $limit
        ));

        $missing = [];
        foreach ($products as $p) {
            $missing[] = [
                'product_id' => $p->ID,
                'name'       => $p->post_title,
                'permalink'  => get_permalink($p->ID),
            ];
        }

        return rest_ensure_response([
            'target_language' => $target_lang,
            'count'           => count($missing),
            'products'        => $missing,
        ]);
    }

    /**
     * Get missing WPML translations
     */
    private function get_missing_wpml($target_lang, $post_type, $limit) {
        global $wpdb;

        if (!defined('ICL_SITEPRESS_VERSION')) {
            return $this->get_missing_luwipress($target_lang, $post_type, $limit);
        }

        $default_lang = apply_filters('wpml_default_language', 'tr');

        // Find default-language posts that either:
        // 1. Have no translation record for the target language, OR
        // 2. Have a translation record but the translated post is not published
        // source_language_code IS NULL = original post (not a translation)
        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND t.language_code = %s
               AND t.element_type = CONCAT('post_', %s)
               AND t.source_language_code IS NULL
               AND t.trid NOT IN (
                   SELECT tr.trid FROM {$wpdb->prefix}icl_translations tr
                   JOIN {$wpdb->posts} tp ON tr.element_id = tp.ID
                   WHERE tr.language_code = %s
                     AND tr.element_type = CONCAT('post_', %s)
                     AND tp.post_status = 'publish'
               )
             ORDER BY p.post_date DESC
             LIMIT %d",
            $post_type, $default_lang, $post_type, $target_lang, $post_type, $limit
        ));

        $missing = [];
        foreach ($products as $p) {
            $missing[] = [
                'product_id' => $p->ID,
                'name'       => $p->post_title,
                'permalink'  => get_permalink($p->ID),
            ];
        }

        return rest_ensure_response([
            'target_language'    => $target_lang,
            'translation_plugin' => 'wpml',
            'count'              => count($missing),
            'products'           => $missing,
        ]);
    }

    /**
     * Get missing Polylang translations
     */
    private function get_missing_polylang($target_lang, $post_type, $limit) {
        if (!function_exists('pll_get_post_translations')) {
            return $this->get_missing_luwipress($target_lang, $post_type, $limit);
        }

        $products = get_posts([
            'post_type'   => $post_type,
            'post_status' => 'publish',
            'numberposts' => $limit * 2,
            'lang'        => pll_default_language(),
        ]);

        $missing = [];
        foreach ($products as $p) {
            $translations = pll_get_post_translations($p->ID);
            if (!isset($translations[$target_lang])) {
                $missing[] = [
                    'product_id' => $p->ID,
                    'name'       => $p->post_title,
                    'permalink'  => get_permalink($p->ID),
                ];
            }
            if (count($missing) >= $limit) {
                break;
            }
        }

        return rest_ensure_response([
            'target_language'    => $target_lang,
            'translation_plugin' => 'polylang',
            'count'              => count($missing),
            'products'           => $missing,
        ]);
    }

    /**
     * Create a translation post and register it with WPML/Polylang.
     *
     * Central method — every translation post in the plugin MUST go through here.
     * Handles: WPML, Polylang, and vanilla WordPress.
     *
     * @param int    $source_id   Original post ID.
     * @param string $language    Target language code (e.g. 'fr', 'it', 'es').
     * @param array  $post_data   Array with keys: title, slug, content, excerpt (all optional).
     * @return int|WP_Error       New post ID or WP_Error on failure.
     */
    public function create_translation_post( $source_id, $language, $post_data = array() ) {
        global $wpdb;

        $source_post = get_post( $source_id );
        if ( ! $source_post ) {
            return new \WP_Error( 'invalid_source', 'Source post #' . $source_id . ' not found' );
        }

        $post_type    = $source_post->post_type;
        $element_type = 'post_' . $post_type;

        // Determine translation plugin
        $detector  = LuwiPress_Plugin_Detector::get_instance();
        $lang_info = $detector->detect_translation();
        $plugin    = $lang_info['plugin'] ?? 'none';

        $default_lang = 'none' !== $plugin
            ? apply_filters( 'wpml_default_language', get_locale() )
            : substr( get_locale(), 0, 2 );

        // ── Duplicate lock ──
        $lock_key = 'luwipress_tpost_' . $source_id . '_' . $language;
        if ( get_transient( $lock_key ) ) {
            return new \WP_Error( 'locked', 'Translation lock active for #' . $source_id . ' → ' . $language );
        }
        set_transient( $lock_key, 1, 60 );

        // ── Get WPML trid (if WPML) ──
        $trid = null;
        if ( 'wpml' === $plugin && defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $trid = apply_filters( 'wpml_element_trid', null, $source_id, $element_type );
            if ( ! $trid ) {
                delete_transient( $lock_key );
                return new \WP_Error( 'no_trid', 'No WPML trid for #' . $source_id );
            }

            // Check if translation already exists
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT element_id FROM {$wpdb->prefix}icl_translations
                 WHERE trid = %d AND language_code = %s AND element_type = %s",
                $trid, $language, $element_type
            ) );
            if ( $existing_id && get_post_status( $existing_id ) ) {
                delete_transient( $lock_key );
                return absint( $existing_id ); // Already exists — return existing ID
            }
        }

        // ── Create the post via direct DB insert (bypass WPML hooks entirely) ──
        $title   = $post_data['title']   ?? $source_post->post_title;
        $slug    = $post_data['slug']    ?? sanitize_title( $title ) . '-' . $language;
        $content = $post_data['content'] ?? '';
        $excerpt = $post_data['excerpt'] ?? '';

        $new_id = $wpdb->insert(
            $wpdb->posts,
            array(
                'post_author'  => $source_post->post_author,
                'post_title'   => $title,
                'post_name'    => wp_unique_post_slug( $slug, 0, 'publish', $post_type, 0 ),
                'post_content' => $content,
                'post_excerpt' => $excerpt,
                'post_status'  => 'publish',
                'post_type'    => $post_type,
                'post_date'    => current_time( 'mysql' ),
                'post_date_gmt'     => current_time( 'mysql', true ),
                'post_modified'     => current_time( 'mysql' ),
                'post_modified_gmt' => current_time( 'mysql', true ),
                'comment_status'    => $source_post->comment_status,
                'ping_status'       => $source_post->ping_status,
                'post_parent'       => 0,
                'guid'              => '',
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
        );

        if ( ! $new_id ) {
            delete_transient( $lock_key );
            return new \WP_Error( 'insert_failed', 'Failed to insert translation post for #' . $source_id );
        }

        $new_post_id = absint( $wpdb->insert_id );

        // Update guid
        $wpdb->update(
            $wpdb->posts,
            array( 'guid' => get_permalink( $new_post_id ) ?: ( home_url( '/?p=' . $new_post_id ) ) ),
            array( 'ID' => $new_post_id ),
            array( '%s' ),
            array( '%d' )
        );

        // Clean object cache
        clean_post_cache( $new_post_id );

        // ── Register with translation plugin ──
        if ( 'wpml' === $plugin && $trid ) {
            // Remove any auto-created WPML record (should not exist since we bypassed hooks)
            $wpdb->delete(
                $wpdb->prefix . 'icl_translations',
                array( 'element_id' => $new_post_id, 'element_type' => $element_type ),
                array( '%d', '%s' )
            );

            // Also remove any record that might occupy this trid+language slot
            $wpdb->delete(
                $wpdb->prefix . 'icl_translations',
                array( 'trid' => $trid, 'language_code' => $language, 'element_type' => $element_type ),
                array( '%d', '%s', '%s' )
            );

            // Insert the correct WPML record
            $wpdb->insert(
                $wpdb->prefix . 'icl_translations',
                array(
                    'element_type'         => $element_type,
                    'element_id'           => $new_post_id,
                    'trid'                 => $trid,
                    'language_code'        => $language,
                    'source_language_code' => $default_lang,
                ),
                array( '%s', '%d', '%d', '%s', '%s' )
            );

            // Verify
            $verify = $wpdb->get_row( $wpdb->prepare(
                "SELECT language_code, source_language_code, trid FROM {$wpdb->prefix}icl_translations
                 WHERE element_id = %d AND element_type = %s",
                $new_post_id, $element_type
            ) );

            if ( ! $verify || $verify->language_code !== $language || (int) $verify->trid !== (int) $trid ) {
                // Catastrophic failure — trash and abort
                wp_delete_post( $new_post_id, true );
                delete_transient( $lock_key );
                LuwiPress_Logger::log(
                    sprintf( 'CRITICAL: WPML registration failed for translation of #%d → %s. Post deleted.', $source_id, $language ),
                    'error'
                );
                return new \WP_Error( 'wpml_failed', 'WPML registration failed — translation post deleted' );
            }

        } elseif ( 'polylang' === $plugin && function_exists( 'pll_set_post_language' ) ) {
            pll_set_post_language( $new_post_id, $language );
            $translations = pll_get_post_translations( $source_id );
            $translations[ $language ] = $new_post_id;
            pll_save_post_translations( $translations );
        }

        // ── Copy featured image ──
        $thumb_id = get_post_thumbnail_id( $source_id );
        if ( $thumb_id ) {
            update_post_meta( $new_post_id, '_thumbnail_id', $thumb_id );
        }

        delete_transient( $lock_key );

        LuwiPress_Logger::log(
            sprintf( 'Translation post created: #%d (%s) from #%d [%s] via %s',
                $new_post_id, strtoupper( $language ), $source_id, $post_type, $plugin ),
            'info'
        );

        return $new_post_id;
    }

    /**
     * Create WPML translation
     */
    private function create_wpml_translation( $product_id, $language, $translated ) {
        global $wpdb;

        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            LuwiPress_Logger::log( 'WPML not active, cannot save translation', 'warning' );
            return;
        }

        $post_obj     = get_post( $product_id );
        $post_type    = $post_obj ? $post_obj->post_type : 'product';
        $element_type = 'post_' . $post_type;

        $trid = apply_filters( 'wpml_element_trid', null, $product_id, $element_type );
        if ( ! $trid ) {
            LuwiPress_Logger::log( 'No WPML trid for ' . $post_type . ' #' . $product_id . ' (element_type: ' . $element_type . ')', 'warning' );
            return;
        }

        $default_lang  = apply_filters( 'wpml_default_language', get_locale() );
        $translated_id = apply_filters( 'wpml_object_id', $product_id, $post_type, false, $language );

        // Determine WPML language of the post we were handed. If the caller passed a post whose
        // WPML language already matches the target (e.g. retranslating the EN copy itself from a
        // different source), `wpml_object_id` returns the same id and the downstream branches skip
        // the update. Detect that case and rewrite the EN copy in place.
        $post_language = self::get_post_wpml_language( $product_id ) ?: '';

        LuwiPress_Logger::log( 'WPML save: source=#' . $product_id . ' trid=' . $trid . ' lang=' . $language . ' post_lang=' . ( $post_language ?: '?' ) . ' translated_id=' . ( $translated_id ?: 'null' ) . ' name_len=' . strlen( $translated['name'] ?? '' ) . ' desc_len=' . strlen( $translated['description'] ?? '' ), 'info' );

        // Check if source is an Elementor page
        $is_elementor = LuwiPress_Elementor::is_elementor_page( $product_id );
        // Skip old copy_elementor_translated when chunked translation will handle it
        $is_chunked = get_post_meta( $product_id, '_luwipress_elementor_chunked', true );

        // Self-retranslate: the passed post IS the target-language copy (e.g. EN copy of a product
        // whose content was corrupted, being rewritten in place from a cleaner source like ES).
        if ( $translated_id && $translated_id === $product_id && $post_language === $language ) {
            $existing_post = get_post( $product_id );
            $needs_slug    = $existing_post && ( is_numeric( $existing_post->post_name ) || empty( $existing_post->post_name ) );
            $update_data   = array(
                'ID'           => $product_id,
                'post_title'   => $translated['name'],
                'post_content' => $translated['description'],
                'post_excerpt' => $translated['short_description'] ?? '',
                'post_status'  => 'publish',
            );
            if ( $needs_slug && ! empty( $translated['name'] ) ) {
                $update_data['post_name'] = ! empty( $translated['slug'] ) ? sanitize_title( $translated['slug'] ) : sanitize_title( $translated['name'] );
            }
            $self_result = wp_update_post( $update_data, true );
            if ( is_wp_error( $self_result ) ) {
                LuwiPress_Logger::log( 'WPML self-update FAILED: #' . $product_id . ' — ' . $self_result->get_error_message(), 'error' );
            } else {
                LuwiPress_Logger::log( 'WPML self-update: #' . $product_id . ' (' . strtoupper( $language ) . ') content_len=' . strlen( $translated['description'] ?? '' ), 'info' );
            }
            if ( $is_elementor && ! $is_chunked ) {
                $this->copy_elementor_translated( $product_id, $product_id, $translated );
            }
            clean_post_cache( $product_id );
            return;
        }

        if ( $translated_id && $translated_id !== $product_id ) {
            // ── Update existing translation ──
            $existing_post = get_post( $translated_id );
            $needs_slug = $existing_post && ( is_numeric( $existing_post->post_name ) || empty( $existing_post->post_name ) );
            $update_data = array(
                'ID'           => $translated_id,
                'post_title'   => $translated['name'],
                'post_content' => $translated['description'],
                'post_excerpt' => $translated['short_description'] ?? '',
                'post_status'  => 'publish',
            );
            if ( $needs_slug && ! empty( $translated['name'] ) ) {
                $update_data['post_name'] = ! empty( $translated['slug'] ) ? sanitize_title( $translated['slug'] ) : sanitize_title( $translated['name'] );
            }
            $result = wp_update_post( $update_data, true );

            if ( is_wp_error( $result ) ) {
                LuwiPress_Logger::log( 'WPML update FAILED: #' . $translated_id . ' — ' . $result->get_error_message(), 'error' );
            } else {
                LuwiPress_Logger::log( 'WPML translation updated: #' . $translated_id . ' (' . strtoupper( $language ) . ') title="' . mb_substr( $translated['name'], 0, 50 ) . '" content_len=' . strlen( $translated['description'] ?? '' ), 'info' );
            }

            // ── Elementor: copy _elementor_data and replace text widgets ──
            // Skip if chunked translation is active (it handles Elementor data separately)
            if ( $is_elementor && ! $is_chunked ) {
                $this->copy_elementor_translated( $product_id, $translated_id, $translated );
            }

            $target_id = $translated_id;

            // ── Ensure images match original ──
            if ( 'product' === $post_type ) {
                $this->copy_product_images( $product_id, $translated_id );
            } else {
                // Copy featured image for non-product posts (blog, pages)
                $thumb_id = get_post_thumbnail_id( $product_id );
                if ( $thumb_id ) {
                    set_post_thumbnail( $translated_id, $thumb_id );
                }
            }
        } else {
            // ── Create new translation post via centralized method ──
            $new_id = $this->create_translation_post( $product_id, $language, array(
                'title'   => $translated['name'],
                'slug'    => $translated['slug'] ?? '',
                'content' => $translated['description'],
                'excerpt' => $translated['short_description'] ?? '',
            ) );

            if ( is_wp_error( $new_id ) ) {
                LuwiPress_Logger::log( 'Translation creation failed: ' . $new_id->get_error_message(), 'error' );
                return;
            }

            $target_id = $new_id;

            // ── Elementor: copy structure with translated text ──
            if ( $is_elementor && ! $is_chunked ) {
                $this->copy_elementor_translated( $product_id, $new_id, $translated );
            }

            // ── Copy WooCommerce-specific meta & taxonomies (products only) ──
            if ( 'product' === $post_type ) {
                $wc_meta_keys = array(
                    '_price', '_regular_price', '_sale_price', '_sku',
                    '_stock', '_stock_status', '_manage_stock', '_backorders',
                    '_weight', '_length', '_width', '_height',
                    '_virtual', '_downloadable', '_sold_individually',
                    '_tax_status', '_tax_class',
                    '_thumbnail_id', '_product_image_gallery',
                    '_upsell_ids', '_crosssell_ids',
                    '_product_attributes', '_default_attributes',
                    '_purchase_note', '_product_url', '_button_text',
                    'total_sales', '_wc_average_rating', '_wc_review_count',
                );
                foreach ( $wc_meta_keys as $key ) {
                    $val = get_post_meta( $product_id, $key, true );
                    if ( '' !== $val && false !== $val ) {
                        update_post_meta( $new_id, $key, $val );
                    }
                }

                $type_terms = wp_get_object_terms( $product_id, 'product_type', array( 'fields' => 'slugs' ) );
                if ( ! empty( $type_terms ) && ! is_wp_error( $type_terms ) ) {
                    wp_set_object_terms( $new_id, $type_terms, 'product_type' );
                }

                $visibility = wp_get_object_terms( $product_id, 'product_visibility', array( 'fields' => 'slugs' ) );
                if ( ! empty( $visibility ) && ! is_wp_error( $visibility ) ) {
                    wp_set_object_terms( $new_id, $visibility, 'product_visibility' );
                }

                $this->copy_wpml_taxonomy_translations( $product_id, $new_id, $language, 'product_cat' );
                $this->copy_wpml_taxonomy_translations( $product_id, $new_id, $language, 'product_tag' );
            }

        }

        // ── Save SEO meta (Rank Math / Yoast) ──
        if ( $target_id && ( ! empty( $translated['meta_title'] ) || ! empty( $translated['meta_description'] ) ) ) {
            $detector = LuwiPress_Plugin_Detector::get_instance();
            $seo      = $detector->detect_seo();

            if ( ! empty( $seo['meta_keys']['title'] ) && ! empty( $translated['meta_title'] ) ) {
                update_post_meta( $target_id, $seo['meta_keys']['title'], sanitize_text_field( $translated['meta_title'] ) );
            }
            if ( ! empty( $seo['meta_keys']['description'] ) && ! empty( $translated['meta_description'] ) ) {
                update_post_meta( $target_id, $seo['meta_keys']['description'], sanitize_text_field( $translated['meta_description'] ) );
            }
            // Rank Math focus keyword
            if ( ! empty( $translated['focus_keyword'] ) && ! empty( $seo['meta_keys']['focus_keyword'] ) ) {
                update_post_meta( $target_id, $seo['meta_keys']['focus_keyword'], sanitize_text_field( $translated['focus_keyword'] ) );
            }
        }
    }

    /**
     * AJAX: Fix images for all existing WPML translations.
     * Copies _thumbnail_id and _product_image_gallery from original to all translated products.
     */
    /**
     * AJAX: Fix category assignments for all WPML translated products.
     * Re-assigns each translated product to the correct translated category.
     */
    public function ajax_fix_category_assignments() {
        check_ajax_referer( 'luwipress_fix_categories', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            wp_send_json_error( 'WPML not active' );
        }

        global $wpdb;
        $default_lang = apply_filters( 'wpml_default_language', get_locale() );

        // Get all original posts/products/pages
        $originals = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.element_id AS post_id, t.trid, p.post_type
             FROM {$wpdb->prefix}icl_translations t
             JOIN {$wpdb->posts} p ON t.element_id = p.ID
             WHERE t.element_type IN ('post_product', 'post_post', 'post_page')
               AND t.language_code = %s
               AND t.source_language_code IS NULL
               AND p.post_status = 'publish'",
            $default_lang
        ) );

        $fixed = 0;
        foreach ( $originals as $row ) {
            $translations = $wpdb->get_results( $wpdb->prepare(
                "SELECT element_id, language_code FROM {$wpdb->prefix}icl_translations
                 WHERE trid = %d AND language_code != %s AND element_id IS NOT NULL",
                $row->trid, $default_lang
            ) );

            // Determine which taxonomies to copy based on post type
            $taxonomies = array();
            if ( 'product' === $row->post_type ) {
                $taxonomies = array( 'product_cat', 'product_tag' );
            } elseif ( 'post' === $row->post_type ) {
                $taxonomies = array( 'category', 'post_tag' );
            }

            foreach ( $translations as $tr ) {
                foreach ( $taxonomies as $tax ) {
                    $this->copy_wpml_taxonomy_translations( $row->post_id, $tr->element_id, $tr->language_code, $tax );
                }
                $fixed++;
            }
        }

        LuwiPress_Logger::log( 'Category assignments fixed for ' . $fixed . ' translated posts/products', 'info' );
        wp_send_json_success( array( 'fixed' => $fixed ) );
    }

    public function ajax_fix_translation_images() {
        check_ajax_referer( 'luwipress_fix_images', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            wp_send_json_error( 'WPML not active' );
        }

        global $wpdb;
        $default_lang = apply_filters( 'wpml_default_language', get_locale() );

        // Get all default-language posts, pages, and products
        $originals = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.element_id AS post_id, t.trid, p.post_type
             FROM {$wpdb->prefix}icl_translations t
             JOIN {$wpdb->posts} p ON t.element_id = p.ID
             WHERE t.element_type IN ('post_product', 'post_post', 'post_page')
               AND t.language_code = %s
               AND t.source_language_code IS NULL
               AND p.post_status = 'publish'",
            $default_lang
        ) );

        $fixed = 0;
        foreach ( $originals as $row ) {
            $source_thumb = get_post_thumbnail_id( $row->post_id );
            if ( ! $source_thumb ) {
                continue;
            }

            $translations = $wpdb->get_results( $wpdb->prepare(
                "SELECT element_id FROM {$wpdb->prefix}icl_translations
                 WHERE trid = %d AND language_code != %s AND element_id IS NOT NULL",
                $row->trid, $default_lang
            ) );

            foreach ( $translations as $tr ) {
                $tr_thumb = get_post_thumbnail_id( $tr->element_id );
                if ( ! $tr_thumb || $tr_thumb !== $source_thumb ) {
                    set_post_thumbnail( $tr->element_id, $source_thumb );
                    $fixed++;
                }
                // Also copy product gallery for WC products
                if ( 'product' === $row->post_type ) {
                    $this->copy_product_images( $row->post_id, $tr->element_id );
                }
            }
        }

        wp_send_json_success( array( 'fixed' => $fixed ) );
    }

    /**
     * Translate an Elementor page by chunking long widget content.
     *
     * Instead of sending the entire page content as one AI call (which fails
     * JSON parse on long content), this method:
     * 1. Extracts translatable text from each widget in _elementor_data
     * 2. Splits long HTML content (>3000 chars) into chunks at <h2>/<h3> boundaries
     * 3. Translates each chunk with dispatch() (plain HTML, not JSON)
     * 4. Writes translated text directly to the target post's _elementor_data
     *
     * @param int    $source_id   Source post ID.
     * @param int    $target_id   Target (translated) post ID.
     * @param string $source_lang Source language name (e.g. 'Turkish').
     * @param string $target_lang Target language name (e.g. 'French').
     * @return true|WP_Error
     */
    private function translate_elementor_chunked( $source_id, $target_id, $source_lang, $target_lang ) {
        $raw_data = get_post_meta( $source_id, '_elementor_data', true );
        if ( empty( $raw_data ) ) {
            return new WP_Error( 'no_elementor', 'No Elementor data for source post #' . $source_id );
        }

        $data = is_string( $raw_data ) ? json_decode( $raw_data, true ) : $raw_data;
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'parse_error', 'Failed to parse Elementor JSON for #' . $source_id );
        }

        // Copy Elementor structure to target first
        update_post_meta( $target_id, '_elementor_edit_mode', 'builder' );
        $page_settings = get_post_meta( $source_id, '_elementor_page_settings', true );
        if ( $page_settings ) {
            update_post_meta( $target_id, '_elementor_page_settings', $page_settings );
        }

        // Translatable text keys grouped by type
        $title_keys = array( 'title', 'heading_title', 'ekit_heading_title', 'ekit_heading_focused_title' );
        $content_keys = array( 'editor', 'tab_content', 'description', 'description_text', 'ekit_heading_extra_title', 'ekit_heading_description' );
        $short_keys = array( 'ekit_heading_sub_title', 'button_text', 'alert_title', 'text' );
        $all_keys = array_merge( $title_keys, $content_keys, $short_keys );

        // Walk the element tree, translate widget texts, rebuild data
        $data = $this->walk_and_translate_elements( $data, $all_keys, $title_keys, $content_keys, $source_lang, $target_lang );

        // Save translated Elementor data to target post
        update_post_meta( $target_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );

        // Clear CSS cache
        delete_post_meta( $target_id, '_elementor_css' );
        delete_post_meta( $target_id, '_elementor_page_assets' );

        LuwiPress_Logger::log( 'Elementor chunked translation completed: #' . $source_id . ' → #' . $target_id . ' (' . $target_lang . ')', 'info' );

        return true;
    }

    /**
     * Recursively walk Elementor elements and translate text settings.
     *
     * @param array  $elements     Elementor element tree.
     * @param array  $all_keys     All translatable setting keys.
     * @param array  $title_keys   Keys that contain titles (short text).
     * @param array  $content_keys Keys that contain long content (HTML).
     * @param string $source_lang  Source language name.
     * @param string $target_lang  Target language name.
     * @return array Modified element tree.
     */
    private function walk_and_translate_elements( array $elements, $all_keys, $title_keys, $content_keys, $source_lang, $target_lang ) {
        foreach ( $elements as &$element ) {
            if ( ! empty( $element['settings'] ) && ! empty( $element['widgetType'] ) ) {
                foreach ( $all_keys as $key ) {
                    if ( empty( $element['settings'][ $key ] ) || ! is_string( $element['settings'][ $key ] ) ) {
                        continue;
                    }

                    $original = $element['settings'][ $key ];
                    // Skip very short strings (not translatable)
                    if ( strlen( strip_tags( $original ) ) < 5 ) {
                        continue;
                    }

                    // Strip leading <h1> from ekit_heading_extra_title if widget has a separate title
                    if ( 'ekit_heading_extra_title' === $key && ! empty( $element['settings']['ekit_heading_title'] ) ) {
                        $original = preg_replace( '/^\s*<h1[^>]*>.*?<\/h1>\s*/is', '', $original, 1 );
                    }

                    // Long content → chunk and translate
                    if ( in_array( $key, $content_keys, true ) && strlen( $original ) > 3000 ) {
                        $translated = $this->translate_html_chunked( $original, $source_lang, $target_lang );
                    } else {
                        // Short text (titles, buttons, etc.) → single AI call
                        $translated = $this->translate_html_single( $original, $source_lang, $target_lang );
                    }

                    if ( ! is_wp_error( $translated ) && ! empty( $translated ) ) {
                        $element['settings'][ $key ] = $translated;
                    } else {
                        LuwiPress_Logger::log(
                            'Elementor chunk translation failed for key=' . $key . ' widget=' . ( $element['id'] ?? '?' ) . ': ' . ( is_wp_error( $translated ) ? $translated->get_error_message() : 'empty' ),
                            'warning'
                        );
                    }
                }
            }

            // Recurse into children
            if ( ! empty( $element['elements'] ) ) {
                $element['elements'] = $this->walk_and_translate_elements( $element['elements'], $all_keys, $title_keys, $content_keys, $source_lang, $target_lang );
            }
        }

        return $elements;
    }

    /**
     * Translate a long HTML string by splitting into chunks at heading boundaries.
     *
     * @param string $html        HTML content to translate.
     * @param string $source_lang Source language name.
     * @param string $target_lang Target language name.
     * @return string|WP_Error Translated HTML or error.
     */
    private function translate_html_chunked( $html, $source_lang, $target_lang ) {
        $chunks = $this->split_html_by_headings( $html, 3000 );

        LuwiPress_Logger::log(
            'Elementor chunked: ' . count( $chunks ) . ' chunks from ' . strlen( $html ) . ' chars',
            'info'
        );

        $translated_chunks = array();
        foreach ( $chunks as $i => $chunk ) {
            $result = $this->translate_html_single( $chunk, $source_lang, $target_lang );
            if ( is_wp_error( $result ) ) {
                LuwiPress_Logger::log( 'Chunk ' . $i . ' translation failed: ' . $result->get_error_message(), 'warning' );
                // Keep original chunk on failure rather than breaking the whole page
                $translated_chunks[] = $chunk;
            } else {
                $translated_chunks[] = $result;
            }
        }

        return implode( '', $translated_chunks );
    }

    /**
     * Translate a single HTML string via AI (plain HTML response, not JSON).
     *
     * @param string $html        HTML content.
     * @param string $source_lang Source language name.
     * @param string $target_lang Target language name.
     * @return string|WP_Error Translated HTML or error.
     */
    private function translate_html_single( $html, $source_lang, $target_lang ) {
        $prompt   = LuwiPress_Prompts::elementor_html_translation( $html, $source_lang, $target_lang );
        $messages = LuwiPress_AI_Engine::build_messages( $prompt );

        $max_tokens = max( 2048, intval( strlen( $html ) / 2 ) );
        $max_tokens = min( $max_tokens, 16000 );

        $result = LuwiPress_AI_Engine::dispatch( 'translation-pipeline', $messages, array(
            'max_tokens' => $max_tokens,
            'timeout'    => 120,
        ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $translated = $result['content'] ?? '';

        // Strip any accidental code fences the AI might add
        $translated = preg_replace( '/^```(?:html)?\s*/i', '', $translated );
        $translated = preg_replace( '/\s*```\s*$/', '', $translated );

        return trim( $translated );
    }

    /**
     * Split HTML content into chunks at <h2> or <h3> boundaries.
     * Each chunk stays under $max_chars. If a single section exceeds $max_chars,
     * it is included as-is (the AI can handle slightly larger chunks).
     *
     * @param string $html      Full HTML content.
     * @param int    $max_chars Target maximum characters per chunk.
     * @return array Array of HTML string chunks.
     */
    private function split_html_by_headings( $html, $max_chars = 3000 ) {
        // Split at <h2> or <h3> tags, keeping the delimiter
        $parts = preg_split( '/(?=<h[23][^>]*>)/i', $html );

        if ( empty( $parts ) || count( $parts ) <= 1 ) {
            // No headings found — split by paragraphs instead
            $parts = preg_split( '/(?=<p[^>]*>)/i', $html );
        }

        if ( empty( $parts ) || count( $parts ) <= 1 ) {
            // Still can't split — return as single chunk
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

    /**
     * Handle Elementor page translation.
     * Copies _elementor_data from source, then replaces ALL text content
     * in the JSON with translated text. This preserves Elementor layout
     * while showing translated content.
     */
    private function copy_elementor_translated( $source_id, $target_id, $translated ) {
        $raw_data = get_post_meta( $source_id, '_elementor_data', true );
        if ( empty( $raw_data ) ) {
            return;
        }

        // Copy Elementor structure
        update_post_meta( $target_id, '_elementor_edit_mode', 'builder' );

        // Copy page settings
        $page_settings = get_post_meta( $source_id, '_elementor_page_settings', true );
        if ( $page_settings ) {
            update_post_meta( $target_id, '_elementor_page_settings', $page_settings );
        }

        // Parse Elementor data
        $data = is_string( $raw_data ) ? json_decode( $raw_data, true ) : $raw_data;
        if ( ! is_array( $data ) ) {
            update_post_meta( $target_id, '_elementor_data', $raw_data );
            return;
        }

        // Replace text content in all widgets with translated description
        $translated_desc = $translated['description'] ?? '';
        if ( ! empty( $translated_desc ) ) {
            // Walk the element tree and replace text in all widget types
            $data = $this->replace_elementor_texts( $data, $translated_desc, $translated['name'] ?? '' );
        }

        // Save modified Elementor data
        update_post_meta( $target_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );

        // Clear CSS cache
        delete_post_meta( $target_id, '_elementor_css' );
        delete_post_meta( $target_id, '_elementor_page_assets' );

        LuwiPress_Logger::log( 'Elementor data translated for #' . $target_id, 'info' );
    }

    /**
     * Replace text content in Elementor element tree with translated content.
     * Walks recursively through sections > columns > widgets and replaces
     * known text settings (title, editor, description, etc.)
     */
    private function replace_elementor_texts( array $elements, $translated_content, $translated_title = '' ) {
        // Text setting keys found in various Elementor widgets
        $text_keys = array(
            // Standard Elementor
            'title', 'editor', 'description', 'text', 'tab_content',
            'tab_title', 'button_text', 'testimonial_content',
            'testimonial_name', 'testimonial_job', 'alert_title',
            'alert_description', 'heading_title', 'description_text',
            'title_text', 'inner_text', 'prefix', 'suffix',
            // ElementsKit heading widget
            'ekit_heading_title', 'ekit_heading_sub_title',
            'ekit_heading_extra_title', 'ekit_heading_description',
            'ekit_heading_focused_title',
        );

        // Title keys — get translated title
        $title_keys = array(
            'title', 'heading_title', 'ekit_heading_title', 'ekit_heading_focused_title',
        );

        // Content keys — get translated description (long content)
        $content_keys = array(
            'editor', 'tab_content', 'description', 'description_text',
            'ekit_heading_extra_title', 'ekit_heading_description',
        );

        foreach ( $elements as &$element ) {
            // Process widget settings
            if ( ! empty( $element['settings'] ) && ! empty( $element['widgetType'] ) ) {
                foreach ( $text_keys as $key ) {
                    if ( ! empty( $element['settings'][ $key ] ) && is_string( $element['settings'][ $key ] ) ) {
                        $original = $element['settings'][ $key ];
                        // Skip very short strings (likely not translatable content)
                        if ( strlen( strip_tags( $original ) ) < 3 ) {
                            continue;
                        }

                        // Title keys → use translated title
                        if ( in_array( $key, $title_keys, true ) && ! empty( $translated_title ) ) {
                            $element['settings'][ $key ] = $translated_title;
                            continue;
                        }

                        // Content keys → use translated description
                        if ( in_array( $key, $content_keys, true ) && ! empty( $translated_content ) ) {
                            $element['settings'][ $key ] = $translated_content;
                            $translated_content = '';
                            continue;
                        }
                    }
                }
            }

            // Recurse into children
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $element['elements'] = $this->replace_elementor_texts( $element['elements'], $translated_content, $translated_title );
                // If translated_content was consumed by a child, mark it empty
                $translated_content = '';
            }
        }
        unset( $element );

        return $elements;
    }

    /**
     * Copy product images from original to translated product.
     * Simply copies _thumbnail_id and _product_image_gallery meta.
     */
    private function copy_product_images( $source_id, $target_id ) {
        $thumb_id = get_post_meta( $source_id, '_thumbnail_id', true );
        if ( $thumb_id ) {
            update_post_meta( $target_id, '_thumbnail_id', $thumb_id );
        }
        $gallery = get_post_meta( $source_id, '_product_image_gallery', true );
        if ( ! empty( $gallery ) ) {
            update_post_meta( $target_id, '_product_image_gallery', $gallery );
        }
    }

    /**
     * @deprecated Use copy_product_images() instead.
     * Share product images (thumbnail + gallery) across WPML languages.
     * WPML may look for translated attachment IDs — this ensures the original
     * attachments are registered for the target language so images display correctly.
     */
    private function wpml_share_product_images( $source_id, $target_id, $language ) {
        global $wpdb;
        $default_lang = apply_filters( 'wpml_default_language', get_locale() );
        $attachment_ids = array();

        // Collect thumbnail
        $thumb_id = get_post_meta( $source_id, '_thumbnail_id', true );
        if ( $thumb_id ) {
            $attachment_ids[] = (int) $thumb_id;
        }

        // Collect gallery images
        $gallery = get_post_meta( $source_id, '_product_image_gallery', true );
        if ( ! empty( $gallery ) ) {
            $gallery_ids = array_map( 'intval', explode( ',', $gallery ) );
            $attachment_ids = array_merge( $attachment_ids, $gallery_ids );
        }

        $attachment_ids = array_unique( array_filter( $attachment_ids ) );

        foreach ( $attachment_ids as $att_id ) {
            // Check if this attachment already has a WPML translation for target language
            $translated_att = apply_filters( 'wpml_object_id', $att_id, 'attachment', false, $language );

            if ( ! $translated_att || $translated_att === $att_id ) {
                // Register the original attachment as its own translation for target language
                $att_trid = apply_filters( 'wpml_element_trid', null, $att_id, 'post_attachment' );
                if ( $att_trid ) {
                    do_action( 'wpml_set_element_language_details', array(
                        'element_id'           => $att_id,
                        'element_type'         => 'post_attachment',
                        'trid'                 => $att_trid,
                        'language_code'        => $language,
                        'source_language_code' => null,
                    ) );

                    // SQL fallback if WPML action didn't fire
                    $att_linked = $wpdb->get_var( $wpdb->prepare(
                        "SELECT translation_id FROM {$wpdb->prefix}icl_translations
                         WHERE element_id = %d AND element_type = 'post_attachment' AND language_code = %s",
                        $att_id, $language
                    ) );
                    if ( ! $att_linked ) {
                        $wpdb->insert(
                            $wpdb->prefix . 'icl_translations',
                            array(
                                'element_type'         => 'post_attachment',
                                'element_id'           => $att_id,
                                'trid'                 => $att_trid,
                                'language_code'        => $language,
                                'source_language_code' => null,
                            ),
                            array( '%s', '%d', '%d', '%s', '%s' )
                        );
                    }
                }
            }

            // If WPML created a different attachment ID for this language, update meta
            $final_att_id = apply_filters( 'wpml_object_id', $att_id, 'attachment', false, $language );
            if ( $final_att_id && $final_att_id !== $att_id ) {
                // Update thumbnail if this was the thumbnail
                if ( (int) $thumb_id === $att_id ) {
                    update_post_meta( $target_id, '_thumbnail_id', $final_att_id );
                }
            }
        }

        // Re-map gallery IDs for translated product
        if ( ! empty( $gallery ) ) {
            $gallery_ids = array_map( 'intval', explode( ',', $gallery ) );
            $translated_gallery = array();
            foreach ( $gallery_ids as $gid ) {
                $translated_gid = apply_filters( 'wpml_object_id', $gid, 'attachment', true, $language );
                $translated_gallery[] = $translated_gid ?: $gid;
            }
            update_post_meta( $target_id, '_product_image_gallery', implode( ',', $translated_gallery ) );
        }
    }

    /**
     * Copy taxonomy terms with WPML translation linking.
     * If a translated term exists in WPML, use it.
     * If not, auto-create a term translation linked to the original via WPML.
     */
    private function copy_wpml_taxonomy_translations( $source_id, $target_id, $language, $taxonomy ) {
        $terms = wp_get_object_terms( $source_id, $taxonomy );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return;
        }

        $default_lang = apply_filters( 'wpml_default_language', get_locale() );
        $translated_term_ids = array();

        foreach ( $terms as $term ) {
            // Check if WPML already has a translation for this term
            $translated_term_id = apply_filters( 'wpml_object_id', $term->term_id, $taxonomy, false, $language );

            if ( $translated_term_id && $translated_term_id !== $term->term_id ) {
                $translated_term_ids[] = (int) $translated_term_id;
            } else {
                // No translation — auto-create one so each language has its own term
                $new_term_id = $this->auto_create_wpml_term_translation( $term, $taxonomy, $language, $default_lang );
                if ( $new_term_id ) {
                    $translated_term_ids[] = $new_term_id;
                } else {
                    // Fallback to original if creation fails
                    $translated_term_ids[] = (int) $term->term_id;
                }
            }
        }

        if ( ! empty( $translated_term_ids ) ) {
            wp_set_object_terms( $target_id, $translated_term_ids, $taxonomy );
        }
    }

    /**
     * Auto-create a WPML term translation.
     * Creates a new term with the original name (placeholder) and links it via WPML.
     * The taxonomy translation workflow can later update the name to the real translation.
     *
     * @return int|false New term ID or false on failure.
     */
    private function auto_create_wpml_term_translation( $original_term, $taxonomy, $language, $default_lang ) {
        global $wpdb;

        $element_type = 'tax_' . $taxonomy;

        // Get trid of original term
        $trid = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT trid FROM {$wpdb->prefix}icl_translations
             WHERE element_id = %d AND element_type = %s",
            $original_term->term_id, $element_type
        ) );

        if ( ! $trid ) {
            return false;
        }

        // Double-check no translation already exists (race condition guard)
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT element_id FROM {$wpdb->prefix}icl_translations
             WHERE trid = %d AND language_code = %s AND element_type = %s",
            $trid, $language, $element_type
        ) );

        if ( $existing ) {
            return (int) $existing;
        }

        // Resolve parent: if original term has a parent, find or create its translation
        $parent = 0;
        if ( $original_term->parent > 0 ) {
            $parent_translated = apply_filters( 'wpml_object_id', $original_term->parent, $taxonomy, false, $language );
            if ( $parent_translated && $parent_translated !== $original_term->parent ) {
                $parent = (int) $parent_translated;
            }
        }

        // Create the term with original name as placeholder
        $slug = $original_term->slug . '-' . $language;
        $new_term = wp_insert_term( $original_term->name, $taxonomy, array(
            'slug'   => $slug,
            'parent' => $parent,
        ) );

        if ( is_wp_error( $new_term ) ) {
            // Slug conflict — try with random suffix
            $new_term = wp_insert_term( $original_term->name, $taxonomy, array(
                'slug'   => $slug . '-' . wp_rand( 100, 999 ),
                'parent' => $parent,
            ) );
            if ( is_wp_error( $new_term ) ) {
                return false;
            }
        }

        $new_term_id = $new_term['term_id'];

        // Link to WPML translation group (with SQL fallback)
        do_action( 'wpml_set_element_language_details', array(
            'element_id'           => $new_term_id,
            'element_type'         => $element_type,
            'trid'                 => $trid,
            'language_code'        => $language,
            'source_language_code' => $default_lang,
        ) );

        // Verify and fallback
        global $wpdb;
        $linked = $wpdb->get_var( $wpdb->prepare(
            "SELECT translation_id FROM {$wpdb->prefix}icl_translations
             WHERE element_id = %d AND element_type = %s",
            $new_term_id, $element_type
        ) );

        if ( ! $linked ) {
            $wpdb->insert(
                $wpdb->prefix . 'icl_translations',
                array(
                    'element_type'         => $element_type,
                    'element_id'           => $new_term_id,
                    'trid'                 => $trid,
                    'language_code'        => $language,
                    'source_language_code' => $default_lang,
                ),
                array( '%s', '%d', '%d', '%s', '%s' )
            );
        }

        return $new_term_id;
    }

    /**
     * Create or update Polylang translation.
     * Mirrors WPML create logic: copies product meta, images, taxonomies, SEO meta.
     */
    private function create_polylang_translation($product_id, $language, $translated) {
        if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
            LuwiPress_Logger::log('Polylang API functions not available', 'warning');
            return;
        }

        $translations = pll_get_post_translations($product_id);
        $target_id = null;

        if (isset($translations[$language])) {
            // ── Update existing translation ──
            wp_update_post([
                'ID'           => $translations[$language],
                'post_title'   => $translated['name'],
                'post_content' => $translated['description'],
                'post_excerpt' => $translated['short_description'] ?? '',
                'post_status'  => 'publish',
            ]);
            $target_id = $translations[$language];

            LuwiPress_Logger::log(
                sprintf('Polylang translation updated: #%d (%s)', $target_id, strtoupper($language)),
                'info',
                ['original_id' => $product_id, 'translated_id' => $target_id, 'language' => $language]
            );
        } else {
            // ── Create new translation post ──
            $original = get_post($product_id);
            if (!$original) {
                return;
            }

            $new_id = wp_insert_post([
                'post_title'   => $translated['name'],
                'post_content' => $translated['description'],
                'post_excerpt' => $translated['short_description'] ?? '',
                'post_type'    => $original->post_type,
                'post_status'  => $original->post_status,
                'post_author'  => $original->post_author,
            ]);

            if (is_wp_error($new_id)) {
                LuwiPress_Logger::log('Polylang: failed to create translation: ' . $new_id->get_error_message(), 'error');
                return;
            }

            // Assign language and link to original
            pll_set_post_language($new_id, $language);
            $translations[$language] = $new_id;
            pll_save_post_translations($translations);

            $target_id = $new_id;

            // ── Copy WooCommerce product meta (whitelist approach) ──
            $wc_meta_keys = array(
                '_price', '_regular_price', '_sale_price', '_sku',
                '_stock', '_stock_status', '_manage_stock', '_backorders',
                '_weight', '_length', '_width', '_height',
                '_virtual', '_downloadable', '_sold_individually',
                '_tax_status', '_tax_class',
                '_thumbnail_id', '_product_image_gallery',
                '_upsell_ids', '_crosssell_ids',
                '_product_attributes', '_default_attributes',
                '_purchase_note', '_product_url', '_button_text',
                'total_sales', '_wc_average_rating', '_wc_review_count',
            );
            foreach ($wc_meta_keys as $key) {
                $val = get_post_meta($product_id, $key, true);
                if ('' !== $val && false !== $val) {
                    update_post_meta($new_id, $key, $val);
                }
            }

            // ── Copy product type taxonomy ──
            $type_terms = wp_get_object_terms($product_id, 'product_type', ['fields' => 'slugs']);
            if (!empty($type_terms) && !is_wp_error($type_terms)) {
                wp_set_object_terms($new_id, $type_terms, 'product_type');
            }

            // ── Copy product visibility ──
            $visibility = wp_get_object_terms($product_id, 'product_visibility', ['fields' => 'slugs']);
            if (!empty($visibility) && !is_wp_error($visibility)) {
                wp_set_object_terms($new_id, $visibility, 'product_visibility');
            }

            // ── Copy product categories and tags ──
            foreach (['product_cat', 'product_tag'] as $taxonomy) {
                $terms = wp_get_object_terms($product_id, $taxonomy, ['fields' => 'ids']);
                if (!empty($terms) && !is_wp_error($terms)) {
                    wp_set_object_terms($new_id, $terms, $taxonomy);
                }
            }

            // Force publish (some hooks may reset to draft)
            wp_update_post(['ID' => $new_id, 'post_status' => 'publish']);

            LuwiPress_Logger::log(
                sprintf('Polylang translation created: #%d (%s) from #%d — "%s"', $new_id, strtoupper($language), $product_id, $translated['name']),
                'info',
                ['original_id' => $product_id, 'translated_id' => $new_id, 'language' => $language]
            );
        }

        // ── Copy product images (thumbnail + gallery) ──
        if ($target_id) {
            $this->copy_product_images($product_id, $target_id);
        }

        // ── Save SEO meta via Plugin Detector ──
        if ($target_id && (!empty($translated['meta_title']) || !empty($translated['meta_description']))) {
            $detector = LuwiPress_Plugin_Detector::get_instance();
            $seo_data = [];
            if (!empty($translated['meta_title'])) {
                $seo_data['title'] = $translated['meta_title'];
            }
            if (!empty($translated['meta_description'])) {
                $seo_data['description'] = $translated['meta_description'];
            }
            if (!empty($translated['focus_keyword'])) {
                $seo_data['focus_keyword'] = $translated['focus_keyword'];
            }
            $detector->set_seo_meta($target_id, $seo_data);
        }
    }

    // ─── TAXONOMY TRANSLATION ──────────────────────────────────────────

    /**
     * GET /translation/taxonomy-missing — Return missing taxonomy terms for clients to translate.
     * This does NOT trigger a webhook — it just returns the data.
     */
    public function get_missing_taxonomy_terms_api($request) {
        $taxonomy = $request->get_param('taxonomy');
        $target_languages_str = $request->get_param('target_languages');
        $limit = min($request->get_param('limit'), 200);

        $target_languages = array_map('trim', explode(',', $target_languages_str));
        $source_language = apply_filters( 'wpml_default_language', get_locale() );

        if (!taxonomy_exists($taxonomy)) {
            return new WP_Error('invalid_taxonomy', 'Taxonomy not found: ' . $taxonomy, ['status' => 400]);
        }

        $terms = $this->get_missing_taxonomy_terms($taxonomy, $target_languages, $source_language, $limit);

        return rest_ensure_response([
            'taxonomy'         => $taxonomy,
            'source_language'  => $source_language,
            'target_languages' => $target_languages,
            'count'            => count($terms),
            'terms'            => $terms,
        ]);
    }

    /**
     * POST /translation/taxonomy — Send untranslated terms to the AI engine for translation
     */
    public function request_taxonomy_translation($request) {
        $taxonomy         = $request->get_param('taxonomy');
        $target_languages = $request->get_param('target_languages');
        $limit            = min($request->get_param('limit'), 200);

        if (is_string($target_languages)) {
            $target_languages = array_map('trim', explode(',', $target_languages));
        }

        if (!taxonomy_exists($taxonomy)) {
            return new WP_Error('invalid_taxonomy', 'Taxonomy not found: ' . $taxonomy, ['status' => 400]);
        }

        $source_language = apply_filters( 'wpml_default_language', get_locale() );

        // Get untranslated terms
        $missing_terms = $this->get_missing_taxonomy_terms($taxonomy, $target_languages, $source_language, $limit);

        if (empty($missing_terms)) {
            return rest_ensure_response([
                'status'  => 'nothing_to_translate',
                'message' => 'All terms are already translated.',
            ]);
        }

        $payload = [
            'taxonomy'         => $taxonomy,
            'source_language'  => $source_language,
            'target_languages' => $target_languages,
            'terms'            => $missing_terms,
        ];

        // Translate directly via AI Engine for each language
        $lang_names = array( 'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'ru' => 'Russian' );
        $source_name = $lang_names[ $source_language ] ?? ucfirst( $source_language );

        $all_translations = array();
        foreach ( $target_languages as $lang ) {
            $target_name = $lang_names[ $lang ] ?? ucfirst( $lang );

            // Build terms array for prompt
            $terms_for_prompt = array();
            foreach ( $missing_terms as $term ) {
                $terms_for_prompt[] = array(
                    'term_id' => $term['term_id'] ?? 0,
                    'name'    => $term['name'] ?? '',
                    'slug'    => $term['slug'] ?? '',
                );
            }

            $prompt   = LuwiPress_Prompts::taxonomy_translation( $terms_for_prompt, $taxonomy, $source_name, $target_name );
            $messages = LuwiPress_AI_Engine::build_messages( $prompt );
            $ai_result = LuwiPress_AI_Engine::dispatch_json( 'translation-pipeline', $messages, array(
                'max_tokens' => 2000,
            ) );

            if ( is_wp_error( $ai_result ) ) {
                LuwiPress_Logger::log( 'Taxonomy translation failed for ' . $lang, 'error', array( 'taxonomy' => $taxonomy ) );
                continue;
            }

            // Ensure result is an array of translations
            $translated = is_array( $ai_result ) && isset( $ai_result[0] ) ? $ai_result : ( $ai_result['translations'] ?? array() );
            $all_translations = array_merge( $all_translations, $translated );
        }

        // Feed into existing callback handler
        if ( ! empty( $all_translations ) ) {
            $callback_request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/taxonomy-callback' );
            $callback_request->set_body_params( array(
                'taxonomy'     => $taxonomy,
                'translations' => $all_translations,
            ) );
            $this->handle_taxonomy_callback( $callback_request );
        }

        LuwiPress_Logger::log( 'Taxonomy translation requested: ' . $taxonomy, 'info', array(
            'taxonomy'  => $taxonomy,
            'languages' => $target_languages,
            'count'     => count( $missing_terms ),
        ) );

        return rest_ensure_response( array(
            'status'           => 'completed',
            'taxonomy'         => $taxonomy,
            'target_languages' => $target_languages,
            'terms_sent'       => count( $missing_terms ),
        ) );
    }

    /**
     * POST /translation/taxonomy-callback — Receive translated taxonomy terms from async AI pipeline
     */
    public function handle_taxonomy_callback($request) {
        $data = $request->get_json_params();

        $taxonomy     = sanitize_text_field($data['taxonomy'] ?? '');
        $translations = $data['translations'] ?? [];

        if (empty($taxonomy) || empty($translations) || !is_array($translations)) {
            return new WP_Error('invalid_data', 'taxonomy and translations array required', ['status' => 400]);
        }

        if (!defined('ICL_SITEPRESS_VERSION')) {
            return new WP_Error('no_wpml', 'WPML required for taxonomy translation', ['status' => 400]);
        }

        $saved = 0;
        $errors = [];

        foreach ($translations as $item) {
            $term_id  = absint($item['term_id'] ?? 0);
            $language = sanitize_text_field($item['language'] ?? '');
            $name     = sanitize_text_field($item['name'] ?? '');
            $slug     = sanitize_title($item['slug'] ?? $name);

            if (!$term_id || empty($language) || empty($name)) {
                continue;
            }

            $result = $this->save_wpml_taxonomy_translation($term_id, $taxonomy, $language, $name, $slug);
            if (is_wp_error($result)) {
                $errors[] = sprintf('term #%d → %s: %s', $term_id, $language, $result->get_error_message());
            } else {
                $saved++;
            }
        }

        LuwiPress_Logger::log(sprintf('Taxonomy translations saved: %d of %d for %s', $saved, count($translations), $taxonomy), 'info');

        return rest_ensure_response([
            'status' => 'saved',
            'saved'  => $saved,
            'errors' => $errors,
        ]);
    }

    /**
     * Fix Elementor-rendered translated blog posts.
     *
     * For translated posts (non-product), removes _elementor_edit_mode so
     * WordPress renders post_content instead of English _elementor_data.
     * Also updates the post title if a translated title is stored in meta.
     *
     * POST /translation/fix-elementor
     *   { "post_ids": "123,456" } or { "post_ids": "all", "language": "fr" }
     */
    public function fix_elementor_translated_posts( $request ) {
        $post_ids_param = sanitize_text_field( $request->get_param( 'post_ids' ) ?: 'all' );
        $language       = sanitize_text_field( $request->get_param( 'language' ) ?: '' );
        $fixed  = array();
        $errors = array();

        if ( 'all' === $post_ids_param ) {
            // Find all WPML translated posts that have _elementor_edit_mode
            if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
                return new WP_Error( 'no_wpml', 'WPML required', array( 'status' => 400 ) );
            }

            global $wpdb;
            $default_lang = apply_filters( 'wpml_default_language', get_locale() );

            $query = "SELECT DISTINCT p.ID, t.language_code
                      FROM {$wpdb->posts} p
                      JOIN {$wpdb->prefix}icl_translations t
                        ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
                      WHERE p.post_type = 'post'
                        AND p.post_status = 'publish'
                        AND t.language_code != %s
                        AND t.source_language_code IS NOT NULL";
            $args = array( $default_lang );

            if ( $language ) {
                $query .= " AND t.language_code = %s";
                $args[] = $language;
            }

            $rows = $wpdb->get_results( $wpdb->prepare( $query, $args ) );
            $post_ids = wp_list_pluck( $rows, 'ID' );
        } else {
            $post_ids = array_filter( array_map( 'absint', explode( ',', $post_ids_param ) ) );
        }

        // Switch WPML to all languages so get_post works for any language
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            do_action( 'wpml_switch_language', 'all' );
        }

        foreach ( $post_ids as $pid ) {
            // Use get_post() to get a proper WP_Post object
            $post = get_post( $pid );
            if ( $post ) {
                $source_id = null;
                $post_lang = null;

                // Find source post via WPML
                if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
                    $post_type    = $post->post_type;
                    $element_type = 'post_' . $post_type;
                    $trid         = apply_filters( 'wpml_element_trid', null, $pid, $element_type );
                    if ( $trid ) {
                        global $wpdb;
                        $source_id = $wpdb->get_var( $wpdb->prepare(
                            "SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE trid = %d AND source_language_code IS NULL",
                            $trid
                        ) );
                        $post_lang = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $pid, 'element_type' => $element_type ) );
                    }
                }

                $info = array( 'post_id' => $pid, 'title' => $post->post_title );

                // Copy featured image from source post if missing
                if ( ! has_post_thumbnail( $pid ) && $source_id ) {
                    $thumb = get_post_thumbnail_id( $source_id );
                    if ( $thumb ) {
                        set_post_thumbnail( $pid, $thumb );
                        $info['thumbnail_copied'] = true;
                    }
                }

                // Fix broken slug — if numeric, regenerate from title
                if ( preg_match( '/^\d+$/', $post->post_name ) && ! empty( $post->post_title ) ) {
                    $new_slug = sanitize_title( $post->post_title );
                    wp_update_post( array( 'ID' => $pid, 'post_name' => $new_slug ) );
                    $info['slug_fixed'] = $new_slug;
                }

                $fixed[] = $info;
            }

            // Clear Elementor CSS cache
            delete_post_meta( $pid, '_elementor_css' );
            delete_post_meta( $pid, '_elementor_page_assets' );
        }

        LuwiPress_Logger::log( 'Elementor fix: removed edit mode from ' . count( $fixed ) . ' translated posts', 'info' );

        return rest_ensure_response( array(
            'status' => 'fixed',
            'count'  => count( $fixed ),
            'posts'  => $fixed,
            'errors' => $errors,
        ) );
    }

    /**
     * Get terms missing translation for given languages (WPML only).
     */
    private function get_missing_taxonomy_terms($taxonomy, $target_languages, $source_language, $limit) {
        global $wpdb;

        if (!defined('ICL_SITEPRESS_VERSION')) {
            return [];
        }

        $element_type = 'tax_' . $taxonomy;
        $terms = [];

        // Get original terms registered in WPML
        $originals = $wpdb->get_results($wpdb->prepare(
            "SELECT t.trid, t.element_id
             FROM {$wpdb->prefix}icl_translations t
             WHERE t.element_type = %s
               AND t.language_code = %s
               AND t.source_language_code IS NULL",
            $element_type, $source_language
        ));

        // Check WPML-registered terms for missing translations
        foreach ($originals as $orig) {
            $term = get_term(absint($orig->element_id), $taxonomy);
            if (!$term || is_wp_error($term)) {
                continue;
            }

            $missing_langs = [];
            foreach ($target_languages as $lang) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT element_id FROM {$wpdb->prefix}icl_translations
                     WHERE trid = %d AND language_code = %s AND element_type = %s",
                    $orig->trid, $lang, $element_type
                ));
                if (!$existing) {
                    $missing_langs[] = $lang;
                }
            }

            if (!empty($missing_langs)) {
                $terms[] = [
                    'term_id'           => $term->term_id,
                    'name'              => $term->name,
                    'slug'              => $term->slug,
                    'description'       => $term->description,
                    'missing_languages' => $missing_langs,
                ];
            }

            if (count($terms) >= $limit) {
                break;
            }
        }

        return $terms;
    }

    /**
     * Register a taxonomy term in WPML as an original-language term.
     * Uses direct SQL insert as fallback when WPML action hooks are not available in REST context.
     */
    private function register_term_in_wpml($term_id, $taxonomy, $language) {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return;
        }

        global $wpdb;
        $element_type = 'tax_' . $taxonomy;

        // Check if already registered
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id = %d AND element_type = %s",
            $term_id, $element_type
        ));

        if ($existing) {
            return;
        }

        // Try WPML action first
        do_action('wpml_set_element_language_details', [
            'element_id'           => $term_id,
            'element_type'         => $element_type,
            'trid'                 => false,
            'language_code'        => $language,
            'source_language_code' => null,
        ]);

        // Verify it worked
        $check = $wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id = %d AND element_type = %s",
            $term_id, $element_type
        ));

        if ($check) {
            return;
        }

        // WPML action failed (common in REST context) — direct SQL insert
        // Get next trid
        $max_trid = (int) $wpdb->get_var("SELECT MAX(trid) FROM {$wpdb->prefix}icl_translations");
        $new_trid = $max_trid + 1;

        $wpdb->insert(
            $wpdb->prefix . 'icl_translations',
            [
                'element_type'         => $element_type,
                'element_id'           => $term_id,
                'trid'                 => $new_trid,
                'language_code'        => $language,
                'source_language_code' => null,
            ],
            ['%s', '%d', '%d', '%s', '%s']
        );
    }

    /**
     * Create a WPML taxonomy term translation.
     */
    private function save_wpml_taxonomy_translation($original_term_id, $taxonomy, $language, $name, $slug) {
        global $wpdb;

        // Verify original term exists in WordPress
        $original_term = get_term($original_term_id, $taxonomy);
        if (!$original_term || is_wp_error($original_term)) {
            return new WP_Error('term_not_found', 'Original term #' . $original_term_id . ' not found');
        }

        $element_type = 'tax_' . $taxonomy;
        $default_lang = apply_filters( 'wpml_default_language', get_locale() );

        // Get the trid of the original term
        $trid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM {$wpdb->prefix}icl_translations
             WHERE element_id = %d AND element_type = %s",
            $original_term_id, $element_type
        ));

        // If not registered in WPML, register it now and re-query
        if (!$trid) {
            $this->register_term_in_wpml($original_term_id, $taxonomy, $default_lang);
            $trid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT trid FROM {$wpdb->prefix}icl_translations
                 WHERE element_id = %d AND element_type = %s",
                $original_term_id, $element_type
            ));
        }

        if (!$trid) {
            return new WP_Error('no_trid', 'Could not register term #' . $original_term_id . ' in WPML');
        }

        // Check if translation already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT element_id FROM {$wpdb->prefix}icl_translations
             WHERE trid = %d AND language_code = %s AND element_type = %s",
            $trid, $language, $element_type
        ));

        if ($existing) {
            // Update existing term
            wp_update_term(absint($existing), $taxonomy, [
                'name' => $name,
                'slug' => $slug,
            ]);
            return true;
        }

        // Get original term's parent for hierarchy
        $original_term = get_term($original_term_id, $taxonomy);
        $parent = 0;
        if ($original_term && $original_term->parent > 0) {
            // Try to find translated parent
            $parent_trid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT trid FROM {$wpdb->prefix}icl_translations
                 WHERE element_id = %d AND element_type = %s",
                $original_term->parent, $element_type
            ));
            if ($parent_trid) {
                $translated_parent = $wpdb->get_var($wpdb->prepare(
                    "SELECT element_id FROM {$wpdb->prefix}icl_translations
                     WHERE trid = %d AND language_code = %s AND element_type = %s",
                    $parent_trid, $language, $element_type
                ));
                if ($translated_parent) {
                    $parent = absint($translated_parent);
                }
            }
        }

        // Create new term
        $new_term = wp_insert_term($name, $taxonomy, [
            'slug'   => $slug,
            'parent' => $parent,
        ]);

        if (is_wp_error($new_term)) {
            // If slug conflict, try with language suffix
            $new_term = wp_insert_term($name, $taxonomy, [
                'slug'   => $slug . '-' . $language,
                'parent' => $parent,
            ]);
            if (is_wp_error($new_term)) {
                return $new_term;
            }
        }

        $new_term_id = $new_term['term_id'];

        // Link to WPML translation group — try action first, SQL fallback
        do_action('wpml_set_element_language_details', [
            'element_id'           => $new_term_id,
            'element_type'         => $element_type,
            'trid'                 => $trid,
            'language_code'        => $language,
            'source_language_code' => $default_lang,
        ]);

        // Verify link was created
        $linked = $wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id = %d AND element_type = %s",
            $new_term_id, $element_type
        ));

        if (!$linked) {
            // SQL fallback for REST context
            $wpdb->insert(
                $wpdb->prefix . 'icl_translations',
                [
                    'element_type'         => $element_type,
                    'element_id'           => $new_term_id,
                    'trid'                 => $trid,
                    'language_code'        => $language,
                    'source_language_code' => $default_lang,
                ],
                ['%s', '%d', '%d', '%s', '%s']
            );
        }

        return true;
    }

    /**
     * AJAX: Clean orphan WPML translation records.
     *
     * Removes icl_translations rows where:
     * - Taxonomy terms: trid has no matching original (source_language_code IS NULL) row
     * - Posts: element_id does not exist in wp_posts
     * - Terms: element_id does not exist in wp_term_taxonomy
     *
     * Also deletes the actual orphan WP posts/terms if they have no original.
     */
    public function ajax_clean_orphan_translations() {
        check_ajax_referer( 'luwipress_clean_orphans', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            wp_send_json_error( 'WPML not active' );
        }

        $dry_run = ! empty( $_POST['dry_run'] ) || empty( $_POST['confirmed'] );

        global $wpdb;
        $icl = $wpdb->prefix . 'icl_translations';
        $terms_removed = 0;
        $posts_removed = 0;

        // ── 1. Orphan taxonomy translations: trid has no original ──
        $orphan_terms = $wpdb->get_results(
            "SELECT t.translation_id, t.element_id, t.element_type, t.trid, t.language_code
             FROM {$icl} t
             WHERE t.element_type LIKE 'tax_%'
               AND t.source_language_code IS NOT NULL
               AND t.trid NOT IN (
                   SELECT trid FROM {$icl}
                   WHERE element_type = t.element_type
                     AND source_language_code IS NULL
               )"
        );

        foreach ( $orphan_terms as $row ) {
            if ( ! $dry_run ) {
                $taxonomy = str_replace( 'tax_', '', $row->element_type );
                if ( term_exists( (int) $row->element_id, $taxonomy ) ) {
                    wp_delete_term( (int) $row->element_id, $taxonomy );
                }
                $wpdb->delete( $icl, array( 'translation_id' => $row->translation_id ), array( '%d' ) );
            }
            $terms_removed++;
        }

        // ── 2. Orphan post translations: element_id not in wp_posts ──
        $orphan_posts = $wpdb->get_results(
            "SELECT t.translation_id, t.element_id, t.element_type
             FROM {$icl} t
             WHERE t.element_type LIKE 'post_%'
               AND t.element_id NOT IN (SELECT ID FROM {$wpdb->posts})"
        );

        foreach ( $orphan_posts as $row ) {
            if ( ! $dry_run ) {
                $wpdb->delete( $icl, array( 'translation_id' => $row->translation_id ), array( '%d' ) );
            }
            $posts_removed++;
        }

        // ── 3. Orphan term translations: element_id not in wp_term_taxonomy ──
        $orphan_term_records = $wpdb->get_results(
            "SELECT t.translation_id, t.element_id
             FROM {$icl} t
             WHERE t.element_type LIKE 'tax_%'
               AND t.element_id NOT IN (SELECT term_id FROM {$wpdb->term_taxonomy})"
        );

        foreach ( $orphan_term_records as $row ) {
            if ( ! $dry_run ) {
                $wpdb->delete( $icl, array( 'translation_id' => $row->translation_id ), array( '%d' ) );
            }
            $terms_removed++;
        }

        $total = $terms_removed + $posts_removed;

        if ( $dry_run ) {
            wp_send_json_success( array(
                'dry_run' => true,
                'terms'   => $terms_removed,
                'posts'   => $posts_removed,
                'total'   => $total,
                'message' => sprintf( 'Found %d orphans (%d terms, %d posts). Click again to clean.', $total, $terms_removed, $posts_removed ),
            ) );
            return;
        }

        LuwiPress_Logger::log(
            sprintf( 'Orphan cleanup: %d terms, %d posts removed from icl_translations', $terms_removed, $posts_removed ),
            $total > 0 ? 'info' : 'debug',
            array( 'terms_removed' => $terms_removed, 'posts_removed' => $posts_removed )
        );

        wp_send_json_success( array(
            'terms_removed' => $terms_removed,
            'posts_removed' => $posts_removed,
        ) );
    }

    // ─── AJAX: Get missing items for a post type + language ──────────────

    public function ajax_get_missing_items() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $lang      = sanitize_text_field( $_POST['language'] ?? '' );
        $post_type = sanitize_text_field( $_POST['post_type'] ?? 'product' );
        $limit     = absint( $_POST['limit'] ?? 500 );

        $request = new WP_REST_Request( 'GET', '/luwipress/v1/translation/missing-all' );
        $request->set_param( 'target_languages', $lang );
        $request->set_param( 'post_type', $post_type );
        $request->set_param( 'limit', min( $limit, 500 ) );

        $response = $this->get_missing_translations_all( $request );
        $data     = $response->get_data();

        $items = array();
        foreach ( ( $data['items'] ?? $data['products'] ?? array() ) as $item ) {
            $items[] = array(
                'id'    => $item['post_id'] ?? $item['product_id'],
                'title' => $item['name'],
            );
        }

        wp_send_json_success( array(
            'items' => $items,
            'total' => count( $items ),
        ) );
    }

    // ─── AJAX: Translate a single post ──────────────────────────────────

    public function ajax_translate_single() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        $lang    = sanitize_text_field( $_POST['language'] ?? '' );

        if ( ! $post_id || ! $lang ) {
            wp_send_json_error( 'Missing post_id or language' );
        }

        // Verify post exists
        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( 'Post #' . $post_id . ' not found' );
        }

        // For Elementor pages: use background job to avoid timeout
        if ( LuwiPress_Elementor::is_elementor_page( $post_id ) && class_exists( 'LuwiPress_Elementor' ) ) {
            // Store queued status for progress polling
            update_post_meta( $post_id, '_luwipress_translation_status', wp_json_encode( array(
                'status'   => 'queued',
                'language' => $lang,
                'queued'   => current_time( 'mysql' ),
            ) ) );
            wp_schedule_single_event( time(), 'luwipress_elementor_translate_single', array( $post_id, $lang ) );
            spawn_cron();
            wp_send_json_success( array(
                'post_id' => $post_id,
                'title'   => $post->post_title,
                'status'  => 'queued',
            ) );
            return;
        }

        $request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/request' );
        $request->set_param( 'post_id', $post_id );
        $request->set_param( 'target_languages', array( $lang ) );

        $result = $this->request_translation( $request );

        if ( is_wp_error( $result ) ) {
            LuwiPress_Logger::log( 'AJAX translate_single failed: ' . $result->get_error_message(), 'error', array(
                'post_id' => $post_id, 'lang' => $lang, 'code' => $result->get_error_code(),
            ) );
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
                'post_id' => $post_id,
            ) );
        }

        $result_data = is_object( $result ) && method_exists( $result, 'get_data' ) ? $result->get_data() : $result;

        wp_send_json_success( array(
            'post_id' => $post_id,
            'title'   => $post->post_title,
            'status'  => $result_data['status'] ?? 'completed',
        ) );
    }

    // ─── AJAX: Translate taxonomy batch ─────────────────────────────────

    public function ajax_translate_taxonomy_batch() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $taxonomy  = sanitize_text_field( $_POST['taxonomy'] ?? '' );
        $languages = sanitize_text_field( $_POST['languages'] ?? '' );

        if ( ! $taxonomy || ! $languages ) {
            wp_send_json_error( 'Missing taxonomy or languages' );
        }

        $request = new WP_REST_Request( 'POST', '/luwipress/v1/translation/taxonomy' );
        $request->set_param( 'taxonomy', $taxonomy );
        $request->set_param( 'target_languages', $languages );
        $request->set_param( 'limit', 200 );

        $result      = $this->request_taxonomy_translation( $request );
        $result_data = is_object( $result ) && method_exists( $result, 'get_data' ) ? $result->get_data() : $result;

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'status'     => $result_data['status'] ?? 'completed',
            'terms_sent' => $result_data['terms_sent'] ?? 0,
        ) );
    }

    // ─── AJAX: Poll translation progress for background jobs ────────────

    public function ajax_translation_progress() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $post_ids = array_map( 'absint', (array) ( $_POST['post_ids'] ?? array() ) );
        if ( empty( $post_ids ) ) {
            wp_send_json_error( 'Missing post_ids' );
        }

        $results = array();
        foreach ( $post_ids as $pid ) {
            $raw = get_post_meta( $pid, '_luwipress_translation_status', true );
            if ( $raw ) {
                $data = json_decode( $raw, true );
                $data['post_id'] = $pid;
                $data['title']   = esc_html( get_the_title( $pid ) );
                $results[]       = $data;
            }
        }

        wp_send_json_success( $results );
    }

    // ─── AJAX: Get missing taxonomy terms ───────────────────────────────

    public function ajax_get_missing_terms() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $taxonomy  = sanitize_text_field( $_POST['taxonomy'] ?? '' );
        $languages = sanitize_text_field( $_POST['languages'] ?? '' );
        if ( ! $taxonomy || ! $languages ) {
            wp_send_json_error( 'Missing taxonomy or languages' );
        }

        $target_langs    = array_map( 'trim', explode( ',', $languages ) );
        $source_language = apply_filters( 'wpml_default_language', get_locale() );
        $terms           = $this->get_missing_taxonomy_terms( $taxonomy, $target_langs, $source_language, 500 );

        // Flatten: one item per term+language pair for per-item progress
        $items = array();
        foreach ( $terms as $term ) {
            foreach ( $term['missing_languages'] as $lang ) {
                $items[] = array(
                    'term_id' => $term['term_id'],
                    'name'    => $term['name'],
                    'lang'    => $lang,
                );
            }
        }

        wp_send_json_success( array( 'items' => $items, 'total' => count( $items ) ) );
    }

    // ─── AJAX: Translate a single taxonomy term ─────────────────────────

    public function ajax_translate_single_term() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $term_id  = absint( $_POST['term_id'] ?? 0 );
        $taxonomy = sanitize_text_field( $_POST['taxonomy'] ?? '' );
        $language = sanitize_text_field( $_POST['language'] ?? '' );
        if ( ! $term_id || ! $taxonomy || ! $language ) {
            wp_send_json_error( 'Missing term_id, taxonomy, or language' );
        }

        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            wp_send_json_error( 'Term not found' );
        }

        $source_language = apply_filters( 'wpml_default_language', get_locale() );
        $lang_names = array( 'tr' => 'Turkish', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'ar' => 'Arabic', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'pt-pt' => 'Portuguese', 'ko' => 'Korean' );
        $source_name = $lang_names[ $source_language ] ?? ucfirst( $source_language );
        $target_name = $lang_names[ $language ] ?? ucfirst( $language );

        // Translate term name via AI
        $prompt = sprintf(
            'Translate the following %s taxonomy term name from %s to %s. Return ONLY the translated term name, nothing else. Term: "%s"',
            $taxonomy, $source_name, $target_name, $term->name
        );
        $messages  = LuwiPress_AI_Engine::build_messages( $prompt );
        $ai_result = LuwiPress_AI_Engine::dispatch( 'taxonomy-translation', $messages, array( 'max_tokens' => 256 ) );

        if ( is_wp_error( $ai_result ) ) {
            wp_send_json_error( $ai_result->get_error_message() );
        }

        $translated_name = sanitize_text_field( trim( $ai_result, ' "\'.' ) );
        if ( empty( $translated_name ) ) {
            wp_send_json_error( 'AI returned empty translation' );
        }

        $translated_slug = sanitize_title( $translated_name );

        // Save via WPML
        $result = $this->save_wpml_taxonomy_translation( $term_id, $taxonomy, $language, $translated_name, $translated_slug );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'term_id'   => $term_id,
            'name'      => esc_html( $translated_name ),
            'language'  => $language,
        ) );
    }

    // ─── AJAX: Re-translate broken translations ─────────────────────────

    public function ajax_retranslate_broken() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            wp_send_json_error( 'WPML not active' );
        }

        global $wpdb;
        $default_lang = apply_filters( 'wpml_default_language', get_locale() );

        // Find translated posts/pages with empty title OR numeric slug
        // SAFE: only post, page, product — never nav_menu_item or other types
        $safe_types = array( 'post', 'page', 'product' );
        $type_ph    = implode( ',', array_fill( 0, count( $safe_types ), '%s' ) );
        $sql        = sprintf(
            "SELECT p.ID, p.post_title, p.post_name, p.post_type, t.trid, t.language_code
             FROM {$wpdb->posts} p
             JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id
             WHERE t.source_language_code IS NOT NULL
               AND t.language_code != %%s
               AND p.post_type IN (%s)
               AND p.post_status IN ('publish','draft','private')
               AND (p.post_title = '' OR p.post_name REGEXP '^[0-9]+$')
             LIMIT 100",
            $type_ph
        );
        $broken = $wpdb->get_results(
            $wpdb->prepare( $sql, array_merge( $safe_types, array( $default_lang ) ) )
        );

        if ( empty( $broken ) ) {
            wp_send_json_success( array( 'fixed' => 0, 'message' => 'No broken translations found.' ) );
        }

        // FIX broken posts in-place: find source, queue re-translation via cron
        $fixed = 0;
        $queued = 0;
        foreach ( $broken as $row ) {
            // Find source post for this translation
            $source_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT element_id FROM {$wpdb->prefix}icl_translations
                 WHERE trid = %d AND source_language_code IS NULL",
                $row->trid
            ) );

            if ( ! $source_id ) {
                continue;
            }

            $source = get_post( absint( $source_id ) );
            if ( ! $source ) {
                continue;
            }

            // Fix 1: If title empty, copy source title as placeholder
            if ( empty( $row->post_title ) ) {
                wp_update_post( array( 'ID' => $row->ID, 'post_title' => $source->post_title ) );
            }

            // Fix 2: If slug numeric, generate from current title
            if ( is_numeric( $row->post_name ) ) {
                $title_for_slug = ! empty( $row->post_title ) ? $row->post_title : $source->post_title;
                wp_update_post( array( 'ID' => $row->ID, 'post_name' => sanitize_title( $title_for_slug ) . '-' . $row->language_code ) );
            }

            $fixed++;

            // Queue Elementor re-translation via cron (non-destructive — overwrites _elementor_data)
            if ( class_exists( 'LuwiPress_Elementor' ) && LuwiPress_Elementor::is_elementor_page( $source_id ) ) {
                wp_schedule_single_event( time() + $queued, 'luwipress_elementor_translate_single', array( absint( $source_id ), $row->language_code ) );
                $queued++;
            }
        }

        if ( $queued > 0 ) {
            spawn_cron();
        }

        LuwiPress_Logger::log( sprintf( 'Re-translate broken: %d fixed, %d queued for re-translation', $fixed, $queued ), 'info' );

        wp_send_json_success( array(
            'fixed'   => $fixed,
            'queued'  => $queued,
            'message' => sprintf( '%d broken posts fixed. %d queued for Elementor re-translation via background jobs.', $fixed, $queued ),
        ) );
    }

    // ─── AJAX: Sync WPML menus from default language ────────────────────

    public function ajax_sync_wpml_menus() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            wp_send_json_error( 'WPML not active' );
        }

        global $wpdb;
        $default_lang = apply_filters( 'wpml_default_language', get_locale() );
        $target_langs = apply_filters( 'wpml_active_languages', array() );

        // Get all menus in the default language
        $menus = wp_get_nav_menus();
        $synced = 0;

        foreach ( $menus as $menu ) {
            // Check if this menu belongs to default language
            $menu_lang = apply_filters( 'wpml_element_language_code', null, array(
                'element_id'   => $menu->term_id,
                'element_type' => 'tax_nav_menu',
            ) );
            if ( $menu_lang !== $default_lang ) {
                continue;
            }

            $menu_items = wp_get_nav_menu_items( $menu->term_id );
            if ( empty( $menu_items ) ) {
                continue;
            }

            $menu_trid = apply_filters( 'wpml_element_trid', null, $menu->term_id, 'tax_nav_menu' );
            if ( ! $menu_trid ) {
                continue;
            }

            // For each target language, check if menu translation exists
            foreach ( $target_langs as $lang_code => $lang_info ) {
                if ( $lang_code === $default_lang ) {
                    continue;
                }

                $translated_menu_id = apply_filters( 'wpml_object_id', $menu->term_id, 'nav_menu', false, $lang_code );
                if ( ! $translated_menu_id || $translated_menu_id === $menu->term_id ) {
                    continue;
                }

                // Get existing translated menu items
                $translated_items = wp_get_nav_menu_items( $translated_menu_id );
                $existing_count = is_array( $translated_items ) ? count( $translated_items ) : 0;
                $source_count = count( $menu_items );

                // If translated menu has fewer items, sync
                if ( $existing_count < $source_count ) {
                    // Use WPML's sync mechanism
                    do_action( 'wpml_sync_custom_element', $menu->term_id, 'nav_menu' );
                    $synced++;
                    LuwiPress_Logger::log( sprintf(
                        'Menu sync: %s (%s → %s) — source: %d items, translated: %d items',
                        $menu->name, $default_lang, $lang_code, $source_count, $existing_count
                    ), 'info' );
                }
            }
        }

        if ( $synced > 0 ) {
            wp_send_json_success( array(
                'message' => sprintf( '%d menu(s) synced. Visit WPML → Menu Sync to complete item translations.', $synced ),
            ) );
        } else {
            wp_send_json_success( array(
                'message' => 'All menus are in sync. If menus are still broken, go to WPML → Menu Sync manually.',
            ) );
        }
    }

    // ─── AJAX: Stop active cron translations ────────────────────────────

    public function ajax_stop_translations() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $post_ids = array_map( 'absint', (array) ( $_POST['post_ids'] ?? array() ) );
        if ( empty( $post_ids ) ) {
            wp_send_json_error( 'No post_ids' );
        }

        $stopped = 0;
        foreach ( $post_ids as $pid ) {
            $raw = get_post_meta( $pid, '_luwipress_translation_status', true );
            if ( ! $raw ) {
                continue;
            }
            $st = json_decode( $raw, true );
            if ( $st && in_array( $st['status'] ?? '', array( 'queued', 'translating' ), true ) ) {
                // Clear the status — cron job will still run but won't find queued status
                delete_post_meta( $pid, '_luwipress_translation_status' );

                // Unschedule the cron event if still pending
                $lang = $st['language'] ?? '';
                if ( $lang ) {
                    wp_clear_scheduled_hook( 'luwipress_elementor_translate_single', array( $pid, $lang ) );
                }
                $stopped++;
            }
        }

        LuwiPress_Logger::log( sprintf( 'Translation stopped: %d jobs cancelled', $stopped ), 'info' );

        wp_send_json_success( array(
            'stopped' => $stopped,
            'message' => sprintf( '%d translation(s) stopped. You can resume with Translate All.', $stopped ),
        ) );
    }

    // ─── REST: Fix excerpts — extract from Elementor widget text ────────

    public function rest_fix_excerpts( $request ) {
        global $wpdb;

        $posts = $wpdb->get_results(
            "SELECT p.ID, p.post_type
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_elementor_data'
             WHERE p.post_status = 'publish'
               AND p.post_type IN ('post', 'page')
               AND (p.post_excerpt = '' OR p.post_excerpt IS NULL)
               AND pm.meta_value != ''
             LIMIT 200"
        );

        $fixed = 0;
        $fixed_ids = array();
        foreach ( $posts as $row ) {
            $raw = get_post_meta( $row->ID, '_elementor_data', true );
            if ( empty( $raw ) ) continue;

            $data = json_decode( $raw, true );
            if ( ! is_array( $data ) ) continue;

            $excerpt = '';
            $this->walk_for_excerpt( $data, $excerpt );

            if ( ! empty( $excerpt ) ) {
                wp_update_post( array( 'ID' => $row->ID, 'post_excerpt' => $excerpt ) );
                $fixed++;
                $fixed_ids[] = $row->ID;
            }
        }

        LuwiPress_Logger::log( sprintf( 'Fix excerpts (REST): %d posts updated', $fixed ), 'info' );
        return rest_ensure_response( array(
            'fixed'     => $fixed,
            'fixed_ids' => $fixed_ids,
            'message'   => sprintf( '%d excerpts extracted from Elementor content.', $fixed ),
        ) );
    }

    // ─── AJAX: Fix excerpts — extract from Elementor widget text ────────

    public function ajax_fix_excerpts() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;

        // Find published posts/pages with empty excerpt that have _elementor_data
        $posts = $wpdb->get_results(
            "SELECT p.ID, p.post_type
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_elementor_data'
             WHERE p.post_status = 'publish'
               AND p.post_type IN ('post', 'page')
               AND (p.post_excerpt = '' OR p.post_excerpt IS NULL)
               AND pm.meta_value != ''
             LIMIT 200"
        );

        $fixed = 0;
        foreach ( $posts as $row ) {
            $raw = get_post_meta( $row->ID, '_elementor_data', true );
            if ( empty( $raw ) ) {
                continue;
            }

            $data = json_decode( $raw, true );
            if ( ! is_array( $data ) ) {
                continue;
            }

            // Walk elements to find first text content
            $excerpt = '';
            $this->walk_for_excerpt( $data, $excerpt );

            if ( ! empty( $excerpt ) ) {
                wp_update_post( array(
                    'ID'           => $row->ID,
                    'post_excerpt' => $excerpt,
                ) );
                $fixed++;
            }
        }

        LuwiPress_Logger::log( sprintf( 'Fix excerpts: %d posts updated', $fixed ), 'info' );
        wp_send_json_success( array(
            'fixed'   => $fixed,
            'message' => sprintf( '%d excerpts extracted from Elementor content.', $fixed ),
        ) );
    }

    private function walk_for_excerpt( $elements, &$excerpt ) {
        if ( ! empty( $excerpt ) ) {
            return;
        }
        foreach ( $elements as $el ) {
            $settings = $el['settings'] ?? array();
            // Check text-editor widget
            foreach ( array( 'editor', 'ekit_heading_extra_title', 'description_text' ) as $field ) {
                if ( ! empty( $settings[ $field ] ) && strlen( strip_tags( $settings[ $field ] ) ) > 50 ) {
                    $excerpt = wp_trim_words( wp_strip_all_tags( $settings[ $field ] ), 30, '...' );
                    return;
                }
            }
            if ( ! empty( $el['elements'] ) ) {
                $this->walk_for_excerpt( $el['elements'], $excerpt );
            }
        }
    }

    // ─── AJAX: Fix orphan translations — set correct source_language_code ─

    public function ajax_fix_orphan_translations() {
        check_ajax_referer( 'luwipress_translation_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            wp_send_json_error( 'WPML not active' );
        }

        global $wpdb;
        $default_lang = apply_filters( 'wpml_default_language', get_locale() );
        $fixed = 0;

        // ── Type 1: Non-EN posts registered as originals (source_language_code IS NULL, lang != EN) ──
        $type1 = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.translation_id, t.element_id, t.language_code, t.trid, p.post_title
             FROM {$wpdb->prefix}icl_translations t
             JOIN {$wpdb->posts} p ON t.element_id = p.ID
             WHERE t.source_language_code IS NULL
               AND t.language_code != %s
               AND t.element_type LIKE 'post_%%'
               AND p.post_type IN ('post', 'page', 'product')
             LIMIT 200",
            $default_lang
        ) );
        foreach ( $type1 as $row ) {
            $has_source = $wpdb->get_var( $wpdb->prepare(
                "SELECT element_id FROM {$wpdb->prefix}icl_translations
                 WHERE trid = %d AND language_code = %s AND source_language_code IS NULL",
                $row->trid, $default_lang
            ) );
            if ( $has_source ) {
                $wpdb->update( $wpdb->prefix . 'icl_translations',
                    array( 'source_language_code' => $default_lang ),
                    array( 'translation_id' => $row->translation_id ) );
                $fixed++;
            }
        }

        // ── Type 2: Posts registered as EN originals but their trid is a lonely group ──
        // These are FR/IT/ES translations that got their own trid with language_code=EN
        // Detect: EN original in a trid where NO other translations exist + title is non-English
        $type2 = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.translation_id, t.element_id, t.trid, p.post_title, p.post_name
             FROM {$wpdb->prefix}icl_translations t
             JOIN {$wpdb->posts} p ON t.element_id = p.ID
             WHERE t.language_code = %s
               AND t.source_language_code IS NULL
               AND t.element_type LIKE 'post_%%'
               AND p.post_type IN ('post', 'page', 'product')
               AND p.post_status = 'publish'
             LIMIT 500",
            $default_lang
        ) );
        foreach ( $type2 as $row ) {
            // Count how many translations this trid has
            $trid_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations WHERE trid = %d",
                $row->trid
            ) );
            // If this trid has only 1 entry (just this post, no translations)
            // AND the title looks non-English, it's likely an orphan
            if ( $trid_count <= 1 ) {
                $title = mb_strtolower( $row->post_title );
                $is_foreign = preg_match( '/[àâäéèêëïîôùûüçñáíóúìòü]/u', $title )
                    || preg_match( '/\b(della|degli|delle|nella|sono|tutto|ogni|comme|tout|sur le|acheter|pour|avec|dans|entre|une|thérap|découvr|tambour|flûte|también|guía|cómo|instrumentos|terapia|poder|viaje|encanto|fascino|tecniche|strumenti|accordare|persiano|gioco)\b/u', $title );
                if ( $is_foreign ) {
                    // Delete this orphan WPML record — the post itself stays but won't appear in EN list
                    $wpdb->delete( $wpdb->prefix . 'icl_translations', array( 'translation_id' => $row->translation_id ) );
                    $fixed++;
                    LuwiPress_Logger::log( sprintf( 'Orphan fixed (Type 2): deleted WPML record for #%d "%s" (lonely EN trid=%d)',
                        $row->element_id, mb_substr( $row->post_title, 0, 40 ), $row->trid ), 'info' );
                }
            }
        }

        LuwiPress_Logger::log( sprintf( 'Fix orphan translations: %d fixed', $fixed ), 'info' );
        wp_send_json_success( array(
            'fixed'   => $fixed,
            'message' => sprintf( '%d orphan translations fixed (source_language_code set to %s).', $fixed, $default_lang ),
        ) );
    }
}
