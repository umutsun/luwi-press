<?php
/**
 * n8nPress Knowledge Graph
 *
 * Single REST endpoint that returns a comprehensive JSON graph of the
 * WordPress/WooCommerce store: products, categories, languages, customer
 * segments, SEO/AEO coverage, and AI opportunity scores.
 *
 * AI workflows consume this to make prioritised decisions (what to enrich,
 * translate, generate FAQ for, etc.).
 *
 * Endpoint: GET /wp-json/n8npress/v1/knowledge-graph
 *
 * @package n8nPress
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class N8nPress_Knowledge_Graph {

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
		'missing_howto'           => 4,
		'missing_speakable'       => 3,
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

		// Auto-invalidate cache when products change
		add_action( 'save_post_product', array( $this, 'invalidate_cache' ) );
		add_action( 'woocommerce_update_product', array( $this, 'invalidate_cache' ) );
	}

	public function register_endpoints() {
		register_rest_route( 'n8npress/v1', '/knowledge-graph', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_knowledge_graph' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'section' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Comma-separated sections: products,categories,translation,seo,aeo,crm,environment,opportunities',
				),
				'fresh' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		) );
	}

	public function check_permission( $request ) {
		$stored = get_option( 'n8npress_seo_api_token', '' );

		$auth_header = $request->get_header( 'authorization' );
		if ( ! empty( $auth_header ) ) {
			$token = str_replace( 'Bearer ', '', $auth_header );
			if ( ! empty( $stored ) && hash_equals( $stored, $token ) ) {
				return true;
			}
			if ( class_exists( 'N8nPress_Auth' ) ) {
				$user = N8nPress_Auth::validate_token( $token );
				if ( ! is_wp_error( $user ) ) {
					return true;
				}
			}
		}

		$api_token = $request->get_header( 'x-n8npress-token' );
		if ( ! empty( $api_token ) && ! empty( $stored ) && hash_equals( $stored, $api_token ) ) {
			return true;
		}

		return current_user_can( 'manage_options' );
	}

	// ─── MAIN HANDLER ──────────────────────────────────────────────────

	public function handle_knowledge_graph( $request ) {
		$start = microtime( true );

		// Parse sections
		$section_param = $request->get_param( 'section' );
		$all_sections  = array( 'products', 'categories', 'translation', 'seo', 'aeo', 'crm', 'store', 'plugins', 'taxonomy', 'environment', 'opportunities' );
		$sections      = $section_param ? array_intersect( array_map( 'trim', explode( ',', $section_param ) ), $all_sections ) : $all_sections;

		// Check cache
		$fresh     = (bool) $request->get_param( 'fresh' );
		$cache_key = 'n8npress_kg_' . md5( implode( ',', $sections ) );
		if ( ! $fresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				$cached['meta']['from_cache'] = true;
				return rest_ensure_response( $cached );
			}
		}

		// Get environment info
		$detector  = N8nPress_Plugin_Detector::get_instance();
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
			$language_nodes = $this->build_language_nodes( $product_nodes, $target_languages );
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
				'plugin_version'    => N8NPRESS_VERSION,
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

		// Edges
		if ( ! empty( $edges ) ) {
			$response['edges'] = $edges;
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

		// Environment
		if ( in_array( 'environment', $sections, true ) ) {
			$response['environment'] = $detector->get_environment();
		}

		// Opportunities
		if ( in_array( 'opportunities', $sections, true ) ) {
			$response['opportunities'] = $this->build_opportunities( $product_nodes );
		}

		$response['meta']['execution_time_ms'] = round( ( microtime( true ) - $start ) * 1000, 1 );

		// Cache for 5 minutes
		$ttl = absint( get_option( 'n8npress_knowledge_graph_ttl', 300 ) );
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
				 ORDER BY p.ID ASC"
			);
		}

		return $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_name, p.post_status,
			        p.post_date, p.post_modified,
			        LENGTH(p.post_content) AS content_length
			 FROM {$wpdb->posts} p
			 WHERE p.post_type = 'product'
			   AND p.post_status = 'publish'
			 ORDER BY p.ID ASC"
		);
	}

	private function load_product_meta( $product_ids, $seo_meta_keys, $target_languages ) {
		global $wpdb;

		$wanted_keys = array(
			'_price', '_regular_price', '_sale_price', '_sku', '_stock_status',
			'_thumbnail_id', '_product_image_gallery',
			'_upsell_ids', '_crosssell_ids',
			'_n8npress_enrich_status', '_n8npress_enrich_completed',
			'_n8npress_faq', '_n8npress_howto', '_n8npress_schema', '_n8npress_speakable',
		);

		// Add SEO meta keys
		$wanted_keys = array_merge( $wanted_keys, $seo_meta_keys );

		// Add translation status keys
		foreach ( $target_languages as $lang ) {
			$wanted_keys[] = '_n8npress_translation_' . $lang . '_status';
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
					$translation[ $lang ] = $meta[ '_n8npress_translation_' . $lang . '_status' ] ?? 'missing';
				}
			}

			// AEO flags
			$has_faq       = ! empty( $meta['_n8npress_faq'] ) && 'a:0:{}' !== $meta['_n8npress_faq'];
			$has_howto     = ! empty( $meta['_n8npress_howto'] );
			$has_schema    = ! empty( $meta['_n8npress_schema'] );
			$has_speakable = ! empty( $meta['_n8npress_speakable'] );

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
			$enrich_status  = $meta['_n8npress_enrich_status'] ?? 'none';
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
			if ( ! $has_howto ) {
				$opps[] = 'missing_howto';
				$score += $this->score_weights['missing_howto'];
			}
			if ( ! $has_speakable ) {
				$opps[] = 'missing_speakable';
				$score += $this->score_weights['missing_speakable'];
			}
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
					'completed_at' => $meta['_n8npress_enrich_completed'] ?? null,
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

	private function build_language_nodes( $product_nodes, $target_languages ) {
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

		foreach ( $lang_counts as $lang => $counts ) {
			$coverage = $total > 0 ? round( ( $counts['completed'] / $total ) * 100, 1 ) : 0;
			$nodes[] = array(
				'id'                  => 'lang_' . $lang,
				'type'                => 'language',
				'code'                => $lang,
				'products_translated' => $counts['completed'],
				'products_processing' => $counts['processing'],
				'products_missing'    => $counts['missing'],
				'coverage_pct'        => $coverage,
				'opportunity_score'   => $counts['missing'] * $this->score_weights['missing_translation'],
			);
		}

		return $nodes;
	}

	private function build_customer_segment_nodes() {
		$segment_counts = get_option( 'n8npress_crm_segment_counts', array() );

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

		$top_list = array();
		foreach ( $top_products as $p ) {
			$top_list[] = array(
				'product_id'       => $p['id'],
				'name'             => $p['name'],
				'opportunity_score' => $p['opportunity_score'],
				'opportunities'    => $p['opportunities'],
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

		return array(
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

		$top_list = array();
		foreach ( $top_sellers as $ts ) {
			$pid = absint( $ts->product_id );
			$top_list[] = array(
				'product_id' => $pid,
				'name'       => get_the_title( $pid ),
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

		$default_lang = apply_filters( 'wpml_default_language', 'en' );
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

			$langs = array();
			foreach ( $target_languages as $lang ) {
				$done = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations WHERE element_type = %s AND language_code = %s AND source_language_code IS NOT NULL",
					$el_type, $lang
				) );
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

		$default_lang = apply_filters( 'wpml_default_language', 'en' );

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

			foreach ( $originals as $orig ) {
				$term = get_term( absint( $orig->element_id ), $taxonomy );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}

				$translations = array();
				foreach ( $target_languages as $lang ) {
					$translated_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT element_id FROM {$wpdb->prefix}icl_translations
						 WHERE trid = %d AND language_code = %s AND element_type = %s",
						$orig->trid, $lang, $el_type
					) );

					if ( $translated_id ) {
						$tr_term = get_term( absint( $translated_id ), $taxonomy );
						$translations[ $lang ] = array(
							'term_id' => absint( $translated_id ),
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

	// ─── CACHE ──────────────────────────────────────────────────────────

	public function invalidate_cache() {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_n8npress_kg_%'
			    OR option_name LIKE '_transient_timeout_n8npress_kg_%'"
		);
	}
}
