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
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($file));
readfile($file);
