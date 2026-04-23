<?php
/**
 * LuwiPress Security Audit — static pattern scan
 *
 * Scans luwipress/ + luwipress-webmcp/ for common WordPress security
 * anti-patterns. Produces a severity-tagged report. Exit code is the
 * number of CRITICAL + HIGH findings (0 = clean).
 *
 * Usage:
 *   php tools/security-audit.php                 # full report
 *   php tools/security-audit.php --only=CRITICAL # filter severity
 *   php tools/security-audit.php --json          # machine-readable
 *   php tools/security-audit.php --baseline      # write baseline file
 *   php tools/security-audit.php --diff          # report only new findings
 *
 * CI integration:
 *   php tools/security-audit.php || exit $?
 *
 * NOTE: This is a pattern scanner, not a taint analyzer. False positives
 * are expected — triage via the baseline mechanism or add inline
 * // luwipress-audit:ignore <rule_id> comments.
 */

// ─── CONFIG ──────────────────────────────────────────────────────────
$ROOTS = array(
	__DIR__ . '/../luwipress',
	__DIR__ . '/../luwipress-webmcp',
);
$SKIP_DIRS   = array( 'node_modules', 'vendor', 'tests', '.git', 'releases', 'build' );
$BASELINE_FP = __DIR__ . '/.security-baseline.json';

// Severity: CRITICAL = active vuln · HIGH = likely vuln · MEDIUM = risky pattern · LOW = style/defensive hint
$RULES = array(
	// CRITICAL — direct vuln patterns
	array(
		'id' => 'eval_used',
		'pattern' => '/\beval\s*\(/',
		'severity' => 'CRITICAL',
		'message' => 'eval() allows arbitrary code execution — always unacceptable in plugin code',
	),
	array(
		'id' => 'create_function_used',
		'pattern' => '/\bcreate_function\s*\(/',
		'severity' => 'CRITICAL',
		'message' => 'create_function() is deprecated and acts as eval',
	),
	array(
		'id' => 'unserialize_user_input',
		// unserialize( $_GET / $_POST / $_REQUEST / $_COOKIE / file_get_contents )
		'pattern' => '/\bunserialize\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE)|file_get_contents)/',
		'severity' => 'CRITICAL',
		'message' => 'unserialize() on user input allows object injection (POP chain) attacks — use json_decode',
	),
	array(
		'id' => 'sql_raw_concat',
		// $wpdb->query( "... $var ..." ) — interpolated SQL.
		// skip_if: contains $wpdb->prepare() on the same line (already prepared),
		// OR the only interpolation is a $wpdb table property (postmeta, options,
		// prefix, commentmeta, posts, users — WordPress-controlled, not user input).
		'pattern' => '/\$wpdb->(?:query|get_(?:var|row|col|results))\s*\(\s*["\'][^"\']*(?:\{\$|\$[a-zA-Z_])/',
		'skip_if'  => '/\$wpdb->prepare\s*\(|\{\$wpdb->(?:prefix|postmeta|options|commentmeta|posts|users|usermeta|termmeta|term_taxonomy|terms|comments|links)\}/',
		'severity' => 'CRITICAL',
		'message' => 'Raw SQL with interpolated variable — use $wpdb->prepare() with %d/%s placeholders',
	),
	array(
		'id' => 'exec_family',
		'pattern' => '/\b(?:system|shell_exec|passthru|proc_open|popen|exec)\s*\(/',
		'severity' => 'CRITICAL',
		'message' => 'Shell execution functions should never appear in plugin code',
	),

	// HIGH — likely-vuln patterns
	array(
		'id' => 'superglobal_unsanitized',
		// Flag value access like $x = $_GET[...] or echo $_POST[...] — but SKIP when line contains
		// isset/empty/array_key_exists (presence checks, no taint flow) or any sanitize_*/wp_unslash/intval/absint.
		'pattern' => '/\$_(?:GET|POST|REQUEST|COOKIE)\[/',
		'skip_if'  => '/(?:isset|empty|array_key_exists|sanitize_|wp_unslash|intval|absint|esc_url_raw|wp_kses|check_admin_referer|check_ajax_referer)\s*\(/',
		'severity' => 'HIGH',
		'message' => 'Direct $_GET/$_POST/$_REQUEST/$_COOKIE access — wrap in sanitize_text_field() + wp_unslash() (or intval/absint for numbers)',
	),
	array(
		'id' => 'output_unescaped_echo',
		// echo $foo with no esc_* wrapping (heuristic)
		'pattern' => '/\becho\s+\$[a-zA-Z_][a-zA-Z0-9_]*\s*[.;,)]/',
		'severity' => 'MEDIUM',
		'message' => 'echo of variable without escaping — use esc_html / esc_attr / esc_url / wp_kses_post',
	),
	array(
		'id' => 'file_get_contents_user_input',
		// Rough: file_get_contents( $_GET / request etc )
		'pattern' => '/\bfile_get_contents\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)/',
		'severity' => 'HIGH',
		'message' => 'file_get_contents() on user input — path traversal / SSRF risk',
	),
	array(
		'id' => 'include_user_input',
		'pattern' => '/\b(?:include|require)(?:_once)?\s*\(?\s*\$_(?:GET|POST|REQUEST|COOKIE)/',
		'severity' => 'CRITICAL',
		'message' => 'include/require with user input — local file inclusion vulnerability',
	),
	array(
		'id' => 'missing_prepare_wildcard',
		// Interpolated SQL statement — only tripped when interpolation is NOT a WordPress table property.
		'pattern' => '/["\'](?:SELECT|UPDATE|DELETE|INSERT|REPLACE)\b[^"\']*(?:FROM|INTO|UPDATE|WHERE)[^"\']*\{\$/i',
		'skip_if'  => '/\$wpdb->prepare\s*\(|\{\$wpdb->(?:prefix|postmeta|options|commentmeta|posts|users|usermeta|termmeta|term_taxonomy|terms|comments|links)\}[^$]*$/',
		'severity' => 'HIGH',
		'message' => 'SQL statement with {$var} interpolation — must use $wpdb->prepare() with %d/%s placeholders',
	),
	array(
		'id' => 'http_no_sslverify_off',
		// wp_remote_* with 'sslverify' => false
		'pattern' => '/[\'"]sslverify[\'"]\s*=>\s*false/',
		'severity' => 'HIGH',
		'message' => 'sslverify => false disables HTTPS cert validation — man-in-the-middle risk',
	),

	// MEDIUM — risky patterns
	array(
		'id' => 'md5_sha1_for_hashing',
		'pattern' => '/\b(?:md5|sha1)\s*\(/',
		'severity' => 'LOW',
		'message' => 'md5/sha1 are OK for non-security hashing (cache keys, IDs) but never for passwords or tokens — review context',
	),
	array(
		'id' => 'weak_random',
		'pattern' => '/\b(?:rand|mt_rand)\s*\(/',
		'severity' => 'LOW',
		'message' => 'rand()/mt_rand() are not cryptographically secure — for tokens/secrets use wp_generate_password() or random_bytes()',
	),
	array(
		'id' => 'hardcoded_token',
		// Looks like a LuwiPress token left in code by accident: lp_[A-Za-z0-9]{20+}
		'pattern' => '/[\'"]lp_[A-Za-z0-9]{20,}[\'"]/',
		'severity' => 'CRITICAL',
		'message' => 'Hardcoded LuwiPress API token — never commit production tokens to source',
	),
	array(
		'id' => 'hardcoded_openai_key',
		'pattern' => '/[\'"]sk-[A-Za-z0-9-_]{20,}[\'"]/',
		'severity' => 'CRITICAL',
		'message' => 'Hardcoded OpenAI/Anthropic-style secret — rotate immediately and remove from source',
	),
	array(
		'id' => 'print_r_var_dump_output',
		// print_r / var_dump in non-test code — could leak in prod
		'pattern' => '/\b(?:print_r|var_dump)\s*\([^)]+\)\s*;\s*(?!\/\/|\*)/',
		'severity' => 'LOW',
		'message' => 'print_r/var_dump in non-test code — remove or guard with WP_DEBUG',
	),
	array(
		'id' => 'ajax_missing_nonce_marker',
		// wp_ajax_ register with callback handler that has no check_ajax_referer / wp_verify_nonce mention
		// Heuristic only — flags for manual review
		'pattern' => '/add_action\s*\(\s*[\'"]wp_ajax_(?!no_priv)/',
		'severity' => 'LOW',
		'message' => 'wp_ajax_ handler — ensure callback calls check_ajax_referer() for CSRF protection (manual review)',
	),
);

// ─── PARSE ARGS ──────────────────────────────────────────────────────
$opts       = getopt( '', array( 'only::', 'json', 'baseline', 'diff', 'help' ) );
$only       = isset( $opts['only'] ) ? strtoupper( (string) $opts['only'] ) : null;
$as_json    = isset( $opts['json'] );
$write_base = isset( $opts['baseline'] );
$diff_mode  = isset( $opts['diff'] );

if ( isset( $opts['help'] ) ) {
	echo "LuwiPress security audit\n\n";
	echo "  --only=LEVEL   Filter by severity (CRITICAL|HIGH|MEDIUM|LOW)\n";
	echo "  --json         Machine-readable output\n";
	echo "  --baseline     Write current findings to .security-baseline.json (accept as noise)\n";
	echo "  --diff         Compare against baseline, report only new findings\n";
	exit( 0 );
}

// ─── SCAN ────────────────────────────────────────────────────────────
$findings = array();

function walk( $dir, &$files, $skip ) {
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
			walk( $full, $files, $skip );
		} elseif ( preg_match( '/\.php$/', $entry ) ) {
			$files[] = $full;
		}
	}
	closedir( $handle );
}

$files = array();
foreach ( $ROOTS as $root ) {
	walk( realpath( $root ) ?: $root, $files, $SKIP_DIRS );
}

$file_count = count( $files );

foreach ( $files as $file ) {
	$lines = file( $file, FILE_IGNORE_NEW_LINES );
	if ( false === $lines ) {
		continue;
	}
	foreach ( $lines as $lineno => $line ) {
		// Allow inline suppression
		if ( false !== stripos( $line, 'luwipress-audit:ignore' ) ) {
			continue;
		}
		foreach ( $RULES as $rule ) {
			if ( ! preg_match( $rule['pattern'], $line ) ) {
				continue;
			}
			// Skip when the same line contains a trusted helper that neutralises the pattern.
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
				'snippet'  => trim( substr( $line, 0, 180 ) ),
			);
		}
	}
}

// ─── BASELINE / DIFF ─────────────────────────────────────────────────
function finding_key( $f ) {
	return $f['rule_id'] . '|' . $f['file'] . '|' . $f['line'];
}

if ( $write_base ) {
	$keys = array_map( 'finding_key', $findings );
	file_put_contents( $BASELINE_FP, json_encode( $keys, JSON_PRETTY_PRINT ) . "\n" );
	echo "Baseline written: " . count( $keys ) . " findings suppressed in " . $BASELINE_FP . "\n";
	exit( 0 );
}

if ( $diff_mode && file_exists( $BASELINE_FP ) ) {
	$baseline = json_decode( (string) file_get_contents( $BASELINE_FP ), true ) ?: array();
	$findings = array_values( array_filter(
		$findings,
		function( $f ) use ( $baseline ) {
			return ! in_array( finding_key( $f ), $baseline, true );
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

// ─── OUTPUT ──────────────────────────────────────────────────────────
$counts   = array( 'CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0 );
foreach ( $findings as $f ) {
	$counts[ $f['severity'] ] = ( $counts[ $f['severity'] ] ?? 0 ) + 1;
}

if ( $as_json ) {
	echo json_encode(
		array(
			'files_scanned' => $file_count,
			'counts'        => $counts,
			'findings'      => $findings,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";
} else {
	// Tiered colour output (POSIX terminal)
	$colour = array(
		'CRITICAL' => "\033[31;1m",
		'HIGH'     => "\033[31m",
		'MEDIUM'   => "\033[33m",
		'LOW'      => "\033[36m",
	);
	$reset  = "\033[0m";
	$bold   = "\033[1m";
	echo "{$bold}LuwiPress Security Audit{$reset} — scanned {$file_count} PHP files\n\n";
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

// Exit code = CRITICAL + HIGH count (cap at 125 to stay within POSIX exit range)
$exit_code = min( 125, $counts['CRITICAL'] + $counts['HIGH'] );
exit( $exit_code );
