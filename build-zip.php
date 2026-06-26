<?php
/**
 * Build LuwiPress plugin ZIP for WordPress installation.
 * Uses forward slashes — required for WordPress unzip_file() compatibility.
 */

$version = $argv[1] ?? '2.0.0';
$slug    = $argv[2] ?? 'luwipress-core'; // RELEASE slug — ZIP filename prefix + license-server key

// Release slug → source folder. The folder is ALSO the ZIP's internal root,
// i.e. the WP install directory — it must NOT change for existing installs
// (plugin basename `luwipress/luwipress.php` is the plugin's identity in WP).
// Since 2026-06-10 the core plugin RELEASES as `luwipress-core` while keeping
// the `luwipress/` install folder; companions release under their folder name.
$dir_map = array(
    'luwipress-core' => 'luwipress',
    'luwipress'      => 'luwipress', // legacy invocation — old ZIP name, same content
);
$dir = $dir_map[ $slug ] ?? $slug;
$src = __DIR__ . '/' . $dir;
$dst = __DIR__ . '/releases/' . $slug . '-v' . $version . '.zip';

if ( ! is_dir( $src ) ) {
    die( "Source directory not found: $src\n" );
}

// ── Pre-build syntax check ──
echo "Checking PHP syntax...\n";
$lint_errors = 0;
$bom_files   = [];
$lint_iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS )
);
foreach ( $lint_iter as $lint_file ) {
    if ( $lint_file->getExtension() !== 'php' ) continue;

    // BOM detection — a UTF-8 BOM at the top of a PHP file gets emitted as
    // 3 bytes of output before headers can be sent, breaking activation with
    // "headers already sent" / "plugin generated 3 characters of unexpected
    // output". Block the build before it ships a busted ZIP.
    $fh = fopen( $lint_file->getRealPath(), 'rb' );
    if ( $fh ) {
        $head = fread( $fh, 3 );
        fclose( $fh );
        if ( $head === "\xEF\xBB\xBF" ) {
            $bom_files[] = str_replace( $src . DIRECTORY_SEPARATOR, '', $lint_file->getRealPath() );
        }
    }

    $out = []; $code = 0;
    exec( 'php -l ' . escapeshellarg( $lint_file->getRealPath() ) . ' 2>&1', $out, $code );
    if ( $code !== 0 ) {
        $lint_errors++;
        $rel = str_replace( $src . DIRECTORY_SEPARATOR, '', $lint_file->getRealPath() );
        echo "  FAIL: $rel\n";
        foreach ( $out as $l ) { if ( stripos( $l, 'no syntax' ) === false ) echo "    $l\n"; }
    }
}
if ( $lint_errors > 0 ) {
    die( "\nBLOCKED: $lint_errors syntax error(s). Fix before building.\n" );
}
if ( ! empty( $bom_files ) ) {
    echo "  BOM detected in:\n";
    foreach ( $bom_files as $bf ) echo "    $bf\n";
    die( "\nBLOCKED: " . count( $bom_files ) . " file(s) start with UTF-8 BOM. WordPress activation will fail with 'unexpected output' — strip the BOM before building.\n" );
}
echo "  All PHP files OK.\n\n";

// ── PHPStan static analysis (if vendor/ exists; core plugin only) ──
// PHPStan config (phpstan.neon) is tuned for the core plugin. Companion plugins
// (luwipress-webmcp, etc.) piggyback on core classes which aren't autoloadable
// from a standalone companion path, so we skip PHPStan for those builds.
$phpstan = __DIR__ . '/vendor/bin/phpstan';
if ( file_exists( $phpstan ) && $dir === 'luwipress' ) {
    echo "Running PHPStan...\n";
    $stan_out = []; $stan_code = 0;
    exec( 'php -d memory_limit=4G ' . escapeshellarg( $phpstan ) . ' analyse --no-progress --memory-limit=4G 2>&1', $stan_out, $stan_code );
    if ( $stan_code !== 0 ) {
        echo implode( "\n", $stan_out ) . "\n";
        die( "\nBLOCKED: PHPStan found new errors. Fix before building.\n" );
    }
    echo "  PHPStan OK.\n\n";
} elseif ( ! file_exists( $phpstan ) ) {
    echo "Skipping PHPStan (run 'composer install' to enable).\n\n";
} else {
    echo "Skipping PHPStan (companion build '{$slug}').\n\n";
}

if ( ! is_dir( dirname( $dst ) ) ) {
    mkdir( dirname( $dst ), 0755, true );
}

if ( file_exists( $dst ) ) {
    unlink( $dst );
}

$zip = new ZipArchive();
if ( $zip->open( $dst, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
    die( "Cannot create ZIP: $dst\n" );
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$count = 0;
foreach ( $files as $file ) {
    $real = $file->getRealPath();
    $rel  = str_replace( '\\', '/', substr( $real, strlen( $src ) + 1 ) );

    // Skip hidden files
    if ( strpos( $rel, '.' ) === 0 || strpos( $rel, '/.' ) !== false ) {
        continue;
    }

    // Use addFromString to avoid ZipArchive truncation on large files (Windows).
    // Internal root = $dir (the WP install folder), NOT the release slug.
    $zip->addFromString( $dir . '/' . $rel, file_get_contents( $real ) );
    $count++;
}

// Distribution enforcement flag — bake the license-enforce constant ONLY into
// the core ZIP (the `luwipress/` install root that loads it via require_once).
// Sold/distributed copies thus require an active license to use plugin features;
// source/dev builds have no config-dist.php and are never gated. The constant is
// guarded so wp-config.php can still override it for special staging needs.
if ( $dir === 'luwipress' ) {
    $zip->addFromString(
        $dir . '/config-dist.php',
        "<?php\n// Generated by build-zip.php for the distribution build. Do not edit.\n"
        . "// Forces license enforcement on (vendor decision, not a buyer toggle).\n"
        . "if ( ! defined( 'LUWIPRESS_LICENSE_ENFORCE' ) ) {\n\tdefine( 'LUWIPRESS_LICENSE_ENFORCE', true );\n}\n"
    );
    $count++;
    echo "  + injected config-dist.php (LUWIPRESS_LICENSE_ENFORCE=true)\n";
}

$zip->close();

$size = round( filesize( $dst ) / 1024 );
echo "ZIP created: $dst\n";
echo "Files: $count\n";
echo "Size: {$size} KB\n";
