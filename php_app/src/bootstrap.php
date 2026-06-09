<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('APP_ROOT', dirname(__DIR__));
define('PROJECT_ROOT', dirname(APP_ROOT));

$appConfig = require APP_ROOT . '/config/app.php';
$GLOBALS['app_config'] = $appConfig;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

foreach (['runtime_root', 'jobs_dir', 'logs_dir', 'uploads_dir', 'workspaces_dir'] as $key) {
    $dir = $appConfig[$key] ?? null;
    if (is_string($dir) && $dir !== '' && !is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $GLOBALS['bootstrap_warnings'][] = '无法创建目录：' . $dir;
        }
    }
}

set_exception_handler(static function (Throwable $throwable): void {
    http_response_code(500);
    $message = $throwable->getMessage();
    if (expects_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    $title = '应用错误';
    $body = '<div class="alert error"><strong>发生错误：</strong> ' . e($message) . '</div>';
    include APP_ROOT . '/views/layouts/base.php';
});

function app_config(?string $key = null, mixed $default = null): mixed
{
    $config = $GLOBALS['app_config'] ?? [];
    if ($key === null) {
        return $config;
    }

    return $config[$key] ?? $default;
}

function expects_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return str_contains($accept, 'application/json');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function asset_url(string $path): string
{
    return '/assets/' . ltrim($path, '/');
}

function url(string $script, array $query = []): string
{
    $base = '/' . ltrim($script, '/');
    if ($query === []) {
        return $base;
    }
    return $base . '?' . http_build_query($query);
}

function redirect_to(string $script, array $query = []): never
{
    header('Location: ' . url($script, $query));
    exit;
}

function render_page(string $title, callable $renderer): void
{
    ob_start();
    $renderer();
    $body = (string) ob_get_clean();
    include APP_ROOT . '/views/layouts/base.php';
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function get_string(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function post_bool(string $key): bool
{
    return isset($_POST[$key]) && in_array((string) $_POST[$key], ['1', 'on', 'true'], true);
}

function format_time(?string $time): string
{
    if (!$time) {
        return '-';
    }
    try {
        return (new DateTimeImmutable($time))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $time;
    }
}

function tail_file(string $path, int $maxBytes): string
{
    if (!is_file($path)) {
        return '';
    }
    $size = filesize($path) ?: 0;
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return '';
    }
    $offset = max(0, $size - $maxBytes);
    fseek($handle, $offset);
    $data = stream_get_contents($handle) ?: '';
    fclose($handle);
    return sanitize_log($data);
}

function sanitize_log(string $content): string
{
    $content = str_replace(PROJECT_ROOT, '[PROJECT_ROOT]', $content);
    $content = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/u', 'Bearer [REDACTED]', $content) ?? $content;
    return $content;
}

function relative_project_path(string $path): string
{
    $projectRoot = rtrim((string) app_config('project_root'), '/');
    if ($projectRoot !== '' && str_starts_with($path, $projectRoot . '/')) {
        return substr($path, strlen($projectRoot) + 1);
    }
    return $path;
}

function format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float) $bytes;
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return sprintf('%.1f %s', $value, $units[$index]);
}
