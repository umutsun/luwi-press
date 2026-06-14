<?php
/**
 * LuwiPress Theme String Translation (i18n).
 *
 * AI-translates a theme's UI strings (its `.pot` template / partial `.po`)
 * into the site's active languages and compiles `.mo` — a built-in stand-in
 * for Loco Translate's paid auto-translate quota (the free tier stops at
 * ~90% to push the Pro upgrade). Output is written to the update-safe system
 * languages directory (`wp-content/languages/themes/{domain}-{locale}.po`) by
 * default, so a theme update never wipes the translations.
 *
 * Reuses the configured LuwiPress AI engine (same provider/key as content
 * translation) via LuwiPress_AI_Engine::dispatch_json(), batching msgids so
 * placeholders (%s, %1$s), HTML tags and surrounding whitespace are preserved.
 *
 * REST (luwipress/v1):
 *   GET  /translation/theme-coverage  — per-locale total / translated / missing counts
 *   POST /translation/theme-strings   — translate the missing msgids, write .po + .mo
 *
 * MCP (webmcp companion): theme_translation_coverage, theme_translate_strings.
 *
 * @package LuwiPress
 * @since   3.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Theme_I18n {

	/** @var LuwiPress_Theme_I18n|null */
	private static $instance = null;

	/** Hard cap on strings translated in a single request (keeps it under PHP/HTTP timeouts). */
	const MAX_PER_REQUEST = 400;

	/** Strings per AI call. */
	const BATCH_SIZE = 25;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$ns = 'luwipress/v1';

		register_rest_route( $ns, '/translation/theme-coverage', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_coverage' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
			'args'                => array(
				'text_domain' => array( 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $ns, '/translation/theme-strings', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_translate' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );

		register_rest_route( $ns, '/translation/theme-clear-fuzzy', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_clear_fuzzy' ),
			'permission_callback' => array( 'LuwiPress_Permission', 'check_token_or_admin' ),
		) );
	}

	/* ─────────────────────────── REST handlers ─────────────────────────── */

	/**
	 * GET /translation/theme-coverage
	 * Returns the .pot string total plus translated/missing counts per locale.
	 */
	public function rest_coverage( $request ) {
		$text_domain = sanitize_text_field( (string) $request->get_param( 'text_domain' ) );
		$ctx = $this->resolve_context( $text_domain );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$template = $this->load_pot_entries( $ctx['pot_path'] );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		$total = count( $template );

		$locales = array();
		foreach ( $ctx['targets'] as $code => $locale ) {
			$existing = $this->load_existing_translations( $ctx['text_domain'], $locale );
			$done = 0;
			foreach ( $template as $key => $entry ) {
				if ( isset( $existing[ $key ] ) && '' !== $existing[ $key ] ) {
					$done++;
				}
			}
			$locales[] = array(
				'language'   => $code,
				'locale'     => $locale,
				'total'      => $total,
				'translated' => $done,
				'missing'    => max( 0, $total - $done ),
				'coverage'   => $total > 0 ? round( ( $done / $total ) * 100 ) : 100,
			);
		}

		return rest_ensure_response( array(
			'text_domain'     => $ctx['text_domain'],
			'pot'             => $ctx['pot_path'],
			'source_language' => $ctx['source_locale'],
			'total_strings'   => $total,
			'languages'       => $locales,
		) );
	}

	/**
	 * POST /translation/theme-strings
	 * Body:
	 *   text_domain      (string) — default: active theme's text domain
	 *   target_languages (array|"all") — WPML/Polylang codes; default "all"
	 *   save_location    ("system"|"theme") — default "system" (update-safe)
	 *   limit            (int) — max strings per request (default + cap MAX_PER_REQUEST)
	 *   dry_run          (bool) — count what would be translated, write nothing
	 */
	public function rest_translate( $request ) {
		$text_domain = sanitize_text_field( (string) $request->get_param( 'text_domain' ) );
		$ctx = $this->resolve_context( $text_domain );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$save_location = sanitize_text_field( (string) $request->get_param( 'save_location' ) );
		$save_location = ( 'theme' === $save_location ) ? 'theme' : 'system';
		$dry_run       = (bool) $request->get_param( 'dry_run' );
		$limit         = (int) $request->get_param( 'limit' );
		$limit         = $limit > 0 ? min( $limit, self::MAX_PER_REQUEST ) : self::MAX_PER_REQUEST;

		// Resolve requested target languages against the active-language map.
		$req_langs = $request->get_param( 'target_languages' );
		$targets   = $ctx['targets'];
		if ( ! empty( $req_langs ) && 'all' !== $req_langs ) {
			$req_langs = is_array( $req_langs ) ? $req_langs : array_map( 'trim', explode( ',', (string) $req_langs ) );
			$targets   = array_intersect_key( $targets, array_flip( array_map( 'strtolower', $req_langs ) ) );
		}
		if ( empty( $targets ) ) {
			return new WP_Error( 'no_targets', 'No matching target languages.', array( 'status' => 400 ) );
		}

		$template = $this->load_pot_entries( $ctx['pot_path'] );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$source_name = $this->locale_to_name( $ctx['source_locale'] );
		$results     = array();
		$budget      = $limit; // shared across locales so one request stays bounded

		foreach ( $targets as $code => $locale ) {
			if ( $budget <= 0 ) {
				$results[] = array( 'language' => $code, 'locale' => $locale, 'skipped' => 'per-request limit reached' );
				continue;
			}

			$existing = $this->load_existing_translations( $ctx['text_domain'], $locale );
			$missing  = array();
			foreach ( $template as $key => $entry ) {
				if ( ! isset( $existing[ $key ] ) || '' === $existing[ $key ] ) {
					$missing[ $key ] = $entry['msgid'];
				}
			}

			if ( empty( $missing ) ) {
				$results[] = array( 'language' => $code, 'locale' => $locale, 'translated' => 0, 'status' => 'already complete' );
				continue;
			}

			$slice = array_slice( $missing, 0, $budget, true );

			if ( $dry_run ) {
				$results[] = array( 'language' => $code, 'locale' => $locale, 'would_translate' => count( $slice ), 'total_missing' => count( $missing ) );
				$budget   -= count( $slice );
				continue;
			}

			$target_name  = $this->locale_to_name( $locale );
			$translated   = $this->translate_strings( array_values( $slice ), $source_name, $target_name );
			if ( is_wp_error( $translated ) ) {
				$results[] = array( 'language' => $code, 'locale' => $locale, 'error' => $translated->get_error_message() );
				continue;
			}

			// Map translations back onto their keys (same order as $slice).
			$keys = array_keys( $slice );
			$new  = $existing;
			$count = 0;
			foreach ( $keys as $i => $key ) {
				$t = $translated[ $i ] ?? '';
				if ( '' !== $t ) {
					$new[ $key ] = $t;
					$count++;
				}
			}

			$written = $this->write_po_mo( $ctx, $locale, $template, $new, $save_location );
			if ( is_wp_error( $written ) ) {
				$results[] = array( 'language' => $code, 'locale' => $locale, 'error' => $written->get_error_message() );
				continue;
			}

			$budget -= count( $slice );
			$results[] = array(
				'language'      => $code,
				'locale'        => $locale,
				'translated'    => $count,
				'still_missing' => max( 0, count( $missing ) - $count ),
				'po'            => $written['po'],
				'mo'            => $written['mo'],
			);

			LuwiPress_Logger::log(
				sprintf( 'Theme i18n: %s → %s, %d strings translated (%s)', $ctx['text_domain'], $locale, $count, $save_location ),
				'info',
				array( 'text_domain' => $ctx['text_domain'], 'locale' => $locale )
			);
		}

		return rest_ensure_response( array(
			'text_domain'   => $ctx['text_domain'],
			'save_location' => $save_location,
			'dry_run'       => $dry_run,
			'results'       => $results,
		) );
	}

	/**
	 * POST /translation/theme-clear-fuzzy
	 *
	 * Activates translations that were entered (e.g. in Loco Translate) but left
	 * marked "fuzzy". Fuzzy entries are excluded from the compiled .mo by msgfmt /
	 * Loco, so they never display even though the .po already holds the text. For
	 * every matching locale this finds the existing .po (system dir, the theme's
	 * languages/ folder, or Loco's wp-content/languages/loco/themes/ dir), strips
	 * the fuzzy flag from every translated entry, and recompiles the .mo so the
	 * translations go live — no re-translation, no AI cost.
	 *
	 * Body:
	 *   text_domain      (string) — default: active theme's text domain
	 *   target_languages (array|"all") — WPML/Polylang codes; default "all"
	 *   dry_run          (bool) — count fuzzy entries without writing
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_clear_fuzzy( $request ) {
		$text_domain = sanitize_text_field( (string) $request->get_param( 'text_domain' ) );
		if ( '' === $text_domain ) {
			$text_domain = (string) wp_get_theme()->get( 'TextDomain' );
		}
		if ( '' === $text_domain ) {
			return new WP_Error( 'no_text_domain', 'Could not determine the theme text domain.', array( 'status' => 400 ) );
		}

		$dry_run = (bool) $request->get_param( 'dry_run' );

		// Resolve target locales: plugin-derived map by default, narrowed (or
		// built from scratch) by an explicit target_languages list.
		$targets   = $this->target_locales();
		$req_langs = $request->get_param( 'target_languages' );
		if ( ! empty( $req_langs ) && 'all' !== $req_langs ) {
			$req_langs = is_array( $req_langs ) ? $req_langs : array_map( 'trim', explode( ',', (string) $req_langs ) );
			$req_langs = array_map( 'strtolower', $req_langs );
			if ( ! empty( $targets ) ) {
				$targets = array_intersect_key( $targets, array_flip( $req_langs ) );
			} else {
				$targets = array();
				foreach ( $req_langs as $code ) {
					$targets[ $code ] = $this->code_to_locale( $code );
				}
			}
		}

		if ( empty( $targets ) ) {
			return new WP_Error(
				'no_targets',
				'No target languages. Activate WPML/Polylang or pass target_languages (e.g. ["de","fr"]).',
				array( 'status' => 400 )
			);
		}

		$results       = array();
		$total_cleared = 0;
		$total_files   = 0;

		foreach ( $targets as $code => $locale ) {
			$files = $this->fuzzy_po_paths( $text_domain, $locale );
			if ( empty( $files ) ) {
				$results[] = array( 'language' => $code, 'locale' => $locale, 'status' => 'no .po file found' );
				continue;
			}

			foreach ( $files as $po_path ) {
				$info = $this->process_fuzzy_file( $po_path, $dry_run );
				if ( is_wp_error( $info ) ) {
					$results[] = array( 'language' => $code, 'locale' => $locale, 'po' => $po_path, 'error' => $info->get_error_message() );
					continue;
				}

				$total_files++;
				$total_cleared += (int) $info['fuzzy_cleared'];
				$results[]      = array_merge( array( 'language' => $code, 'locale' => $locale ), $info );

				if ( ! $dry_run && $info['fuzzy_cleared'] > 0 ) {
					LuwiPress_Logger::log(
						sprintf( 'Theme i18n: cleared %d fuzzy flags in %s (%s) and recompiled .mo', $info['fuzzy_cleared'], basename( $po_path ), $locale ),
						'info',
						array( 'text_domain' => $text_domain, 'locale' => $locale, 'po' => $po_path )
					);
				}
			}
		}

		return rest_ensure_response( array(
			'text_domain'         => $text_domain,
			'dry_run'             => $dry_run,
			'files_processed'     => $total_files,
			'total_fuzzy_cleared' => $total_cleared,
			'note'                => $dry_run
				? 'Dry run — nothing written. Re-run with dry_run=false to apply.'
				: 'Fuzzy flags cleared and .mo recompiled. Purge page cache / hard-refresh to see the strings live.',
			'results'             => $results,
		) );
	}

	/* ─────────────────────────── Internals ─────────────────────────── */

	/**
	 * Resolve the theme text domain, its .pot path, source locale and target
	 * locale map (code => locale, default language excluded).
	 *
	 * @return array|WP_Error
	 */
	private function resolve_context( $text_domain = '' ) {
		$theme_dir = get_stylesheet_directory();
		if ( '' === $text_domain ) {
			$text_domain = wp_get_theme()->get( 'TextDomain' );
		}
		if ( '' === $text_domain ) {
			return new WP_Error( 'no_text_domain', 'Could not determine the theme text domain.', array( 'status' => 400 ) );
		}

		$pot_path = $this->find_pot( $theme_dir, $text_domain );
		if ( ! $pot_path ) {
			// fall back to the template (parent) theme
			$pot_path = $this->find_pot( get_template_directory(), $text_domain );
		}
		if ( ! $pot_path ) {
			return new WP_Error( 'no_pot', sprintf( 'No .pot template found for "%s" in the theme languages folder.', $text_domain ), array( 'status' => 404 ) );
		}

		$targets = $this->target_locales();
		if ( empty( $targets ) ) {
			return new WP_Error( 'no_languages', 'No translation plugin / active languages detected (WPML or Polylang required).', array( 'status' => 400 ) );
		}

		return array(
			'text_domain'   => $text_domain,
			'theme_dir'     => $theme_dir,
			'pot_path'      => $pot_path,
			'source_locale' => $this->default_locale(),
			'targets'       => $targets,
		);
	}

	private function find_pot( $dir, $text_domain ) {
		$candidate = trailingslashit( $dir ) . 'languages/' . $text_domain . '.pot';
		if ( file_exists( $candidate ) ) {
			return $candidate;
		}
		$glob = glob( trailingslashit( $dir ) . 'languages/*.pot' );
		return ( is_array( $glob ) && ! empty( $glob ) ) ? $glob[0] : '';
	}

	/**
	 * Build the target-locale map from the active translation plugin, excluding
	 * the default language. Returns [ lang_code => wp_locale ].
	 */
	private function target_locales() {
		$map     = array();
		$default = '';

		$wpml = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( is_array( $wpml ) && ! empty( $wpml ) ) {
			$default = apply_filters( 'wpml_default_language', null );
			foreach ( $wpml as $code => $l ) {
				$locale = is_array( $l ) ? ( $l['default_locale'] ?? '' ) : '';
				if ( '' === $locale ) {
					$locale = $this->code_to_locale( $code );
				}
				$map[ strtolower( $code ) ] = $locale;
			}
		} elseif ( function_exists( 'pll_languages_list' ) ) {
			$codes   = pll_languages_list( array( 'fields' => 'slug' ) );
			$locales = pll_languages_list( array( 'fields' => 'locale' ) );
			if ( is_array( $codes ) ) {
				foreach ( $codes as $i => $code ) {
					$map[ strtolower( $code ) ] = $locales[ $i ] ?? $this->code_to_locale( $code );
				}
			}
			$default = function_exists( 'pll_default_language' ) ? pll_default_language() : '';
		}

		if ( $default && isset( $map[ strtolower( $default ) ] ) ) {
			unset( $map[ strtolower( $default ) ] );
		}
		return $map;
	}

	private function default_locale() {
		$default = apply_filters( 'wpml_default_language', null );
		if ( $default ) {
			return $this->code_to_locale( $default );
		}
		return get_locale();
	}

	/** Best-effort lang-code → WP locale when the plugin doesn't supply one. */
	private function code_to_locale( $code ) {
		$code = strtolower( $code );
		$known = array(
			'en' => 'en_US', 'tr' => 'tr_TR', 'de' => 'de_DE', 'fr' => 'fr_FR',
			'es' => 'es_ES', 'it' => 'it_IT', 'nl' => 'nl_NL', 'ru' => 'ru_RU',
			'ar' => 'ar', 'ja' => 'ja', 'ko' => 'ko_KR', 'pt-br' => 'pt_BR',
			'pt-pt' => 'pt_PT', 'zh-hans' => 'zh_CN', 'zh-hant' => 'zh_TW',
			'zh-cn' => 'zh_CN', 'zh-tw' => 'zh_TW', 'sv' => 'sv_SE', 'pl' => 'pl_PL',
			'uk' => 'uk',
		);
		return $known[ $code ] ?? str_replace( '-', '_', $code );
	}

	private function locale_to_name( $locale ) {
		$code = strtolower( str_replace( '_', '-', $locale ) );
		$base = explode( '-', $code )[0];
		$names = array(
			'en' => 'English', 'tr' => 'Turkish', 'de' => 'German', 'fr' => 'French',
			'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'ru' => 'Russian',
			'ar' => 'Arabic', 'ja' => 'Japanese', 'ko' => 'Korean', 'pt' => 'Portuguese',
			'zh' => 'Chinese', 'sv' => 'Swedish', 'pl' => 'Polish', 'uk' => 'Ukrainian',
		);
		if ( 'zh-cn' === $code || 'zh-hans' === $code ) { return 'Simplified Chinese'; }
		if ( 'zh-tw' === $code || 'zh-hant' === $code ) { return 'Traditional Chinese'; }
		return $names[ $base ] ?? $locale;
	}

	/**
	 * Load .pot entries as [ key => ['msgid'=>..., 'context'=>...] ].
	 * Key = context-prefixed msgid (matches how PO/MO key entries).
	 *
	 * @return array|WP_Error
	 */
	private function load_pot_entries( $pot_path ) {
		$po = $this->new_po();
		if ( is_wp_error( $po ) ) {
			return $po;
		}
		if ( ! $po->import_from_file( $pot_path ) ) {
			return new WP_Error( 'pot_parse', 'Failed to parse the .pot template.', array( 'status' => 500 ) );
		}
		$out = array();
		foreach ( $po->entries as $key => $entry ) {
			if ( '' === (string) $entry->singular ) {
				continue; // skip the header entry
			}
			$out[ $key ] = array(
				'msgid'   => $entry->singular,
				'context' => $entry->context,
				'plural'  => $entry->plural,
			);
		}
		return $out;
	}

	/** Load existing translations for a locale as [ key => translated_string ]. */
	private function load_existing_translations( $text_domain, $locale ) {
		$out = array();
		foreach ( $this->po_candidates( $text_domain, $locale ) as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$po = $this->new_po();
			if ( is_wp_error( $po ) || ! $po->import_from_file( $path ) ) {
				continue;
			}
			foreach ( $po->entries as $key => $entry ) {
				$t = isset( $entry->translations[0] ) ? (string) $entry->translations[0] : '';
				if ( '' !== $t ) {
					$out[ $key ] = $t;
				}
			}
		}
		return $out;
	}

	/** Existing .po lookup order: system dir first, then theme dir. */
	private function po_candidates( $text_domain, $locale ) {
		return array(
			trailingslashit( WP_LANG_DIR ) . 'themes/' . $text_domain . '-' . $locale . '.po',
			trailingslashit( get_stylesheet_directory() ) . 'languages/' . $locale . '.po',
			trailingslashit( get_stylesheet_directory() ) . 'languages/' . $text_domain . '-' . $locale . '.po',
		);
	}

	/**
	 * All EXISTING .po files for a locale across every place a theme catalogue
	 * may live — the system dir, Loco Translate's custom dir, and the
	 * stylesheet/template languages/ folders (both naming conventions). Used by
	 * clear-fuzzy, which must rewrite whichever file is actually loaded at
	 * runtime rather than guess one canonical location.
	 *
	 * @param string $text_domain Theme text domain.
	 * @param string $locale      WP locale (e.g. de_DE).
	 * @return string[] Unique existing .po paths.
	 */
	private function fuzzy_po_paths( $text_domain, $locale ) {
		$candidates = array(
			trailingslashit( WP_LANG_DIR ) . 'themes/' . $text_domain . '-' . $locale . '.po',
			trailingslashit( WP_LANG_DIR ) . 'loco/themes/' . $text_domain . '-' . $locale . '.po',
			trailingslashit( get_stylesheet_directory() ) . 'languages/' . $locale . '.po',
			trailingslashit( get_stylesheet_directory() ) . 'languages/' . $text_domain . '-' . $locale . '.po',
			trailingslashit( get_template_directory() ) . 'languages/' . $locale . '.po',
			trailingslashit( get_template_directory() ) . 'languages/' . $text_domain . '-' . $locale . '.po',
		);

		$out = array();
		foreach ( $candidates as $path ) {
			if ( file_exists( $path ) && ! in_array( $path, $out, true ) ) {
				$out[] = $path;
			}
		}
		return $out;
	}

	/**
	 * Strip the fuzzy flag from every translated entry in a .po and recompile
	 * its .mo. WordPress's MO writer (unlike msgfmt/Loco) exports any entry with
	 * a non-empty translation regardless of the fuzzy flag, so recompiling alone
	 * already activates the strings; clearing the flag keeps Loco from
	 * re-excluding them on its next save and marks them as approved.
	 *
	 * @param string $po_path  Path to the .po file.
	 * @param bool   $dry_run  Count only, write nothing.
	 * @return array|WP_Error  ['po','mo','translated','fuzzy_cleared']
	 */
	private function process_fuzzy_file( $po_path, $dry_run ) {
		$po = $this->new_po();
		if ( is_wp_error( $po ) ) {
			return $po;
		}
		if ( ! $po->import_from_file( $po_path ) ) {
			return new WP_Error( 'po_parse', 'Failed to parse ' . basename( $po_path ), array( 'status' => 500 ) );
		}

		$translated = 0;
		$fuzzy      = 0;
		foreach ( $po->entries as $entry ) {
			/** @var \Translation_Entry $entry */
			$has_translation = ! empty( $entry->translations ) && '' !== (string) ( $entry->translations[0] ?? '' );
			if ( ! $has_translation ) {
				continue;
			}
			$translated++;
			$entry_flags = ( isset( $entry->flags ) && is_array( $entry->flags ) ) ? $entry->flags : array();
			if ( in_array( 'fuzzy', $entry_flags, true ) ) {
				$fuzzy++;
				if ( ! $dry_run ) {
					$entry->flags = array_values( array_diff( $entry_flags, array( 'fuzzy' ) ) );
				}
			}
		}

		$mo_path = preg_replace( '/\.po$/i', '.mo', $po_path );

		if ( $dry_run ) {
			return array(
				'po'            => $po_path,
				'mo'            => $mo_path,
				'translated'    => $translated,
				'fuzzy_cleared' => $fuzzy, // count that WOULD be cleared
			);
		}

		if ( ! is_writable( $po_path ) ) {
			return new WP_Error( 'not_writable', 'PO file is not writable: ' . $po_path, array( 'status' => 500 ) );
		}
		if ( ! $po->export_to_file( $po_path ) ) {
			return new WP_Error( 'po_write', 'Failed to write ' . basename( $po_path ), array( 'status' => 500 ) );
		}

		$mo          = new MO();
		$mo->headers = $po->headers;
		$mo->entries = $po->entries;
		if ( ! $mo->export_to_file( $mo_path ) ) {
			return new WP_Error( 'mo_write', 'Failed to compile ' . basename( $mo_path ), array( 'status' => 500 ) );
		}

		return array(
			'po'            => $po_path,
			'mo'            => $mo_path,
			'translated'    => $translated,
			'fuzzy_cleared' => $fuzzy,
		);
	}

	/**
	 * AI-translate a flat list of strings, preserving placeholders/HTML.
	 * Batches of BATCH_SIZE; returns a same-order array of translations.
	 *
	 * @return array|WP_Error
	 */
	private function translate_strings( $strings, $source_name, $target_name ) {
		$out = array();
		foreach ( array_chunk( $strings, self::BATCH_SIZE ) as $chunk ) {
			$numbered = array();
			foreach ( $chunk as $i => $s ) {
				$numbered[] = array( 'i' => $i, 's' => $s );
			}
			$system = 'You are a professional software-UI translator. Translate each source string '
				. 'from ' . $source_name . ' to ' . $target_name . '. '
				. 'CRITICAL RULES: keep all placeholders exactly as-is (%s, %d, %1$s, {name}, etc.); '
				. 'keep all HTML tags and their attributes unchanged; preserve any leading/trailing '
				. 'whitespace; do NOT translate brand names, shortcodes ([...]), or URLs; if a string '
				. 'has no translatable words (pure markup/number), return it unchanged. '
				. 'Return ONLY a JSON object: {"t":[{"i":<index>,"v":"<translation>"}, ...]} for every input.';
			$user = wp_json_encode( array( 'strings' => $numbered ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			$messages = LuwiPress_AI_Engine::build_messages( array( 'system' => $system, 'user' => $user ) );
			$result   = LuwiPress_AI_Engine::dispatch_json( 'theme-i18n', $messages, array(
				'max_tokens' => 4000,
				'timeout'    => 120,
				'json_mode'  => true,
			) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$rows = $result['t'] ?? ( isset( $result[0] ) ? $result : array() );
			$by_i = array();
			foreach ( (array) $rows as $row ) {
				if ( is_array( $row ) && isset( $row['i'] ) ) {
					$by_i[ (int) $row['i'] ] = (string) ( $row['v'] ?? '' );
				}
			}
			foreach ( $chunk as $i => $s ) {
				$out[] = $by_i[ $i ] ?? '';
			}
		}
		return $out;
	}

	/**
	 * Write the merged catalogue to .po + .mo at the chosen location.
	 *
	 * @return array|WP_Error  ['po'=>path, 'mo'=>path]
	 */
	private function write_po_mo( $ctx, $locale, $template, $translations, $save_location ) {
		if ( 'theme' === $save_location ) {
			$dir       = trailingslashit( $ctx['theme_dir'] ) . 'languages';
			$base_name = $locale; // theme convention: {locale}.po
		} else {
			$dir       = trailingslashit( WP_LANG_DIR ) . 'themes';
			$base_name = $ctx['text_domain'] . '-' . $locale; // system convention
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'mkdir', 'Could not create languages directory: ' . $dir, array( 'status' => 500 ) );
		}
		if ( ! is_writable( $dir ) ) {
			return new WP_Error( 'not_writable', 'Languages directory is not writable: ' . $dir, array( 'status' => 500 ) );
		}

		$po = $this->new_po();
		if ( is_wp_error( $po ) ) {
			return $po;
		}
		if ( ! $po->import_from_file( $ctx['pot_path'] ) ) {
			return new WP_Error( 'pot_parse', 'Failed to re-read the .pot for writing.', array( 'status' => 500 ) );
		}

		// Headers: locale + content-type (keep .pot headers, override language ones).
		$po->set_header( 'Language', str_replace( '-', '_', $locale ) );
		$po->set_header( 'Content-Type', 'text/plain; charset=UTF-8' );
		$po->set_header( 'MIME-Version', '1.0' );
		$po->set_header( 'Content-Transfer-Encoding', '8bit' );
		$po->set_header( 'X-Generator', 'LuwiPress Theme i18n' );

		foreach ( $po->entries as $key => $entry ) {
			if ( isset( $translations[ $key ] ) && '' !== $translations[ $key ] ) {
				$entry->translations = array( $translations[ $key ] );
			}
		}

		$po_path = trailingslashit( $dir ) . $base_name . '.po';
		$mo_path = trailingslashit( $dir ) . $base_name . '.mo';

		if ( ! $po->export_to_file( $po_path ) ) {
			return new WP_Error( 'po_write', 'Failed to write .po file.', array( 'status' => 500 ) );
		}

		$mo          = new MO();
		$mo->headers = $po->headers;
		$mo->entries = $po->entries;
		if ( ! $mo->export_to_file( $mo_path ) ) {
			return new WP_Error( 'mo_write', 'Failed to write .mo file.', array( 'status' => 500 ) );
		}

		return array( 'po' => $po_path, 'mo' => $mo_path );
	}

	/** Instantiate a PO object, loading WP's pomo classes on demand. */
	private function new_po() {
		if ( ! class_exists( 'PO' ) ) {
			require_once ABSPATH . WPINC . '/pomo/po.php';
		}
		if ( ! class_exists( 'MO' ) ) {
			require_once ABSPATH . WPINC . '/pomo/mo.php';
		}
		if ( ! class_exists( 'PO' ) ) {
			return new WP_Error( 'no_pomo', 'WordPress PO/MO classes unavailable.', array( 'status' => 500 ) );
		}
		return new PO();
	}
}
