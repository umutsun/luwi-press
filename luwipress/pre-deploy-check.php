<?php
/**
 * LuwiPress Pre-Deploy Syntax Checker
 *
 * Run before uploading to production:
 *   php pre-deploy-check.php
 *
 * Checks all PHP files for syntax errors.
 * Exit code 0 = all clear, 1 = errors found.
 */

$dir    = __DIR__;
$errors = 0;
$files  = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
    if ( $file->getExtension() !== 'php' ) {
        continue;
    }
    if ( $file->getFilename() === 'pre-deploy-check.php' ) {
        continue;
    }

    $files++;
    $path   = $file->getRealPath();
    $output = [];
    $code   = 0;
    exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1', $output, $code );

    if ( $code !== 0 ) {
        $errors++;
        $rel = str_replace( $dir . DIRECTORY_SEPARATOR, '', $path );
        echo "FAIL: {$rel}\n";
        foreach ( $output as $line ) {
            if ( stripos( $line, 'no syntax errors' ) === false ) {
                echo "  {$line}\n";
            }
        }
    }
}

echo "\n";
if ( $errors > 0 ) {
    echo "BLOCKED: {$errors} file(s) with syntax errors out of {$files} checked.\n";
    echo "DO NOT UPLOAD — fix errors first.\n";
    exit( 1 );
} else {
    echo "PASSED: {$files} PHP files checked, 0 errors.\n";
    echo "Safe to upload.\n";
    exit( 0 );
}
