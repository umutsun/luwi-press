<?php
$version = $argv[1] ?? '1.7.5';
$zip = new ZipArchive();
$zipFile = "releases/n8npress-bridge-v{$version}.zip";
if (file_exists($zipFile)) unlink($zipFile);
$zip->open($zipFile, ZipArchive::CREATE);

$dir = 'n8npress-bridge';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
);
$count = 0;
foreach ($iterator as $file) {
    $filePath = $file->getRealPath();
    $relativePath = str_replace('\\', '/', $iterator->getSubPathName());
    $zipPath = 'n8npress-bridge/' . $relativePath;
    $zip->addFile($filePath, $zipPath);
    $count++;
}
$zip->close();
echo "Created $zipFile with $count files\n";
