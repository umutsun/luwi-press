<?php
/**
 * LuwiPress Knowledge Graph
 *
 * Single REST endpoint that returns a comprehensive JSON graph of the
 * WordPress/WooCommerce store: products, categories, languages, customer
 * segments, SEO/AEO coverage, and AI opportunity scores.
 *
 * AI workflows consume this to make prioritised decisions (what to enrich,
 * translate, generate FAQ for, etc.).
 *
 * Endpoint: GET /wp-json/luwipress/v1/knowledge-graph
 *
 * @package LuwiPress
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Knowledge_Graph {

	private static $instance = null;

	/** @var array Opportunity score weights */
	private $score_weights = array(
		'missing_seo_title'       => 5,
		'missing_seo_description' => 5,
		'missing_focus_kw'        => 3,
		'not_enriched'            => 15,
		'thin_content'            => 10,
		'missing_translation'     => 8,
		'missing_faq'             => 6,
		'missing_schema'          => 5,
		// 'missing_howto'  => 4,  // disabled: HowTo deprecated by Google for product pages
		// 'missing_speakable' => 3, // disabled: Speakable deprecated by Google late 2024
		'no_reviews'              => 3,
		'missing_alt_text'        => 3,
		'stale_content'           => 5,
	);

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );

		// Auto-invalidate cache when content changes
		add_action( 'save_post', array( $this, 'invalidate_cache' ) );
		add_action( 'delete_post', array( $this, 'invalidate_cache' ) );
		add_action( 'created_term', array( $this, 'invalidate_cache' ) );
		add_action( 'delete_term', array( $this, 'invalidate_cache' ) );

		// Invalidate when LuwiPress AI pipelines write product meta directly.
		add_action( 'updated_post_meta', array( $this, 'maybe_invalidate_on_meta' ), 10, 4 );
		add_action( 'added_post_meta',   array( $this, 'maybe_invalidate_on_meta' ), 10, 4 );
	}

	/**
	 * Flush graph cache when LuwiPress-owned meta keys change — save_post
	 * doesn't always fire for direct update_post_meta from async AI jobs.
	 */
	public function maybe_invalidate_on_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( ! is_string( $meta_key ) || '' === $meta_key ) {
			return;
		}
		if ( 0 === strpos( $meta_key, '_luwipress_' )
			|| 'rank_math_title' === $meta_key
			|| 'rank_math_description' === $meta_key
			|| 'rank_math_focus_keyword' === $meta_key
			|| '_yoast_wpseo_title' === $meta_key
			|| '_yoast_wpseo_metadesc' === $meta_key
			|| '_yoast_wpseo_focuskw' === $meta_key
		) {
			$this->invalidate_cache();
		}
	}

	public function register_endpoints() {
		register_rest_route( 'luwipress/v1', '/knowledge-graph', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_knowledge_graph' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'section' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Comma-separated sections: products,categories,translation,seo,aeo,crm,store,plugins,taxonomy,environment,opportunities,posts,pages,content_taxonomy,media_inventory,menus,product_attributes,authors,order_analytics,design_audit',
				),
				'sections' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Alias for section (accepts comma-separated sections).',
				),
				'fresh' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		) );
	}

	public function check_permission( $request ) {
		return LuwiPress_Permission::check_token_or_admin( $request );
	}

	// ─── MAIN HANDLER ──────────────────────────────────────────────────

	public function handle_knowledge_graph( $request ) {
		$start = microtime( true );

		// Parse sections (accept both `section` and `sections` for client compatibility)
		$section_param = $request->get_param( 'section' );
		if ( empty( $section_param ) ) {
			$section_param = $request->get_param( 'sections' );
		}
		$all_sections  = array( 'products', 'categories', 'translation', 'seo', 'aeo', 'crm', 'store', 'plugins', 'taxonomy', 'environment', 'opportunities', 'posts', 'pages', 'content_taxonomy', 'media_inventory', 'menus', 'product_attributes', 'authors', 'order_analytics', 'design_audit' );
		$sections      = $section_param ? array_intersect( array_map( 'trim', explode( ',', $section_param ) ), $all_sections ) : $all_sections;

		// Check cache
		$fresh     = (bool) $request->get_param( 'fresh' );
		$cache_key = 'luwipress_kg_' . md5( implode( ',', $sections ) );
		if ( ! $fresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				$cached['meta']['from_cache'] = true;
				return rest_ensure_response( $cached );
			}
		}

		// Get environment info
		$detector  = LuwiPress_Plugin_Detector::get_instance();
		$seo_info  = $detector->detect_seo();
		$lang_info = $detector->detect_translation();

		$active_languages  = $lang_info['active_languages'] ?? array();
		$default_language  = $lang_info['default_language'] ?? substr( get_locale(), 0, 2 );
		$target_languages  = array_values( array_diff( $active_languages, array( $default_language ) ) );

		// Determine SEO meta keys
		$seo_meta_keys = array();
		if ( ! empty( $seo_info['meta_keys'] ) ) {
			foreach ( $seo_info['meta_keys'] as $key ) {
				$seo_meta_keys[] = $key;
			}
		}

		// ── Bulk load data ──
		$product_nodes  = array();
		$category_nodes = array();
		$language_nodes = array();
		$segment_nodes  = array();
		$edges          = array();

		$needs_products = array_intersect( $sections, array( 'products', 'categories', 'translation', 'seo', 'aeo', 'plugins', 'opportunities' ) );

		if ( $needs_products ) {
			$products    = $this->load_product_posts();
			$product_ids = wp_list_pluck( $products, 'ID' );

			if ( ! empty( $product_ids ) ) {
				$meta_map   = $this->load_product_meta( $product_ids, $seo_meta_keys, $target_languages );
				$term_map   = $this->load_product_terms( $product_ids );
				$image_map  = $this->load_image_stats( $product_ids );
				$review_map = $this->load_review_stats( $product_ids );
				$wpml_map   = $this->load_wpml_translation_status( $product_ids, $target_languages );

				$product_nodes = $this->build_product_nodes(
					$products, $meta_map, $term_map, $image_map, $review_map,
					$seo_meta_keys, $target_languages, $wpml_map
				);
			}
		}

		if ( in_array( 'categories', $sections, true ) ) {
			$category_nodes = $this->build_category_nodes( $product_nodes );
		}

		if ( in_array( 'translation', $sections, true ) ) {
			$language_nodes = $this->build_language_nodes( $product_nodes, $target_languages, $default_language );
		}

		if ( in_array( 'crm', $sections, true ) ) {
			$segment_nodes = $this->build_customer_segment_nodes();
		}

		if ( $needs_products ) {
			$edges = $this->build_edges( $product_nodes );
		}

		// ── Assemble response ──
		$response = array(
			'meta' => array(
				'generated_at'      => current_time( 'c' ),
				'version'           => '1.0',
				'plugin_version'    => LUWIPRESS_VERSION,
				'sections_included' => array_values( $sections ),
				'store_url'         => get_site_url(),
				'from_cache'        => false,
			),
		);

		// Summary
		$response['summary'] = $this->build_summary( $product_nodes, $category_nodes, $language_nodes, $segment_nodes, $target_languages );

		// Nodes
		$response['nodes'] = array();
		if ( in_array( 'products', $sections, true ) ) {
			$response['nodes']['products'] = $product_nodes;
		}
		if ( in_array( 'categories', $sections, true ) ) {
			$response['nodes']['categories'] = $category_nodes;
		}
		if ( in_array( 'translation', $sections, true ) ) {
			$response['nodes']['languages'] = $language_nodes;
		}
		if ( in_array( 'crm', $sections, true ) ) {
			$response['nodes']['customer_segments'] = $segment_nodes;
		}

		// Store intelligence (WooCommerce deep analysis)
		if ( in_array( 'store', $sections, true ) ) {
			$response['store'] = $this->build_store_intelligence();
		}

		// Plugin health analysis
		if ( in_array( 'plugins', $sections, true ) ) {
			$response['plugins'] = $this->build_plugin_health( $detector, $product_nodes, $target_languages );
		}

		// Taxonomy translation status
		if ( in_array( 'taxonomy', $sections, true ) ) {
			$response['nodes']['taxonomies'] = $this->build_taxonomy_nodes( $target_languages );
		}

		// Blog posts
		if ( in_array( 'posts', $sections, true ) ) {
			$response['nodes']['posts'] = $this->build_post_nodes( $seo_meta_keys, $target_languages );
		}

		// Static pages
		if ( in_array( 'pages', $sections, true ) ) {
			$response['nodes']['pages'] = $this->build_page_nodes();
		}

		// Content taxonomy (all registered taxonomies)
		if ( in_array( 'content_taxonomy', $sections, true ) ) {
			$response['nodes']['content_taxonomy'] = $this->build_content_taxonomy_nodes();
		}

		// Media inventory
		if ( in_array( 'media_inventory', $sections, true ) ) {
			$response['nodes']['media_inventory'] = $this->build_media_inventory_nodes();
		}

		// Navigation menus
		if ( in_array( 'menus', $sections, true ) ) {
			$response['nodes']['menus'] = $this->build_menu_nodes();
		}

		// Product attributes
		if ( in_array( 'product_attributes', $sections, true ) ) {
			$response['nodes']['product_attributes'] = $this->build_product_attribute_nodes();
		}

		// Authors
		if ( in_array( 'authors', $sections, true ) ) {
			$response['nodes']['authors'] = $this->build_author_nodes();
		}

		// Order analytics
		if ( in_array( 'order_analytics', $sections, true ) ) {
			$response['nodes']['order_analytics'] = $this->build_order_analytics_nodes();
		}

		// Design audit (Elementor responsive/spacing/accessibility analysis)
		if ( in_array( 'design_audit', $sections, true ) ) {
			$response['nodes']['design_audit'] = $this->build_design_audit_nodes();
		}

		// Post/page edges
		if ( in_array( 'posts', $sections, true ) && ! empty( $response['nodes']['posts'] ) ) {
			$edges = array_merge( $edges, $this->build_post_edges( $response['nodes']['posts'] ) );
		}
		if ( in_array( 'pages', $sections, true ) && ! empty( $response['nodes']['pages'] ) ) {
			$edges = array_merge( $edges, $this->build_page_edges( $response['nodes']['pages'] ) );
		}

		// Edges
		if ( ! empty( $edges ) ) {
			$response['edges'] = $edges;
		}

		// Environment
		if ( in_array( 'environment', $sections, true ) ) {
			$response['environment'] = $detector->get_environment();
		}

		// Opportunities
		if ( in_array( 'opportunities', $sections, true ) ) {
			$response['opportunities'] = $this->build_opportunities( $product_nodes );
		}

		// Extend summary with new section counts
		if ( in_array( 'posts', $sections, true ) && ! empty( $response['nodes']['posts'] ) ) {
			$response['summary']['total_posts'] = count( $response['nodes']['posts'] );
		}
		if ( in_array( 'pages', $sections, true ) && ! empty( $response['nodes']['pages'] ) ) {
			$response['summary']['total_pages'] = count( $response['nodes']['pages'] );
		}
		if ( in_array( 'authors', $sections, true ) && ! empty( $response['nodes']['authors'] ) ) {
			$response['summary']['total_authors'] = count( $response['nodes']['authors'] );
		}

		$response['meta']['execution_time_ms'] = round( ( microtime( true ) - $start ) * 1000, 1 );

		// Cache for 5 minutes
		$ttl = absint( get_option( 'luwipress_knowledge_graph_ttl', 300 ) );
		set_transient( $cache_key, $response, $ttl );

		return rest_ensure_response( $response );
	}

	// ─── BULK DATA LOADERS ──────────────────────────────────────────────

	private function load_product_posts() {
		global $wpdb;

		$translation_plugin = 'none';
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$translation_plugin = 'wpml';
		} elseif ( defined( 'POLYLANG_VERSION' ) ) {
			$translation_plugin = 'polylang';
		}

		// For WPML: only original products (not translations)
		if ( 'wpml' === $translation_plugin ) {
			return $wpdb->get_results(
				"SELECT p.ID, p.post_title, p.post_name, p.post_status,
				        p.post_date, p.post_modified,
				        LENGTH(p.post_content) AS content_length
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->prefix}icl_translations t
				   ON p.ID = t.element_id AND t.element_type = 'post_product'
				 WHERE p.post_type = 'product'
				   AND p.post_status = 'publish'
				   AND t.source_language_code IS NULL
				 ORDER BY p.ID ASC
				 LIMIT 2000"
			);
		}

		return $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_name, p.post_status,
			        p.post_date, p.post_modified,
			        LENGTH(p.post_content) AS content_length
			 FROM {$wpdb->posts} p
			 WHERE p.post_type = 'product'
			   AND p.post_status = 'publish'
			 ORDER BY p.ID ASC
			 LIMIT 2000"
		);
	}

	private function load_product_meta( $product_ids, $seo_meta_keys, $target_languages ) {
		global $wpdb;

		$wanted_keys = array(
			'_price', '_regular_price', '_sale_price', '_sku', '_stock_status',
			'_thumbnail_id', '_product_image_gallery',
			'_upsell_ids', '_crosssell_ids',
			'_luwipress_enrich_status', '_luwipress_enrich_completed',
			'_luwipress_faq', '_luwipress_howto', '_luwipress_schema', '_luwipress_speakable',
		);

		// Add SEO meta keys
		$wanted_keys = array_merge( $wanted_keys, $seo_meta_keys );

		// Add translation status keys
		foreach ( $target_languages as $lang ) {
			$wanted_keys[] = '_luwipress_translation_' . $lang . '_status';
		}

		$wanted_keys = array_unique( array_filter( $wanted_keys ) );

		// Build query
		$id_placeholders  = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$key_placeholders = implode( ',', array_fill( 0, count( $wanted_keys ), '%s' ) );

		$params = array_merge( $product_ids, $wanted_keys );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_key, meta_value
			 FROM {$wpdb->postmeta}
			 WHERE post_id IN ($id_placeholders)
			   AND meta_key IN ($key_placeholders)",
			...$params
		) );

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row->post_id ][ $row->meta_key ] = $row->meta_value;
		}

		return $map;
	}

	private function load_product_terms( $product_ids ) {
		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT tr.object_id, t.term_id, t.name, t.slug, tt.taxonomy, tt.parent
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			 WHERE tr.object_id IN ($id_placeholders)
			   AND tt.taxonomy IN ('product_cat', 'product_tag')",
			...$product_ids
		) );

		$map = array();
		foreach ( $rows as $row ) {
			$key = 'product_cat' === $row->taxonomy ? 'categories' : 'tags';
			$map[ $row->object_id ][ $key ][] = array(
				'id'   => absint( $row->term_id ),
				'name' => $row->name,
				'slug' => $row->slug,
			);
		}

		return $map;
	}

	private function load_image_stats( $product_ids ) {
		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.post_parent AS product_id,
			        COUNT(*) AS total_images,
			        SUM(CASE WHEN pm.meta_value IS NOT NULL AND pm.meta_value != '' THEN 1 ELSE 0 END) AS with_alt
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type LIKE 'image/%%'
			   AND p.post_parent IN ($id_placeholders)
			 GROUP BY p.post_parent",
			...$product_ids
		) );

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row->product_id ] = array(
				'total'    => absint( $row->total_images ),
				'with_alt' => absint( $row->with_alt ),
			);
		}

		return $map;
	}

	private function load_review_stats( $product_ids ) {
		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.comment_post_ID AS product_id,
			        COUNT(*) AS review_count,
			        AVG(cm.meta_value) AS avg_rating
			 FROM {$wpdb->comments} c
			 INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_id AND cm.meta_key = 'rating'
			 WHERE c.comment_post_ID IN ($id_placeholders)
			   AND c.comment_approved = '1'
			   AND c.comment_type = 'review'
			 GROUP BY c.comment_post_ID",
			...$product_ids
		) );

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row->product_id ] = array(
				'count'      => absint( $row->review_count ),
				'avg_rating' => round( floatval( $row->avg_rating ), 1 ),
			);
		}

		return $map;
	}

	// ─── NODE BUILDERS ──────────────────────────────────────────────────

	/**
	 * Bulk load WPML translation status for products.
	 * Returns map: product_id => [lang => true/false]
	 */
	private function load_wpml_translation_status( $product_ids, $target_languages ) {
		global $wpdb;
		$map = array();

		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) || empty( $target_languages ) ) {
			return $map;
		}

		$id_placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		// Get trid for each product
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT element_id, trid FROM {$wpdb->prefix}icl_translations
			 WHERE element_type = 'post_product' AND element_id IN ($id_placeholders)",
			...$product_ids
		) );

		$trid_map = array();
		foreach ( $rows as $row ) {
			$trid_map[ $row->element_id ] = $row->trid;
		}

		// Get all translations for these trids
		$trids = array_unique( array_values( $trid_map ) );
		if ( empty( $trids ) ) {
			return $map;
		}

		$trid_placeholders = implode( ',', array_fill( 0, count( $trids ), '%d' ) );
		$trans_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT trid, language_code FROM {$wpdb->prefix}icl_translations
			 WHERE trid IN ($trid_placeholders) AND element_type = 'post_product'",
			...$trids
		) );

		// Build: trid => [lang1, lang2, ...]
		$trid_langs = array();
		foreach ( $trans_rows as $tr ) {
			$trid_langs[ $tr->trid ][] = $tr->language_code;
		}

		// Map back to product_id => [lang => completed/missing]
		foreach ( $product_ids as $pid ) {
			$trid = $trid_map[ $pid ] ?? null;
			$existing_langs = $trid ? ( $trid_langs[ $trid ] ?? array() ) : array();
			$map[ $pid ] = array();
			foreach ( $target_languages as $lang ) {
				$map[ $pid ][ $lang ] = in_array( $lang, $existing_langs, true ) ? 'completed' : 'missing';
			}
		}

		return $map;
	}

	/**
	 * Generic WPML translation status loader for any element type.
	 * Returns: [ element_id => [ lang => true ] ] for existing translations.
	 */
	private function load_wpml_status_for_type( $element_ids, $target_languages, $element_type = 'post_post' ) {
		global $wpdb;
		$map = array();

		if ( empty( $element_ids ) || empty( $target_languages ) ) {
			return $map;
		}

		$id_ph = implode( ',', array_fill( 0, count( $element_ids ), '%d' ) );
		$params = array_merge( array( $element_type ), $element_ids );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT element_id, trid FROM {$wpdb->prefix}icl_translations
			 WHERE element_type = %s AND element_id IN ($id_ph)",
			...$params
		) );

		$trid_map = array();
		foreach ( $rows as $row ) {
			$trid_map[ $row->element_id ] = $row->trid;
		}

		$trids = array_unique( array_values( $trid_map ) );
		if ( empty( $trids ) ) {
			return $map;
		}

		$trid_ph = implode( ',', array_fill( 0, count( $trids ), '%d' ) );
		$params = array_merge( $trids, array( $element_type ) );
		$trans_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT trid, language_code FROM {$wpdb->prefix}icl_translations
			 WHERE trid IN ($trid_ph) AND element_type = %s",
			...$params
		) );

		$trid_langs = array();
		foreach ( $trans_rows as $tr ) {
			$trid_langs[ $tr->trid ][ $tr->language_code ] = true;
		}

		foreach ( $element_ids as $eid ) {
			$trid = $trid_map[ $eid ] ?? null;
			$existing = $trid ? ( $trid_langs[ $trid ] ?? array() ) : array();
			foreach ( $target_languages as $lang ) {
				if ( isset( $existing[ $lang ] ) ) {
					$map[ $eid ][ $lang ] = true;
				}
			}
		}

		return $map;
	}

	private function build_product_nodes( $products, $meta_map, $term_map, $image_map, $review_map, $seo_meta_keys, $target_languages, $wpml_map = array() ) {
		$nodes = array();

		foreach ( $products as $p ) {
			$id   = absint( $p->ID );
			$meta = $meta_map[ $id ] ?? array();
			$terms = $term_map[ $id ] ?? array();
			$images = $image_map[ $id ] ?? array( 'total' => 0, 'with_alt' => 0 );
			$reviews = $review_map[ $id ] ?? array( 'count' => 0, 'avg_rating' => 0 );

			// Image count from gallery + thumbnail
			$gallery_str = $meta['_product_image_gallery'] ?? '';
			$gallery_count = ! empty( $gallery_str ) ? count( explode( ',', $gallery_str ) ) : 0;
			$has_thumb = ! empty( $meta['_thumbnail_id'] );
			$image_count = $gallery_count + ( $has_thumb ? 1 : 0 );

			// SEO flags
			$has_seo_title = false;
			$has_seo_desc  = false;
			$has_focus_kw  = false;
			foreach ( $seo_meta_keys as $key ) {
				$val = $meta[ $key ] ?? '';
				if ( empty( $val ) ) {
					continue;
				}
				if ( strpos( $key, 'title' ) !== false ) {
					$has_seo_title = true;
				}
				if ( strpos( $key, 'desc' ) !== false ) {
					$has_seo_desc = true;
				}
				if ( strpos( $key, 'focus' ) !== false || strpos( $key, 'keyword' ) !== false ) {
					$has_focus_kw = true;
				}
			}

			// Translation status per language — prefer WPML data, fallback to meta
			$translation = array();
			foreach ( $target_languages as $lang ) {
				if ( isset( $wpml_map[ $id ][ $lang ] ) ) {
					$translation[ $lang ] = $wpml_map[ $id ][ $lang ];
				} else {
					$translation[ $lang ] = $meta[ '_luwipress_translation_' . $lang . '_status' ] ?? 'missing';
				}
			}

			// AEO flags
			$has_faq       = ! empty( $meta['_luwipress_faq'] ) && 'a:0:{}' !== $meta['_luwipress_faq'];
			$has_howto     = ! empty( $meta['_luwipress_howto'] );
			$has_schema    = ! empty( $meta['_luwipress_schema'] );
			$has_speakable = ! empty( $meta['_luwipress_speakable'] );

			// Upsells/cross-sells
			$upsell_ids    = ! empty( $meta['_upsell_ids'] ) ? maybe_unserialize( $meta['_upsell_ids'] ) : array();
			$crosssell_ids = ! empty( $meta['_crosssell_ids'] ) ? maybe_unserialize( $meta['_crosssell_ids'] ) : array();
			if ( ! is_array( $upsell_ids ) ) {
				$upsell_ids = array();
			}
			if ( ! is_array( $crosssell_ids ) ) {
				$crosssell_ids = array();
			}

			$category_ids = wp_list_pluck( $terms['categories'] ?? array(), 'id' );
			$tag_ids      = wp_list_pluck( $terms['tags'] ?? array(), 'id' );

			$content_length = absint( $p->content_length );
			$enrich_status  = $meta['_luwipress_enrich_status'] ?? 'none';
			$days_since_mod = max( 0, round( ( time() - strtotime( $p->post_modified ) ) / DAY_IN_SECONDS ) );

			// Compute opportunities list and score
			$opps  = array();
			$score = 0;

			if ( ! $has_seo_title ) {
				$opps[] = 'missing_seo_title';
				$score += $this->score_weights['missing_seo_title'];
			}
			if ( ! $has_seo_desc ) {
				$opps[] = 'missing_seo_description';
				$score += $this->score_weights['missing_seo_description'];
			}
			if ( ! $has_focus_kw ) {
				$opps[] = 'missing_focus_kw';
				$score += $this->score_weights['missing_focus_kw'];
			}
			if ( 'completed' !== $enrich_status ) {
				$opps[] = 'not_enriched';
				$score += $this->score_weights['not_enriched'];
			}
			if ( $content_length < 300 ) {
				$opps[] = 'thin_content';
				$score += $this->score_weights['thin_content'];
			}
			foreach ( $target_languages as $lang ) {
				if ( 'completed' !== ( $translation[ $lang ] ?? 'missing' ) ) {
					$opps[] = 'missing_translation_' . $lang;
					$score += $this->score_weights['missing_translation'];
				}
			}
			if ( ! $has_faq ) {
				$opps[] = 'missing_faq';
				$score += $this->score_weights['missing_faq'];
			}
			if ( ! $has_schema ) {
				$opps[] = 'missing_schema';
				$score += $this->score_weights['missing_schema'];
			}
			// 'missing_howto' and 'missing_speakable' opportunities are intentionally
			// NOT counted in the score (3.1.42 IMP-006 / Schema Reality v1.1 doctrine).
			// Google deprecated HowTo for product pages and Speakable entirely (late
			// 2024). Counting them inflated the priority queue with dead signals
			// (~%12 of total score on Tapadum). The booleans $has_howto/$has_speakable
			// are still surfaced in the per-product `aeo` payload below for visibility,
			// but they no longer drive prioritisation.
			if ( $reviews['count'] === 0 ) {
				$opps[] = 'no_reviews';
				$score += $this->score_weights['no_reviews'];
			}
			if ( $images['total'] > 0 && $images['with_alt'] < $images['total'] ) {
				$opps[] = 'missing_alt_text';
				$score += $this->score_weights['missing_alt_text'];
			}
			if ( $days_since_mod > 90 ) {
				$opps[] = 'stale_content';
				$score += $this->score_weights['stale_content'];
			}

			$nodes[] = array(
				'id'              => $id,
				'type'            => 'product',
				'name'            => $p->post_title,
				'slug'            => $p->post_name,
				'sku'             => $meta['_sku'] ?? '',
				'price'           => $meta['_price'] ?? '',
				'stock_status'    => $meta['_stock_status'] ?? 'instock',
				'content_length'  => $content_length,
				'days_since_modified' => $days_since_mod,
				'categories'      => $category_ids,
				'tags'            => $tag_ids,
				'upsell_ids'      => array_map( 'absint', $upsell_ids ),
				'cross_sell_ids'  => array_map( 'absint', $crosssell_ids ),
				'image_count'     => $image_count,
				'images_with_alt' => $images['with_alt'],
				'seo'             => array(
					'has_title'       => $has_seo_title,
					'has_description' => $has_seo_desc,
					'has_focus_kw'    => $has_focus_kw,
				),
				'enrichment'      => array(
					'status'       => $enrich_status,
					'completed_at' => $meta['_luwipress_enrich_completed'] ?? null,
				),
				'aeo'             => array(
					'has_faq'       => $has_faq,
					'has_howto'     => $has_howto,
					'has_schema'    => $has_schema,
					'has_speakable' => $has_speakable,
				),
				'translation'      => $translation,
				'reviews'          => $reviews,
				'opportunity_score' => $score,
				'opportunities'     => $opps,
			);
		}

		return $nodes;
	}

	private function build_category_nodes( $product_nodes ) {
		// Collect all unique category IDs first, then batch-fetch terms
		$all_cat_ids = array();
		foreach ( $product_nodes as $p ) {
			foreach ( $p['categories'] as $cat_id ) {
				$all_cat_ids[ $cat_id ] = true;
			}
		}

		$term_map = array();
		if ( ! empty( $all_cat_ids ) ) {
			$terms = get_terms( array(
				'taxonomy'   => 'product_cat',
				'include'    => array_keys( $all_cat_ids ),
				'hide_empty' => false,
			) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_map[ $term->term_id ] = $term;
				}
			}
		}

		$cat_data = array();

		foreach ( $product_nodes as $p ) {
			foreach ( $p['categories'] as $cat_id ) {
				if ( ! isset( $cat_data[ $cat_id ] ) ) {
					$term = $term_map[ $cat_id ] ?? null;
					$cat_data[ $cat_id ] = array(
						'id'            => $cat_id,
						'type'          => 'category',
						'name'          => $term ? $term->name : '',
						'slug'          => $term ? $term->slug : '',
						'parent_id'     => $term ? $term->parent : 0,
						'product_count' => 0,
						'total_score'   => 0,
						'seo_complete'  => 0,
						'enriched'      => 0,
						'translation_complete' => array(),
					);
				}

				$cat_data[ $cat_id ]['product_count']++;
				$cat_data[ $cat_id ]['total_score'] += $p['opportunity_score'];

				if ( $p['seo']['has_title'] && $p['seo']['has_description'] ) {
					$cat_data[ $cat_id ]['seo_complete']++;
				}
				if ( 'completed' === $p['enrichment']['status'] ) {
					$cat_data[ $cat_id ]['enriched']++;
				}

				foreach ( $p['translation'] as $lang => $status ) {
					if ( ! isset( $cat_data[ $cat_id ]['translation_complete'][ $lang ] ) ) {
						$cat_data[ $cat_id ]['translation_complete'][ $lang ] = 0;
					}
					if ( 'completed' === $status ) {
						$cat_data[ $cat_id ]['translation_complete'][ $lang ]++;
					}
				}
			}
		}

		$nodes = array();
		foreach ( $cat_data as $cat ) {
			$count = max( 1, $cat['product_count'] );

			$translation_pct = array();
			foreach ( $cat['translation_complete'] as $lang => $done ) {
				$translation_pct[ $lang ] = round( ( $done / $count ) * 100, 1 );
			}

			$nodes[] = array(
				'id'              => $cat['id'],
				'type'            => 'category',
				'name'            => $cat['name'],
				'slug'            => $cat['slug'],
				'parent_id'       => $cat['parent_id'],
				'product_count'   => $cat['product_count'],
				'avg_opportunity_score' => round( $cat['total_score'] / $count, 1 ),
				'seo_coverage_pct'      => round( ( $cat['seo_complete'] / $count ) * 100, 1 ),
				'enrichment_pct'        => round( ( $cat['enriched'] / $count ) * 100, 1 ),
				'translation_pct'       => $translation_pct,
			);
		}

		// Sort by avg opportunity score descending
		usort( $nodes, function ( $a, $b ) {
			return $b['avg_opportunity_score'] <=> $a['avg_opportunity_score'];
		} );

		return $nodes;
	}

	private function build_language_nodes( $product_nodes, $target_languages, $default_language = '' ) {
		$lang_counts = array();

		foreach ( $target_languages as $lang ) {
			$lang_counts[ $lang ] = array( 'completed' => 0, 'processing' => 0, 'missing' => 0 );
		}

		foreach ( $product_nodes as $p ) {
			foreach ( $p['translation'] as $lang => $status ) {
				if ( isset( $lang_counts[ $lang ] ) ) {
					if ( 'completed' === $status ) {
						$lang_counts[ $lang ]['completed']++;
					} elseif ( 'processing' === $status ) {
						$lang_counts[ $lang ]['processing']++;
					} else {
						$lang_counts[ $lang ]['missing']++;
					}
				}
			}
		}

		$total = count( $product_nodes );
		$nodes = array();

		// Primary / source language — 100% coverage by definition (products
		// originate in this language). Including it lets the Language view /
		// Taxonomy heatmap / stats show a complete picture instead of hiding
		// the source dimension.
		if ( ! empty( $default_language ) ) {
			$nodes[] = array(
				'id'                  => 'lang_' . $default_language,
				'type'                => 'language',
				'code'                => $default_language,
				'name'                => $this->language_native_name( $default_language ),
				'english_name'        => $this->language_english_name( $default_language ),
				'is_primary'          => true,
				'products_translated' => $total,
				'products_processing' => 0,
				'products_missing'    => 0,
				'coverage_pct'        => 100,
				'opportunity_score'   => 0,
			);
		}

		foreach ( $lang_counts as $lang => $counts ) {
			$coverage = $total > 0 ? round( ( $counts['completed'] / $total ) * 100, 1 ) : 0;
			$nodes[] = array(
				'id'                  => 'lang_' . $lang,
				'type'                => 'language',
				'code'                => $lang,
				'name'                => $this->language_native_name( $lang ),
				'english_name'        => $this->language_english_name( $lang ),
				'is_primary'          => false,
				'products_translated' => $counts['completed'],
				'products_processing' => $counts['processing'],
				'products_missing'    => $counts['missing'],
				'coverage_pct'        => $coverage,
				'opportunity_score'   => $counts['missing'] * $this->score_weights['missing_translation'],
			);
		}

		return $nodes;
	}

	// Language code → native + english display name. Falls back to the
	// uppercased code for anything we don't have in the map.
	private function language_native_name( $code ) {
		$map = array(
			'en' => 'English',   'fr' => 'Français',   'it' => 'Italiano', 'es' => 'Español',
			'de' => 'Deutsch',   'pt' => 'Português',  'nl' => 'Nederlands','pl' => 'Polski',
			'ru' => 'Русский',   'tr' => 'Türkçe',     'ar' => 'العربية',  'zh' => '中文',
			'ja' => '日本語',     'ko' => '한국어',      'hi' => 'हिन्दी',      'el' => 'Ελληνικά',
			'sv' => 'Svenska',   'da' => 'Dansk',      'no' => 'Norsk',    'fi' => 'Suomi',
			'cs' => 'Čeština',   'ro' => 'Română',     'hu' => 'Magyar',   'uk' => 'Українська',
			'he' => 'עברית',      'th' => 'ไทย',         'vi' => 'Tiếng Việt','id' => 'Bahasa Indonesia',
		);
		return $map[ $code ] ?? strtoupper( $code );
	}

	private function language_english_name( $code ) {
		$map = array(
			'en' => 'English',   'fr' => 'French',    'it' => 'Italian',   'es' => 'Spanish',
			'de' => 'German',    'pt' => 'Portuguese','nl' => 'Dutch',     'pl' => 'Polish',
			'ru' => 'Russian',   'tr' => 'Turkish',   'ar' => 'Arabic',    'zh' => 'Chinese',
			'ja' => 'Japanese',  'ko' => 'Korean',    'hi' => 'Hindi',     'el' => 'Greek',
			'sv' => 'Swedish',   'da' => 'Danish',    'no' => 'Norwegian', 'fi' => 'Finnish',
			'cs' => 'Czech',     'ro' => 'Romanian',  'hu' => 'Hungarian', 'uk' => 'Ukrainian',
			'he' => 'Hebrew',    'th' => 'Thai',      'vi' => 'Vietnamese','id' => 'Indonesian',
		);
		return $map[ $code ] ?? strtoupper( $code );
	}

	private function build_customer_segment_nodes() {
		$segment_counts = get_option( 'luwipress_crm_segment_counts', array() );

		$segment_defs = array(
			'vip'      => 'VIP',
			'loyal'    => 'Loyal',
			'active'   => 'Active',
			'new'      => 'New',
			'at_risk'  => 'At Risk',
			'dormant'  => 'Dormant',
			'lost'     => 'Lost',
			'one_time' => 'One-Time',
		);

		$total = array_sum( $segment_counts );
		$nodes = array();

		foreach ( $segment_defs as $key => $label ) {
			$count = absint( $segment_counts[ $key ] ?? 0 );
			$nodes[] = array(
				'id'           => 'segment_' . $key,
				'type'         => 'customer_segment',
				'segment'      => $key,
				'label'        => $label,
				'count'        => $count,
				'share_pct'    => $total > 0 ? round( ( $count / $total ) * 100, 1 ) : 0,
			);
		}

		return $nodes;
	}

	// ─── EDGES ──────────────────────────────────────────────────────────

	private function build_edges( $product_nodes ) {
		$edges = array();

		foreach ( $product_nodes as $p ) {
			$source = 'product:' . $p['id'];

			foreach ( $p['categories'] as $cat_id ) {
				$edges[] = array( 'source' => $source, 'target' => 'category:' . $cat_id, 'type' => 'belongs_to' );
			}

			foreach ( $p['tags'] as $tag_id ) {
				$edges[] = array( 'source' => $source, 'target' => 'tag:' . $tag_id, 'type' => 'tagged_with' );
			}

			foreach ( $p['upsell_ids'] as $uid ) {
				$edges[] = array( 'source' => $source, 'target' => 'product:' . $uid, 'type' => 'upsell' );
			}

			foreach ( $p['cross_sell_ids'] as $cid ) {
				$edges[] = array( 'source' => $source, 'target' => 'product:' . $cid, 'type' => 'cross_sell' );
			}

			foreach ( $p['translation'] as $lang => $status ) {
				$type = 'completed' === $status ? 'translated_to' : 'missing_translation';
				$edges[] = array( 'source' => $source, 'target' => 'lang_' . $lang, 'type' => $type );
			}
		}

		return $edges;
	}

	// ─── OPPORTUNITIES ──────────────────────────────────────────────────

	private function build_opportunities( $product_nodes ) {
		// Group by opportunity type
		$by_type = array();

		foreach ( $product_nodes as $p ) {
			foreach ( $p['opportunities'] as $opp ) {
				$by_type[ $opp ][] = $p['id'];
			}
		}

		// Sort by total impact (count * weight)
		$prioritised = array();
		foreach ( $by_type as $opp_type => $product_ids ) {
			// Normalize type for weight lookup (strip language suffix)
			$weight_key = preg_replace( '/_[a-z]{2,5}$/', '', $opp_type );
			$weight     = $this->score_weights[ $weight_key ] ?? 1;

			$prioritised[] = array(
				'type'          => $opp_type,
				'product_count' => count( $product_ids ),
				'total_impact'  => count( $product_ids ) * $weight,
				'weight'        => $weight,
				'product_ids'   => array_slice( $product_ids, 0, 20 ),
			);
		}

		usort( $prioritised, function ( $a, $b ) {
			return $b['total_impact'] <=> $a['total_impact'];
		} );

		// Top 10 products by score
		$top_products = $product_nodes;
		usort( $top_products, function ( $a, $b ) {
			return $b['opportunity_score'] <=> $a['opportunity_score'];
		} );
		$top_products = array_slice( $top_products, 0, 10 );

		// 3.1.42-hotfix3 (BUG-008): expose `id` alongside `product_id` and add
		// `issue_count` so admin UIs can render clickable rows without an extra
		// REST roundtrip. Both keys point to the same product node id; older
		// callers that read `product_id` are unaffected.
		$top_list = array();
		foreach ( $top_products as $p ) {
			$top_list[] = array(
				'id'                => $p['id'],
				'product_id'        => $p['id'],
				'name'              => $p['name'],
				'opportunity_score' => $p['opportunity_score'],
				'opportunities'     => $p['opportunities'],
				'issue_count'       => is_array( $p['opportunities'] ?? null ) ? count( $p['opportunities'] ) : 0,
			);
		}

		return array(
			'by_type'      => $prioritised,
			'top_products' => $top_list,
		);
	}

	// ─── SUMMARY ────────────────────────────────────────────────────────

	private function build_summary( $product_nodes, $category_nodes, $language_nodes, $segment_nodes, $target_languages ) {
		$total = count( $product_nodes );

		// SEO coverage
		$seo_complete = 0;
		$enriched     = 0;
		$total_score  = 0;
		$aeo_counts   = array( 'faq' => 0, 'howto' => 0, 'schema' => 0, 'speakable' => 0 );

		foreach ( $product_nodes as $p ) {
			if ( $p['seo']['has_title'] && $p['seo']['has_description'] ) {
				$seo_complete++;
			}
			if ( 'completed' === $p['enrichment']['status'] ) {
				$enriched++;
			}
			$total_score += $p['opportunity_score'];

			if ( $p['aeo']['has_faq'] ) { $aeo_counts['faq']++; }
			if ( $p['aeo']['has_howto'] ) { $aeo_counts['howto']++; }
			if ( $p['aeo']['has_schema'] ) { $aeo_counts['schema']++; }
			if ( $p['aeo']['has_speakable'] ) { $aeo_counts['speakable']++; }
		}

		$pct = function ( $count ) use ( $total ) {
			return $total > 0 ? round( ( $count / $total ) * 100, 1 ) : 0;
		};

		// Translation coverage per language
		$translation_coverage = array();
		foreach ( $language_nodes as $ln ) {
			$translation_coverage[ $ln['code'] ] = $ln['coverage_pct'];
		}

		// AEO
		$aeo_coverage = array();
		foreach ( $aeo_counts as $type => $count ) {
			$aeo_coverage[ $type ] = $pct( $count );
		}

		$summary = array(
			'total_products'        => $total,
			'total_categories'      => count( $category_nodes ),
			'total_customer_segments' => count( $segment_nodes ),
			'enrichment_coverage'   => $pct( $enriched ),
			'seo_coverage'          => $pct( $seo_complete ),
			'aeo_coverage'          => $aeo_coverage,
			'translation_coverage'  => $translation_coverage,
			'opportunity_score_total' => $total_score,
			'opportunity_score_avg'   => $total > 0 ? round( $total_score / $total, 1 ) : 0,
		);

		// History + delta — keep a rolling 30-day ring of daily snapshots so
		// the frontend can show "X wins this week" progress badges. Throttled
		// to one write per day (keyed by Y-m-d) so hot callers don't spam.
		$summary['trend'] = $this->update_and_get_summary_trend( $summary );

		return $summary;
	}

	// Stores a small daily snapshot of the key coverage numbers in a site
	// option (autoload=no). Returns { last_week_delta_opportunities,
	// last_week_delta_seo, last_week_delta_enrichment, points_count }.
	private function update_and_get_summary_trend( $summary ) {
		$key     = 'luwipress_kg_summary_history';
		$history = get_option( $key, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$today = current_time( 'Y-m-d' );
		$entry = array(
			'date'                => $today,
			'ts'                  => current_time( 'mysql' ),
			'total_products'      => absint( $summary['total_products'] ),
			'seo_coverage'        => floatval( $summary['seo_coverage'] ),
			'enrichment_coverage' => floatval( $summary['enrichment_coverage'] ),
			'opportunity_total'   => absint( $summary['opportunity_score_total'] ),
		);

		// Upsert by date — today's write overwrites any earlier snapshot today.
		$found = false;
		foreach ( $history as $i => $row ) {
			if ( ( $row['date'] ?? '' ) === $today ) {
				$history[ $i ] = $entry;
				$found         = true;
				break;
			}
		}
		if ( ! $found ) {
			$history[] = $entry;
		}

		// Keep only the last 30 entries (≈1 month).
		usort( $history, function ( $a, $b ) {
			return strcmp( $a['date'] ?? '', $b['date'] ?? '' );
		} );
		if ( count( $history ) > 30 ) {
			$history = array_slice( $history, -30 );
		}

		update_option( $key, $history, false );

		// Delta vs ≈7 days ago (or earliest available if we have less history).
		$delta = array(
			'points_count'          => count( $history ),
			'opportunities_delta'   => null,
			'seo_delta'             => null,
			'enrichment_delta'      => null,
			'baseline_date'         => null,
		);
		if ( count( $history ) >= 2 ) {
			// Pick the snapshot closest to 7 days before today.
			$target_ts = strtotime( $today ) - 7 * DAY_IN_SECONDS;
			$baseline  = $history[0];
			foreach ( $history as $row ) {
				if ( ( $row['date'] ?? '' ) === $today ) {
					continue;
				}
				if ( abs( strtotime( $row['date'] ) - $target_ts ) < abs( strtotime( $baseline['date'] ) - $target_ts ) ) {
					$baseline = $row;
				}
			}
			$delta['baseline_date']       = $baseline['date'];
			$delta['opportunities_delta'] = absint( $summary['opportunity_score_total'] ) - absint( $baseline['opportunity_total'] );
			$delta['seo_delta']           = round( floatval( $summary['seo_coverage'] ) - floatval( $baseline['seo_coverage'] ), 1 );
			$delta['enrichment_delta']    = round( floatval( $summary['enrichment_coverage'] ) - floatval( $baseline['enrichment_coverage'] ), 1 );
		}

		return $delta;
	}

	// ─── STORE INTELLIGENCE (WooCommerce deep analysis) ─────────────────

	private function build_store_intelligence() {
		global $wpdb;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'available' => false );
		}

		// Revenue: 30 days, 7 days, today
		$revenue = array();
		foreach ( array( 30, 7, 1 ) as $days ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS orders, COALESCE(SUM(pm.meta_value), 0) AS revenue
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
				 WHERE p.post_type = 'shop_order'
				   AND p.post_status IN ('wc-completed','wc-processing')
				   AND p.post_date > DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			) );
			$label = $days === 1 ? 'today' : ( $days === 7 ? 'last_7_days' : 'last_30_days' );
			$revenue[ $label ] = array(
				'orders'  => absint( $row->orders ?? 0 ),
				'revenue' => round( floatval( $row->revenue ?? 0 ), 2 ),
			);
		}

		// Average order value
		$lifetime = $wpdb->get_row(
			"SELECT COUNT(*) AS orders, COALESCE(SUM(pm.meta_value), 0) AS revenue
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ('wc-completed','wc-processing')"
		);
		$aov = ( $lifetime->orders > 0 ) ? round( $lifetime->revenue / $lifetime->orders, 2 ) : 0;

		// Top 10 selling products (last 90 days)
		$top_sellers = $wpdb->get_results(
			"SELECT oi_meta.meta_value AS product_id,
			        SUM(oi_qty.meta_value) AS qty_sold,
			        SUM(oi_total.meta_value) AS revenue
			 FROM {$wpdb->prefix}woocommerce_order_items oi
			 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oi_meta ON oi.order_item_id = oi_meta.order_item_id AND oi_meta.meta_key = '_product_id'
			 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oi_qty ON oi.order_item_id = oi_qty.order_item_id AND oi_qty.meta_key = '_qty'
			 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oi_total ON oi.order_item_id = oi_total.order_item_id AND oi_total.meta_key = '_line_total'
			 INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ('wc-completed','wc-processing')
			   AND p.post_date > DATE_SUB(NOW(), INTERVAL 90 DAY)
			 GROUP BY oi_meta.meta_value
			 ORDER BY revenue DESC
			 LIMIT 10"
		);

		// Prime post cache for all top seller IDs in one query
		$top_pids = wp_list_pluck( $top_sellers, 'product_id' );
		if ( ! empty( $top_pids ) ) {
			_prime_post_caches( array_map( 'absint', $top_pids ), false, false );
		}

		// 3.1.42-hotfix3 (BUG-009): filter out trashed/deleted products. The
		// aggregate query joins on order_itemmeta which retains rows even after
		// the underlying product is deleted; without this filter, top_sellers
		// returned empty `name` for ~50% of entries on Tapadum.
		$top_list = array();
		foreach ( $top_sellers as $ts ) {
			$pid = absint( $ts->product_id );
			$post_obj = $pid ? get_post( $pid ) : null;
			if ( ! $post_obj || $post_obj->post_status !== 'publish' ) {
				continue;
			}
			$title = get_the_title( $pid );
			if ( ! $title ) {
				continue;
			}
			$top_list[] = array(
				'product_id' => $pid,
				'id'         => $pid,
				'name'       => $title,
				'qty_sold'   => absint( $ts->qty_sold ),
				'revenue'    => round( floatval( $ts->revenue ), 2 ),
			);
		}

		// Revenue by category (top 10)
		$cat_revenue = $wpdb->get_results(
			"SELECT tt.term_id, t.name,
			        SUM(oi_total.meta_value) AS revenue,
			        COUNT(DISTINCT p.ID) AS order_count
			 FROM {$wpdb->prefix}woocommerce_order_items oi
			 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oi_meta ON oi.order_item_id = oi_meta.order_item_id AND oi_meta.meta_key = '_product_id'
			 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oi_total ON oi.order_item_id = oi_total.order_item_id AND oi_total.meta_key = '_line_total'
			 INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
			 INNER JOIN {$wpdb->term_relationships} tr ON oi_meta.meta_value = tr.object_id
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
			 INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ('wc-completed','wc-processing')
			   AND p.post_date > DATE_SUB(NOW(), INTERVAL 90 DAY)
			 GROUP BY tt.term_id
			 ORDER BY revenue DESC
			 LIMIT 10"
		);

		$cat_list = array();
		foreach ( $cat_revenue as $cr ) {
			$cat_list[] = array(
				'category_id' => absint( $cr->term_id ),
				'name'        => $cr->name,
				'revenue'     => round( floatval( $cr->revenue ), 2 ),
				'orders'      => absint( $cr->order_count ),
			);
		}

		// Stock alerts
		$low_stock = $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'
			   AND pm_stock.meta_value = 'outofstock'"
		);
		$on_backorder = $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'
			   AND pm_stock.meta_value = 'onbackorder'"
		);

		// Pricing: products without price, zero price
		$no_price = $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_price'
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'
			   AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value = '0')"
		);

		// Products on sale
		$on_sale = absint( $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sale_price'
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'
			   AND pm.meta_value != '' AND pm.meta_value > 0"
		) );

		return array(
			'revenue'            => $revenue,
			'average_order_value' => $aov,
			'lifetime_orders'    => absint( $lifetime->orders ),
			'lifetime_revenue'   => round( floatval( $lifetime->revenue ), 2 ),
			'currency'           => get_woocommerce_currency(),
			'top_sellers'        => $top_list,
			'revenue_by_category' => $cat_list,
			'stock_alerts'       => array(
				'out_of_stock' => absint( $low_stock ),
				'on_backorder' => absint( $on_backorder ),
				'no_price'     => absint( $no_price ),
				'on_sale'      => $on_sale,
			),
		);
	}

	// ─── PLUGIN HEALTH ANALYSIS ─────────────────────────────────────────

	private function build_plugin_health( $detector, $product_nodes, $target_languages ) {
		$env = $detector->get_environment();
		$total_products = count( $product_nodes );

		// SEO plugin health
		$seo = $env['seo'];
		$seo_health = array(
			'plugin'      => $seo['plugin'],
			'version'     => $seo['version'] ?? null,
			'status'      => 'none' === $seo['plugin'] ? 'not_installed' : 'active',
			'features'    => $seo['features'] ?? array(),
		);
		if ( 'none' !== $seo['plugin'] && $total_products > 0 ) {
			$with_title = 0;
			$with_desc  = 0;
			$with_kw    = 0;
			foreach ( $product_nodes as $p ) {
				if ( $p['seo']['has_title'] ) { $with_title++; }
				if ( $p['seo']['has_description'] ) { $with_desc++; }
				if ( $p['seo']['has_focus_kw'] ) { $with_kw++; }
			}
			$seo_health['coverage'] = array(
				'meta_title'     => round( ( $with_title / $total_products ) * 100, 1 ),
				'meta_description' => round( ( $with_desc / $total_products ) * 100, 1 ),
				'focus_keyword'  => round( ( $with_kw / $total_products ) * 100, 1 ),
			);
			$seo_health['health_score'] = round( ( $seo_health['coverage']['meta_title'] + $seo_health['coverage']['meta_description'] ) / 2, 1 );
		}

		// Translation plugin health
		$trans = $env['translation'];
		$trans_health = array(
			'plugin'           => $trans['plugin'],
			'version'          => $trans['version'] ?? null,
			'status'           => 'none' === $trans['plugin'] ? 'not_installed' : 'active',
			'default_language' => $trans['default_language'] ?? 'en',
			'active_languages' => $trans['active_languages'] ?? array(),
			'features'         => $trans['features'] ?? array(),
		);
		if ( 'none' !== $trans['plugin'] && $total_products > 0 && ! empty( $target_languages ) ) {
			$lang_coverage = array();
			foreach ( $target_languages as $lang ) {
				$done = 0;
				foreach ( $product_nodes as $p ) {
					if ( 'completed' === ( $p['translation'][ $lang ] ?? '' ) ) { $done++; }
				}
				$lang_coverage[ $lang ] = round( ( $done / $total_products ) * 100, 1 );
			}
			$trans_health['product_coverage'] = $lang_coverage;
			$trans_health['avg_coverage']     = count( $lang_coverage ) > 0 ? round( array_sum( $lang_coverage ) / count( $lang_coverage ), 1 ) : 0;

			// Taxonomy translation health
			$trans_health['taxonomy_coverage'] = $this->get_taxonomy_translation_health( $target_languages );
		}

		// Email plugin health
		$email = $env['email'];
		$email_health = array(
			'plugin'     => $email['plugin'],
			'method'     => $email['method'] ?? 'php_mail',
			'from_email' => $email['from_email'] ?? '',
			'from_name'  => $email['from_name'] ?? '',
			'status'     => 'wp_mail' === $email['plugin'] ? 'default' : 'configured',
		);

		// CRM health
		$crm = $env['crm'];
		$crm_health = array(
			'plugin'  => $crm['plugin'],
			'version' => $crm['version'] ?? null,
			'status'  => 'none' === $crm['plugin'] ? 'not_installed' : 'active',
		);

		// Cache plugin health
		$cache = $env['cache'] ?? array( 'plugin' => 'none' );
		$cache_health = array(
			'plugin'  => $cache['plugin'],
			'version' => $cache['version'] ?? null,
			'status'  => 'none' === $cache['plugin'] ? 'not_installed' : 'active',
		);

		// Support
		$support = $env['customer_support'] ?? array( 'plugin' => 'none' );
		$support_health = array(
			'plugin'  => $support['plugin'],
			'status'  => 'none' === $support['plugin'] ? 'not_installed' : 'active',
		);

		// Overall readiness score (0-100)
		$readiness = 0;
		$checks = 0;
		if ( 'none' !== $seo['plugin'] ) { $readiness += ( $seo_health['health_score'] ?? 0 ); $checks++; }
		if ( 'none' !== $trans['plugin'] ) { $readiness += ( $trans_health['avg_coverage'] ?? 0 ); $checks++; }
		if ( 'wp_mail' !== $email['plugin'] ) { $readiness += 100; $checks++; }
		if ( 'none' !== $cache['plugin'] ) { $readiness += 100; $checks++; }
		$readiness_score = $checks > 0 ? round( $readiness / $checks, 1 ) : 0;

		return array(
			'seo'         => $seo_health,
			'translation' => $trans_health,
			'email'       => $email_health,
			'crm'         => $crm_health,
			'cache'       => $cache_health,
			'support'     => $support_health,
			'readiness_score' => $readiness_score,
			'recommendations' => $this->build_plugin_recommendations( $env, $seo_health, $trans_health ),
		);
	}

	private function get_taxonomy_translation_health( $target_languages ) {
		global $wpdb;

		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return array();
		}

		$default_lang = LuwiPress_Translation::get_default_language();
		$result = array();

		foreach ( array( 'product_cat', 'product_tag' ) as $taxonomy ) {
			$el_type = 'tax_' . $taxonomy;
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations WHERE element_type = %s AND language_code = %s AND source_language_code IS NULL",
				$el_type, $default_lang
			) );

			if ( $total === 0 ) {
				continue;
			}

			// Batch: single GROUP BY query instead of per-language loop
			$lang_placeholders = implode( ',', array_fill( 0, count( $target_languages ), '%s' ) );
			$params = array_merge( array( $el_type ), $target_languages );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT language_code, COUNT(*) AS cnt FROM {$wpdb->prefix}icl_translations WHERE element_type = %s AND language_code IN ({$lang_placeholders}) AND source_language_code IS NOT NULL GROUP BY language_code",
				$params
			) );
			$done_map = array();
			foreach ( $rows as $row ) {
				$done_map[ $row->language_code ] = (int) $row->cnt;
			}
			$langs = array();
			foreach ( $target_languages as $lang ) {
				$done = $done_map[ $lang ] ?? 0;
				$langs[ $lang ] = array(
					'done'    => $done,
					'missing' => max( 0, $total - $done ),
					'pct'     => round( ( $done / $total ) * 100, 1 ),
				);
			}

			$result[ $taxonomy ] = array(
				'total'     => $total,
				'languages' => $langs,
			);
		}

		return $result;
	}

	private function build_plugin_recommendations( $env, $seo_health, $trans_health ) {
		$recs = array();

		// SEO
		if ( 'none' === $env['seo']['plugin'] ) {
			$recs[] = array( 'priority' => 'high', 'area' => 'seo', 'message' => 'No SEO plugin installed. Install Rank Math or Yoast for meta title/description management.' );
		} elseif ( isset( $seo_health['coverage'] ) && $seo_health['coverage']['meta_title'] < 50 ) {
			$recs[] = array( 'priority' => 'high', 'area' => 'seo', 'message' => 'Less than 50% of products have SEO meta titles. Use AI enrichment to generate them.' );
		}

		// Translation
		if ( 'none' !== $env['translation']['plugin'] ) {
			if ( isset( $trans_health['avg_coverage'] ) && $trans_health['avg_coverage'] < 50 ) {
				$recs[] = array( 'priority' => 'high', 'area' => 'translation', 'message' => 'Translation coverage below 50%. Prioritize translating top-selling products first.' );
			}
			$tax = $trans_health['taxonomy_coverage'] ?? array();
			foreach ( $tax as $taxonomy => $data ) {
				foreach ( $data['languages'] ?? array() as $lang => $stats ) {
					if ( $stats['pct'] < 100 ) {
						$recs[] = array( 'priority' => 'medium', 'area' => 'taxonomy', 'message' => sprintf( 'Taxonomy %s missing %d translations for %s. Translate taxonomies before products.', $taxonomy, $stats['missing'], strtoupper( $lang ) ) );
						break 2;
					}
				}
			}
		}

		// Cache
		if ( 'none' === ( $env['cache']['plugin'] ?? 'none' ) ) {
			$recs[] = array( 'priority' => 'medium', 'area' => 'cache', 'message' => 'No cache plugin detected. Install LiteSpeed Cache or WP Rocket for better performance.' );
		}

		// Email
		if ( 'wp_mail' === $env['email']['plugin'] ) {
			$recs[] = array( 'priority' => 'low', 'area' => 'email', 'message' => 'Using default PHP mail. Install WP Mail SMTP for reliable email delivery.' );
		}

		return $recs;
	}

	// ─── TAXONOMY NODES ─────────────────────────────────────────────────

	private function build_taxonomy_nodes( $target_languages ) {
		global $wpdb;

		$nodes = array();

		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return $nodes;
		}

		$default_lang = LuwiPress_Translation::get_default_language();

		foreach ( array( 'product_cat' => 'Product Categories', 'product_tag' => 'Product Tags' ) as $taxonomy => $label ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$el_type = 'tax_' . $taxonomy;

			// Get all original terms
			$originals = $wpdb->get_results( $wpdb->prepare(
				"SELECT t.element_id, t.trid
				 FROM {$wpdb->prefix}icl_translations t
				 WHERE t.element_type = %s AND t.language_code = %s AND t.source_language_code IS NULL",
				$el_type, $default_lang
			) );

			// Bulk-fetch all translations for these trids in one query
			$trid_list = wp_list_pluck( $originals, 'trid' );
			$trid_translation_map = array();
			if ( ! empty( $trid_list ) ) {
				$trid_placeholders = implode( ',', array_fill( 0, count( $trid_list ), '%d' ) );
				$bulk_translations = $wpdb->get_results( $wpdb->prepare(
					"SELECT element_id, trid, language_code
					 FROM {$wpdb->prefix}icl_translations
					 WHERE trid IN ({$trid_placeholders}) AND element_type = %s AND source_language_code IS NOT NULL",
					array_merge( $trid_list, array( $el_type ) )
				) );
				foreach ( $bulk_translations as $bt ) {
					$trid_translation_map[ $bt->trid ][ $bt->language_code ] = absint( $bt->element_id );
				}
			}

			// Prime term cache for all element IDs
			$all_element_ids = wp_list_pluck( $originals, 'element_id' );
			foreach ( $trid_translation_map as $trid_translations ) {
				$all_element_ids = array_merge( $all_element_ids, array_values( $trid_translations ) );
			}
			if ( ! empty( $all_element_ids ) ) {
				_prime_term_caches( array_map( 'absint', $all_element_ids ) );
			}

			foreach ( $originals as $orig ) {
				$term = get_term( absint( $orig->element_id ), $taxonomy );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}

				$translations = array();
				foreach ( $target_languages as $lang ) {
					$translated_id = $trid_translation_map[ $orig->trid ][ $lang ] ?? null;

					if ( $translated_id ) {
						$tr_term = get_term( $translated_id, $taxonomy );
						$translations[ $lang ] = array(
							'term_id' => $translated_id,
							'name'    => $tr_term && ! is_wp_error( $tr_term ) ? $tr_term->name : '',
							'status'  => 'translated',
						);
					} else {
						$translations[ $lang ] = array(
							'term_id' => null,
							'name'    => null,
							'status'  => 'missing',
						);
					}
				}

				$nodes[] = array(
					'id'           => $term->term_id,
					'type'         => $taxonomy,
					'name'         => $term->name,
					'slug'         => $term->slug,
					'parent_id'    => $term->parent,
					'count'        => $term->count,
					'translations' => $translations,
				);
			}
		}

		return $nodes;
	}

	// ─── POSTS & PAGES ─────────────────────────────────────────────────

	private function build_post_nodes( $seo_meta_keys, $target_languages = array() ) {
		global $wpdb;

		// For WPML: only original posts
		$wpml_join = '';
		$wpml_where = '';
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$wpml_join = "JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id AND t.element_type = 'post_post'";
			$wpml_where = 'AND t.source_language_code IS NULL';
		}

		$posts = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_name, p.post_status, p.post_author,
			        p.post_date, p.post_modified, p.comment_count,
			        LENGTH(p.post_content) AS content_length
			 FROM {$wpdb->posts} p
			 {$wpml_join}
			 WHERE p.post_type = 'post'
			   AND p.post_status = 'publish'
			   {$wpml_where}
			 ORDER BY p.post_date DESC
			 LIMIT 500"
		);

		if ( empty( $posts ) ) {
			return array();
		}

		$post_ids = wp_list_pluck( $posts, 'ID' );

		// Bulk load terms
		$term_map = $this->load_post_terms( $post_ids );

		// Bulk load SEO meta
		$seo_map = array();
		if ( ! empty( $seo_meta_keys ) ) {
			$id_ph  = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			$key_ph = implode( ',', array_fill( 0, count( $seo_meta_keys ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				 WHERE post_id IN ($id_ph) AND meta_key IN ($key_ph)",
				...array_merge( $post_ids, $seo_meta_keys )
			) );
			foreach ( $rows as $row ) {
				$seo_map[ $row->post_id ][ $row->meta_key ] = $row->meta_value;
			}
		}

		// Bulk load featured image IDs
		$thumb_ids = array();
		$id_ph = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$thumb_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta}
			 WHERE post_id IN ($id_ph) AND meta_key = '_thumbnail_id'",
			...$post_ids
		) );
		foreach ( $thumb_rows as $tr ) {
			$thumb_ids[ $tr->post_id ] = absint( $tr->meta_value );
		}

		// Bulk load WPML translation status for posts
		$wpml_map = array();
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && ! empty( $target_languages ) ) {
			$wpml_map = $this->load_wpml_status_for_type( $post_ids, $target_languages, 'post_post' );
		}

		// Collect author IDs
		$author_ids = array_unique( wp_list_pluck( $posts, 'post_author' ) );
		$author_map = array();
		if ( ! empty( $author_ids ) ) {
			$users = get_users( array( 'include' => $author_ids, 'fields' => array( 'ID', 'display_name' ) ) );
			foreach ( $users as $u ) {
				$author_map[ $u->ID ] = $u->display_name;
			}
		}

		$nodes = array();
		foreach ( $posts as $p ) {
			$id   = absint( $p->ID );
			$meta = $seo_map[ $id ] ?? array();
			$terms = $term_map[ $id ] ?? array();

			$has_seo_title = false;
			$has_seo_desc  = false;
			$has_focus_kw  = false;
			foreach ( $seo_meta_keys as $key ) {
				$val = $meta[ $key ] ?? '';
				if ( empty( $val ) ) continue;
				if ( strpos( $key, 'title' ) !== false ) $has_seo_title = true;
				if ( strpos( $key, 'desc' ) !== false ) $has_seo_desc = true;
				if ( strpos( $key, 'focus' ) !== false || strpos( $key, 'keyword' ) !== false ) $has_focus_kw = true;
			}

			$content_length = absint( $p->content_length );
			$word_count     = intval( $content_length / 5 );
			$days_since_mod = max( 0, round( ( time() - strtotime( $p->post_modified ) ) / DAY_IN_SECONDS ) );

			$category_ids  = wp_list_pluck( $terms['categories'] ?? array(), 'id' );
			$tag_ids       = wp_list_pluck( $terms['tags'] ?? array(), 'id' );
			$category_list = array();
			foreach ( $terms['categories'] ?? array() as $cat ) {
				$category_list[] = array( 'id' => $cat['id'], 'name' => $cat['name'] );
			}

			// Translation status per language
			$translation = array();
			foreach ( $target_languages as $lang ) {
				$translation[ $lang ] = isset( $wpml_map[ $id ][ $lang ] ) ? 'completed' : 'missing';
			}

			$nodes[] = array(
				'id'                  => $id,
				'type'                => 'post',
				'title'               => $p->post_title,
				'slug'                => $p->post_name,
				'author_id'           => absint( $p->post_author ),
				'author_name'         => $author_map[ $p->post_author ] ?? '',
				'content_length'      => $content_length,
				'word_count'          => $word_count,
				'days_since_modified' => $days_since_mod,
				'categories'          => $category_ids,
				'category_names'      => $category_list,
				'tags'                => $tag_ids,
				'comment_count'       => absint( $p->comment_count ),
				'has_featured_image'  => isset( $thumb_ids[ $id ] ),
				'seo'                 => array(
					'has_title'       => $has_seo_title,
					'has_description' => $has_seo_desc,
					'has_focus_kw'    => $has_focus_kw,
				),
				'translation'         => $translation,
				'is_stale'            => $days_since_mod > 90,
			);
		}

		return $nodes;
	}

	private function load_post_terms( $post_ids ) {
		global $wpdb;

		$id_ph = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT tr.object_id, t.term_id, t.name, t.slug, tt.taxonomy, tt.parent
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			 WHERE tr.object_id IN ($id_ph)
			   AND tt.taxonomy IN ('category', 'post_tag')",
			...$post_ids
		) );

		$map = array();
		foreach ( $rows as $row ) {
			$key = 'category' === $row->taxonomy ? 'categories' : 'tags';
			$map[ $row->object_id ][ $key ][] = array(
				'id'   => absint( $row->term_id ),
				'name' => $row->name,
				'slug' => $row->slug,
			);
		}

		return $map;
	}

	private function build_page_nodes() {
		global $wpdb;

		$front_page_id = absint( get_option( 'page_on_front', 0 ) );
		$blog_page_id  = absint( get_option( 'page_for_posts', 0 ) );
		$shop_page_id  = function_exists( 'wc_get_page_id' ) ? absint( wc_get_page_id( 'shop' ) ) : 0;

		// For WPML: only original pages
		$wpml_join = '';
		$wpml_where = '';
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$wpml_join = "JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id AND t.element_type = 'post_page'";
			$wpml_where = 'AND t.source_language_code IS NULL';
		}

		$pages = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_name, p.post_parent, p.menu_order,
			        LENGTH(p.post_content) AS content_length
			 FROM {$wpdb->posts} p
			 {$wpml_join}
			 WHERE p.post_type = 'page'
			   AND p.post_status = 'publish'
			   {$wpml_where}
			 ORDER BY p.menu_order ASC, p.post_title ASC
			 LIMIT 500"
		);

		if ( empty( $pages ) ) {
			return array();
		}

		// Detect page template
		$page_ids = wp_list_pluck( $pages, 'ID' );
		$id_ph = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
		$template_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta}
			 WHERE post_id IN ($id_ph) AND meta_key = '_wp_page_template'",
			...$page_ids
		) );
		$template_map = array();
		foreach ( $template_rows as $tr ) {
			$template_map[ $tr->post_id ] = $tr->meta_value;
		}

		// Build children map
		$children_map = array();
		foreach ( $pages as $pg ) {
			if ( $pg->post_parent > 0 ) {
				$children_map[ $pg->post_parent ][] = absint( $pg->ID );
			}
		}

		$nodes = array();
		foreach ( $pages as $pg ) {
			$id = absint( $pg->ID );
			$template = $template_map[ $id ] ?? 'default';
			if ( $template === '' || $template === 'default' ) {
				$template = 'default';
			}

			$nodes[] = array(
				'id'              => $id,
				'type'            => 'page',
				'title'           => $pg->post_title,
				'slug'            => $pg->post_name,
				'parent_id'       => absint( $pg->post_parent ),
				'menu_order'      => absint( $pg->menu_order ),
				'content_length'  => absint( $pg->content_length ),
				'template'        => $template,
				'is_front_page'   => $id === $front_page_id,
				'is_blog_page'    => $id === $blog_page_id,
				'is_shop_page'    => $id === $shop_page_id,
				'children_ids'    => $children_map[ $id ] ?? array(),
			);
		}

		return $nodes;
	}

	// ─── CONTENT TAXONOMY ──────────────────────────────────────────────

	private function build_content_taxonomy_nodes() {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		$nodes = array();

		foreach ( $taxonomies as $tax ) {
			$term_count = wp_count_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false ) );
			if ( is_wp_error( $term_count ) ) {
				$term_count = 0;
			}

			// Get top 20 terms by count
			$top_terms = get_terms( array(
				'taxonomy'   => $tax->name,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 20,
				'hide_empty' => false,
			) );

			$term_list = array();
			if ( ! is_wp_error( $top_terms ) ) {
				foreach ( $top_terms as $term ) {
					$term_list[] = array(
						'id'    => $term->term_id,
						'name'  => $term->name,
						'slug'  => $term->slug,
						'count' => $term->count,
					);
				}
			}

			$nodes[] = array(
				'type'         => 'taxonomy',
				'name'         => $tax->name,
				'label'        => $tax->label,
				'post_types'   => (array) $tax->object_type,
				'hierarchical' => $tax->hierarchical,
				'term_count'   => absint( $term_count ),
				'top_terms'    => $term_list,
			);
		}

		return $nodes;
	}

	// ─── MEDIA INVENTORY ───────────────────────────────────────────────

	private function build_media_inventory_nodes() {
		global $wpdb;

		// Total counts by mime type
		$type_counts = $wpdb->get_results(
			"SELECT
			    SUM(CASE WHEN post_mime_type LIKE 'image/%' THEN 1 ELSE 0 END) AS images,
			    SUM(CASE WHEN post_mime_type LIKE 'video/%' THEN 1 ELSE 0 END) AS videos,
			    SUM(CASE WHEN post_mime_type LIKE 'application/pdf' THEN 1 ELSE 0 END) AS documents,
			    COUNT(*) AS total
			 FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);
		$counts = $type_counts[0] ?? (object) array( 'images' => 0, 'videos' => 0, 'documents' => 0, 'total' => 0 );

		// Images without alt text
		$missing_alt = $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type LIKE 'image/%'
			   AND p.post_status = 'inherit'
			   AND (pm.meta_value IS NULL OR pm.meta_value = '')"
		);

		// Orphaned media (no post_parent)
		$orphaned = $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts}
			 WHERE post_type = 'attachment'
			   AND post_status = 'inherit'
			   AND post_parent = 0"
		);

		// Top 10 largest files
		$largest = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_mime_type, pm.meta_value AS file_meta
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_metadata'
			 WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
			 ORDER BY p.ID DESC
			 LIMIT 10"
		);

		$largest_list = array();
		foreach ( $largest as $file ) {
			$meta = maybe_unserialize( $file->file_meta );
			$filesize = 0;
			if ( is_array( $meta ) && isset( $meta['filesize'] ) ) {
				$filesize = absint( $meta['filesize'] );
			}
			$largest_list[] = array(
				'id'        => absint( $file->ID ),
				'title'     => $file->post_title,
				'mime_type' => $file->post_mime_type,
				'filesize'  => $filesize,
			);
		}

		// Sort by filesize desc
		usort( $largest_list, function( $a, $b ) {
			return $b['filesize'] <=> $a['filesize'];
		} );

		return array(
			'total_media'       => absint( $counts->total ),
			'total_images'      => absint( $counts->images ),
			'total_videos'      => absint( $counts->videos ),
			'total_documents'   => absint( $counts->documents ),
			'missing_alt_count' => absint( $missing_alt ),
			'orphaned_count'    => absint( $orphaned ),
			'largest_files'     => $largest_list,
		);
	}

	// ─── MENUS ─────────────────────────────────────────────────────────

	private function build_menu_nodes() {
		$locations = get_nav_menu_locations();
		$registered = get_registered_nav_menus();
		$nodes = array();

		foreach ( $registered as $location => $description ) {
			$menu_id = $locations[ $location ] ?? 0;
			$menu = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;

			$items_data = array();
			if ( $menu ) {
				$items = wp_get_nav_menu_items( $menu->term_id );
				if ( $items ) {
					foreach ( $items as $item ) {
						$items_data[] = array(
							'id'        => absint( $item->ID ),
							'title'     => $item->title,
							'url'       => $item->url,
							'type'      => $item->type,
							'object'    => $item->object,
							'object_id' => absint( $item->object_id ),
							'parent'    => absint( $item->menu_item_parent ),
						);
					}
				}
			}

			$nodes[] = array(
				'location'    => $location,
				'description' => $description,
				'menu_name'   => $menu ? $menu->name : null,
				'menu_id'     => $menu_id,
				'item_count'  => count( $items_data ),
				'items'       => $items_data,
			);
		}

		return $nodes;
	}

	// ─── PRODUCT ATTRIBUTES ────────────────────────────────────────────

	private function build_product_attribute_nodes() {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return array();
		}

		$attributes = wc_get_attribute_taxonomies();
		$nodes = array();

		foreach ( $attributes as $attr ) {
			$taxonomy = wc_attribute_taxonomy_name( $attr->attribute_name );
			$terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			) );

			$term_list = array();
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_list[] = array(
						'id'    => $term->term_id,
						'name'  => $term->name,
						'slug'  => $term->slug,
						'count' => $term->count,
					);
				}
			}

			$nodes[] = array(
				'id'         => absint( $attr->attribute_id ),
				'type'       => 'product_attribute',
				'name'       => $attr->attribute_name,
				'label'      => $attr->attribute_label,
				'taxonomy'   => $taxonomy,
				'type_slug'  => $attr->attribute_type,
				'term_count' => count( $term_list ),
				'terms'      => $term_list,
			);
		}

		return $nodes;
	}

	// ─── AUTHORS ───────────────────────────────────────────────────────

	private function build_author_nodes() {
		global $wpdb;

		$authors = $wpdb->get_results(
			"SELECT p.post_author,
			        u.display_name,
			        COUNT(*) AS post_count,
			        MAX(p.post_date) AS last_published
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->users} u ON p.post_author = u.ID
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ('post', 'page', 'product')
			 GROUP BY p.post_author
			 ORDER BY post_count DESC
			 LIMIT 50"
		);

		// Get per-type counts
		$type_counts = $wpdb->get_results(
			"SELECT post_author, post_type, COUNT(*) AS cnt
			 FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ('post', 'page', 'product')
			 GROUP BY post_author, post_type"
		);
		$type_map = array();
		foreach ( $type_counts as $tc ) {
			$type_map[ $tc->post_author ][ $tc->post_type ] = absint( $tc->cnt );
		}

		// Get roles
		$author_ids = wp_list_pluck( $authors, 'post_author' );
		$role_map = array();
		if ( ! empty( $author_ids ) ) {
			$users = get_users( array( 'include' => $author_ids ) );
			foreach ( $users as $u ) {
				$role_map[ $u->ID ] = implode( ', ', $u->roles );
			}
		}

		$nodes = array();
		foreach ( $authors as $a ) {
			$uid = absint( $a->post_author );
			$counts = $type_map[ $uid ] ?? array();
			$days_since = max( 0, round( ( time() - strtotime( $a->last_published ) ) / DAY_IN_SECONDS ) );

			$nodes[] = array(
				'id'              => $uid,
				'type'            => 'author',
				'display_name'    => $a->display_name,
				'role'            => $role_map[ $uid ] ?? 'unknown',
				'total_posts'     => absint( $a->post_count ),
				'post_count'      => $counts['post'] ?? 0,
				'page_count'      => $counts['page'] ?? 0,
				'product_count'   => $counts['product'] ?? 0,
				'last_published'  => $a->last_published,
				'days_since_last' => $days_since,
			);
		}

		return $nodes;
	}

	// ─── ORDER ANALYTICS ───────────────────────────────────────────────

	private function build_order_analytics_nodes() {
		global $wpdb;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'available' => false );
		}

		// Orders by status
		$status_counts = $wpdb->get_results(
			"SELECT post_status, COUNT(*) AS cnt
			 FROM {$wpdb->posts}
			 WHERE post_type = 'shop_order'
			 GROUP BY post_status"
		);
		$by_status = array();
		foreach ( $status_counts as $sc ) {
			$by_status[ $sc->post_status ] = absint( $sc->cnt );
		}

		// Monthly revenue last 12 months
		$monthly = $wpdb->get_results(
			"SELECT DATE_FORMAT(p.post_date, '%Y-%m') AS month,
			        COUNT(*) AS orders,
			        COALESCE(SUM(pm.meta_value), 0) AS revenue
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ('wc-completed', 'wc-processing')
			   AND p.post_date > DATE_SUB(NOW(), INTERVAL 12 MONTH)
			 GROUP BY DATE_FORMAT(p.post_date, '%Y-%m')
			 ORDER BY month ASC"
		);
		$monthly_data = array();
		foreach ( $monthly as $m ) {
			$monthly_data[] = array(
				'month'   => $m->month,
				'orders'  => absint( $m->orders ),
				'revenue' => round( floatval( $m->revenue ), 2 ),
			);
		}

		// Repeat customer rate
		$total_customers = $wpdb->get_var(
			"SELECT COUNT(DISTINCT pm.meta_value)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ('wc-completed', 'wc-processing')
			   AND pm.meta_value != '0'"
		);
		$repeat_customers = $wpdb->get_var(
			"SELECT COUNT(*) FROM (
			    SELECT pm.meta_value
			    FROM {$wpdb->posts} p
			    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
			    WHERE p.post_type = 'shop_order'
			      AND p.post_status IN ('wc-completed', 'wc-processing')
			      AND pm.meta_value != '0'
			    GROUP BY pm.meta_value
			    HAVING COUNT(*) > 1
			) AS repeats"
		);
		$repeat_rate = $total_customers > 0 ? round( ( $repeat_customers / $total_customers ) * 100, 1 ) : 0;

		// Average items per order (last 90 days)
		$avg_items = $wpdb->get_var(
			"SELECT AVG(item_count) FROM (
			    SELECT oi.order_id, COUNT(*) AS item_count
			    FROM {$wpdb->prefix}woocommerce_order_items oi
			    INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
			    WHERE p.post_type = 'shop_order'
			      AND p.post_status IN ('wc-completed', 'wc-processing')
			      AND p.post_date > DATE_SUB(NOW(), INTERVAL 90 DAY)
			      AND oi.order_item_type = 'line_item'
			    GROUP BY oi.order_id
			) AS counts"
		);

		// Payment method distribution (last 90 days)
		$payment_rows = $wpdb->get_results(
			"SELECT pm.meta_value AS method, COUNT(*) AS cnt
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_payment_method_title'
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ('wc-completed', 'wc-processing')
			   AND p.post_date > DATE_SUB(NOW(), INTERVAL 90 DAY)
			 GROUP BY pm.meta_value
			 ORDER BY cnt DESC"
		);
		$payment_methods = array();
		foreach ( $payment_rows as $pr ) {
			$payment_methods[ $pr->method ] = absint( $pr->cnt );
		}

		// Refund stats
		$refund_total = $wpdb->get_row(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(pm.meta_value), 0) AS amount
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
			 WHERE p.post_type = 'shop_order_refund'
			   AND p.post_date > DATE_SUB(NOW(), INTERVAL 90 DAY)"
		);

		return array(
			'orders_by_status'      => $by_status,
			'monthly_revenue_12m'   => $monthly_data,
			'repeat_customer_rate'  => $repeat_rate,
			'total_customers'       => absint( $total_customers ),
			'repeat_customers'      => absint( $repeat_customers ),
			'avg_items_per_order'   => round( floatval( $avg_items ), 1 ),
			'payment_methods'       => $payment_methods,
			'refund_count_90d'      => absint( $refund_total->cnt ?? 0 ),
			'refund_amount_90d'     => round( floatval( $refund_total->amount ?? 0 ), 2 ),
		);
	}

	// ─── POST / PAGE EDGES ─────────────────────────────────────────────

	private function build_post_edges( $post_nodes ) {
		$edges = array();
		foreach ( $post_nodes as $p ) {
			$source = 'post:' . $p['id'];
			foreach ( $p['categories'] as $cat_id ) {
				$edges[] = array( 'source' => $source, 'target' => 'post_category:' . $cat_id, 'type' => 'belongs_to' );
			}
			foreach ( $p['tags'] as $tag_id ) {
				$edges[] = array( 'source' => $source, 'target' => 'post_tag:' . $tag_id, 'type' => 'tagged_with' );
			}
		}
		return $edges;
	}

	private function build_page_edges( $page_nodes ) {
		$edges = array();
		foreach ( $page_nodes as $p ) {
			if ( $p['parent_id'] > 0 ) {
				$edges[] = array( 'source' => 'page:' . $p['id'], 'target' => 'page:' . $p['parent_id'], 'type' => 'child_of' );
			}
		}
		return $edges;
	}

	// ─── DESIGN AUDIT ──────────────────────────────────────────────────

	/**
	 * Build design audit nodes for Elementor-built pages.
	 *
	 * Checks: responsive settings, mobile padding, image optimization,
	 * accessibility basics, spacing consistency, Kit CSS coverage.
	 *
	 * @return array Design audit data per page type.
	 */
	private function build_design_audit_nodes() {
		$elementor_active = defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' );

		$audit = array(
			'elementor_available' => $elementor_active,
			'kit'                 => $elementor_active ? $this->audit_kit_css() : array( 'has_kit' => false, 'scopes' => array() ),
			'page_types'          => $elementor_active ? $this->audit_page_types() : array(),
			'summary'             => array(),
		);

		if ( ! $elementor_active ) {
			$audit['summary'] = array(
				'overall_health'  => null,
				'total_issues'    => 0,
				'critical_issues' => 0,
				'pages_audited'   => 0,
				'note'            => 'Elementor not installed — design audit disabled.',
			);
			return $audit;
		}

		// Calculate overall design health score
		$scores = wp_list_pluck( $audit['page_types'], 'health_score' );
		$audit['summary'] = array(
			'overall_health'  => count( $scores ) > 0 ? round( array_sum( $scores ) / count( $scores ) ) : 0,
			'total_issues'    => array_sum( wp_list_pluck( $audit['page_types'], 'issue_count' ) ),
			'critical_issues' => array_sum( wp_list_pluck( $audit['page_types'], 'critical_count' ) ),
			'pages_audited'   => count( $audit['page_types'] ),
		);

		return $audit;
	}

	/**
	 * Audit Elementor Kit CSS — check coverage per page type.
	 */
	private function audit_kit_css() {
		$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		if ( ! $kit_id ) {
			return array( 'has_kit' => false, 'scopes' => array() );
		}

		$kit_settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		$css = is_array( $kit_settings ) ? ( $kit_settings['custom_css'] ?? '' ) : '';

		$scopes = array(
			'global'    => array( 'selector' => null,                'has_desktop' => false, 'has_mobile' => false, 'has_tablet' => false ),
			'homepage'  => array( 'selector' => '.home',             'has_desktop' => false, 'has_mobile' => false, 'has_tablet' => false ),
			'shop'      => array( 'selector' => '.woocommerce-shop', 'has_desktop' => false, 'has_mobile' => false, 'has_tablet' => false ),
			'product'   => array( 'selector' => '.single-product',   'has_desktop' => false, 'has_mobile' => false, 'has_tablet' => false ),
			'blog'      => array( 'selector' => '.single-post',      'has_desktop' => false, 'has_mobile' => false, 'has_tablet' => false ),
		);

		foreach ( $scopes as $key => &$scope ) {
			if ( $scope['selector'] === null ) {
				$scope['has_desktop'] = ! empty( $css );
				$scope['has_mobile']  = strpos( $css, 'max-width' ) !== false;
				$scope['has_tablet']  = strpos( $css, 'min-width: 768px' ) !== false;
			} else {
				$scope['has_desktop'] = strpos( $css, $scope['selector'] ) !== false;
				$scope['has_mobile']  = preg_match( '/max-width.*?' . preg_quote( $scope['selector'], '/' ) . '|' . preg_quote( $scope['selector'], '/' ) . '.*?max-width/s', $css ) === 1;
				$scope['has_tablet']  = preg_match( '/min-width:\s*768px.*?' . preg_quote( $scope['selector'], '/' ) . '|' . preg_quote( $scope['selector'], '/' ) . '.*?min-width:\s*768px/s', $css ) === 1;
			}
		}
		unset( $scope );

		return array(
			'has_kit'    => true,
			'kit_id'     => $kit_id,
			'css_length' => strlen( $css ),
			'scopes'     => $scopes,
		);
	}

	/**
	 * Audit individual page types for design health.
	 */
	private function audit_page_types() {
		$results = array();

		// Homepage
		$front_id = absint( get_option( 'page_on_front', 0 ) );
		if ( $front_id ) {
			$results[] = $this->audit_single_page( $front_id, 'homepage' );
		}

		// Shop page
		$shop_id = function_exists( 'wc_get_page_id' ) ? absint( wc_get_page_id( 'shop' ) ) : 0;
		if ( $shop_id ) {
			$results[] = $this->audit_single_page( $shop_id, 'shop' );
		}

		// Sample blog post (latest)
		$latest_post = get_posts( array( 'numberposts' => 1, 'post_type' => 'post', 'post_status' => 'publish' ) );
		if ( ! empty( $latest_post ) ) {
			$results[] = $this->audit_single_page( $latest_post[0]->ID, 'blog_post' );
		}

		// Sample product (latest)
		$latest_product = get_posts( array( 'numberposts' => 1, 'post_type' => 'product', 'post_status' => 'publish' ) );
		if ( ! empty( $latest_product ) ) {
			$results[] = $this->audit_single_page( $latest_product[0]->ID, 'product' );
		}

		return $results;
	}

	/**
	 * Audit a single Elementor page for design issues.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $page_type Page type identifier.
	 * @return array Audit results.
	 */
	private function audit_single_page( $post_id, $page_type ) {
		$issues   = array();
		$post     = get_post( $post_id );
		$title    = $post ? $post->post_title : 'Unknown';

		// Check Elementor data exists
		$el_data_raw = get_post_meta( $post_id, '_elementor_data', true );
		$has_elementor = ! empty( $el_data_raw );

		if ( $has_elementor ) {
			$el_data = is_string( $el_data_raw ) ? json_decode( $el_data_raw, true ) : $el_data_raw;
			if ( is_array( $el_data ) ) {
				$this->audit_elementor_tree( $el_data, $issues, $page_type );
			}
		}

		// Check page-level custom CSS
		$page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		$has_page_css  = is_array( $page_settings ) && ! empty( $page_settings['custom_css'] );

		// Calculate health score (100 = perfect, 0 = many issues)
		$critical = count( array_filter( $issues, function( $i ) { return $i['severity'] === 'critical'; } ) );
		$warnings = count( array_filter( $issues, function( $i ) { return $i['severity'] === 'warning'; } ) );
		$info     = count( $issues ) - $critical - $warnings;
		$deduction = ( $critical * 20 ) + ( $warnings * 8 ) + ( $info * 2 );
		$score     = max( 0, 100 - $deduction );

		return array(
			'post_id'        => $post_id,
			'title'          => $title,
			'page_type'      => $page_type,
			'has_elementor'  => $has_elementor,
			'has_page_css'   => $has_page_css,
			'health_score'   => $score,
			'issue_count'    => count( $issues ),
			'critical_count' => $critical,
			'warning_count'  => $warnings,
			'info_count'     => $info,
			'issues'         => $issues,
		);
	}

	/**
	 * Walk Elementor element tree and detect design issues.
	 *
	 * @param array  $elements  Elementor data array.
	 * @param array  &$issues   Issues array to append to.
	 * @param string $page_type Page type for context.
	 */
	private function audit_elementor_tree( array $elements, array &$issues, $page_type ) {
		foreach ( $elements as $el ) {
			$type     = $el['elType'] ?? '';
			$wtype    = $el['widgetType'] ?? '';
			$id       = $el['id'] ?? '';
			$settings = $el['settings'] ?? array();

			// Check sections/containers for responsive issues
			if ( in_array( $type, array( 'section', 'container' ), true ) ) {
				$this->check_responsive_spacing( $settings, $id, $type, $issues );
			}

			// Check columns for zero padding
			if ( $type === 'column' ) {
				$this->check_column_padding( $settings, $id, $issues );
			}

			// Check containers for mobile flex direction
			if ( $type === 'container' ) {
				$this->check_container_responsive( $settings, $id, $issues );
			}

			// Recurse into children
			if ( ! empty( $el['elements'] ) ) {
				$this->audit_elementor_tree( $el['elements'], $issues, $page_type );
			}
		}
	}

	/**
	 * Check section/container for missing mobile padding.
	 */
	private function check_responsive_spacing( $settings, $id, $type, &$issues ) {
		$padding = $settings['padding'] ?? null;
		$padding_mobile = $settings['padding_mobile'] ?? null;

		// Has desktop padding with 0 left/right but no mobile override
		if ( is_array( $padding ) ) {
			$left  = $padding['left'] ?? '';
			$right = $padding['right'] ?? '';
			if ( ( $left === '0' || $left === '' ) && ( $right === '0' || $right === '' ) ) {
				if ( ! is_array( $padding_mobile ) || empty( $padding_mobile['left'] ) ) {
					$issues[] = array(
						'element_id' => $id,
						'element_type' => $type,
						'severity'   => 'warning',
						'type'       => 'missing_mobile_padding',
						'message'    => 'Zero left/right padding with no mobile override — content hugs edges on mobile',
						'fix'        => 'Add padding_mobile with 16px left/right',
					);
				}
			}
		}
	}

	/**
	 * Check column for zero padding on all sides.
	 */
	private function check_column_padding( $settings, $id, &$issues ) {
		$padding = $settings['padding'] ?? null;
		if ( ! is_array( $padding ) ) {
			return;
		}

		$all_zero = true;
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$val = $padding[ $side ] ?? '';
			if ( $val !== '0' && $val !== '' && $val !== 0 ) {
				$all_zero = false;
				break;
			}
		}

		if ( $all_zero ) {
			$padding_mobile = $settings['padding_mobile'] ?? null;
			if ( ! is_array( $padding_mobile ) || empty( $padding_mobile['left'] ) ) {
				$issues[] = array(
					'element_id' => $id,
					'element_type' => 'column',
					'severity'   => 'warning',
					'type'       => 'zero_column_padding',
					'message'    => 'Column has zero padding — content touches edges',
					'fix'        => 'Add at least 16px horizontal padding for mobile',
				);
			}
		}
	}

	/**
	 * Check container for missing mobile flex direction.
	 */
	private function check_container_responsive( $settings, $id, &$issues ) {
		$direction = $settings['flex_direction'] ?? '';
		$direction_mobile = $settings['flex_direction_mobile'] ?? '';

		// Row direction on desktop without mobile column override
		if ( $direction === 'row' && empty( $direction_mobile ) ) {
			// Check if children have fixed widths that would be too narrow on mobile
			$width = $settings['width'] ?? null;
			if ( is_array( $width ) && isset( $width['size'] ) && $width['unit'] === '%' && $width['size'] < 50 ) {
				$issues[] = array(
					'element_id' => $id,
					'element_type' => 'container',
					'severity'   => 'critical',
					'type'       => 'narrow_container_no_stack',
					'message'    => 'Container is ' . $width['size'] . '% wide in row layout with no mobile column override — too narrow on mobile',
					'fix'        => 'Add flex_direction_mobile: column and width_mobile: 100%',
				);
			}
		}

		// Row container without any mobile override
		if ( $direction === 'row' && empty( $direction_mobile ) ) {
			$child_count = 0;
			// Count is checked upstream — flag if row has no mobile stack
			$issues[] = array(
				'element_id' => $id,
				'element_type' => 'container',
				'severity'   => 'info',
				'type'       => 'row_no_mobile_stack',
				'message'    => 'Row container without explicit mobile column direction',
				'fix'        => 'Consider adding flex_direction_mobile: column for better mobile layout',
			);
		}
	}

	// ─── CACHE ──────────────────────────────────────────────────────────

	public function invalidate_cache() {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_luwipress_kg_%'
			    OR option_name LIKE '_transient_timeout_luwipress_kg_%'"
		);
	}
}
