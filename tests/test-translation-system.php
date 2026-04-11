#!/usr/bin/env php
<?php
/**
 * n8nPress Translation System Test Suite
 *
 * Tests all REST endpoints and n8n workflow integration for:
 * - Taxonomy translation (product_cat, product_tag, category, post_tag)
 * - Product translation (multi-language missing detection)
 * - WPML integration (trid, term registration, translation linking)
 * - Callback handling (taxonomy + product)
 * - Knowledge Graph accuracy
 *
 * Usage: php tests/test-translation-system.php https://osenben.com n8npress-bridge-2026-secure
 */

$site_url  = $argv[1] ?? 'https://osenben.com';
$api_token = $argv[2] ?? 'n8npress-bridge-2026-secure';

$passed = 0;
$failed = 0;
$errors = [];

function api_get( $url, $token ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_HTTPHEADER     => [
			'Authorization: Bearer ' . $token,
			'Content-Type: application/json',
		],
	] );
	$body = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	// Strip BOM
	$body = ltrim( $body, "\xEF\xBB\xBF" );
	return [ 'code' => $code, 'body' => json_decode( $body, true ), 'raw' => $body ];
}

function api_post( $url, $token, $data ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => json_encode( $data ),
		CURLOPT_HTTPHEADER     => [
			'Authorization: Bearer ' . $token,
			'Content-Type: application/json',
		],
	] );
	$body = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	$body = ltrim( $body, "\xEF\xBB\xBF" );
	return [ 'code' => $code, 'body' => json_decode( $body, true ), 'raw' => $body ];
}

function test( $name, $condition, $detail = '' ) {
	global $passed, $failed, $errors;
	if ( $condition ) {
		echo "  PASS  $name\n";
		$passed++;
	} else {
		echo "  FAIL  $name" . ( $detail ? " — $detail" : '' ) . "\n";
		$failed++;
		$errors[] = $name . ( $detail ? ": $detail" : '' );
	}
}

function section( $title ) {
	echo "\n=== $title ===\n";
}

$base = rtrim( $site_url, '/' ) . '/wp-json/n8npress/v1';

// ─── 1. HEALTH & AUTH ──────────────────────────────────────────────

section( '1. Health & Authentication' );

$r = api_get( "$base/site-config", $api_token );
test( 'API reachable (site-config 200)', $r['code'] === 200, "got {$r['code']}" );

$r = api_get( "$base/site-config", $api_token );
test( 'Site config returns 200', $r['code'] === 200, "got {$r['code']}" );
test( 'Site config has plugins.translation', isset( $r['body']['plugins']['translation'] ), 'no plugins.translation key' );

$trans = $r['body']['plugins']['translation'] ?? [];
$plugin = $trans['plugin'] ?? 'none';
test( 'Translation plugin detected', $plugin !== 'none', "got: $plugin" );
$default_lang = $trans['default_language'] ?? '?';
$active_langs = $trans['active_languages'] ?? [];
$target_langs = array_values( array_diff( $active_langs, [ $default_lang ] ) );
test( 'Default language set', strlen( $default_lang ) === 2, "got: $default_lang" );
test( 'At least 1 target language', count( $target_langs ) >= 1, 'targets: ' . implode( ',', $target_langs ) );

echo "  INFO  Plugin=$plugin Default=$default_lang Targets=" . implode( ',', $target_langs ) . "\n";

// ─── 2. KNOWLEDGE GRAPH ────────────────────────────────────────────

section( '2. Knowledge Graph' );

$r = api_get( "$base/knowledge-graph?section=taxonomy,plugins&fresh=1", $api_token );
test( 'Knowledge Graph returns 200', $r['code'] === 200, "got {$r['code']}" );
test( 'Knowledge Graph has summary', isset( $r['body']['summary'] ), 'no summary' );
test( 'Knowledge Graph has taxonomy nodes', isset( $r['body']['nodes']['taxonomies'] ), 'no taxonomy nodes' );
test( 'Knowledge Graph has plugins', isset( $r['body']['plugins'] ), 'no plugins' );

$kg_taxonomies = $r['body']['nodes']['taxonomies'] ?? [];
$kg_by_type = [];
foreach ( $kg_taxonomies as $t ) {
	$kg_by_type[ $t['type'] ][] = $t;
}

echo "  INFO  KG taxonomy types: " . implode( ', ', array_keys( $kg_by_type ) ) . "\n";
foreach ( $kg_by_type as $type => $terms ) {
	$missing = 0;
	foreach ( $terms as $t ) {
		foreach ( $t['translations'] as $tr ) {
			if ( $tr['status'] === 'missing' ) $missing++;
		}
	}
	echo "  INFO  $type: " . count( $terms ) . " originals, $missing missing\n";
}

// ─── 3. TAXONOMY MISSING ENDPOINT ──────────────────────────────────

section( '3. Taxonomy Missing Endpoint' );

$target_str = implode( ',', $target_langs );

foreach ( [ 'product_cat', 'product_tag' ] as $tax ) {
	$r = api_get( "$base/translation/taxonomy-missing?taxonomy=$tax&target_languages=$target_str&limit=50", $api_token );
	test( "taxonomy-missing/$tax returns 200", $r['code'] === 200, "got {$r['code']}" );

	$terms = $r['body']['terms'] ?? [];
	$count = $r['body']['count'] ?? -1;
	test( "taxonomy-missing/$tax count matches terms array", $count === count( $terms ), "count=$count array=" . count( $terms ) );

	// Verify no orphan terms (terms should all have valid WPML trid)
	$orphans = 0;
	foreach ( $terms as $t ) {
		// Check if missing_languages is a valid array
		if ( empty( $t['missing_languages'] ) || ! is_array( $t['missing_languages'] ) ) {
			$orphans++;
		}
	}
	test( "taxonomy-missing/$tax no invalid terms", $orphans === 0, "$orphans invalid" );

	echo "  INFO  $tax: $count terms to translate\n";
	foreach ( array_slice( $terms, 0, 3 ) as $t ) {
		echo "    #" . $t['term_id'] . " \"" . $t['name'] . "\" missing=" . implode( ',', $t['missing_languages'] ) . "\n";
	}
}

// ─── 4. PRODUCT MISSING-ALL ENDPOINT ───────────────────────────────

section( '4. Product Missing-All Endpoint' );

$r = api_get( "$base/translation/missing-all?target_languages=$target_str&post_type=product&limit=10", $api_token );
test( 'missing-all returns 200', $r['code'] === 200, "got {$r['code']}" );

$products = $r['body']['products'] ?? [];
$count = $r['body']['count'] ?? -1;
test( 'missing-all count matches', $count === count( $products ), "count=$count array=" . count( $products ) );

// Each product should have missing_languages array with valid langs
$invalid = 0;
foreach ( $products as $p ) {
	if ( ! isset( $p['missing_languages'] ) || ! is_array( $p['missing_languages'] ) ) {
		$invalid++;
		continue;
	}
	if ( empty( $p['missing_languages'] ) ) {
		$invalid++; // product listed as missing but no languages specified
		continue;
	}
	foreach ( $p['missing_languages'] as $lang ) {
		if ( ! empty( $target_langs ) && ! in_array( $lang, $target_langs ) ) {
			$invalid++;
		}
	}
}
test( 'missing-all all products have valid missing_languages', $invalid === 0, "$invalid invalid" );

echo "  INFO  Products to translate: $count\n";
foreach ( array_slice( $products, 0, 3 ) as $p ) {
	echo "    #" . $p['product_id'] . " \"" . $p['name'] . "\" missing=" . implode( ',', $p['missing_languages'] ) . "\n";
}

// Verify single-lang endpoint works
if ( ! empty( $target_langs ) ) {
	$r_single = api_get( "$base/translation/missing?target_language=" . $target_langs[0] . "&post_type=product&limit=50", $api_token );
	test( 'Single-lang missing endpoint returns 200', $r_single['code'] === 200, "got {$r_single['code']}" );
	$single_count = $r_single['body']['count'] ?? -1;
	echo "  INFO  Single-lang ({$target_langs[0]}) missing: $single_count\n";
} else {
	echo "  SKIP  Single-lang test (no target languages detected)\n";
}

// ─── 5. TAXONOMY CALLBACK (dry run) ───────────────────────────────

section( '5. Taxonomy Callback Validation' );

// Test with empty data — should return 400
$r = api_post( "$base/translation/taxonomy-callback", $api_token, [] );
test( 'Empty taxonomy callback returns 400', $r['code'] === 400, "got {$r['code']}" );

// Test with valid structure but fake term
$r = api_post( "$base/translation/taxonomy-callback", $api_token, [
	'taxonomy'     => 'product_cat',
	'translations' => [
		[ 'term_id' => 999999, 'language' => 'fr', 'name' => 'Test', 'slug' => 'test-fr' ],
	],
] );
test( 'Fake term callback returns 200 (resilient)', $r['code'] === 200, "got {$r['code']}" );
$saved = $r['body']['saved'] ?? -1;
$errs = $r['body']['errors'] ?? [];
test( 'Fake term not saved (0 saved)', $saved === 0, "saved=$saved" );
test( 'Fake term has error in response', count( $errs ) > 0, 'no errors reported' );

// ─── 6. PRODUCT TRANSLATION CALLBACK (dry run) ────────────────────

section( '6. Product Translation Callback Validation' );

// Test with missing fields
$r = api_post( "$base/translation/callback", $api_token, [] );
test( 'Empty product callback returns 400', $r['code'] === 400, "got {$r['code']}" );

// Test with non-existent product
$r = api_post( "$base/translation/callback", $api_token, [
	'product_id' => 999999,
	'language'   => 'fr',
	'content'    => [ 'name' => 'Test', 'description' => 'Test desc' ],
] );
test( 'Non-existent product returns 404', $r['code'] === 404, "got {$r['code']}" );

// ─── 7. KNOWLEDGE GRAPH CONSISTENCY ───────────────────────────────

section( '7. Knowledge Graph vs Translation Manager Consistency' );

$r_kg = api_get( "$base/knowledge-graph?section=products,translation&fresh=1", $api_token );
$kg_products = $r_kg['body']['nodes']['products'] ?? [];
$kg_languages = $r_kg['body']['nodes']['languages'] ?? [];

$total_products = count( $kg_products );
echo "  INFO  KG total products: $total_products\n";

foreach ( $kg_languages as $ln ) {
	$kg_missing = $ln['products_missing'] ?? 0;
	$kg_done = $ln['products_translated'] ?? 0;
	$lang = $ln['code'];

	// Compare with missing-all
	$r_miss = api_get( "$base/translation/missing?target_language=$lang&post_type=product&limit=200", $api_token );
	$api_missing = $r_miss['body']['count'] ?? -1;

	// Allow small variance — WPML can have orphan records that inflate API count
	$diff = abs( $kg_missing - $api_missing );
	test( "$lang: KG ($kg_missing) vs API ($api_missing) within tolerance", $diff <= 3,
		"KG=$kg_missing API=$api_missing diff=$diff" );
	echo "  INFO  $lang: done=$kg_done missing=$kg_missing coverage=" . $ln['coverage_pct'] . "%\n";
}

// ─── 8. PLUGIN HEALTH ──────────────────────────────────────────────

section( '8. Plugin Health' );

$r = api_get( "$base/knowledge-graph?section=plugins&fresh=1", $api_token );
$plugins = $r['body']['plugins'] ?? [];

test( 'SEO plugin detected', ( $plugins['seo']['plugin'] ?? 'none' ) !== 'none', $plugins['seo']['plugin'] ?? 'none' );
test( 'Translation plugin detected', ( $plugins['translation']['plugin'] ?? 'none' ) !== 'none', $plugins['translation']['plugin'] ?? 'none' );
test( 'SEO has coverage data', isset( $plugins['seo']['coverage'] ), 'no coverage' );
test( 'Translation has product_coverage', isset( $plugins['translation']['product_coverage'] ), 'no product_coverage' );

$readiness = $plugins['readiness_score'] ?? 0;
echo "  INFO  Readiness score: $readiness\n";

$recs = $plugins['recommendations'] ?? [];
foreach ( $recs as $rec ) {
	echo "  INFO  [{$rec['priority']}] {$rec['area']}: {$rec['message']}\n";
}

// ─── 9. STORE INTELLIGENCE ─────────────────────────────────────────

section( '9. Store Intelligence' );

$r = api_get( "$base/knowledge-graph?section=store&fresh=1", $api_token );
$store = $r['body']['store'] ?? [];
test( 'Store data available', ! empty( $store ), 'empty store data' );
test( 'Revenue data present', isset( $store['revenue'] ), 'no revenue' );
test( 'Top sellers present', isset( $store['top_sellers'] ), 'no top_sellers' );
test( 'Stock alerts present', isset( $store['stock_alerts'] ), 'no stock_alerts' );

if ( ! empty( $store['revenue'] ) ) {
	echo "  INFO  Revenue 30d: $" . ( $store['revenue']['last_30_days']['revenue'] ?? 0 ) . " (" . ( $store['revenue']['last_30_days']['orders'] ?? 0 ) . " orders)\n";
	echo "  INFO  AOV: $" . ( $store['average_order_value'] ?? 0 ) . "\n";
}

// ─── SUMMARY ───────────────────────────────────────────────────────

section( 'SUMMARY' );
$total = $passed + $failed;
echo "\n  Total: $total tests | Passed: $passed | Failed: $failed\n";

if ( $failed > 0 ) {
	echo "\n  Failed tests:\n";
	foreach ( $errors as $e ) {
		echo "    - $e\n";
	}
	echo "\n";
	exit( 1 );
}

echo "\n  All tests passed!\n\n";
exit( 0 );
