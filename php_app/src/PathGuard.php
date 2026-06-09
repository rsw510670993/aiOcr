<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class PathGuard
{
    /** @var list<string> */
    private array $allowedRoots;

    public function __construct()
    {
        $projectRoot = rtrim((string) \app_config('project_root'), '/');
        $runtimeRoot = rtrim((string) \app_config('runtime_root'), '/');
        $this->allowedRoots = array_values(array_filter([$projectRoot, $runtimeRoot]));
    }

    public function assertFile(string $path, bool $mustExist = true): string
    {
        $normalized = $this->assertAllowed($path, $mustExist);
        if ($mustExist && !is_file($normalized)) {
            throw new RuntimeException('文件不存在：' . $path);
        }
        return $normalized;
    }

    public function assertDir(string $path, bool $mustExist = true): string
    {
        $normalized = $this->assertAllowed($path, $mustExist);
        if ($mustExist && !is_dir($normalized)) {
            throw new RuntimeException('目录不存在：' . $path);
        }
        return $normalized;
    }

    public function assertAllowed(string $path, bool $mustExist = false): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('路径不能为空。');
        }

        $normalized = $this->normalizePath($path);
        if ($mustExist && !file_exists($normalized)) {
            throw new RuntimeException('路径不存在：' . $path);
        }

        if (!$this->isAllowed($normalized)) {
            throw new RuntimeException('路径超出允许范围：' . $path);
        }

        return $normalized;
    }

    public function safeFilename(string $filename, string $fallback): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($filename)) ?? '';
        $filename = trim($filename, '._');
        return $filename !== '' ? $filename : $fallback;
    }

    private function isAllowed(string $path): bool
    {
        foreach ($this->allowedRoots as $root) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    private function normalizePath(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            $path = rtrim((string) \app_config('project_root'), '/') . '/' . ltrim($path, '/');
        }

        $segments = [];
        foreach (explode('/', preg_replace('#/+#', '/', $path) ?? $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }
}
