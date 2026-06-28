<?php
/**
 * LuwiPress Plugin Detector
 *
 * Detects installed/active WordPress plugins and reads their configuration.
 * Downstream modules use this data to integrate with existing plugins instead of
 * duplicating their functionality.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Plugin_Detector {

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
	 * Get full environment snapshot for AI automations.
	 *
	 * @return array
	 */
	public function get_environment() {
		return array(
			'seo'              => $this->detect_seo(),
			'translation'      => $this->detect_translation(),
			'email'            => $this->detect_email(),
			'forms'            => $this->detect_forms(),
			'crm'              => $this->detect_crm(),
			'customer_support' => $this->detect_customer_support(),
			'page_builder'     => $this->detect_page_builder(),
			'cache'            => $this->detect_cache(),
			'analytics'        => $this->detect_analytics(),
			'google_ads'       => $this->detect_google_ads(),
			'meta_ads'         => $this->detect_meta_ads(),
			'product_feed'     => $this->detect_product_feed(),
			'security'         => $this->detect_security(),
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
		// LuwiPress built-in SEO Writer — no third-party SEO plugin installed.
		// Downstream consumers (Knowledge Graph, Translation, Settings UI) key off
		// meta_keys so they work with native keys without per-caller changes.
		else {
			$result['plugin']  = 'luwipress-native';
			$result['version'] = defined( 'LUWIPRESS_VERSION' ) ? LUWIPRESS_VERSION : null;
			$result['features'] = array(
				'schema'  => true,  // LuwiPress outputs Product/FAQ/HowTo schema itself
				'sitemap' => false, // relies on WordPress core or another sitemap plugin
			);
			$result['meta_keys'] = array(
				'title'       => class_exists( 'LuwiPress_SEO_Writer' ) ? LuwiPress_SEO_Writer::META_TITLE : '_luwipress_seo_title',
				'description' => class_exists( 'LuwiPress_SEO_Writer' ) ? LuwiPress_SEO_Writer::META_DESCRIPTION : '_luwipress_seo_description',
				'focus_kw'    => class_exists( 'LuwiPress_SEO_Writer' ) ? LuwiPress_SEO_Writer::META_FOCUS_KW : '_luwipress_seo_focus_keyword',
			);
		}

		$this->cache['seo'] = $result;
		return $result;
	}

	/**
	 * Is Product (schema.org) JSON-LD already emitted for WooCommerce products
	 * by something OTHER than LuwiPress? True when a third-party SEO plugin with
	 * schema support is active (Rank Math / Yoast / AIOSEO / SEOPress) OR when
	 * WooCommerce core's own structured data emitter (WC_Structured_Data) is
	 * present — which it is by default on every WC store.
	 *
	 * Used to (a) suppress the "missing Product Schema" Next-Wins card so it
	 * never fires when schema is already covered, and (b) dedup LuwiPress's own
	 * Product schema renderer so we never emit a duplicate Product node.
	 *
	 * Sites that have explicitly stripped WooCommerce structured data (some
	 * speed/headless setups) can force LuwiPress to take over by returning
	 * false from the `luwipress_wc_emits_product_schema` filter.
	 *
	 * @return bool
	 */
	public function product_schema_covered() {
		$seo = $this->detect_seo();
		$third_party = in_array( $seo['plugin'], array( 'rank-math', 'yoast', 'aioseo', 'seopress' ), true )
			&& ! empty( $seo['features']['schema'] );
		if ( $third_party ) {
			return true;
		}
		if ( class_exists( 'WC_Structured_Data' ) && apply_filters( 'luwipress_wc_emits_product_schema', true ) ) {
			return true;
		}
		return false;
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
		// Easy WP SMTP 2.x (SendLayer/Awesome Motive rewrite). The `EASY_WP_SMTP_VERSION`
		// constant is the documented signature, but the post-SendLayer builds sometimes
		// load the autoloader before the constant is defined, or only expose namespaced
		// classes. We OR multiple signatures so a single missing constant doesn't break
		// detection. Also check function `easy_wp_smtp()` which is the v2 bootstrap helper.
		elseif (
			defined( 'EASY_WP_SMTP_VERSION' )
			|| function_exists( 'easy_wp_smtp' )
			|| class_exists( 'EasyWPSMTP\\Plugin' )
			|| class_exists( 'EasyWPSMTP\\Core' )
			|| class_exists( '\\EasyWPSMTP\\Plugin' )
		) {
			$result['plugin']  = 'easy-wp-smtp';
			$result['version'] = defined( 'EASY_WP_SMTP_VERSION' ) ? constant( 'EASY_WP_SMTP_VERSION' ) : 'unknown';
			$easy_opts = get_option( 'easy_wp_smtp', array() );
			if ( ! empty( $easy_opts['mail']['from_email'] ) ) {
				$result['from_email'] = $easy_opts['mail']['from_email'];
			}
			if ( ! empty( $easy_opts['mail']['from_name'] ) ) {
				$result['from_name'] = $easy_opts['mail']['from_name'];
			}
			if ( ! empty( $easy_opts['mail']['mailer'] ) ) {
				$result['method'] = $easy_opts['mail']['mailer'];
			} else {
				$result['method'] = 'smtp';
			}
		}
		// Easy WP SMTP 1.x legacy (`SWPSMTP_VERSION_NUM` constant) — kept for older installs
		elseif ( defined( 'SWPSMTP_VERSION_NUM' ) ) {
			$result['plugin']  = 'easy-wp-smtp';
			$result['version'] = constant( 'SWPSMTP_VERSION_NUM' );
			$result['method']  = 'smtp';
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
	// Forms Detection (WPForms / Fluent Forms / Gravity / Forminator / CF7)
	// ------------------------------------------------------------------

	/**
	 * Detect the active form plugin — user's choice, like WPML vs Polylang.
	 * Whichever is active surfaces as a green pill on the dashboard ribbon.
	 *
	 * `features` flags what the active plugin/edition can actually do, so the
	 * UI can tell e.g. WPForms Lite (single-step, email-only) from Fluent Forms
	 * (free multi-step wizard + DB entries + native Elementor widget).
	 */
	public function detect_forms() {
		if ( isset( $this->cache['forms'] ) ) {
			return $this->cache['forms'];
		}

		$result = array( 'plugin' => 'none', 'version' => null, 'features' => array() );

		// Fluent Forms — free multi-step wizard + DB entries + native Elementor widget.
		if ( defined( 'FLUENTFORM_VERSION' ) || function_exists( 'wpFluentForm' ) ) {
			$result = array(
				'plugin'   => 'fluent-forms',
				'version'  => defined( 'FLUENTFORM_VERSION' ) ? constant( 'FLUENTFORM_VERSION' ) : 'unknown',
				'features' => array( 'multi_step' => true, 'entries_db' => true, 'elementor_widget' => true ),
			);
		}
		// WPForms — multi-step + DB entries are PRO-only; Lite is single-step, email-only.
		elseif ( function_exists( 'wpforms' ) || defined( 'WPFORMS_VERSION' ) ) {
			$is_pro = ( defined( 'WPFORMS_PRO' ) && constant( 'WPFORMS_PRO' ) ) || class_exists( 'WPForms\\Pro\\Pro' );
			$result = array(
				'plugin'   => $is_pro ? 'wpforms-pro' : 'wpforms-lite',
				'version'  => defined( 'WPFORMS_VERSION' ) ? constant( 'WPFORMS_VERSION' ) : 'unknown',
				'features' => array( 'multi_step' => $is_pro, 'entries_db' => $is_pro, 'elementor_widget' => $is_pro ),
			);
		}
		// Gravity Forms
		elseif ( class_exists( 'GFForms' ) || class_exists( 'GFCommon' ) ) {
			$gf_ver = 'unknown';
			if ( class_exists( 'GFForms' ) && isset( GFForms::$version ) ) {
				$gf_ver = GFForms::$version;
			}
			$result = array(
				'plugin'   => 'gravity-forms',
				'version'  => $gf_ver,
				'features' => array( 'multi_step' => true, 'entries_db' => true, 'elementor_widget' => false ),
			);
		}
		// Forminator
		elseif ( defined( 'FORMINATOR_VERSION' ) || class_exists( 'Forminator' ) ) {
			$result = array(
				'plugin'   => 'forminator',
				'version'  => defined( 'FORMINATOR_VERSION' ) ? constant( 'FORMINATOR_VERSION' ) : 'unknown',
				'features' => array( 'multi_step' => true, 'entries_db' => true, 'elementor_widget' => false ),
			);
		}
		// Contact Form 7 (single-step; DB entries only with Flamingo).
		elseif ( defined( 'WPCF7_VERSION' ) || function_exists( 'wpcf7' ) ) {
			$result = array(
				'plugin'   => 'contact-form-7',
				'version'  => defined( 'WPCF7_VERSION' ) ? constant( 'WPCF7_VERSION' ) : 'unknown',
				'features' => array( 'multi_step' => false, 'entries_db' => defined( 'FLAMINGO_VERSION' ), 'elementor_widget' => false ),
			);
		}

		$this->cache['forms'] = $result;
		return $result;
	}

	// ------------------------------------------------------------------
	// CRM / Marketing Detection
	// ------------------------------------------------------------------

	public function detect_crm() {
		// CRM track is intentionally pure-Woo as of 3.2.4 — LuwiPress is its own
		// content-analysis + segmentation surface. Operators export cohorts to
		// whatever email tool they prefer via the segment CSV. We no longer pay
		// the runtime cost of probing third-party CRM plugins.
		$this->cache['crm'] = array( 'plugin' => 'none', 'version' => null );
		return $this->cache['crm'];
	}

	// ------------------------------------------------------------------
	// Customer Support Detection (LiveChat, Tawk.to, etc.)
	// ------------------------------------------------------------------

	public function detect_customer_support() {
		// Customer support track dropped as of 3.2.4 — LuwiPress ships its own
		// Customer Chat module so there's no need to probe LiveChat / Tawk.to.
		// Keeping the method as a no-op preserves the detect_all() shape.
		$this->cache['customer_support'] = array( 'plugin' => 'none', 'version' => null );
		return $this->cache['customer_support'];
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
	// Security (Wordfence / Sucuri / iThemes / AIOS) Detection
	// ------------------------------------------------------------------

	/**
	 * Detect installed security / firewall / malware-scan plugins. When
	 * Wordfence is detected, the LuwiPress Bot Shield module switches its
	 * UA blocklist + rate limit + honeypot layers to OFF so the two
	 * defences don't double-block the same request. Cookie consent +
	 * REST user enumeration + XML-RPC kill stay on under LuwiPress
	 * because Wordfence Free does not provide those.
	 *
	 * @return array{plugin:string,version:string|null,is_premium:bool,delegate_layers:array<string>}
	 */
	public function detect_security() {
		if ( isset( $this->cache['security'] ) ) {
			return $this->cache['security'];
		}

		$result = array(
			'plugin'          => 'none',
			'version'         => null,
			'is_premium'      => false,
			'delegate_layers' => array(),
		);

		// Wordfence (free + premium share the same constant).
		if ( defined( 'WORDFENCE_VERSION' ) ) {
			$result['plugin']  = 'wordfence';
			$result['version'] = WORDFENCE_VERSION;
			// Premium check: the premium key file exists in wp-content/.
			$result['is_premium'] = defined( 'WORDFENCE_PREMIUM' ) ? (bool) WORDFENCE_PREMIUM : false;
			// What layers we delegate when Wordfence is present.
			$result['delegate_layers'] = array(
				'ua_blocklist',
				'rate_limit',
				'honeypot',
			);
		}

		$this->cache['security'] = $result;
		return $result;
	}

	// ------------------------------------------------------------------
	// Analytics (GTM / GA4) Detection
	// ------------------------------------------------------------------

	public function detect_analytics() {
		if ( isset( $this->cache['analytics'] ) ) {
			return $this->cache['analytics'];
		}

		$result = array(
			'plugin'  => 'none',
			'version' => null,
			'features' => array(),
		);

		// Google Site Kit (official Google plugin — bundles GA4, GTM, Search Console, AdSense)
		if ( defined( 'GOOGLESITEKIT_VERSION' ) ) {
			$result['plugin']  = 'google-site-kit';
			$result['version'] = GOOGLESITEKIT_VERSION;
			$result['features'] = array(
				'analytics'      => true,
				'tag_manager'    => true,
				'search_console' => true,
				'adsense'        => true,
			);
		}
		// GTM4WP (Google Tag Manager for WordPress)
		elseif ( defined( 'GTM4WP_VERSION' ) ) {
			$result['plugin']  = 'gtm4wp';
			$result['version'] = GTM4WP_VERSION;
			$container_id      = get_option( 'gtm4wp-options', array() );
			$result['features'] = array(
				'container_id' => ! empty( $container_id['gtm-code'] ) ? $container_id['gtm-code'] : null,
				'ecommerce'    => ! empty( $container_id['integrate-woocommerce-track-enhanced-ecommerce'] ),
			);
		}
		// MonsterInsights (Google Analytics plugin)
		elseif ( class_exists( 'MonsterInsights' ) ) {
			$result['plugin']  = 'monsterinsights';
			$result['version'] = defined( 'MONSTERINSIGHTS_VERSION' ) ? MONSTERINSIGHTS_VERSION : null;
			$result['features'] = array(
				'ecommerce' => class_exists( 'MonsterInsights_eCommerce' ),
			);
		}

		$this->cache['analytics'] = $result;
		return $result;
	}

	// ------------------------------------------------------------------
	// Google Ads / Conversion Tracking Detection
	// ------------------------------------------------------------------

	public function detect_google_ads() {
		if ( isset( $this->cache['google_ads'] ) ) {
			return $this->cache['google_ads'];
		}

		$result = array(
			'plugin'  => 'none',
			'version' => null,
			'features' => array(),
		);

		// Conversios (Google Ads & Pixel for WooCommerce — formerly Enhanced Ecommerce)
		if ( defined( 'JEELZ_STARTER_PLUGIN_VERSION' ) || class_exists( 'Jeelz\\Bootstrap' ) || defined( 'JEELZ_STARTER_PLUGIN_DIR' ) ) {
			$result['plugin']  = 'conversios';
			$result['version'] = defined( 'JEELZ_STARTER_PLUGIN_VERSION' ) ? JEELZ_STARTER_PLUGIN_VERSION : null;
			$result['features'] = array(
				'google_ads'       => true,
				'conversion_track' => true,
				'remarketing'      => true,
			);
		}
		// Google Listings & Ads (official WooCommerce Google integration)
		elseif ( defined( 'WC_GLA_VERSION' ) ) {
			$result['plugin']  = 'google-listings-and-ads';
			$result['version'] = WC_GLA_VERSION;
			$result['features'] = array(
				'google_ads'        => true,
				'merchant_center'   => true,
				'conversion_track'  => true,
				'free_listings'     => true,
			);
		}
		// WooCommerce Google Ads Conversion Tracking (by Jeelz)
		elseif ( defined( 'JEELZ_GACT_VERSION' ) ) {
			$result['plugin']  = 'wc-google-ads-tracking';
			$result['version'] = JEELZ_GACT_VERSION;
			$result['features'] = array(
				'conversion_track' => true,
			);
		}

		$this->cache['google_ads'] = $result;
		return $result;
	}

	// ------------------------------------------------------------------
	// Meta (Facebook / Instagram) Ads Detection
	// ------------------------------------------------------------------

	public function detect_meta_ads() {
		if ( isset( $this->cache['meta_ads'] ) ) {
			return $this->cache['meta_ads'];
		}

		$result = array(
			'plugin'  => 'none',
			'version' => null,
			'features' => array(),
		);

		// Official Meta Pixel for WordPress (formerly Facebook Pixel)
		if ( defined( 'STARTER_FILE' ) && defined( 'STARTER_PLUGIN_VERSION' ) && class_exists( 'FacebookPixelPlugin\\FacebookForWordpress' ) ) {
			$result['plugin']  = 'meta-pixel';
			$result['version'] = STARTER_PLUGIN_VERSION;
			$result['features'] = array(
				'pixel'           => true,
				'conversion_api'  => true,
				'catalog'         => false,
			);
		}
		// Meta for WooCommerce (Facebook for WooCommerce — full Catalog + Pixel + CAPI)
		elseif ( class_exists( 'WC_Facebookcommerce' ) || defined( 'WC_FACEBOOK_PLUGIN_VERSION' ) ) {
			$result['plugin']  = 'meta-for-woocommerce';
			$result['version'] = defined( 'WC_FACEBOOK_PLUGIN_VERSION' ) ? WC_FACEBOOK_PLUGIN_VERSION : null;
			$result['features'] = array(
				'pixel'           => true,
				'conversion_api'  => true,
				'catalog'         => true,
				'instagram_shop'  => true,
			);
		}
		// PixelYourSite (multi-pixel manager — Facebook, Google, TikTok)
		elseif ( class_exists( 'PixelYourSite\\PYS' ) || defined( 'PYS_FREE_VERSION' ) || defined( 'PYS_PRO_VERSION' ) ) {
			$result['plugin']  = 'pixelyoursite';
			$result['version'] = defined( 'PYS_PRO_VERSION' ) ? PYS_PRO_VERSION : ( defined( 'PYS_FREE_VERSION' ) ? PYS_FREE_VERSION : null );
			$result['features'] = array(
				'pixel'           => true,
				'conversion_api'  => true,
				'google_ads'      => true,
				'tiktok'          => true,
			);
		}

		$this->cache['meta_ads'] = $result;
		return $result;
	}

	// ------------------------------------------------------------------
	// Product Feed (Google Merchant Center / Shopping) Detection
	// ------------------------------------------------------------------

	public function detect_product_feed() {
		if ( isset( $this->cache['product_feed'] ) ) {
			return $this->cache['product_feed'];
		}

		$result = array(
			'plugin'  => 'none',
			'version' => null,
			'features' => array(),
		);

		// Google Listings & Ads (already covers Merchant Center — check again for feed)
		if ( defined( 'WC_GLA_VERSION' ) ) {
			$result['plugin']  = 'google-listings-and-ads';
			$result['version'] = WC_GLA_VERSION;
			$result['features'] = array(
				'merchant_center' => true,
				'auto_sync'       => true,
			);
		}
		// ATUM Product Feed (formerly Product Feed PRO / SUSPENDED)
		elseif ( defined( 'WOOCOMMERCE_SEA_PLUGIN_VERSION' ) ) {
			$result['plugin']  = 'atum-product-feed';
			$result['version'] = WOOCOMMERCE_SEA_PLUGIN_VERSION;
			$result['features'] = array(
				'google_shopping' => true,
				'facebook'        => true,
				'bing'            => true,
			);
		}
		// Product Feed PRO for WooCommerce (by AdTribes)
		elseif ( defined( 'WOOCOMMERCESEA_PLUGIN_VERSION' ) ) {
			$result['plugin']  = 'product-feed-pro';
			$result['version'] = WOOCOMMERCESEA_PLUGIN_VERSION;
			$result['features'] = array(
				'google_shopping'  => true,
				'facebook'         => true,
				'bing'             => true,
				'custom_feed'      => true,
			);
		}
		// CTX Feed (formerly WooCommerce Product Feed)
		elseif ( defined( 'WOO_FEED_FREE_VERSION' ) || defined( 'WOO_FEED_PRO_VERSION' ) ) {
			$result['plugin']  = 'ctx-feed';
			$result['version'] = defined( 'WOO_FEED_PRO_VERSION' ) ? WOO_FEED_PRO_VERSION : ( defined( 'WOO_FEED_FREE_VERSION' ) ? WOO_FEED_FREE_VERSION : null );
			$result['features'] = array(
				'google_shopping'  => true,
				'facebook'         => true,
				'custom_templates' => true,
			);
		}
		// RexTheme Product Feed Manager (Free + PRO) — `WPPFM_VERSION` (Free) / `WPPFM_PRO_VERSION` (PRO)
		elseif ( defined( 'WPPFM_VERSION' ) || defined( 'WPPFM_PRO_VERSION' ) || class_exists( 'Rex_Product_Feed' ) ) {
			$is_pro = defined( 'WPPFM_PRO_VERSION' );
			$result['plugin']  = $is_pro ? 'rextheme-pfm-pro' : 'rextheme-pfm';
			// Defensive constant access: PHPStan can't statically verify
			// third-party plugin constants exist, so each branch guards.
			if ( defined( 'WPPFM_PRO_VERSION' ) ) {
				$result['version'] = constant( 'WPPFM_PRO_VERSION' );
			} elseif ( defined( 'WPPFM_VERSION' ) ) {
				$result['version'] = constant( 'WPPFM_VERSION' );
			} else {
				$result['version'] = null;
			}
			$result['features'] = array(
				'google_shopping'  => true,
				'facebook'         => true,
				'bing'             => $is_pro,
				'custom_templates' => true,
				'multi_channel'    => $is_pro,
			);
		}

		$this->cache['product_feed'] = $result;
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

		if ( empty( $seo['meta_keys'] ) ) {
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

	/**
	 * Detect the active theme. Mirrors `detect_seo()` / `detect_translation()`
	 * shape so theme-aware admin surfaces and the WebMCP `theme_status` tool
	 * can read it uniformly.
	 *
	 * The `is_official_companion` flag uses the `luwipress_official_themes`
	 * filter — by default `luwipress-gold` is the only official theme. Other
	 * themes (or third parties shipping LuwiPress-friendly themes) can hook
	 * into the filter to register additional slugs.
	 *
	 * @return array {
	 *   @type bool   $detected
	 *   @type string $slug                  Stylesheet slug (active theme).
	 *   @type string $name
	 *   @type string $version
	 *   @type string $author
	 *   @type bool   $is_child_theme
	 *   @type string $template              Parent template slug (or same as slug).
	 *   @type bool   $is_official_companion
	 *   @type array  $official_themes       Resolved registry from the filter.
	 * }
	 */
	public function detect_theme() {
		if ( isset( $this->cache['theme'] ) ) {
			return $this->cache['theme'];
		}

		$stylesheet = get_stylesheet();
		$theme      = wp_get_theme( $stylesheet );

		$official = apply_filters( 'luwipress_official_themes', array( 'luwipress-gold' ) );
		if ( ! is_array( $official ) ) {
			$official = array( 'luwipress-gold' );
		}
		$official = array_values( array_unique( array_filter( array_map( 'sanitize_key', $official ) ) ) );

		// wp_get_theme() always returns a WP_Theme instance (a "broken" one
		// when the directory is missing), so no null check needed — `get()`
		// returns the header value or false; we coerce to string for the API.
		$result = array(
			'detected'              => ! empty( $stylesheet ),
			'slug'                  => $stylesheet,
			'name'                  => (string) $theme->get( 'Name' ),
			'version'               => (string) $theme->get( 'Version' ),
			'author'                => (string) $theme->get( 'Author' ),
			'is_child_theme'        => ( $theme->get_template() !== $stylesheet ),
			'template'              => $theme->get_template(),
			'is_official_companion' => in_array( $stylesheet, $official, true ),
			'official_themes'       => $official,
		);

		$this->cache['theme'] = $result;
		return $result;
	}
}
