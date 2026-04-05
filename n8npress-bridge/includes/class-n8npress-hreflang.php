<?php
/**
 * n8nPress hreflang Tag Generator
 *
 * Generates hreflang link tags for multilingual content.
 * Respects WPML/Polylang native hreflang output — only activates
 * when the translation plugin doesn't handle it.
 *
 * @package n8nPress
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class N8nPress_Hreflang {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$mode = get_option( 'n8npress_hreflang_mode', 'auto' );
		if ( 'never' === $mode ) {
			return;
		}

		add_action( 'wp_head', array( $this, 'output_hreflang_tags' ), 1 );
	}

	/**
	 * Output hreflang link tags in <head>
	 */
	public function output_hreflang_tags() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$mode = get_option( 'n8npress_hreflang_mode', 'auto' );

		// In auto mode, check if WPML/Polylang already outputs hreflang
		if ( 'auto' === $mode && $this->plugin_handles_hreflang() ) {
			return;
		}

		$post_id  = get_the_ID();
		$detector = N8nPress_Plugin_Detector::get_instance();
		$trans    = $detector->detect_translation();

		if ( 'none' === $trans['plugin'] || count( $trans['active_languages'] ) < 2 ) {
			return;
		}

		$alternates = $this->get_language_alternates( $post_id, $trans );

		if ( empty( $alternates ) || count( $alternates ) < 2 ) {
			return;
		}

		echo "\n<!-- n8nPress hreflang tags -->\n";
		foreach ( $alternates as $lang => $url ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $lang ),
				esc_url( $url )
			);
		}

		// x-default points to the default language version
		$default_lang = $trans['default_language'] ?? '';
		if ( $default_lang && isset( $alternates[ $default_lang ] ) ) {
			printf(
				'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
				esc_url( $alternates[ $default_lang ] )
			);
		}
		echo "<!-- /n8nPress hreflang -->\n";
	}

	/**
	 * Check if the active translation plugin already outputs hreflang tags
	 */
	private function plugin_handles_hreflang() {
		// WPML: check if the SEO module is active (it handles hreflang)
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			// WPML SEO module adds hreflang via wpml-seo class
			if ( class_exists( 'WPML_SEO_HeadLangs' ) || has_action( 'wp_head', 'wpml_add_hreflang_header' ) ) {
				return true;
			}
			// WPML itself may add hreflang
			global $wp_filter;
			if ( isset( $wp_filter['wp_head'] ) ) {
				foreach ( $wp_filter['wp_head']->callbacks as $priority => $hooks ) {
					foreach ( $hooks as $hook ) {
						if ( is_array( $hook['function'] ) && is_object( $hook['function'][0] ) ) {
							$class = get_class( $hook['function'][0] );
							if ( strpos( $class, 'WPML' ) !== false && $hook['function'][1] === 'head_langs' ) {
								return true;
							}
						}
					}
				}
			}
		}

		// Polylang: it adds hreflang by default
		if ( function_exists( 'pll_the_languages' ) && ! defined( 'PLL_HREFLANG_OFF' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get all language URLs for a given post
	 */
	private function get_language_alternates( $post_id, $trans ) {
		$alternates = array();

		if ( 'wpml' === $trans['plugin'] ) {
			$alternates = $this->get_wpml_alternates( $post_id, $trans );
		} elseif ( 'polylang' === $trans['plugin'] ) {
			$alternates = $this->get_polylang_alternates( $post_id, $trans );
		} else {
			// n8nPress native translation tracking
			$alternates = $this->get_native_alternates( $post_id, $trans );
		}

		return $alternates;
	}

	/**
	 * WPML: get all translations of a post
	 */
	private function get_wpml_alternates( $post_id, $trans ) {
		$alternates = array();

		if ( ! function_exists( 'icl_get_languages' ) ) {
			return $alternates;
		}

		$post_type    = get_post_type( $post_id );
		$element_type = 'post_' . $post_type;

		$trid = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );
		if ( ! $trid ) {
			return $alternates;
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
		if ( ! is_array( $translations ) ) {
			return $alternates;
		}

		foreach ( $translations as $lang_code => $translation ) {
			if ( ! empty( $translation->element_id ) ) {
				$translated_post = get_post( $translation->element_id );
				if ( $translated_post && 'publish' === $translated_post->post_status ) {
					$alternates[ $lang_code ] = get_permalink( $translation->element_id );
				}
			}
		}

		return $alternates;
	}

	/**
	 * Polylang: get all translations of a post
	 */
	private function get_polylang_alternates( $post_id, $trans ) {
		$alternates = array();

		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return $alternates;
		}

		$translations = pll_get_post_translations( $post_id );
		foreach ( $translations as $lang => $translated_id ) {
			$translated_post = get_post( $translated_id );
			if ( $translated_post && 'publish' === $translated_post->post_status ) {
				$alternates[ $lang ] = get_permalink( $translated_id );
			}
		}

		return $alternates;
	}

	/**
	 * n8nPress native: check translation meta
	 */
	private function get_native_alternates( $post_id, $trans ) {
		$alternates   = array();
		$default_lang = $trans['default_language'] ?? '';

		// Add current post as the default language
		if ( $default_lang ) {
			$alternates[ $default_lang ] = get_permalink( $post_id );
		}

		// Check for n8nPress-tracked translations
		foreach ( $trans['active_languages'] as $lang ) {
			if ( $lang === $default_lang ) {
				continue;
			}

			$status = get_post_meta( $post_id, '_n8npress_translation_' . $lang . '_status', true );
			if ( 'completed' !== $status ) {
				continue;
			}

			$translation_data = get_post_meta( $post_id, '_n8npress_translation_' . $lang, true );
			if ( ! empty( $translation_data['post_id'] ) ) {
				$translated_post = get_post( $translation_data['post_id'] );
				if ( $translated_post && 'publish' === $translated_post->post_status ) {
					$alternates[ $lang ] = get_permalink( $translation_data['post_id'] );
				}
			}
		}

		return $alternates;
	}
}
