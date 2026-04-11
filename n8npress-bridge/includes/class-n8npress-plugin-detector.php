<?php
/**
 * n8nPress Plugin Detector
 *
 * Detects installed/active WordPress plugins and reads their configuration.
 * n8n workflows use this data to integrate with existing plugins instead of
 * duplicating their functionality.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class N8nPress_Plugin_Detector {

	private static $instance = null;

	/** @var array Cached detection results */
	private $cache = array();

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Get full environment snapshot for n8n workflows.
	 *
	 * @return array
	 */
	public function get_environment() {
		return array(
			'seo'              => $this->detect_seo(),
			'translation'      => $this->detect_translation(),
			'email'            => $this->detect_email(),
			'crm'              => $this->detect_crm(),
			'customer_support' => $this->detect_customer_support(),
			'page_builder'     => $this->detect_page_builder(),
			'cache'            => $this->detect_cache(),
		);
	}

	// ------------------------------------------------------------------
	// SEO Plugin Detection
	// ------------------------------------------------------------------

	public function detect_seo() {
		if ( isset( $this->cache['seo'] ) ) {
			return $this->cache['seo'];
		}

		$result = array(
			'plugin'  => 'none',
			'version' => null,
			'features' => array(),
		);

		// Rank Math
		if ( class_exists( 'RankMath' ) ) {
			$result['plugin']  = 'rank-math';
			$result['version'] = defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null;
			$result['features'] = array(
				'schema'       => true,
				'sitemap'      => true,
				'redirections' => class_exists( 'RankMath\\Redirections\\Redirections' ),
				'analytics'    => class_exists( 'RankMath\\Analytics\\Analytics' ),
				'instant_indexing' => class_exists( 'RankMath\\Instant_Indexing\\Instant_Indexing' ),
			);
			$result['meta_keys'] = array(
				'title'       => 'rank_math_title',
				'description' => 'rank_math_description',
				'focus_kw'    => 'rank_math_focus_keyword',
				'schema'      => 'rank_math_schema_Article',
			);
		}
		// Yoast SEO
		elseif ( defined( 'WPSEO_VERSION' ) ) {
			$result['plugin']  = 'yoast';
			$result['version'] = WPSEO_VERSION;
			$result['features'] = array(
				'schema'       => true,
				'sitemap'      => true,
				'redirections' => defined( 'WPSEO_PREMIUM_FILE' ),
				'analytics'    => false,
			);
			$result['meta_keys'] = array(
				'title'       => '_yoast_wpseo_title',
				'description' => '_yoast_wpseo_metadesc',
				'focus_kw'    => '_yoast_wpseo_focuskw',
			);
		}
		// All in One SEO
		elseif ( defined( 'AIOSEO_VERSION' ) ) {
			$result['plugin']  = 'aioseo';
			$result['version'] = AIOSEO_VERSION;
			$result['features'] = array(
				'schema'  => true,
				'sitemap' => true,
			);
			$result['meta_keys'] = array(
				'title'       => '_aioseo_title',
				'description' => '_aioseo_description',
				'focus_kw'    => '_aioseo_keyphrases',
			);
		}
		// SEOPress
		elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$result['plugin']  = 'seopress';
			$result['version'] = SEOPRESS_VERSION;
			$result['features'] = array(
				'schema'  => function_exists( 'seopress_get_toggle_option' ),
				'sitemap' => true,
			);
			$result['meta_keys'] = array(
				'title'       => '_seopress_titles_title',
				'description' => '_seopress_titles_desc',
				'focus_kw'    => '_seopress_analysis_target_kw',
			);
		}

		$this->cache['seo'] = $result;
		return $result;
	}

	// ------------------------------------------------------------------
	// Translation Plugin Detection
	// ------------------------------------------------------------------

	public function detect_translation() {
		if ( isset( $this->cache['translation'] ) ) {
			return $this->cache['translation'];
		}

		$result = array(
			'plugin'           => 'none',
			'version'          => null,
			'default_language' => get_locale(),
			'active_languages' => array( get_locale() ),
			'features'         => array(),
		);

		// WPML
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			global $sitepress;
			$result['plugin']  = 'wpml';
			$result['version'] = ICL_SITEPRESS_VERSION;

			if ( $sitepress ) {
				$result['default_language'] = $sitepress->get_default_language();
				$active = $sitepress->get_active_languages();
				$result['active_languages'] = array_keys( $active );
			}

			$result['features'] = array(
				'string_translation' => defined( 'WPML_ST_VERSION' ),
				'media_translation'  => defined( 'WPML_MEDIA_VERSION' ),
				'woocommerce'        => class_exists( 'WCML_WC_Strings' ),
			);

			$result['api'] = array(
				'get_languages'    => 'wpml_active_languages',
				'switch_language'  => 'wpml_switch_language',
				'translate_id'     => 'wpml_object_id',
				'current_language' => 'wpml_current_language',
			);
		}
		// Polylang
		elseif ( defined( 'POLYLANG_VERSION' ) ) {
			$result['plugin']  = 'polylang';
			$result['version'] = POLYLANG_VERSION;

			if ( function_exists( 'pll_default_language' ) ) {
				$result['default_language'] = pll_default_language();
			}
			if ( function_exists( 'pll_languages_list' ) ) {
				$result['active_languages'] = pll_languages_list();
			}

			$result['features'] = array(
				'string_translation' => class_exists( 'Polylang_Pro' ),
				'woocommerce'        => defined( 'PLLWC_VERSION' ),
			);

			$result['api'] = array(
				'get_languages'    => 'pll_languages_list',
				'translate_id'     => 'pll_get_post',
				'current_language' => 'pll_current_language',
				'set_post_language' => 'pll_set_post_language',
				'save_translation' => 'pll_save_post_translations',
			);
		}
		// TranslatePress
		elseif ( class_exists( 'TRP_Translate_Press' ) ) {
			$result['plugin']  = 'translatepress';
			$result['version'] = defined( 'TRP_PLUGIN_VERSION' ) ? TRP_PLUGIN_VERSION : null;
			$trp_settings      = get_option( 'trp_settings', array() );
			if ( ! empty( $trp_settings['default-language'] ) ) {
				$result['default_language'] = $trp_settings['default-language'];
			}
			if ( ! empty( $trp_settings['translation-languages'] ) ) {
				$result['active_languages'] = $trp_settings['translation-languages'];
			}
		}

		$this->cache['translation'] = $result;
		return $result;
	}

	// ------------------------------------------------------------------
	// Email / SMTP Detection
	// ------------------------------------------------------------------

	public function detect_email() {
		if ( isset( $this->cache['email'] ) ) {
			return $this->cache['email'];
		}

		$result = array(
			'plugin'     => 'wp_mail',
			'from_name'  => get_option( 'blogname' ),
			'from_email' => get_option( 'admin_email' ),
			'method'     => 'php_mail',
		);

		// WP Mail SMTP
		if ( function_exists( 'wp_mail_smtp' ) || class_exists( 'WPMailSMTP\\Core' ) ) {
			$result['plugin'] = 'wp-mail-smtp';
			$smtp_opts = get_option( 'wp_mail_smtp', array() );
			if ( ! empty( $smtp_opts['mail']['from_email'] ) ) {
				$result['from_email'] = $smtp_opts['mail']['from_email'];
			}
			if ( ! empty( $smtp_opts['mail']['from_name'] ) ) {
				$result['from_name'] = $smtp_opts['mail']['from_name'];
			}
			if ( ! empty( $smtp_opts['mail']['mailer'] ) ) {
				$result['method'] = $smtp_opts['mail']['mailer']; // smtp, gmail, sendgrid, etc.
			}
		}
		// FluentSMTP
		elseif ( defined( 'FLUENTMAIL_PLUGIN_VERSION' ) ) {
			$result['plugin'] = 'fluent-smtp';
			$settings = get_option( 'fluentmail-settings', array() );
			if ( ! empty( $settings['connections'] ) ) {
				$first = reset( $settings['connections'] );
				if ( ! empty( $first['sender_email'] ) ) {
					$result['from_email'] = $first['sender_email'];
				}
				if ( ! empty( $first['sender_name'] ) ) {
					$result['from_name'] = $first['sender_name'];
				}
				if ( ! empty( $first['provider'] ) ) {
					$result['method'] = $first['provider'];
				}
			}
		}
		// Post SMTP
		elseif ( class_exists( 'PostmanOptions' ) ) {
			$result['plugin'] = 'post-smtp';
			$opts = PostmanOptions::getInstance();
			if ( method_exists( $opts, 'getSenderEmail' ) ) {
				$result['from_email'] = $opts->getSenderEmail();
			}
			if ( method_exists( $opts, 'getSenderName' ) ) {
				$result['from_name'] = $opts->getSenderName();
			}
			$result['method'] = 'smtp';
		}

		// WooCommerce email overrides
		if ( class_exists( 'WooCommerce' ) ) {
			$wc_from_name  = get_option( 'woocommerce_email_from_name' );
			$wc_from_email = get_option( 'woocommerce_email_from_address' );
			if ( $wc_from_name ) {
				$result['wc_from_name'] = $wc_from_name;
			}
			if ( $wc_from_email ) {
				$result['wc_from_email'] = $wc_from_email;
			}
		}

		$this->cache['email'] = $result;
		return $result;
	}

	// ------------------------------------------------------------------
	// CRM / Marketing Detection
	// ------------------------------------------------------------------

	public function detect_crm() {
		if ( isset( $this->cache['crm'] ) ) {
			return $this->cache['crm'];
		}

		$result = array(
			'plugin' => 'none',
			'version' => null,
		);

		if ( defined( 'FLUENTCRM_PLUGIN_VERSION' ) ) {
			$result['plugin']  = 'fluentcrm';
			$result['version'] = FLUENTCRM_PLUGIN_VERSION;
		} elseif ( class_exists( 'MailChimp_WooCommerce' ) ) {
			$result['plugin'] = 'mailchimp-for-woocommerce';
		} elseif ( class_exists( 'Klaviyo' ) ) {
			$result['plugin'] = 'klaviyo';
		}

		$this->cache['crm'] = $result;
		return $result;
	}

	// ------------------------------------------------------------------
	// Customer Support Detection (Chatwoot, LiveChat, Tawk.to, etc.)
	// ------------------------------------------------------------------

	public function detect_customer_support() {
		if ( isset( $this->cache['customer_support'] ) ) {
			return $this->cache['customer_support'];
		}

		$result = array(
			'plugin'  => 'none',
			'version' => null,
		);

		// n8nPress Chatwoot integration (external, not a WP plugin)
		$chatwoot_url = get_option( 'n8npress_chatwoot_url', '' );
		$chatwoot_enabled = get_option( 'n8npress_chatwoot_enabled', 0 );
		if ( ! empty( $chatwoot_url ) && $chatwoot_enabled ) {
			$result['plugin']  = 'chatwoot';
			$result['version'] = 'external';
			$result['url']     = $chatwoot_url;
			$result['features'] = array(
				'live_chat'             => true,
				'whatsapp'              => true,
				'instagram'             => true,
				'contact_management'    => true,
				'conversation_history'  => true,
				'webhook_integration'   => true,
			);
		} elseif ( class_exists( 'LiveChat\\LiveChat' ) || defined( 'STARTER_PLUGIN_VERSION' ) ) {
			$result['plugin'] = 'livechat';
		} elseif ( class_exists( 'TawkTo_Settings' ) ) {
			$result['plugin'] = 'tawk-to';
		}

		$this->cache['customer_support'] = $result;
		return $result;
	}

	// ------------------------------------------------------------------
	// Page Builder Detection
	// ------------------------------------------------------------------

	public function detect_page_builder() {
		if ( isset( $this->cache['page_builder'] ) ) {
			return $this->cache['page_builder'];
		}

		$result = array( 'plugin' => 'none' );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$result['plugin']  = 'elementor';
			$result['version'] = ELEMENTOR_VERSION;
		} elseif ( defined( 'ET_BUILDER_VERSION' ) ) {
			$result['plugin']  = 'divi';
			$result['version'] = ET_BUILDER_VERSION;
		} elseif ( defined( 'JETSTYLEMANAGER_PLUGIN_DIR' ) || defined( 'JETSTYLEMANAGER_VERSION' ) ) {
			$result['plugin'] = 'jetelements';
		}

		$this->cache['page_builder'] = $result;
		return $result;
	}

	// ------------------------------------------------------------------
	// Cache Plugin Detection
	// ------------------------------------------------------------------

	public function detect_cache() {
		if ( isset( $this->cache['cache'] ) ) {
			return $this->cache['cache'];
		}

		$result = array( 'plugin' => 'none' );

		if ( defined( 'LSCWP_V' ) ) {
			$result['plugin']  = 'litespeed';
			$result['version'] = LSCWP_V;
		} elseif ( defined( 'WP_ROCKET_VERSION' ) ) {
			$result['plugin']  = 'wp-rocket';
			$result['version'] = WP_ROCKET_VERSION;
		} elseif ( defined( 'W3TC' ) ) {
			$result['plugin'] = 'w3-total-cache';
		}

		$this->cache['cache'] = $result;
		return $result;
	}

	// ------------------------------------------------------------------
	// Utility: Get specific SEO meta for a post
	// ------------------------------------------------------------------

	/**
	 * Read SEO meta from whichever SEO plugin is active.
	 *
	 * @param int $post_id
	 * @return array [ 'title' => ..., 'description' => ..., 'focus_keyword' => ... ]
	 */
	public function get_seo_meta( $post_id ) {
		$seo  = $this->detect_seo();
		$meta = array(
			'title'         => '',
			'description'   => '',
			'focus_keyword' => '',
		);

		if ( 'none' === $seo['plugin'] || empty( $seo['meta_keys'] ) ) {
			return $meta;
		}

		$keys = $seo['meta_keys'];

		if ( ! empty( $keys['title'] ) ) {
			$meta['title'] = get_post_meta( $post_id, $keys['title'], true );
		}
		if ( ! empty( $keys['description'] ) ) {
			$meta['description'] = get_post_meta( $post_id, $keys['description'], true );
		}
		if ( ! empty( $keys['focus_kw'] ) ) {
			$meta['focus_keyword'] = get_post_meta( $post_id, $keys['focus_kw'], true );
		}

		return $meta;
	}

	/**
	 * Write SEO meta through whichever SEO plugin is active.
	 *
	 * @param int   $post_id
	 * @param array $data [ 'title' => ..., 'description' => ..., 'focus_keyword' => ... ]
	 * @return bool
	 */
	public function set_seo_meta( $post_id, $data ) {
		$seo = $this->detect_seo();

		if ( 'none' === $seo['plugin'] || empty( $seo['meta_keys'] ) ) {
			return false;
		}

		$keys = $seo['meta_keys'];

		if ( isset( $data['title'] ) && ! empty( $keys['title'] ) ) {
			update_post_meta( $post_id, $keys['title'], sanitize_text_field( $data['title'] ) );
		}
		if ( isset( $data['description'] ) && ! empty( $keys['description'] ) ) {
			update_post_meta( $post_id, $keys['description'], sanitize_text_field( $data['description'] ) );
		}
		if ( isset( $data['focus_keyword'] ) && ! empty( $keys['focus_kw'] ) ) {
			update_post_meta( $post_id, $keys['focus_kw'], sanitize_text_field( $data['focus_keyword'] ) );
		}

		return true;
	}

	/**
	 * Purge cache for a post using the detected cache plugin.
	 * Falls back to WordPress core clean_post_cache().
	 *
	 * @param int $post_id
	 */
	public function purge_post_cache( $post_id ) {
		$cache = $this->detect_cache();

		switch ( $cache['plugin'] ) {
			case 'wp-rocket':
				if ( function_exists( 'rocket_clean_post' ) ) {
					rocket_clean_post( $post_id );
				}
				if ( function_exists( 'rocket_clean_home' ) ) {
					rocket_clean_home();
				}
				break;

			case 'litespeed':
				do_action( 'litespeed_purge_post', $post_id );
				break;

			case 'w3-total-cache':
				if ( function_exists( 'w3tc_flush_post' ) ) {
					w3tc_flush_post( $post_id );
				}
				break;
		}

		// Always clear WordPress core object cache
		clean_post_cache( $post_id );
	}

	/**
	 * Save a translation using the detected translation plugin.
	 *
	 * @param int    $original_post_id
	 * @param int    $translated_post_id
	 * @param string $language Language code (e.g. 'en', 'de')
	 * @return bool|WP_Error
	 */
	public function save_translation( $original_post_id, $translated_post_id, $language ) {
		$t = $this->detect_translation();

		switch ( $t['plugin'] ) {
			case 'wpml':
				$post_type = get_post_type( $original_post_id );
				$trid      = apply_filters( 'wpml_element_trid', null, $original_post_id, 'post_' . $post_type );
				do_action( 'wpml_set_element_language_details', array(
					'element_id'    => $translated_post_id,
					'element_type'  => 'post_' . $post_type,
					'trid'          => $trid,
					'language_code' => $language,
				) );
				return true;

			case 'polylang':
				if ( function_exists( 'pll_set_post_language' ) ) {
					pll_set_post_language( $translated_post_id, $language );
					$translations = pll_get_post_translations( $original_post_id );
					$translations[ $language ] = $translated_post_id;
					pll_save_post_translations( $translations );
					return true;
				}
				return new WP_Error( 'polylang_api_missing', 'Polylang API functions not available' );

			case 'translatepress':
				// TranslatePress uses a different approach — translations are stored
				// in its own table, not as separate posts.
				return new WP_Error( 'translatepress_not_supported', 'TranslatePress uses inline translation; use its own UI or API' );

			default:
				return new WP_Error( 'no_translation_plugin', 'No supported translation plugin detected' );
		}
	}
}
