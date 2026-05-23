<?php
/**
 * LuwiPress SEO Writer — fallback meta handler + hreflang output
 *
 * Two responsibilities, both off-by-default when a third-party handles them:
 *
 * 1. META FALLBACK — active only when no third-party SEO plugin (Rank Math,
 *    Yoast, AIOSEO, SEOPress) is detected. Stores SEO meta in LuwiPress-owned
 *    post meta keys and outputs <title> + <meta name="description"> in wp_head.
 *
 * 2. HREFLANG — respects the store's preference. In `auto` mode, checks whether
 *    WPML/Polylang already output hreflang and stays out of the way if so.
 *    In `always`, forces LuwiPress tags. In `never`, disabled entirely.
 *
 * Meta keys (fallback):
 *   _luwipress_seo_title
 *   _luwipress_seo_description
 *   _luwipress_seo_focus_keyword
 *
 * @package LuwiPress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_SEO_Writer {

	const META_TITLE       = '_luwipress_seo_title';
	const META_DESCRIPTION = '_luwipress_seo_description';
	const META_FOCUS_KW    = '_luwipress_seo_focus_keyword';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Is the LuwiPress fallback SEO meta writer active?
	 * True when Plugin Detector reports the "luwipress-native" branch, which
	 * triggers only when no third-party SEO plugin (Rank Math, Yoast, AIOSEO,
	 * SEOPress) is installed.
	 */
	public static function is_active() {
		if ( ! class_exists( 'LuwiPress_Plugin_Detector' ) ) {
			return false;
		}
		$seo = LuwiPress_Plugin_Detector::get_instance()->detect_seo();
		return 'luwipress-native' === ( $seo['plugin'] ?? '' );
	}

	private function __construct() {
		// Meta fallback hooks — only when no SEO plugin is active
		if ( self::is_active() ) {
			add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 20 );
			add_action( 'wp_head', array( $this, 'output_meta_description' ), 2 );
		}

		// Hreflang hook — respects mode option, independent of is_active()
		if ( 'never' !== get_option( 'luwipress_hreflang_mode', 'auto' ) ) {
			add_action( 'wp_head', array( $this, 'output_hreflang_tags' ), 1 );
		}

		// x-default supplement (Vendor-FR-012) — runs LATE so WPML/Polylang have
		// already emitted their hreflang block. If x-default is missing, we add
		// just that one line pointing at the default-language URL. Default-on
		// for all LuwiPress users (option `luwipress_hreflang_xdefault`).
		if ( 'off' !== get_option( 'luwipress_hreflang_xdefault', 'auto' ) ) {
			add_action( 'wp_head', array( $this, 'output_xdefault_supplement' ), 99 );
		}
	}

	// ─── META FALLBACK ─────────────────────────────────────────────────

	/**
	 * Persist SEO meta for a post. Mirrors the plugin-agnostic signature used
	 * by enrichment callbacks: ['title' => ..., 'description' => ..., 'focus_keyword' => ...].
	 *
	 * @param int   $post_id
	 * @param array $data
	 * @return bool
	 */
	public static function set_meta( $post_id, $data ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return false;
		}

		if ( isset( $data['title'] ) ) {
			update_post_meta( $post_id, self::META_TITLE, sanitize_text_field( $data['title'] ) );
		}
		if ( isset( $data['description'] ) ) {
			update_post_meta( $post_id, self::META_DESCRIPTION, sanitize_text_field( $data['description'] ) );
		}
		if ( isset( $data['focus_keyword'] ) ) {
			update_post_meta( $post_id, self::META_FOCUS_KW, sanitize_text_field( $data['focus_keyword'] ) );
		}

		return true;
	}

	/**
	 * Read SEO meta for a post.
	 */
	public static function get_meta( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return array();
		}

		return array(
			'title'         => (string) get_post_meta( $post_id, self::META_TITLE, true ),
			'description'   => (string) get_post_meta( $post_id, self::META_DESCRIPTION, true ),
			'focus_keyword' => (string) get_post_meta( $post_id, self::META_FOCUS_KW, true ),
		);
	}

	/**
	 * Filter the document title when a LuwiPress-stored title exists.
	 */
	public function filter_document_title( $title ) {
		if ( ! is_singular() ) {
			return $title;
		}
		$stored = get_post_meta( get_queried_object_id(), self::META_TITLE, true );
		if ( ! empty( $stored ) ) {
			return $stored;
		}
		return $title;
	}

	/**
	 * Output <meta name="description"> for singular posts when we have one stored.
	 */
	public function output_meta_description() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}
		$desc = get_post_meta( get_queried_object_id(), self::META_DESCRIPTION, true );
		if ( empty( $desc ) ) {
			return;
		}
		printf(
			'<meta name="description" content="%s" />' . "\n",
			esc_attr( $desc )
		);
	}

	// ─── HREFLANG OUTPUT ───────────────────────────────────────────────

	/**
	 * Output hreflang link tags in <head>. Mode controls behaviour:
	 *   - auto   (default): output only if WPML/Polylang don't already handle it
	 *   - always: force LuwiPress output regardless
	 *   - never : disabled (hook not registered)
	 */
	public function output_hreflang_tags() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$mode = get_option( 'luwipress_hreflang_mode', 'auto' );

		if ( 'auto' === $mode && $this->plugin_handles_hreflang() ) {
			return;
		}

		$post_id  = get_the_ID();
		$detector = LuwiPress_Plugin_Detector::get_instance();
		$trans    = $detector->detect_translation();

		if ( 'none' === $trans['plugin'] || count( $trans['active_languages'] ) < 2 ) {
			return;
		}

		$alternates = $this->get_language_alternates( $post_id, $trans );

		if ( empty( $alternates ) || count( $alternates ) < 2 ) {
			return;
		}

		echo "\n<!-- LuwiPress hreflang tags -->\n";
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
		echo "<!-- /LuwiPress hreflang -->\n";
	}

	/**
	 * x-default supplement — Vendor-FR-012.
	 *
	 * Some WPML installs (especially with WPML SEO 2.x) emit hreflang tags
	 * for every active language but skip x-default. Code Snippets filters
	 * on `wpml_hreflangs` don't always fire (depends on which WPML
	 * code path produced the tags). Tapadum reported this on
	 * `new.tapadum.com/product-category/string-instruments/tar/`.
	 *
	 * This callback runs at priority 99 — after WPML/Polylang/our own
	 * `output_hreflang_tags` have already emitted. We never duplicate:
	 * if x-default is already in the buffered output, this is a no-op.
	 * If it's missing, we add a single link pointing at the default
	 * language's URL for the current request (singular OR taxonomy archive).
	 *
	 * Option `luwipress_hreflang_xdefault`:
	 *   - auto (default) — emit when missing, regardless of which plugin
	 *     produced the rest of the hreflang block.
	 *   - always — emit even if already present (will dedupe via output buffer)
	 *   - off    — disabled (hook not registered)
	 */
	public function output_xdefault_supplement() {
		if ( is_admin() || is_feed() || is_404() ) {
			return;
		}

		// We need a singular post OR a taxonomy archive — search results / home / archives don't get hreflang.
		$is_supported_context = is_singular() || is_tax() || is_category() || is_tag();
		if ( ! $is_supported_context ) {
			return;
		}

		$detector = LuwiPress_Plugin_Detector::get_instance();
		$trans    = $detector->detect_translation();

		// No translation plugin → no hreflang at all → no x-default needed.
		if ( 'none' === $trans['plugin'] || count( $trans['active_languages'] ) < 2 ) {
			return;
		}

		$default_lang = $trans['default_language'] ?? '';
		if ( empty( $default_lang ) ) {
			return;
		}

		// Resolve the default-language URL for the current request.
		$default_url = $this->resolve_default_language_url( $default_lang, $trans );
		if ( empty( $default_url ) ) {
			return;
		}

		echo sprintf(
			"<link rel=\"alternate\" hreflang=\"x-default\" href=\"%s\" /> <!-- LuwiPress x-default supplement -->\n",
			esc_url( $default_url )
		);
	}

	/**
	 * Get the default-language URL for whatever the current request is
	 * rendering. Works on both singular posts and taxonomy archives.
	 */
	private function resolve_default_language_url( $default_lang, $trans ) {
		// Singular post — WPML/Polylang both expose a permalink filter.
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			if ( ! $post_id ) {
				return '';
			}

			// WPML: apply_filters('wpml_permalink', $url, $lang)
			if ( 'wpml' === $trans['plugin'] ) {
				$url = apply_filters( 'wpml_permalink', get_permalink( $post_id ), $default_lang );
				return is_string( $url ) ? $url : '';
			}

			// Polylang: pll_get_post returns the post ID in the target language.
			if ( 'polylang' === $trans['plugin'] && function_exists( 'pll_get_post' ) ) {
				$target_id = pll_get_post( $post_id, $default_lang );
				return $target_id ? get_permalink( $target_id ) : '';
			}

			return get_permalink( $post_id );
		}

		// Taxonomy archive — WPML provides a per-language term link via the
		// translation API. We translate the term, then call get_term_link()
		// while WPML's switch_lang context is active (WPML hooks term_link).
		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( ! $term instanceof WP_Term ) {
				return '';
			}

			if ( 'wpml' === $trans['plugin'] ) {
				$translated_id = apply_filters( 'wpml_object_id', $term->term_id, $term->taxonomy, false, $default_lang );
				if ( ! $translated_id ) {
					return '';
				}
				// Briefly switch the WPML language context so get_term_link uses
				// the right URL pattern (subdir/subdomain/different domain).
				$current_lang = apply_filters( 'wpml_current_language', null );
				if ( $current_lang !== $default_lang ) {
					do_action( 'wpml_switch_language', $default_lang );
				}
				$link = get_term_link( $translated_id, $term->taxonomy );
				if ( $current_lang !== $default_lang ) {
					do_action( 'wpml_switch_language', $current_lang );
				}
				return is_wp_error( $link ) ? '' : $link;
			}

			if ( 'polylang' === $trans['plugin'] && function_exists( 'pll_get_term' ) ) {
				$target_term = pll_get_term( $term->term_id, $default_lang );
				if ( ! $target_term ) {
					return '';
				}
				$link = get_term_link( $target_term, $term->taxonomy );
				return is_wp_error( $link ) ? '' : $link;
			}

			return get_term_link( $term );
		}

		return '';
	}

	/**
	 * Check if the active translation plugin already outputs hreflang tags.
	 */
	private function plugin_handles_hreflang() {
		// WPML: check if the SEO module is active (it handles hreflang)
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			if ( class_exists( 'WPML_SEO_HeadLangs' ) || has_action( 'wp_head', 'wpml_add_hreflang_header' ) ) {
				return true;
			}
			// WPML itself may add hreflang via a class-based head_langs method
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
	 * Resolve all language URLs for a post via the active translation plugin.
	 */
	private function get_language_alternates( $post_id, $trans ) {
		if ( 'wpml' === $trans['plugin'] ) {
			return $this->get_wpml_alternates( $post_id, $trans );
		}
		if ( 'polylang' === $trans['plugin'] ) {
			return $this->get_polylang_alternates( $post_id, $trans );
		}
		return $this->get_native_alternates( $post_id, $trans );
	}

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
	 * Native translation tracking fallback — uses LuwiPress-owned meta keys
	 * when neither WPML nor Polylang is present.
	 */
	private function get_native_alternates( $post_id, $trans ) {
		$alternates   = array();
		$default_lang = $trans['default_language'] ?? '';

		if ( $default_lang ) {
			$alternates[ $default_lang ] = get_permalink( $post_id );
		}

		// Prime the meta cache once — subsequent get_post_meta calls are free
		get_post_meta( $post_id );

		// Collect translated post IDs for batch permalink resolution
		$translated_ids = array();
		foreach ( $trans['active_languages'] as $lang ) {
			if ( $lang === $default_lang ) {
				continue;
			}

			$status = get_post_meta( $post_id, '_luwipress_translation_' . $lang . '_status', true );
			if ( 'completed' !== $status ) {
				continue;
			}

			$translation_data = get_post_meta( $post_id, '_luwipress_translation_' . $lang, true );
			if ( ! empty( $translation_data['post_id'] ) ) {
				$translated_ids[ $lang ] = intval( $translation_data['post_id'] );
			}
		}

		// Prime post cache for all translated IDs in one query
		if ( ! empty( $translated_ids ) ) {
			_prime_post_caches( array_values( $translated_ids ), false, false );
		}

		foreach ( $translated_ids as $lang => $tid ) {
			$translated_post = get_post( $tid );
			if ( $translated_post && 'publish' === $translated_post->post_status ) {
				$alternates[ $lang ] = get_permalink( $tid );
			}
		}

		return $alternates;
	}
}
