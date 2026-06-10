<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\PathGuard;

$path = get_string('path');
if ($path === '') {
    http_response_code(400);
    echo '缺少 path';
    exit;
}

$guard = new PathGuard();
$file = $guard->assertFile($path);
$download = get_string('download') === '1';
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($file));
if ($download) {
    $filename = basename($file);
    $asciiFilename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';
    header('Content-Disposition: attachment; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
}
readfile($file);
