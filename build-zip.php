<?php
/**
 * Build LuwiPress plugin ZIP for WordPress installation.
 * Uses forward slashes — required for WordPress unzip_file() compatibility.
 */

$version = $argv[1] ?? '2.0.0';
$src = __DIR__ . '/luwipress';
$dst = __DIR__ . '/releases/luwipress-v' . $version . '.zip';

if ( ! is_dir( $src ) ) {
    die( "Source directory not found: $src\n" );
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

    $zip->addFile( $real, 'luwipress/' . $rel );
    $count++;
}

$zip->close();

$size = round( filesize( $dst ) / 1024 );
echo "ZIP created: $dst\n";
echo "Files: $count\n";
echo "Size: {$size} KB\n";
