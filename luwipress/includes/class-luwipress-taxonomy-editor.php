<?php
/**
 * LuwiPress Taxonomy Editor — Sprint 2 (3.5.6+)
 *
 * Multi-language matrix editor for product_cat (extensible to any taxonomy).
 * One admin page mounted at LuwiPress -> Taxonomy Editor. Each term group
 * (trid in WPML, translation group in Polylang) is rendered as a single
 * accordion section with a (field x language) matrix inside: 5 fields x
 * N languages, click-to-edit cells, Save All batches every dirty cell
 * into ONE round trip.
 *
 * Save path collapses 52 categories x 4 langs x 5 fields = 1040 sequential
 * MCP calls into a single REST POST. Internally:
 *   - SEO meta (RM title/desc/focus_keyword) -> LuwiPress_API bulk handler
 *   - Term name + description -> WPML-aware update loop (mirrors
 *     webmcp taxonomy_update_term — slug-collision bypass via
 *     direct $wpdb description write OR sitepress->switch_lang()
 *     before wp_update_term for full name/slug updates).
 *
 * REST surface (luwipress/v1):
 *   GET  /taxonomy-editor/terms?taxonomy={slug}  -> grouped term tree
 *   POST /taxonomy-editor/save                    -> bulk save
 *   GET  /taxonomy-editor/settings                -> { taxonomies[], seo_plugin }
 *
 * @package LuwiPress
 * @since   3.5.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Taxonomy_Editor {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// -----------------------------------------------------------------
	// Asset enqueue (scoped to our admin page)
	// -----------------------------------------------------------------

	public function enqueue_assets( $hook_suffix ) {
		// Only fire on our admin page.
		if ( false === strpos( (string) $hook_suffix, 'luwipress-taxonomy-editor' ) ) {
			return;
		}

		$css_path = LUWIPRESS_PLUGIN_DIR . 'assets/css/taxonomy-editor.css';
		$js_path  = LUWIPRESS_PLUGIN_DIR . 'assets/js/taxonomy-editor.js';
		$css_ver  = LUWIPRESS_VERSION . '.' . ( file_exists( $css_path ) ? filemtime( $css_path ) : '0' );
		$js_ver   = LUWIPRESS_VERSION . '.' . ( file_exists( $js_path ) ? filemtime( $js_path ) : '0' );

		wp_enqueue_style(
			'luwipress-taxonomy-editor',
			LUWIPRESS_PLUGIN_URL . 'assets/css/taxonomy-editor.css',
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'luwipress-taxonomy-editor',
			LUWIPRESS_PLUGIN_URL . 'assets/js/taxonomy-editor.js',
			array(),
			$js_ver,
			true
		);

		$detector  = class_exists( 'LuwiPress_Plugin_Detector' )
			? LuwiPress_Plugin_Detector::get_instance()->detect_translation()
			: array( 'plugin' => 'none', 'active_languages' => array( 'en' ), 'default_language' => 'en' );
		$seo_info  = class_exists( 'LuwiPress_Plugin_Detector' )
			? LuwiPress_Plugin_Detector::get_instance()->detect_seo()
			: array( 'plugin' => 'none' );

		wp_localize_script( 'luwipress-taxonomy-editor', 'lwpTaxEditor', array(
			'restUrl'    => esc_url_raw( rest_url( 'luwipress/v1' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'languages'  => $this->get_active_languages_meta( $detector ),
			'defaultLng' => self::normalize_lang_code( $detector['default_language'] ?? 'en' ),
			'plugin'     => $detector['plugin'] ?? 'none',
			'seoPlugin'  => $seo_info['plugin'] ?? 'none',
			'taxonomies' => $this->get_editable_taxonomies(),
			'strings'    => array(
				'saveAll'         => __( 'Save all changes', 'luwipress' ),
				'saving'          => __( 'Saving…', 'luwipress' ),
				'noChanges'       => __( 'No changes to save', 'luwipress' ),
				'savedSuccess'    => __( '%d updates saved.', 'luwipress' ),
				'savedPartial'    => __( '%1$d saved, %2$d failed.', 'luwipress' ),
				'savedFailure'    => __( 'Save failed.', 'luwipress' ),
				'loading'         => __( 'Loading terms…', 'luwipress' ),
				'empty'           => __( 'No terms found.', 'luwipress' ),
				'fieldName'       => __( 'Name', 'luwipress' ),
				'fieldDesc'       => __( 'Description', 'luwipress' ),
				'fieldSeoTitle'   => __( 'SEO Title', 'luwipress' ),
				'fieldSeoDesc'    => __( 'SEO Description', 'luwipress' ),
				'fieldFocusKw'    => __( 'Focus Keyword', 'luwipress' ),
				'expand'          => __( 'Expand', 'luwipress' ),
				'collapse'        => __( 'Collapse', 'luwipress' ),
				'missingSibling'  => __( '(no translation)', 'luwipress' ),
				'search'          => __( 'Search terms…', 'luwipress' ),
				'taxonomyLabel'   => __( 'Taxonomy:', 'luwipress' ),
				'dirty'           => __( 'unsaved', 'luwipress' ),
				'rowsTotal'       => __( '%d term groups', 'luwipress' ),
				'editUrl'         => __( 'Edit in WP →', 'luwipress' ),
			),
		) );
	}

	// -----------------------------------------------------------------
	// Discovery helpers
	// -----------------------------------------------------------------

	/**
	 * Editable taxonomy slugs. product_cat + product_tag if WC active,
	 * plus category for blog. Filter extensible.
	 */
	public function get_editable_taxonomies() {
		$default = array();
		if ( class_exists( 'WooCommerce' ) ) {
			$default[] = array( 'slug' => 'product_cat', 'label' => __( 'Product Categories', 'luwipress' ) );
			$default[] = array( 'slug' => 'product_tag', 'label' => __( 'Product Tags', 'luwipress' ) );
		}
		$default[] = array( 'slug' => 'category', 'label' => __( 'Blog Categories', 'luwipress' ) );
		$default[] = array( 'slug' => 'post_tag', 'label' => __( 'Blog Tags', 'luwipress' ) );

		/**
		 * Filter the list of taxonomies editable through the Taxonomy Editor.
		 * Pass an array of arrays with 'slug' and 'label' keys.
		 *
		 * @param array $default
		 */
		$filtered = apply_filters( 'luwipress_taxonomy_editor_taxonomies', $default );

		// Keep only registered taxonomies.
		$result = array();
		foreach ( $filtered as $tx ) {
			if ( ! isset( $tx['slug'] ) ) {
				continue;
			}
			if ( taxonomy_exists( $tx['slug'] ) ) {
				$result[] = array(
					'slug'  => sanitize_key( $tx['slug'] ),
					'label' => isset( $tx['label'] ) ? wp_strip_all_tags( $tx['label'] ) : $tx['slug'],
				);
			}
		}
		return $result;
	}

	private function get_active_languages_meta( $detector ) {
		$plugin = $detector['plugin'] ?? 'none';
		$langs  = isset( $detector['active_languages'] ) && is_array( $detector['active_languages'] )
			? $detector['active_languages']
			: array( 'en' );

		$out = array();
		if ( 'wpml' === $plugin ) {
			$active = function_exists( 'apply_filters' ) ? apply_filters( 'wpml_active_languages', null, array() ) : null;
			if ( is_array( $active ) ) {
				foreach ( $active as $code => $info ) {
					$out[] = array(
						'code'    => self::normalize_lang_code( $code ),
						'rawCode' => (string) $code,
						'label'   => isset( $info['native_name'] ) ? $info['native_name'] : ( isset( $info['translated_name'] ) ? $info['translated_name'] : $code ),
						'flag'    => isset( $info['country_flag_url'] ) ? esc_url_raw( $info['country_flag_url'] ) : '',
					);
				}
			}
		} elseif ( 'polylang' === $plugin && function_exists( 'pll_languages_list' ) ) {
			$lang_objs = function_exists( 'pll_languages_list' )
				? pll_languages_list( array( 'fields' => '' ) )
				: array();
			if ( is_array( $lang_objs ) ) {
				foreach ( $lang_objs as $obj ) {
					$code  = is_object( $obj ) ? ( property_exists( $obj, 'slug' ) ? $obj->slug : '' ) : '';
					$name  = is_object( $obj ) ? ( property_exists( $obj, 'name' ) ? $obj->name : $code ) : $code;
					if ( $code ) {
						$out[] = array(
							'code'    => self::normalize_lang_code( $code ),
							'rawCode' => $code,
							'label'   => $name,
							'flag'    => '',
						);
					}
				}
			}
		}

		// Fallback when active list couldn't be reconstructed but detector
		// returned at least one code.
		if ( empty( $out ) ) {
			foreach ( $langs as $code ) {
				$out[] = array(
					'code'    => self::normalize_lang_code( $code ),
					'rawCode' => (string) $code,
					'label'   => (string) $code,
					'flag'    => '',
				);
			}
		}

		return $out;
	}

	private static function normalize_lang_code( $code ) {
		$code = strtolower( str_replace( '_', '-', (string) $code ) );
		$multi_region = array( 'pt-br', 'pt-pt', 'zh-cn', 'zh-tw', 'zh-hk', 'es-mx', 'es-cl', 'fr-ca', 'en-gb', 'en-au', 'en-ca' );
		if ( strpos( $code, '-' ) !== false && ! in_array( $code, $multi_region, true ) ) {
			return substr( $code, 0, 2 );
		}
		return $code;
	}

	// -----------------------------------------------------------------
	// REST endpoints
	// -----------------------------------------------------------------

	public function register_endpoints() {
		register_rest_route( 'luwipress/v1', '/taxonomy-editor/terms', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_get_terms' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'taxonomy' => array( 'type' => 'string', 'required' => true ),
				'limit'    => array( 'type' => 'integer', 'default' => 200 ),
				'offset'   => array( 'type' => 'integer', 'default' => 0 ),
				'search'   => array( 'type' => 'string', 'default' => '' ),
			),
		) );

		register_rest_route( 'luwipress/v1', '/taxonomy-editor/save', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_save' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );

		register_rest_route( 'luwipress/v1', '/taxonomy-editor/settings', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_settings' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );
	}

	/**
	 * Lightweight settings response — same payload the admin JS loads via
	 * wp_localize_script but available over REST for headless callers.
	 */
	public function handle_settings( $request ) {
		$detector = class_exists( 'LuwiPress_Plugin_Detector' )
			? LuwiPress_Plugin_Detector::get_instance()->detect_translation()
			: array( 'plugin' => 'none' );
		$seo_info = class_exists( 'LuwiPress_Plugin_Detector' )
			? LuwiPress_Plugin_Detector::get_instance()->detect_seo()
			: array( 'plugin' => 'none' );

		return rest_ensure_response( array(
			'taxonomies'        => $this->get_editable_taxonomies(),
			'languages'         => $this->get_active_languages_meta( $detector ),
			'default_language'  => self::normalize_lang_code( $detector['default_language'] ?? 'en' ),
			'translation_plugin' => $detector['plugin'] ?? 'none',
			'seo_plugin'        => $seo_info['plugin'] ?? 'none',
		) );
	}

	/**
	 * Return term groups for the selected taxonomy. Groups are keyed by
	 * trid (WPML) or translation-group id (Polylang) so the UI can render
	 * a single accordion row per group with one cell column per active
	 * language.
	 */
	public function handle_get_terms( $request ) {
		$taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );
		$limit    = max( 1, min( 1000, intval( $request->get_param( 'limit' ) ) ) );
		$offset   = max( 0, intval( $request->get_param( 'offset' ) ) );
		$search   = trim( (string) $request->get_param( 'search' ) );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'unknown_taxonomy', 'Unknown taxonomy', array( 'status' => 400 ) );
		}

		$detector       = class_exists( 'LuwiPress_Plugin_Detector' )
			? LuwiPress_Plugin_Detector::get_instance()->detect_translation()
			: array( 'plugin' => 'none' );
		$plugin         = $detector['plugin'] ?? 'none';
		$default_lang   = self::normalize_lang_code( $detector['default_language'] ?? 'en' );
		$active_lang_meta = $this->get_active_languages_meta( $detector );
		$active_codes   = array_map( function ( $l ) { return $l['code']; }, $active_lang_meta );

		// Strategy:
		// 1) Query terms in the DEFAULT language only — these become group
		//    anchors. Avoids 4x duplicate work when WC has 4-language siblings.
		// 2) For each anchor, walk all active languages and resolve the
		//    sibling term id via the plugin's translate-id API.
		// 3) Collect 5 editable fields per (term, lang) cell.
		$anchor_args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => $limit,
			'offset'     => $offset,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);
		if ( $search !== '' ) {
			$anchor_args['search'] = $search;
		}

		// Scope anchors to the default language when WPML is active so the
		// group count is N (not 4N).
		if ( 'wpml' === $plugin ) {
			$prev_lang = function_exists( 'apply_filters' ) ? apply_filters( 'wpml_current_language', null ) : null;
			global $sitepress;
			if ( is_object( $sitepress ) && method_exists( $sitepress, 'switch_lang' ) ) {
				$sitepress->switch_lang( $default_lang );
			}
			$anchor_terms = get_terms( $anchor_args );
			if ( is_object( $sitepress ) && method_exists( $sitepress, 'switch_lang' ) && $prev_lang ) {
				$sitepress->switch_lang( $prev_lang );
			}
		} else {
			$anchor_terms = get_terms( $anchor_args );
		}

		if ( is_wp_error( $anchor_terms ) ) {
			return new WP_Error( 'terms_query_failed', $anchor_terms->get_error_message(), array( 'status' => 500 ) );
		}

		$groups = array();
		foreach ( $anchor_terms as $term ) {
			$group_id   = (string) $term->term_id; // anchor's term_id stands in for trid in UI
			$siblings   = $this->resolve_siblings_for_term( $term, $taxonomy, $plugin, $active_codes );
			$cells      = array();
			foreach ( $siblings as $code => $sibling ) {
				$cells[ $code ] = $sibling
					? $this->collect_term_cell( $sibling, $taxonomy )
					: array( 'exists' => false, 'term_id' => 0, 'name' => '', 'description' => '', 'rm_title' => '', 'rm_description' => '', 'rm_focus_keyword' => '', 'edit_url' => '' );
			}

			$groups[] = array(
				'group_id'     => $group_id,
				'anchor_lang'  => $default_lang,
				'anchor_name'  => $term->name,
				'anchor_slug'  => $term->slug,
				'cells'        => $cells,
			);
		}

		return rest_ensure_response( array(
			'taxonomy'         => $taxonomy,
			'plugin'           => $plugin,
			'default_language' => $default_lang,
			'languages'        => $active_lang_meta,
			'limit'            => $limit,
			'offset'           => $offset,
			'count'            => count( $groups ),
			'has_more'         => count( $anchor_terms ) === $limit,
			'groups'           => $groups,
		) );
	}

	/**
	 * Resolve all active-language sibling terms for an anchor term.
	 * Returns assoc array keyed by language code; missing siblings are
	 * present as `null`.
	 */
	private function resolve_siblings_for_term( $anchor, $taxonomy, $plugin, $active_codes ) {
		$out = array();
		foreach ( $active_codes as $code ) {
			$out[ $code ] = null;
		}

		if ( 'wpml' === $plugin ) {
			foreach ( $active_codes as $code ) {
				$sibling_id = apply_filters( 'wpml_object_id', $anchor->term_id, $taxonomy, false, $code );
				if ( $sibling_id ) {
					$sibling_term = get_term( $sibling_id, $taxonomy );
					if ( $sibling_term && ! is_wp_error( $sibling_term ) ) {
						$out[ $code ] = $sibling_term;
					}
				}
			}
		} elseif ( 'polylang' === $plugin && function_exists( 'pll_get_term_translations' ) ) {
			$translations = pll_get_term_translations( $anchor->term_id );
			if ( is_array( $translations ) ) {
				foreach ( $translations as $code => $sibling_id ) {
					$norm = self::normalize_lang_code( $code );
					if ( ! in_array( $norm, $active_codes, true ) ) {
						continue;
					}
					$sibling_term = get_term( $sibling_id, $taxonomy );
					if ( $sibling_term && ! is_wp_error( $sibling_term ) ) {
						$out[ $norm ] = $sibling_term;
					}
				}
			}
		} else {
			// Single-language: the anchor itself fills the default slot.
			$default = $active_codes[0] ?? 'en';
			$out[ $default ] = $anchor;
		}

		return $out;
	}

	private function collect_term_cell( $term, $taxonomy ) {
		$rm_title       = (string) get_term_meta( $term->term_id, 'rank_math_title', true );
		$rm_description = (string) get_term_meta( $term->term_id, 'rank_math_description', true );
		$rm_focus       = (string) get_term_meta( $term->term_id, 'rank_math_focus_keyword', true );

		$edit_url = '';
		if ( function_exists( 'get_edit_term_link' ) ) {
			$edit_url = (string) get_edit_term_link( $term->term_id, $taxonomy );
		}

		return array(
			'exists'           => true,
			'term_id'          => (int) $term->term_id,
			'slug'             => $term->slug,
			'name'             => $term->name,
			'description'      => $term->description,
			'rm_title'         => $rm_title,
			'rm_description'   => $rm_description,
			'rm_focus_keyword' => $rm_focus,
			'edit_url'         => $edit_url,
		);
	}

	// -----------------------------------------------------------------
	// Save — single bulk POST
	// -----------------------------------------------------------------

	/**
	 * Body shape:
	 *   {
	 *     taxonomy: "product_cat",
	 *     rows: [
	 *       {
	 *         term_id: 123,
	 *         name?: "...",
	 *         description?: "...",
	 *         rm_title?: "...",
	 *         rm_description?: "...",
	 *         rm_focus_keyword?: "..."
	 *       },
	 *       ...
	 *     ]
	 *   }
	 *
	 * Caller sends only the (term, field) pairs that actually changed
	 * — the UI tracks dirty cells client-side. Cap 500 rows per call.
	 */
	public function handle_save( $request ) {
		$taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );
		$rows     = $request->get_param( 'rows' );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'unknown_taxonomy', 'Unknown taxonomy', array( 'status' => 400 ) );
		}
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return new WP_Error( 'no_rows', 'rows array is required', array( 'status' => 400 ) );
		}
		if ( count( $rows ) > 500 ) {
			return new WP_Error( 'too_many_rows', 'Max 500 rows per call; split and retry.', array( 'status' => 400 ) );
		}

		global $wpdb, $sitepress;
		$results = array();
		$applied = 0;
		$skipped = 0;
		$errors  = array();

		foreach ( $rows as $idx => $row ) {
			$term_id = isset( $row['term_id'] ) ? absint( $row['term_id'] ) : 0;
			if ( ! $term_id ) {
				$errors[] = array( 'row' => $idx, 'error' => 'missing_term_id' );
				$skipped++;
				continue;
			}
			$term = get_term( $term_id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				$errors[] = array( 'row' => $idx, 'term_id' => $term_id, 'error' => 'term_not_found' );
				$skipped++;
				continue;
			}

			$writes = 0;

			// 1) SEO meta writes (RM keys hardcoded — symmetric with
			// LuwiPress_API::handle_bulk_taxonomy_seo_meta).
			$key_map = array(
				'rm_title'         => 'rank_math_title',
				'rm_description'   => 'rank_math_description',
				'rm_focus_keyword' => 'rank_math_focus_keyword',
			);
			foreach ( $key_map as $input_key => $meta_key ) {
				if ( ! array_key_exists( $input_key, $row ) ) {
					continue;
				}
				$raw = $row[ $input_key ];
				if ( $raw === null ) {
					continue;
				}
				$value = ( 'rm_description' === $input_key )
					? wp_kses_post( (string) $raw )
					: sanitize_text_field( (string) $raw );
				update_term_meta( $term_id, $meta_key, $value );
				$writes++;
			}

			// 2) Term name + description — WPML-aware path mirroring
			// webmcp taxonomy_update_term (slug-collision bypass).
			$has_name = array_key_exists( 'name', $row ) && $row['name'] !== null;
			$has_desc = array_key_exists( 'description', $row ) && $row['description'] !== null;

			if ( $has_desc && ! $has_name ) {
				// Description-only -> direct $wpdb write (skip
				// wp_update_term entirely so we don't trigger
				// wp_unique_term_slug language-blindness).
				$new_desc = wp_kses_post( (string) $row['description'] );
				$updated  = $wpdb->update(
					$wpdb->term_taxonomy,
					array( 'description' => $new_desc ),
					array( 'term_taxonomy_id' => (int) $term->term_taxonomy_id ),
					array( '%s' ),
					array( '%d' )
				);
				if ( false === $updated ) {
					$errors[] = array( 'row' => $idx, 'term_id' => $term_id, 'error' => 'desc_write_failed', 'detail' => $wpdb->last_error );
					$skipped++;
					continue;
				}
				clean_term_cache( $term_id, $taxonomy );
				do_action( 'edited_term', $term_id, $term->term_taxonomy_id, $taxonomy );
				do_action( "edited_{$taxonomy}", $term_id, $term->term_taxonomy_id );
				$writes++;
			} elseif ( $has_name ) {
				// Name change (or name + desc) -> set WPML language
				// context before wp_update_term so uniqueness check
				// scopes to siblings in this term's own language.
				$restore_lang = null;
				if ( is_object( $sitepress ) && method_exists( $sitepress, 'switch_lang' ) ) {
					$term_lang = apply_filters( 'wpml_element_language_code', null, array(
						'element_id'   => $term_id,
						'element_type' => $taxonomy,
					) );
					if ( ! empty( $term_lang ) ) {
						$restore_lang = apply_filters( 'wpml_current_language', null );
						$sitepress->switch_lang( $term_lang );
					}
				}

				$term_args = array(
					'name' => sanitize_text_field( (string) $row['name'] ),
				);
				if ( $has_desc ) {
					$term_args['description'] = wp_kses_post( (string) $row['description'] );
				}

				$update_result = wp_update_term( $term_id, $taxonomy, $term_args );

				if ( $restore_lang && is_object( $sitepress ) && method_exists( $sitepress, 'switch_lang' ) ) {
					$sitepress->switch_lang( $restore_lang );
				}

				if ( is_wp_error( $update_result ) ) {
					$errors[] = array(
						'row'     => $idx,
						'term_id' => $term_id,
						'error'   => 'term_update_failed',
						'detail'  => $update_result->get_error_message(),
					);
					$skipped++;
					continue;
				}
				$writes++;
			}

			if ( $writes > 0 ) {
				$applied++;
				$results[] = array(
					'row'     => $idx,
					'term_id' => $term_id,
					'writes'  => $writes,
				);
			} else {
				$skipped++;
			}
		}

		if ( class_exists( 'LuwiPress_Logger' ) ) {
			LuwiPress_Logger::log( 'Taxonomy Editor bulk save', 'info', array(
				'taxonomy' => $taxonomy,
				'applied'  => $applied,
				'skipped'  => $skipped,
				'errors'   => count( $errors ),
			) );
		}

		// Bust Health Score + AEO coverage caches so dashboards reflect
		// any new SEO meta within the next reload.
		delete_transient( 'luwipress_aeo_coverage' );
		if ( class_exists( 'LuwiPress_Health_Score' ) ) {
			LuwiPress_Health_Score::get_instance()->invalidate_cache();
		}

		return rest_ensure_response( array(
			'success'    => true,
			'taxonomy'   => $taxonomy,
			'applied'    => $applied,
			'skipped'    => $skipped,
			'error_rows' => array_slice( $errors, 0, 20 ),
			'results'    => $results,
			'total'      => count( $rows ),
		) );
	}
}
