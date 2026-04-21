<?php
/**
 * LuwiPress Site Config
 *
 * Exposes a single REST endpoint that returns the full WordPress + WooCommerce
 * + plugin environment to remote automations. This eliminates the need for clients
 * to maintain their own copies of site settings.
 *
 * Endpoint: GET /wp-json/luwipress/v1/site-config
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Site_Config {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	public function register_endpoints() {
		register_rest_route( 'luwipress/v1', '/site-config', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_site_config' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'luwipress/v1', '/content/opportunities', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_content_opportunities' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args' => array(
				'limit' => array(
					'default'           => 50,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		) );
	}

	public function check_permission( $request ) {
		if ( LuwiPress_Permission::check_token_or_admin( $request ) ) {
			return true;
		}
		return new WP_Error( 'unauthorized', 'Authentication required', array( 'status' => 401 ) );
	}

	// ------------------------------------------------------------------
	// GET /site-config
	// ------------------------------------------------------------------

	public function get_site_config() {
		$detector = LuwiPress_Plugin_Detector::get_instance();

		$config = array(
			'site'        => $this->get_site_info(),
			'woocommerce' => $this->get_woocommerce_info(),
			'plugins'     => $detector->get_environment(),
			'luwipress'    => $this->get_luwipress_info(),
		);

		return rest_ensure_response( $config );
	}

	private function get_site_info() {
		return array(
			'url'        => get_site_url(),
			'name'       => get_bloginfo( 'name' ),
			'tagline'    => get_bloginfo( 'description' ),
			'locale'     => get_locale(),
			'timezone'   => wp_timezone_string(),
			'date_format' => get_option( 'date_format' ),
			'admin_email' => get_option( 'admin_email' ),
			'wp_version' => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'multisite'  => is_multisite(),
			'permalink'  => get_option( 'permalink_structure' ),
			'theme'      => get_stylesheet(),
		);
	}

	private function get_woocommerce_info() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'active' => false );
		}

		$info = array(
			'active'           => true,
			'version'          => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'currency'         => get_woocommerce_currency(),
			'currency_symbol'  => get_woocommerce_currency_symbol(),
			'currency_pos'     => get_option( 'woocommerce_currency_pos' ),
			'thousand_sep'     => get_option( 'woocommerce_price_thousand_sep' ),
			'decimal_sep'      => get_option( 'woocommerce_price_decimal_sep' ),
			'num_decimals'     => absint( get_option( 'woocommerce_price_num_decimals', 2 ) ),
			'weight_unit'      => get_option( 'woocommerce_weight_unit' ),
			'dimension_unit'   => get_option( 'woocommerce_dimension_unit' ),
			'tax_enabled'      => wc_tax_enabled(),
			'store_country'    => WC()->countries->get_base_country(),
			'store_state'      => WC()->countries->get_base_state(),
			'store_city'       => WC()->countries->get_base_city(),
			'review_enabled'   => 'yes' === get_option( 'woocommerce_enable_reviews' ),
			'stock_management' => 'yes' === get_option( 'woocommerce_manage_stock' ),
			'low_stock_threshold' => absint( get_option( 'woocommerce_notify_low_stock_amount', 2 ) ),
		);

		// Product counts
		$counts = wp_count_posts( 'product' );
		$info['product_count'] = array(
			'publish' => absint( $counts->publish ?? 0 ),
			'draft'   => absint( $counts->draft ?? 0 ),
			'total'   => absint( ( $counts->publish ?? 0 ) + ( $counts->draft ?? 0 ) ),
		);

		// Shipping zones (names only, not config details)
		if ( class_exists( 'WC_Shipping_Zones' ) ) {
			$zones = WC_Shipping_Zones::get_zones();
			$info['shipping_zones'] = array_map( function ( $z ) {
				return $z['zone_name'];
			}, $zones );
		}

		// Payment gateways (active ones only)
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->get_available_payment_gateways();
			$info['payment_gateways'] = array_keys( $gateways );
		}

		return $info;
	}

	private function get_luwipress_info() {
		$ai_provider = get_option( 'luwipress_ai_provider', 'openai' );
		$api_token   = get_option( 'luwipress_seo_api_token', '' );
		$ai_key      = $this->get_active_ai_key( $ai_provider );

		$info = array(
			'version'               => defined( 'LUWIPRESS_VERSION' ) ? LUWIPRESS_VERSION : null,
			'api_token_configured'  => ! empty( $api_token ),
			'api_token_hint'        => self::mask_secret( $api_token ),
			'modules_active'        => $this->get_active_modules(),
			'target_language'       => get_option( 'luwipress_target_language', 'tr' ),
			'target_languages'      => get_option( 'luwipress_translation_languages', array() ),
			'auto_enrich'           => (bool) get_option( 'luwipress_auto_enrich', false ),
			'ai'                    => array(
				'provider'           => $ai_provider,
				'model'              => get_option( 'luwipress_ai_model', 'gpt-4o-mini' ),
				'max_tokens'         => absint( get_option( 'luwipress_max_output_tokens', 1024 ) ),
				'api_key_configured' => ! empty( $ai_key ),
				'api_key_hint'       => self::mask_secret( $ai_key ),
			),
		);

		return $info;
	}

	private function get_active_ai_key( $provider ) {
		$key_map = array(
			'anthropic'         => 'luwipress_anthropic_api_key',
			'openai'            => 'luwipress_openai_api_key',
			'google'            => 'luwipress_google_ai_api_key',
			'openai-compatible' => 'luwipress_oai_compat_api_key',
		);

		$option = $key_map[ $provider ] ?? '';
		if ( empty( $option ) ) {
			return '';
		}

		return get_option( $option, '' );
	}

	/**
	 * Returns a non-reversible hint for a secret: last 4 chars prefixed with stars.
	 * Never returns the full value. Empty secrets return empty string.
	 */
	private static function mask_secret( $secret ) {
		if ( ! is_string( $secret ) || '' === $secret ) {
			return '';
		}
		$tail = substr( $secret, -4 );
		return '****' . $tail;
	}

	private function get_active_modules() {
		$modules = array();

		if ( class_exists( 'LuwiPress_AI_Content' ) ) {
			$modules[] = 'ai-content';
		}
		if ( class_exists( 'LuwiPress_AEO' ) ) {
			$modules[] = 'aeo';
		}
		if ( class_exists( 'LuwiPress_Translation' ) ) {
			$modules[] = 'translation';
		}
		if ( class_exists( 'LuwiPress_Content_Scheduler' ) ) {
			$modules[] = 'content-scheduler';
		}
		if ( class_exists( 'LuwiPress_Email_Proxy' ) ) {
			$modules[] = 'email-proxy';
		}

		return $modules;
	}

	// ------------------------------------------------------------------
	// GET /content/opportunities
	//
	// Single endpoint for AI clients to discover what needs work:
	// - products missing SEO meta
	// - products missing translations
	// - stale content needing refresh
	// - products with thin descriptions
	// ------------------------------------------------------------------

	public function get_content_opportunities( $request ) {
		$limit = $request->get_param( 'limit' );

		$opportunities = array(
			'missing_seo_meta'   => $this->find_missing_seo_meta( $limit ),
			'missing_translations' => $this->find_missing_translations( $limit ),
			'stale_content'      => $this->find_stale_content( $limit ),
			'thin_content'       => $this->find_thin_content( $limit ),
			'missing_alt_text'   => $this->find_missing_alt_text( $limit ),
		);

		$opportunities['summary'] = array(
			'total_issues' => array_sum( array_map( 'count', $opportunities ) ) - 1, // exclude summary itself
			'generated_at' => current_time( 'c' ),
		);

		return rest_ensure_response( $opportunities );
	}

	private function find_missing_seo_meta( $limit ) {
		$detector = LuwiPress_Plugin_Detector::get_instance();
		$seo      = $detector->detect_seo();

		if ( 'none' === $seo['plugin'] || empty( $seo['meta_keys']['description'] ) ) {
			return array();
		}

		$desc_key = $seo['meta_keys']['description'];

		global $wpdb;
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			 WHERE p.post_type = 'product'
			   AND p.post_status = 'publish'
			   AND (pm.meta_value IS NULL OR pm.meta_value = '')
			 ORDER BY p.ID DESC
			 LIMIT %d",
			$desc_key,
			$limit
		) );

		return array_map( function ( $r ) {
			return array(
				'id'    => (int) $r->ID,
				'title' => $r->post_title,
				'url'   => get_permalink( $r->ID ),
				'type'  => 'missing_seo_description',
			);
		}, $results );
	}

	private function find_missing_translations( $limit ) {
		$detector = LuwiPress_Plugin_Detector::get_instance();
		$t        = $detector->detect_translation();

		if ( 'none' === $t['plugin'] || count( $t['active_languages'] ) < 2 ) {
			return array();
		}

		$missing = array();

		if ( 'wpml' === $t['plugin'] ) {
			global $wpdb;
			$default_lang = $t['default_language'];
			$target_langs = array_diff( $t['active_languages'], array( $default_lang ) );

			foreach ( $target_langs as $lang ) {
				$results = $wpdb->get_results( $wpdb->prepare(
					"SELECT p.ID, p.post_title
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->prefix}icl_translations src
					   ON p.ID = src.element_id AND src.element_type = 'post_product'
					 WHERE p.post_type = 'product'
					   AND p.post_status = 'publish'
					   AND src.language_code = %s
					   AND NOT EXISTS (
					     SELECT 1 FROM {$wpdb->prefix}icl_translations dst
					     WHERE dst.trid = src.trid AND dst.language_code = %s
					   )
					 ORDER BY p.ID DESC
					 LIMIT %d",
					$default_lang,
					$lang,
					$limit
				) );

				foreach ( $results as $r ) {
					$missing[] = array(
						'id'              => (int) $r->ID,
						'title'           => $r->post_title,
						'missing_language' => $lang,
						'type'            => 'missing_translation',
					);
				}
			}
		} elseif ( 'polylang' === $t['plugin'] && function_exists( 'pll_get_post_translations' ) ) {
			$default_lang = $t['default_language'];
			$target_langs = array_diff( $t['active_languages'], array( $default_lang ) );

			$products = get_posts( array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'lang'           => $default_lang,
			) );

			foreach ( $products as $product ) {
				$translations = pll_get_post_translations( $product->ID );
				foreach ( $target_langs as $lang ) {
					if ( empty( $translations[ $lang ] ) ) {
						$missing[] = array(
							'id'              => $product->ID,
							'title'           => $product->post_title,
							'missing_language' => $lang,
							'type'            => 'missing_translation',
						);
					}
				}
			}
		}

		return $missing;
	}

	private function find_stale_content( $limit ) {
		$stale_days = absint( get_option( 'luwipress_freshness_stale_days', 90 ) );
		$cutoff     = gmdate( 'Y-m-d H:i:s', strtotime( "-{$stale_days} days" ) );

		global $wpdb;
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_modified
			 FROM {$wpdb->posts}
			 WHERE post_type IN ('product', 'post')
			   AND post_status = 'publish'
			   AND post_modified < %s
			 ORDER BY post_modified ASC
			 LIMIT %d",
			$cutoff,
			$limit
		) );

		return array_map( function ( $r ) {
			return array(
				'id'            => (int) $r->ID,
				'title'         => $r->post_title,
				'last_modified' => $r->post_modified,
				'days_stale'    => (int) ( ( time() - strtotime( $r->post_modified ) ) / DAY_IN_SECONDS ),
				'type'          => 'stale_content',
			);
		}, $results );
	}

	private function find_thin_content( $limit ) {
		global $wpdb;
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, LENGTH(post_content) as content_length
			 FROM {$wpdb->posts}
			 WHERE post_type = 'product'
			   AND post_status = 'publish'
			   AND LENGTH(post_content) < 300
			 ORDER BY content_length ASC
			 LIMIT %d",
			$limit
		) );

		return array_map( function ( $r ) {
			return array(
				'id'             => (int) $r->ID,
				'title'          => $r->post_title,
				'content_length' => (int) $r->content_length,
				'type'           => 'thin_content',
			);
		}, $results );
	}

	private function find_missing_alt_text( $limit ) {
		global $wpdb;
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_parent
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type LIKE 'image/%%'
			   AND p.post_parent > 0
			   AND (pm.meta_value IS NULL OR pm.meta_value = '')
			 ORDER BY p.ID DESC
			 LIMIT %d",
			$limit
		) );

		return array_map( function ( $r ) {
			return array(
				'id'        => (int) $r->ID,
				'filename'  => $r->post_title,
				'parent_id' => (int) $r->post_parent,
				'type'      => 'missing_alt_text',
			);
		}, $results );
	}
}
