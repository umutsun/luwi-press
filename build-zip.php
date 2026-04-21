<?php
/**
 * Build LuwiPress plugin ZIP for WordPress installation.
 * Uses forward slashes — required for WordPress unzip_file() compatibility.
 */

$version = $argv[1] ?? '2.0.0';
$slug    = $argv[2] ?? 'luwipress'; // plugin folder slug; also used as ZIP prefix
$src = __DIR__ . '/' . $slug;
$dst = __DIR__ . '/releases/' . $slug . '-v' . $version . '.zip';

if ( ! is_dir( $src ) ) {
    die( "Source directory not found: $src\n" );
}

// ── Pre-build syntax check ──
echo "Checking PHP syntax...\n";
$lint_errors = 0;
$lint_iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS )
);
foreach ( $lint_iter as $lint_file ) {
    if ( $lint_file->getExtension() !== 'php' ) continue;
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
echo "  All PHP files OK.\n\n";

// ── PHPStan static analysis (if vendor/ exists; core plugin only) ──
// PHPStan config (phpstan.neon) is tuned for the core plugin. Companion plugins
// (luwipress-webmcp, etc.) piggyback on core classes which aren't autoloadable
// from a standalone companion path, so we skip PHPStan for those builds.
$phpstan = __DIR__ . '/vendor/bin/phpstan';
if ( file_exists( $phpstan ) && $slug === 'luwipress' ) {
    echo "Running PHPStan...\n";
    $stan_out = []; $stan_code = 0;
    exec( 'php -d memory_limit=2G ' . escapeshellarg( $phpstan ) . ' analyse --no-progress --memory-limit=2G 2>&1', $stan_out, $stan_code );
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

    // Use addFromString to avoid ZipArchive truncation on large files (Windows)
    $zip->addFromString( $slug . '/' . $rel, file_get_contents( $real ) );
    $count++;
}

$zip->close();

$size = round( filesize( $dst ) / 1024 );
echo "ZIP created: $dst\n";
echo "Files: $count\n";
echo "Size: {$size} KB\n";
