<?php
/**
 * Slug Collision Resolver — generic page→product_cat redirect engine.
 *
 * Lifted out of `luwipress-gold` theme's `inc/template-redirects.php` and
 * promoted to core in 3.1.56 so EVERY LuwiPress site (regardless of which
 * theme is active) gets the same migration-friendly slug-collision rescue
 * behavior. The motivation: long-lived WooCommerce stores commonly end up
 * with a legacy `/<hub>/` Elementor page sitting alongside the matching
 * `/product-category/<hub>/` archive. Without redirection, menus, search
 * results and bookmarks land visitors on the stale static page even though
 * the commerce content lives behind the archive route.
 *
 * Why this matters during a migration / DNS swap:
 *   - Google indexes both URLs as separate documents → duplicate content
 *     dilutes ranking signals.
 *   - hreflang declarations become inconsistent (the page URL has its own
 *     hreflang map separate from the term's, so multilingual sites lose
 *     cross-language parity).
 *   - Customer-facing menus appear navigationally broken: "Percussions"
 *     leads to an editorial blurb instead of the products.
 *
 * Algorithm (six discovery passes):
 *
 *   1. Exact match — page.post_name === term.slug, term has population.
 *   2. WPML / Polylang cross-language — page slug in any language whose
 *      sibling translation matches a populated product_cat term slug
 *      (handles the case where pages were translated but terms stay in
 *      the source language).
 *   3. Plural + prefix fuzzy — `arabic-oud` → `arabic-ouds`,
 *      `persian-kamancheh` → `persian-kamancheh-kemenches`.
 *   4. Levenshtein-1 — `classical-kemence` ↔ `classical-kemenche`.
 *      Safety: single-candidate, ≥6-char slug.
 *   5. Menu-parent inheritance — editorial sub-pages inherit their
 *      parent menu item's category. `/santur/` (under "String
 *      Instruments") → `/product-category/string-instruments/`.
 *   6. Ancestor fallback for empty terms — when a page matches a real
 *      term but the term + all its descendants are empty, walk the
 *      parent chain to find the first populated ancestor. Fixes the
 *      `/duduk/` → empty leaf `duduk-armanian-winds` → ancestor
 *      `winds` (populated via Ney/Mey/Zurna/Kaval) case.
 *
 * "Population check" recurses descendants: a WC parent term often has
 * count=0 because WC only counts products directly attached to a term,
 * not products attached to its children. Without recursion, every
 * top-level category would be skipped as "unused".
 *
 * Operator opt-in via the option `luwipress_slug_resolver_enabled` (or
 * the legacy theme_mod `luwipress_gold_resolve_slug_conflicts` so an
 * existing theme-tier install survives the upgrade without re-toggling).
 *
 * REST surface (admin auth):
 *   - GET  /luwipress/v1/slug-resolver/diag      — runtime diagnostic
 *   - GET  /luwipress/v1/slug-resolver/map       — current map preview
 *   - POST /luwipress/v1/slug-resolver/rebuild   — bust cache + rebuild
 *   - POST /luwipress/v1/slug-resolver/override  — explicit slug→target
 *   - GET  /luwipress/v1/slug-resolver/settings  — toggle state
 *   - POST /luwipress/v1/slug-resolver/settings  — partial-update toggle
 *
 * Filter hooks (operator extensibility):
 *   - `luwipress_slug_resolver_skip_slugs`  — extend system-page skip list
 *   - `luwipress_slug_resolver_map_pre_cache` — modify map before caching
 *   - `luwipress_slug_resolver_redirects`   — final composition (return [] to disable)
 *
 * @package LuwiPress
 * @since   3.1.56
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Slug_Resolver {

	const TRANSIENT_KEY      = 'luwipress_slug_resolver_map_v1';
	const TRANSIENT_BASES    = 'luwipress_slug_resolver_prodcat_bases_v1';
	const OPTION_ENABLED     = 'luwipress_slug_resolver_enabled';
	const OPTION_LAST_BUILD  = 'luwipress_slug_resolver_last_build';
	const OPTION_OVERRIDES   = 'luwipress_slug_resolver_overrides';
	const LEGACY_THEME_MOD   = 'luwipress_gold_resolve_slug_conflicts';

	private static $instance = null;

	/** @var array<int,bool> Memoized term population checks per request. */
	private $population_cache = array();

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Redirect on every front-end GET — priority 1 so we run BEFORE
		// WordPress surfaces the static page or a 404.
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );

		// REST endpoints.
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );

		// Cache invalidation hooks — bust transient when pages or
		// product_cat terms change so newly resolved conflicts reflect
		// without waiting for the 1-hour TTL.
		add_action( 'save_post_page',         array( $this, 'bust_cache' ) );
		add_action( 'deleted_post',           array( $this, 'bust_cache' ) );
		add_action( 'edited_product_cat',     array( $this, 'bust_cache' ) );
		add_action( 'created_product_cat',    array( $this, 'bust_cache' ) );
		add_action( 'delete_product_cat',     array( $this, 'bust_cache' ) );
		add_action( 'switch_theme',           array( $this, 'bust_cache' ) );
		add_action( 'update_option_' . self::OPTION_ENABLED, array( $this, 'bust_cache' ) );
	}

	/**
	 * Is the resolver active for this site?
	 *
	 * Checks the canonical core option first, then falls back to the
	 * legacy theme_mod key from `luwipress-gold` so installs that
	 * previously toggled the theme-tier resolver don't have to re-opt-in
	 * after the core promotion in 3.1.56.
	 */
	public function is_enabled() {
		$opt = get_option( self::OPTION_ENABLED, null );
		if ( $opt !== null ) {
			return (bool) $opt;
		}
		// Legacy theme_mod fallback.
		$mod = get_theme_mod( self::LEGACY_THEME_MOD, null );
		if ( $mod !== null ) {
			return (bool) $mod;
		}
		return false;
	}

	/**
	 * Detect runtime page slugs that should never be auto-redirected
	 * (privacy, WC commerce flow, front + posts pages).
	 *
	 * @return string[] Lowercase slugs.
	 */
	public function skip_slugs() {
		$slugs = array();

		$priv_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		if ( $priv_id > 0 ) {
			$p = get_post( $priv_id );
			if ( $p && $p->post_name ) {
				$slugs[] = (string) $p->post_name;
			}
		}

		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' ) as $key ) {
				$pid = (int) wc_get_page_id( $key );
				if ( $pid > 0 ) {
					$p = get_post( $pid );
					if ( $p && $p->post_name ) {
						$slugs[] = (string) $p->post_name;
					}
				}
			}
		}

		foreach ( array( 'page_on_front', 'page_for_posts' ) as $opt ) {
			$pid = (int) get_option( $opt, 0 );
			if ( $pid > 0 ) {
				$p = get_post( $pid );
				if ( $p && $p->post_name ) {
					$slugs[] = (string) $p->post_name;
				}
			}
		}

		$slugs = array_values( array_unique( array_filter( array_map( 'strtolower', $slugs ) ) ) );

		/**
		 * Filter the runtime skip list. Operators can append site-specific
		 * slugs that should never be auto-redirected.
		 *
		 * @param string[] $slugs
		 */
		$slugs = (array) apply_filters( 'luwipress_slug_resolver_skip_slugs', $slugs );
		return array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
	}

	/**
	 * Test whether a product_cat term is "populated" — self count > 0 OR
	 * any descendant count > 0.
	 *
	 * Memoised per-request to keep deep walks cheap.
	 */
	public function term_has_population( $term_id, $taxonomy = 'product_cat' ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return false;
		}
		$cache_key = $taxonomy . ':' . $term_id;
		if ( isset( $this->population_cache[ $cache_key ] ) ) {
			return $this->population_cache[ $cache_key ];
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return $this->population_cache[ $cache_key ] = false;
		}
		if ( (int) $term->count > 0 ) {
			return $this->population_cache[ $cache_key ] = true;
		}
		$children = get_term_children( $term_id, $taxonomy );
		if ( is_wp_error( $children ) || empty( $children ) ) {
			return $this->population_cache[ $cache_key ] = false;
		}
		foreach ( $children as $child_id ) {
			$child = get_term( (int) $child_id, $taxonomy );
			if ( $child instanceof \WP_Term && (int) $child->count > 0 ) {
				return $this->population_cache[ $cache_key ] = true;
			}
		}
		return $this->population_cache[ $cache_key ] = false;
	}

	/**
	 * Walk the parent chain of a term and return the first ancestor (or
	 * the term itself) with `term_has_population` true.
	 */
	public function term_find_populated_ancestor( $term_id, $taxonomy = 'product_cat' ) {
		$term_id = (int) $term_id;
		$visited = array();
		while ( $term_id > 0 && ! isset( $visited[ $term_id ] ) ) {
			$visited[ $term_id ] = true;
			if ( $this->term_has_population( $term_id, $taxonomy ) ) {
				return $term_id;
			}
			$term = get_term( $term_id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				return 0;
			}
			$term_id = (int) $term->parent;
		}
		return 0;
	}

	/**
	 * Collect the URL path-segment(s) that mark a product_cat archive on
	 * this site. Without this list the nested-leaf-fallback can fire on a
	 * URL that is ALREADY a product_cat archive — the leaf segment matches
	 * the map, the resolved target is the same URL, infinite redirect loop.
	 * Affects every locale because WPML translates the category base
	 * (`product-category` / `categorie-produit` / `categoria-prodotto` /
	 * `categoria-producto`). Cache for an hour, bust with the same hooks
	 * as the discovery map. Operators can extend via
	 * `luwipress_slug_resolver_prodcat_bases` filter.
	 *
	 * @since 3.1.59
	 * @return string[] Lowercase first-segment values.
	 */
	public function get_product_cat_bases() {
		$cached = get_transient( self::TRANSIENT_BASES );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$bases = array();

		// Default WC base from permalink structure.
		if ( function_exists( 'wc_get_permalink_structure' ) ) {
			$struct = wc_get_permalink_structure();
			if ( ! empty( $struct['category_base'] ) ) {
				$bases[] = (string) $struct['category_base'];
			}
		}

		// Probe a sample populated term in every active WPML/Polylang
		// language to discover translated category bases.
		$sample = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'number'     => 1,
			'fields'     => 'all',
		) );
		if ( ! is_wp_error( $sample ) && ! empty( $sample ) ) {
			$sample_id = (int) $sample[0]->term_id;

			$lang_codes = array();
			if ( function_exists( 'icl_get_languages' ) ) {
				$list = icl_get_languages( 'skip_missing=0' );
				if ( is_array( $list ) ) {
					$lang_codes = array_keys( $list );
				}
			} elseif ( function_exists( 'pll_languages_list' ) ) {
				$list = pll_languages_list();
				if ( is_array( $list ) ) {
					$lang_codes = array_map( 'strval', $list );
				}
			}

			foreach ( $lang_codes as $code ) {
				$translated_id = $sample_id;
				if ( function_exists( 'icl_object_id' ) ) {
					$tid = icl_object_id( $sample_id, 'product_cat', true, $code );
					if ( $tid ) {
						$translated_id = (int) $tid;
					}
				} elseif ( function_exists( 'pll_get_term' ) ) {
					$tid = pll_get_term( $sample_id, $code );
					if ( $tid ) {
						$translated_id = (int) $tid;
					}
				}
				$link = get_term_link( $translated_id, 'product_cat' );
				if ( is_wp_error( $link ) || ! $link ) {
					continue;
				}
				$p = (string) wp_parse_url( $link, PHP_URL_PATH );
				$segs = array_values( array_filter( explode( '/', $p ) ) );
				if ( ! empty( $segs ) && in_array( $segs[0], $lang_codes, true ) ) {
					array_shift( $segs );
				}
				if ( ! empty( $segs ) ) {
					$bases[] = (string) $segs[0];
				}
			}
		}

		$bases = array_values( array_unique( array_filter( array_map( 'strtolower', $bases ) ) ) );
		/**
		 * Filter the list of URL first-segments that mark a product_cat
		 * archive. Used to short-circuit the resolver for any URL that is
		 * already on the canonical archive — prevents the nested-leaf
		 * loop on translated category bases.
		 *
		 * @since 3.1.59
		 * @param string[] $bases
		 */
		$bases = (array) apply_filters( 'luwipress_slug_resolver_prodcat_bases', $bases );
		$bases = array_values( array_unique( array_filter( array_map( 'strval', $bases ) ) ) );
		set_transient( self::TRANSIENT_BASES, $bases, HOUR_IN_SECONDS );
		return $bases;
	}

	/**
	 * Discover the full slug-conflict map via six passes. Cached in a
	 * transient for one hour; cache buster hooks fire on relevant edits.
	 *
	 * @return array<string,int|bool|string> slug => term_id | true | URL
	 */
	public function discover_map() {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$build_start = microtime( true );
		$build_errors = array();

		global $wpdb;

		$map = array();
		$empty_term_candidates = array();

		// ---- Pass 1: exact match -----------------------------------
		$exact_rows = $wpdb->get_results(
			"SELECT DISTINCT p.post_name AS page_slug, t.term_id
			   FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->terms} t ON p.post_name = t.slug
			   INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			  WHERE p.post_type = 'page'
			    AND p.post_status = 'publish'
			    AND tt.taxonomy = 'product_cat'",
			ARRAY_A
		);
		if ( is_array( $exact_rows ) ) {
			foreach ( $exact_rows as $r ) {
				$slug = (string) $r['page_slug'];
				if ( $slug === '' ) {
					continue;
				}
				$tid = (int) $r['term_id'];
				if ( $tid <= 0 ) {
					continue;
				}
				if ( $this->term_has_population( $tid ) ) {
					$map[ $slug ] = $tid;
				} else {
					$empty_term_candidates[ $slug ] = $tid;
				}
			}
		}

		// ---- Pass 2: WPML cross-language ---------------------------
		$wpml_table = $wpdb->prefix . 'icl_translations';
		$wpml_active = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpml_table ) ) === $wpml_table );

		if ( $wpml_active ) {
			$cross_rows = $wpdb->get_results(
				"SELECT DISTINCT p.post_name AS page_slug, t.term_id
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpml_table} pl
				     ON pl.element_id = p.ID
				    AND pl.element_type = CONCAT('post_', p.post_type)
				   INNER JOIN {$wpml_table} sib
				     ON sib.trid = pl.trid
				   INNER JOIN {$wpdb->posts} sp
				     ON sp.ID = sib.element_id
				    AND sp.post_status = 'publish'
				   INNER JOIN {$wpdb->terms} t
				     ON sp.post_name = t.slug
				   INNER JOIN {$wpdb->term_taxonomy} tt
				     ON tt.term_id = t.term_id
				    AND tt.taxonomy = 'product_cat'
				  WHERE p.post_type = 'page'
				    AND p.post_status = 'publish'",
				ARRAY_A
			);
			if ( is_array( $cross_rows ) ) {
				foreach ( $cross_rows as $r ) {
					$slug = (string) $r['page_slug'];
					if ( $slug === '' || isset( $map[ $slug ] ) ) {
						continue;
					}
					$tid = (int) $r['term_id'];
					if ( $tid <= 0 ) {
						continue;
					}
					if ( $this->term_has_population( $tid ) ) {
						$map[ $slug ] = $tid;
					} elseif ( ! isset( $empty_term_candidates[ $slug ] ) ) {
						$empty_term_candidates[ $slug ] = $tid;
					}
				}
			}
		}

		// ---- Pass 2b: Polylang fallback ----------------------------
		if ( function_exists( 'pll_get_post_translations' ) ) {
			$skip_slugs = $this->skip_slugs();
			$placeholders = $skip_slugs ? implode( ',', array_fill( 0, count( $skip_slugs ), '%s' ) ) : '';
			$page_rows = $skip_slugs
				? $wpdb->get_results( $wpdb->prepare(
					"SELECT ID, post_name FROM {$wpdb->posts}
					  WHERE post_type = 'page' AND post_status = 'publish'
					    AND post_name <> '' AND post_name NOT IN ($placeholders)",
					$skip_slugs
				), ARRAY_A )
				: $wpdb->get_results(
					"SELECT ID, post_name FROM {$wpdb->posts}
					  WHERE post_type = 'page' AND post_status = 'publish' AND post_name <> ''",
					ARRAY_A
				);
			foreach ( (array) $page_rows as $r ) {
				$pid  = (int) $r['ID'];
				$slug = (string) $r['post_name'];
				if ( $slug === '' || isset( $map[ $slug ] ) ) {
					continue;
				}
				$siblings = (array) pll_get_post_translations( $pid );
				foreach ( $siblings as $sib_id ) {
					$sib_id = (int) $sib_id;
					if ( $sib_id <= 0 ) continue;
					$sib_post = get_post( $sib_id );
					if ( ! $sib_post ) continue;
					$term = get_term_by( 'slug', (string) $sib_post->post_name, 'product_cat' );
					if ( ! ( $term instanceof \WP_Term ) ) continue;
					if ( $this->term_has_population( (int) $term->term_id ) ) {
						$map[ $slug ] = (int) $term->term_id;
						break;
					}
					if ( ! isset( $empty_term_candidates[ $slug ] ) ) {
						$empty_term_candidates[ $slug ] = (int) $term->term_id;
					}
				}
			}
		}

		// ---- Pass 3 + 4: fuzzy / Levenshtein on remaining slugs ----
		$skip_slugs = $this->skip_slugs();
		$placeholders = $skip_slugs ? implode( ',', array_fill( 0, count( $skip_slugs ), '%s' ) ) : '';
		$page_slugs = $skip_slugs
			? $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT p.post_name FROM {$wpdb->posts} p
				  WHERE p.post_type = 'page' AND p.post_status = 'publish'
				    AND p.post_name <> '' AND p.post_name NOT IN ($placeholders)",
				$skip_slugs
			) )
			: $wpdb->get_col(
				"SELECT DISTINCT p.post_name FROM {$wpdb->posts} p
				  WHERE p.post_type = 'page' AND p.post_status = 'publish' AND p.post_name <> ''"
			);
		$page_slugs = is_array( $page_slugs ) ? $page_slugs : array();

		$all_terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'fields'     => 'all',
			'number'     => 0,
		) );
		if ( is_wp_error( $all_terms ) ) {
			$all_terms = array();
		}
		$terms_by_slug = array();
		foreach ( $all_terms as $t ) {
			$terms_by_slug[ (string) $t->slug ] = $t;
		}

		// Pass 3: plural / prefix
		foreach ( $page_slugs as $slug ) {
			$slug = (string) $slug;
			if ( $slug === '' || isset( $map[ $slug ] ) ) {
				continue;
			}
			$candidates = array();
			foreach ( array( $slug . 's', $slug . 'es' ) as $cand_slug ) {
				if ( isset( $terms_by_slug[ $cand_slug ] ) ) {
					$candidates[] = $terms_by_slug[ $cand_slug ];
				}
			}
			$prefix = $slug . '-';
			$prefix_len = strlen( $prefix );
			foreach ( $terms_by_slug as $tslug => $tobj ) {
				if ( $tslug !== $slug && substr( $tslug, 0, $prefix_len ) === $prefix ) {
					$candidates[] = $tobj;
				}
			}
			$by_id = array();
			foreach ( $candidates as $c ) { $by_id[ (int) $c->term_id ] = $c; }
			$candidates = array_values( $by_id );
			if ( count( $candidates ) !== 1 ) {
				continue;
			}
			$tid = (int) $candidates[0]->term_id;
			if ( $tid <= 0 ) continue;
			if ( $this->term_has_population( $tid ) ) {
				$map[ $slug ] = $tid;
			} elseif ( ! isset( $empty_term_candidates[ $slug ] ) ) {
				$empty_term_candidates[ $slug ] = $tid;
			}
		}

		// Pass 4: Levenshtein-1
		foreach ( $page_slugs as $slug ) {
			$slug = (string) $slug;
			if ( $slug === '' || isset( $map[ $slug ] ) ) {
				continue;
			}
			$slug_len = strlen( $slug );
			if ( $slug_len < 6 ) continue;
			$candidates = array();
			foreach ( $terms_by_slug as $tslug => $tobj ) {
				$diff = abs( strlen( $tslug ) - $slug_len );
				if ( $diff > 1 || $tslug === $slug ) continue;
				if ( levenshtein( $slug, $tslug ) === 1 ) {
					$candidates[] = $tobj;
				}
			}
			if ( count( $candidates ) !== 1 ) continue;
			$tid = (int) $candidates[0]->term_id;
			if ( $tid <= 0 ) continue;
			if ( $this->term_has_population( $tid ) ) {
				$map[ $slug ] = $tid;
			} elseif ( ! isset( $empty_term_candidates[ $slug ] ) ) {
				$empty_term_candidates[ $slug ] = $tid;
			}
		}

		// ---- Pass 5: menu-parent inheritance -----------------------
		$menus = wp_get_nav_menus();
		foreach ( (array) $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			if ( empty( $items ) || ! is_array( $items ) ) continue;
			$by_id = array();
			foreach ( $items as $i ) {
				$by_id[ (int) $i->ID ] = $i;
			}
			foreach ( $items as $item ) {
				// wp_get_nav_menu_items() returns objects without a typed
				// schema; use `??` null-coalescing + cast so PHPStan strict
				// mode doesn't complain about "undefined property" access.
				$obj_type   = isset( $item->object ) ? (string) $item->object : '';
				$obj_id     = isset( $item->object_id ) ? (int) $item->object_id : 0;
				if ( $obj_type !== 'page' || $obj_id <= 0 ) continue;
				$parent_menu_id = isset( $item->menu_item_parent ) ? (int) $item->menu_item_parent : 0;
				if ( ! $parent_menu_id || ! isset( $by_id[ $parent_menu_id ] ) ) continue;
				$parent = $by_id[ $parent_menu_id ];
				$parent_term_id = 0;
				if ( $parent->object === 'product_cat' && ! empty( $parent->object_id ) ) {
					$pt = get_term( (int) $parent->object_id );
					if ( $pt instanceof \WP_Term && $this->term_has_population( (int) $pt->term_id ) ) {
						$parent_term_id = (int) $pt->term_id;
					}
				}
				if ( ! $parent_term_id && ! empty( $parent->url ) ) {
					$ppath = (string) wp_parse_url( $parent->url, PHP_URL_PATH );
					$psegs = array_values( array_filter( explode( '/', trim( $ppath, '/' ) ) ) );
					$pslug = ! empty( $psegs ) ? (string) end( $psegs ) : '';
					if ( $pslug !== '' && isset( $terms_by_slug[ $pslug ] ) ) {
						$cand = (int) $terms_by_slug[ $pslug ]->term_id;
						if ( $this->term_has_population( $cand ) ) {
							$parent_term_id = $cand;
						}
					}
				}
				if ( ! $parent_term_id ) continue;
				$page_post = get_post( (int) $item->object_id );
				if ( ! $page_post || $page_post->post_status !== 'publish' ) continue;
				$page_slug = (string) $page_post->post_name;
				if ( $page_slug === '' || isset( $map[ $page_slug ] ) ) continue;
				if ( in_array( $page_slug, $skip_slugs, true ) ) continue;
				$map[ $page_slug ] = $parent_term_id;
			}
		}

		// ---- Pass 6: ancestor fallback for empty-term candidates ---
		foreach ( $empty_term_candidates as $slug => $empty_tid ) {
			if ( isset( $map[ $slug ] ) ) continue;
			$anc = $this->term_find_populated_ancestor( (int) $empty_tid );
			if ( $anc > 0 ) {
				$map[ $slug ] = $anc;
			}
		}

		/**
		 * Filter the freshly-built map before it is cached. Useful for
		 * adding curated entries or stripping noisy ones discovered
		 * during routine site QA.
		 *
		 * @param array $map  slug => term_id|bool|string
		 */
		$map = (array) apply_filters( 'luwipress_slug_resolver_map_pre_cache', $map );

		set_transient( self::TRANSIENT_KEY, $map, HOUR_IN_SECONDS );
		update_option( self::OPTION_LAST_BUILD, array(
			'timestamp'  => current_time( 'mysql' ),
			'duration_s' => round( microtime( true ) - $build_start, 3 ),
			'map_size'   => count( $map ),
			'errors'     => $build_errors,
		), false );
		return $map;
	}

	/**
	 * Compose the final redirect map: auto-discovered + operator overrides.
	 * Overrides win on key collision. Returning `false` for a key suppresses
	 * the auto-discovered redirect for that slug.
	 */
	public function get_redirects() {
		if ( ! $this->is_enabled() ) {
			return array();
		}
		$auto = $this->discover_map();
		$overrides = (array) get_option( self::OPTION_OVERRIDES, array() );
		$composed = array_merge( $auto, $overrides );
		/**
		 * Final composition filter. Returning [] disables the module
		 * entirely for the current request.
		 *
		 * @param array $composed
		 */
		return (array) apply_filters( 'luwipress_slug_resolver_redirects', $composed );
	}

	/**
	 * Emit a debug header at every decision-point in the redirect
	 * pipeline. Cheap (no DB, just header()), always-on so cross-customer
	 * troubleshooting works with a single `curl -I`. Stripped values
	 * stay short to avoid HTTP header length limits. Header name uses
	 * the `X-LWP-SR-` (LuwiPress Slug-Resolver) prefix.
	 */
	private function trace_header( $state, $detail = '' ) {
		if ( headers_sent() ) return;
		$value = (string) $state;
		if ( $detail !== '' ) {
			$value .= ':' . preg_replace( '/[^A-Za-z0-9_\-\/\.\:]/', '_', (string) $detail );
			$value = substr( $value, 0, 200 );
		}
		header( 'X-LWP-SR: ' . $value, false );
	}

	/**
	 * Inspect the current request; if it matches a registered slug,
	 * 301-redirect. Hooked at `template_redirect` priority 1.
	 */
	public function maybe_redirect() {
		if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) { $this->trace_header('skip-admin'); return; }
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { $this->trace_header('skip-rest'); return; }
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) !== 'GET' ) { $this->trace_header('skip-method'); return; }
		if ( is_feed() || is_robots() ) { $this->trace_header('skip-feed-robots'); return; }
		$this->trace_header( 'entry' );

		$redirects = $this->get_redirects();
		if ( empty( $redirects ) ) { $this->trace_header('skip-empty-map'); return; }
		$this->trace_header( 'map-size', count( $redirects ) );

		$req = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( $req === '' ) { $this->trace_header('skip-no-uri'); return; }
		$path = (string) wp_parse_url( $req, PHP_URL_PATH );
		$path = trim( $path, '/' );
		if ( $path === '' ) { $this->trace_header('skip-empty-path'); return; }
		$this->trace_header( 'path', $path );

		// Strip WPML / Polylang language prefix.
		$lang_prefixes = array();
		if ( function_exists( 'icl_get_languages' ) ) {
			$langs = icl_get_languages( 'skip_missing=0' );
			if ( is_array( $langs ) ) {
				foreach ( $langs as $code => $info ) {
					$lang_prefixes[] = (string) $code;
				}
			}
		} elseif ( function_exists( 'pll_languages_list' ) ) {
			$langs = pll_languages_list();
			if ( is_array( $langs ) ) {
				$lang_prefixes = array_map( 'strval', $langs );
			}
		}
		if ( ! empty( $lang_prefixes ) ) {
			$first = strtok( $path, '/' );
			if ( $first !== false && in_array( $first, $lang_prefixes, true ) ) {
				$path = (string) substr( $path, strlen( $first ) + 1 );
				$path = trim( $path, '/' );
				$this->trace_header( 'lang-stripped', $first . ':' . $path );
			}
		}

		// Skip when the URL is already a product_cat archive. Without this
		// guard the nested-leaf-fallback (below) would treat e.g.
		// `product-category/accessories` as a multi-segment path, find
		// `accessories` in the map, target `/product-category/accessories/`
		// — the exact URL we're already on — and bounce the visitor through
		// a 5-hop redirect chain until the browser gives up. WPML translates
		// the base, so we check all known variants per language.
		$prodcat_bases = $this->get_product_cat_bases();
		if ( ! empty( $prodcat_bases ) ) {
			$first_seg = strtok( $path, '/' );
			if ( $first_seg !== false && in_array( strtolower( (string) $first_seg ), $prodcat_bases, true ) ) {
				$this->trace_header( 'skip-prodcat-base', (string) $first_seg );
				return;
			}
		}

		if ( ! array_key_exists( $path, $redirects ) ) {
			// Nested-path leaf-segment fallback. Common case: blog
			// permalink `/blog/<slug>/` where the post has been deleted
			// — the leaf slug may still be registered in the resolver
			// map (via operator override or future page-name match).
			// Rescuing it as a 301 keeps the Google-indexed URL out of
			// the 404 bucket and steers visitors to a still-relevant
			// archive instead of the workshop-door page.
			//
			// Applies to ALL multi-segment paths, not just `/blog/...`
			// — operator-defined categories (`/guide/<slug>/`,
			// `/journal/<slug>/`) inherit the same rescue automatically.
			if ( strpos( $path, '/' ) !== false ) {
				$segs = array_values( array_filter( explode( '/', $path ) ) );
				$leaf = end( $segs );
				if ( $leaf && $leaf !== $path && isset( $redirects[ $leaf ] ) ) {
					$this->trace_header( 'nested-leaf-match', $leaf );
					$path = (string) $leaf;
				} else {
					$this->trace_header( 'no-match-nested', $path );
					return;
				}
			} else {
				$this->trace_header( 'no-match', $path );
				return;
			}
		}
		$rule = $redirects[ $path ];
		if ( $rule === false ) { $this->trace_header('suppressed'); return; }
		if ( is_int( $rule ) ) {
			$rule_str = (string) $rule;
		} elseif ( $rule === true ) {
			$rule_str = 'true';
		} else {
			$rule_str = (string) $rule;
		}
		$this->trace_header( 'match', $path . '=' . $rule_str );

		if ( $rule === true ) {
			$target = home_url( '/product-category/' . $path . '/' );
		} elseif ( is_int( $rule ) || ( is_string( $rule ) && ctype_digit( $rule ) ) ) {
			$term = get_term( (int) $rule, 'product_cat' );
			if ( ! ( $term instanceof \WP_Term ) ) { $this->trace_header('bad-term', $rule_str); return; }
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) || ! $link ) { $this->trace_header('bad-term-link', $rule_str); return; }
			$target = (string) $link;
		} else {
			$rule = (string) $rule;
			$target = ( strpos( $rule, 'http' ) === 0 )
				? $rule
				: home_url( '/' . ltrim( $rule, '/' ) );
		}
		$this->trace_header( 'target', $target );

		// Loop guard. Compare PATHS only — `$req` carries query strings
		// (cache-busters, analytics params) that the resolved `$target`
		// never has, so a naive string compare misses and the visitor
		// enters a redirect chain bounded only by the browser hop limit.
		$current_path = (string) wp_parse_url( home_url( $req ), PHP_URL_PATH );
		$target_path  = (string) wp_parse_url( $target, PHP_URL_PATH );
		if ( untrailingslashit( $current_path ) === untrailingslashit( $target_path ) ) {
			$this->trace_header( 'loop-guard' );
			return;
		}

		$this->trace_header( 'will-redirect' );
		wp_safe_redirect( esc_url_raw( $target ), 301 );
		exit;
	}

	public function bust_cache() {
		delete_transient( self::TRANSIENT_KEY );
		delete_transient( self::TRANSIENT_BASES );
	}

	// -------------------- REST API --------------------

	public function register_endpoints() {
		$ns = 'luwipress/v1';

		register_rest_route( $ns, '/slug-resolver/diag', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_diag' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );

		register_rest_route( $ns, '/slug-resolver/map', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_map' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );

		register_rest_route( $ns, '/slug-resolver/rebuild', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_rebuild' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );

		register_rest_route( $ns, '/slug-resolver/override', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_override' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args' => array(
				'slug'   => array( 'type' => 'string', 'required' => true ),
				'target' => array( 'required' => true ),
			),
		) );

		register_rest_route( $ns, '/slug-resolver/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_settings_get' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_settings_set' ),
				'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			),
		) );
	}

	/**
	 * Diagnostic payload — surfaces runtime state without requiring
	 * server-side log access. Critical for cross-customer migration
	 * troubleshooting: a single GET tells you whether the resolver is
	 * enabled, whether the map built, what the build cost, what would
	 * happen to a sample slug.
	 */
	public function rest_diag( $request ) {
		$probe_slugs = (array) $request->get_param( 'probe' );
		if ( empty( $probe_slugs ) ) {
			$probe_slugs = array( 'percussions', 'duduk', 'ney', 'winds', 'persian-kamancheh' );
		}
		$map = $this->discover_map();
		$redirects = $this->get_redirects();
		$last_build = (array) get_option( self::OPTION_LAST_BUILD, array() );

		$probe = array();
		foreach ( $probe_slugs as $s ) {
			$s = sanitize_title( (string) $s );
			if ( $s === '' ) continue;
			$rule = isset( $redirects[ $s ] ) ? $redirects[ $s ] : null;
			$probe[ $s ] = array(
				'in_map'  => isset( $map[ $s ] ),
				'rule'    => $rule,
				'resolved_target' => $this->resolve_target_preview( $s, $rule ),
			);
		}

		// Detect WPML / Polylang.
		$wpml = false;
		if ( function_exists( 'icl_object_id' ) ) $wpml = true;
		$polylang = function_exists( 'pll_get_post_translations' );

		// Count distinct active hooks at template_redirect priority 1
		// to surface conflicts with other plugins / themes.
		global $wp_filter;
		$hook_obj = isset( $wp_filter['template_redirect'] ) ? $wp_filter['template_redirect'] : null;
		$p1_count = 0;
		if ( $hook_obj && isset( $hook_obj->callbacks[1] ) ) {
			$p1_count = count( $hook_obj->callbacks[1] );
		}

		return rest_ensure_response( array(
			'enabled'              => $this->is_enabled(),
			'core_class_loaded'    => true,
			'core_version'         => defined( 'LUWIPRESS_VERSION' ) ? LUWIPRESS_VERSION : null,
			'hook_attached'        => has_action( 'template_redirect', array( $this, 'maybe_redirect' ) ) !== false,
			'hook_priority'        => 1,
			'p1_callback_count'    => $p1_count,
			'map_size'             => count( $map ),
			'overrides_size'       => count( (array) get_option( self::OPTION_OVERRIDES, array() ) ),
			'wpml_active'          => $wpml,
			'polylang_active'      => $polylang,
			'wc_active'            => class_exists( 'WooCommerce' ),
			'last_build'           => $last_build,
			'transient_present'    => get_transient( self::TRANSIENT_KEY ) !== false,
			'skip_slugs'           => $this->skip_slugs(),
			'map_sample'           => array_slice( $map, 0, 25, true ),
			'probe'                => $probe,
			'legacy_theme_mod'     => get_theme_mod( self::LEGACY_THEME_MOD, null ),
		) );
	}

	/**
	 * Preview what `maybe_redirect` would emit for a given slug+rule.
	 */
	private function resolve_target_preview( $slug, $rule ) {
		if ( $rule === null || $rule === false ) return null;
		if ( $rule === true ) {
			return home_url( '/product-category/' . $slug . '/' );
		}
		if ( is_int( $rule ) || ( is_string( $rule ) && ctype_digit( $rule ) ) ) {
			$term = get_term( (int) $rule, 'product_cat' );
			if ( ! ( $term instanceof \WP_Term ) ) return null;
			$link = get_term_link( $term );
			return is_wp_error( $link ) ? null : (string) $link;
		}
		$rule = (string) $rule;
		return ( strpos( $rule, 'http' ) === 0 )
			? $rule
			: home_url( '/' . ltrim( $rule, '/' ) );
	}

	public function rest_map( $request ) {
		return rest_ensure_response( array(
			'map'         => $this->discover_map(),
			'overrides'   => (array) get_option( self::OPTION_OVERRIDES, array() ),
			'composed'    => $this->get_redirects(),
			'last_build'  => (array) get_option( self::OPTION_LAST_BUILD, array() ),
		) );
	}

	public function rest_rebuild( $request ) {
		$this->bust_cache();
		$map = $this->discover_map();
		return rest_ensure_response( array(
			'status'   => 'ok',
			'map_size' => count( $map ),
			'last_build' => (array) get_option( self::OPTION_LAST_BUILD, array() ),
		) );
	}

	public function rest_override( $request ) {
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		if ( $slug === '' ) {
			return new WP_Error( 'invalid_slug', 'slug must be a non-empty url-safe string', array( 'status' => 400 ) );
		}
		$target = $request->get_param( 'target' );
		// Accept: int term_id, string url, true (auto), false (suppress), null (remove)
		$overrides = (array) get_option( self::OPTION_OVERRIDES, array() );
		if ( $target === null ) {
			unset( $overrides[ $slug ] );
		} elseif ( is_bool( $target ) ) {
			$overrides[ $slug ] = $target;
		} elseif ( is_numeric( $target ) ) {
			$overrides[ $slug ] = (int) $target;
		} else {
			$overrides[ $slug ] = esc_url_raw( (string) $target );
		}
		update_option( self::OPTION_OVERRIDES, $overrides, false );
		$this->bust_cache();
		return rest_ensure_response( array(
			'status'    => 'ok',
			'slug'      => $slug,
			'target'    => $overrides[ $slug ] ?? null,
			'overrides' => $overrides,
		) );
	}

	public function rest_settings_get( $request ) {
		return rest_ensure_response( array(
			'enabled' => $this->is_enabled(),
		) );
	}

	public function rest_settings_set( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) $body = array();
		if ( array_key_exists( 'enabled', $body ) ) {
			update_option( self::OPTION_ENABLED, ! empty( $body['enabled'] ), false );
		}
		$this->bust_cache();
		return rest_ensure_response( array(
			'enabled' => $this->is_enabled(),
		) );
	}
}
