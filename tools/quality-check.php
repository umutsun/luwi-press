<?php
/**
 * LuwiPress Quality Check — codebase hygiene scan
 *
 * Enforces project-specific conventions from CLAUDE.md + MEMORY.md:
 *   - English-only code/comments (no Turkish tokens in PHP)
 *   - No hardcoded hex colors in PHP (use --lp-* CSS vars)
 *   - No "Claude", "GPT", "Stitch", "ChatGPT" exposure in user-visible strings
 *   - Plugin prefix discipline on options / hooks
 *   - No TODO/FIXME/XXX in shipped code (at least flag them)
 *   - No print_r/var_dump outside // luwipress-quality:ignore
 *   - Branding: "Luwi" usage instead of generic "AI"
 *   - i18n: translatable strings have a text domain ('luwipress')
 *
 * Usage:
 *   php tools/quality-check.php               # full report
 *   php tools/quality-check.php --json        # machine-readable
 *   php tools/quality-check.php --baseline    # accept current state
 *   php tools/quality-check.php --diff        # new findings only
 *
 * Exit code = HIGH + CRITICAL count (0 = clean).
 */

$ROOTS = array(
	__DIR__ . '/../luwipress',
	__DIR__ . '/../luwipress-webmcp',
);
$SKIP_DIRS   = array( 'node_modules', 'vendor', 'tests', '.git', 'releases', 'build' );
$BASELINE_FP = __DIR__ . '/.quality-baseline.json';

$RULES = array(
	// BRANDING — never expose AI vendor names in user-facing code
	array(
		'id' => 'branding_claude_leak',
		'pattern' => '/[\'"][^\'"]*\b(?:Claude|ChatGPT|GPT-4|GPT-3)\b[^\'"]*[\'"]/i',
		'skip_if' => '/@package|@author|luwipress-quality:ignore|system_prompt|target_language|api-keys?[-_]|claude-haiku|claude-sonnet|claude-opus|gpt-4o|gpt-4\.1/i',
		'severity' => 'HIGH',
		'message' => 'AI vendor name in user-facing string — use "Luwi" or generic "AI" in UI labels',
	),
	array(
		'id' => 'branding_stitch_leak',
		'pattern' => '/[\'"][^\'"]*\b(?:Stitch)\b[^\'"]*[\'"]/i',
		'skip_if' => '/luwipress-quality:ignore|@/i',
		'severity' => 'MEDIUM',
		'message' => 'Stitch (Google Stitch) reference in string — only acceptable inside stitch-skill context',
	),

	// HARDCODED COLORS — CLAUDE.md rule: use --lp-* CSS tokens
	array(
		'id' => 'hardcoded_hex_color',
		// style="color:#abc..." or inline hex in PHP/HTML
		'pattern' => '/(?:style\s*=\s*[\'"][^\'"]*|[\'"]color[\'"]\s*=>\s*[\'"])#[0-9a-fA-F]{3,8}/',
		'skip_if' => '/--lp-|luwipress-quality:ignore|rank_math|yoast/i',
		'severity' => 'MEDIUM',
		'message' => 'Hardcoded hex color in PHP — use --lp-* CSS token or admin.css class',
	),

	// TODO / FIXME / XXX — flag for visibility
	array(
		'id' => 'todo_marker',
		'pattern' => '/\b(?:TODO|FIXME|XXX|HACK)\b/',
		'skip_if' => '/luwipress-quality:ignore/',
		'severity' => 'LOW',
		'message' => 'TODO/FIXME/XXX/HACK marker — track or resolve before release',
	),

	// DEBUG LEAK — print_r/var_dump outside debug guards
	array(
		'id' => 'debug_leak',
		'pattern' => '/\b(?:print_r|var_dump|var_export|error_log)\s*\(/',
		'skip_if' => '/WP_DEBUG|defined\s*\(\s*[\'"]WP_DEBUG[\'"]|luwipress-quality:ignore|LuwiPress_Logger::|error_log\s*\(\s*[\'"]\w+ error/i',
		'severity' => 'LOW',
		'message' => 'Debug output function — guard with WP_DEBUG or remove',
	),

	// I18N — translation strings should carry text domain
	array(
		'id' => 'i18n_missing_textdomain',
		// __( 'foo' ) or _e( 'foo' ) with no second arg
		'pattern' => '/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_ex|_n|_nx)\s*\(\s*[\'"][^\'"]+[\'"]\s*\)/',
		'skip_if' => '/luwipress-quality:ignore/',
		'severity' => 'MEDIUM',
		'message' => 'Translation function missing text domain — add \', \'luwipress\' as second arg',
	),

	// TURKISH LEAK — CLAUDE.md rule: all code English only
	array(
		'id' => 'turkish_in_string',
		// ğüşıöç or specific common Turkish words in strings/comments
		'pattern' => '/[ğüşıöçĞÜŞİÖÇ]|[\'"][^\'"]*\b(?:için|değil|olur|başla|bitir|lütfen|kayıt|başarı|hata|yeni|kapat)\b/i',
		'skip_if' => '/luwipress-quality:ignore/',
		'severity' => 'MEDIUM',
		'message' => 'Turkish characters or words in code — CLAUDE.md rule: all code/comments/UI in English',
	),

	// OPTION PREFIX — update_option / get_option without luwipress_ prefix
	// Only flag CALLS that pass a string literal
	array(
		'id' => 'option_missing_prefix',
		'pattern' => '/(?:update_option|get_option|add_option|delete_option)\s*\(\s*[\'"]((?!luwipress_|_transient_luwipress_|_luwipress_|woocommerce_|wp_|active_plugins|stylesheet|template|blogname|siteurl|home|admin_email|gmt_offset|timezone_string|date_format|time_format|start_of_week|permalink_structure|blog_public|show_on_front|page_on_front|page_for_posts|default_comment_status)[a-zA-Z_])/',
		'skip_if' => '/luwipress-quality:ignore/',
		'severity' => 'LOW',
		'message' => 'WordPress option without luwipress_ prefix or WP core option — follow CLAUDE.md prefix rule',
	),

	// HOOK PREFIX — do_action / apply_filters with non-prefixed name
	array(
		'id' => 'hook_missing_prefix',
		'pattern' => '/(?:do_action|apply_filters)\s*\(\s*[\'"]((?!luwipress_|wp_|admin_|init|plugins_loaded|rest_|save_post|transition_post_status|woocommerce_|elementor_|rank_math|wpml_|pll_|the_content|the_title|wp_enqueue|wp_ajax|pre_get_posts|register_|parse_request|template_|customize_|switch_blog|activate_|deactivate_|shutdown|wp_footer|wp_head|wp_body_open)[a-zA-Z_])/',
		'skip_if' => '/luwipress-quality:ignore/',
		'severity' => 'LOW',
		'message' => 'do_action / apply_filters without luwipress_ prefix (or WP/vendor core hook) — follow CLAUDE.md prefix rule',
	),

	// DANGEROUS DIE / EXIT ABUSE
	array(
		'id' => 'die_exit_abuse',
		// die() or exit() with string (should use wp_die in WP context)
		'pattern' => '/\b(?:die|exit)\s*\(\s*[\'"]/',
		'skip_if' => '/wp_die|luwipress-quality:ignore/',
		'severity' => 'LOW',
		'message' => 'die()/exit() with message — use wp_die() for WordPress-aware output',
	),
);

// ─── PARSE ARGS ──────────────────────────────────────────────────────
$opts       = getopt( '', array( 'only::', 'json', 'baseline', 'diff', 'help' ) );
$only       = isset( $opts['only'] ) ? strtoupper( (string) $opts['only'] ) : null;
$as_json    = isset( $opts['json'] );
$write_base = isset( $opts['baseline'] );
$diff_mode  = isset( $opts['diff'] );

if ( isset( $opts['help'] ) ) {
	echo "LuwiPress quality check\n\n";
	echo "  --only=LEVEL   Filter by severity (CRITICAL|HIGH|MEDIUM|LOW)\n";
	echo "  --json         Machine-readable output\n";
	echo "  --baseline     Write current findings to .quality-baseline.json\n";
	echo "  --diff         Report only new findings\n";
	exit( 0 );
}

// ─── SCAN ────────────────────────────────────────────────────────────
function walk_q( $dir, &$files, $skip ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$handle = opendir( $dir );
	while ( false !== ( $entry = readdir( $handle ) ) ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$full = $dir . DIRECTORY_SEPARATOR . $entry;
		if ( is_dir( $full ) ) {
			if ( in_array( $entry, $skip, true ) ) {
				continue;
			}
			walk_q( $full, $files, $skip );
		} elseif ( preg_match( '/\.(?:php|css|js)$/', $entry ) ) {
			$files[] = $full;
		}
	}
	closedir( $handle );
}

$files = array();
foreach ( $ROOTS as $root ) {
	walk_q( realpath( $root ) ?: $root, $files, $SKIP_DIRS );
}

$findings = array();
foreach ( $files as $file ) {
	$is_php = preg_match( '/\.php$/', $file );
	$is_css = preg_match( '/\.css$/', $file );
	$is_js  = preg_match( '/\.js$/', $file );
	$lines = file( $file, FILE_IGNORE_NEW_LINES );
	if ( false === $lines ) {
		continue;
	}
	foreach ( $lines as $lineno => $line ) {
		if ( false !== stripos( $line, 'luwipress-quality:ignore' ) ) {
			continue;
		}
		foreach ( $RULES as $rule ) {
			// Rules that apply only to PHP
			$php_only = array( 'option_missing_prefix', 'hook_missing_prefix', 'i18n_missing_textdomain', 'die_exit_abuse' );
			if ( in_array( $rule['id'], $php_only, true ) && ! $is_php ) {
				continue;
			}
			if ( ! preg_match( $rule['pattern'], $line ) ) {
				continue;
			}
			if ( isset( $rule['skip_if'] ) && preg_match( $rule['skip_if'], $line ) ) {
				continue;
			}
			$rel = str_replace( realpath( __DIR__ . '/..' ), '', $file );
			$rel = str_replace( array( '\\', '/' ), '/', $rel );
			$rel = ltrim( $rel, '/' );
			$findings[] = array(
				'rule_id'  => $rule['id'],
				'severity' => $rule['severity'],
				'file'     => $rel,
				'line'     => $lineno + 1,
				'message'  => $rule['message'],
				'snippet'  => trim( mb_substr( $line, 0, 180 ) ),
			);
		}
	}
}

// ─── BASELINE / DIFF ─────────────────────────────────────────────────
function finding_key_q( $f ) {
	return $f['rule_id'] . '|' . $f['file'] . '|' . $f['line'];
}

if ( $write_base ) {
	$keys = array_map( 'finding_key_q', $findings );
	file_put_contents( $BASELINE_FP, json_encode( $keys, JSON_PRETTY_PRINT ) . "\n" );
	echo "Baseline written: " . count( $keys ) . " findings suppressed in " . $BASELINE_FP . "\n";
	exit( 0 );
}

if ( $diff_mode && file_exists( $BASELINE_FP ) ) {
	$baseline = json_decode( (string) file_get_contents( $BASELINE_FP ), true ) ?: array();
	$findings = array_values( array_filter(
		$findings,
		function( $f ) use ( $baseline ) {
			return ! in_array( finding_key_q( $f ), $baseline, true );
		}
	) );
}

if ( $only ) {
	$findings = array_values( array_filter(
		$findings,
		function( $f ) use ( $only ) {
			return $f['severity'] === $only;
		}
	) );
}

$counts = array( 'CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0 );
foreach ( $findings as $f ) {
	$counts[ $f['severity'] ] = ( $counts[ $f['severity'] ] ?? 0 ) + 1;
}

if ( $as_json ) {
	echo json_encode(
		array(
			'files_scanned' => count( $files ),
			'counts'        => $counts,
			'findings'      => $findings,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";
} else {
	$colour = array(
		'CRITICAL' => "\033[31;1m",
		'HIGH'     => "\033[31m",
		'MEDIUM'   => "\033[33m",
		'LOW'      => "\033[36m",
	);
	$reset = "\033[0m";
	$bold  = "\033[1m";
	echo "{$bold}LuwiPress Quality Check{$reset} — scanned " . count( $files ) . " files\n\n";
	if ( 0 === count( $findings ) ) {
		echo "\033[32;1m✓ No findings in scope.\033[0m\n";
	} else {
		foreach ( $findings as $f ) {
			$c = $colour[ $f['severity'] ] ?? '';
			echo "{$c}[{$f['severity']}]{$reset} {$f['rule_id']} — {$f['file']}:{$f['line']}\n";
			echo "  {$f['message']}\n";
			echo "  \033[90m{$f['snippet']}\033[0m\n\n";
		}
	}
	echo "{$bold}═══ SUMMARY ═══{$reset}\n";
	foreach ( $counts as $sev => $n ) {
		$c = $colour[ $sev ] ?? '';
		echo sprintf( "  %s%-10s%s %d\n", $c, $sev, $reset, $n );
	}
}

exit( min( 125, $counts['CRITICAL'] + $counts['HIGH'] ) );
