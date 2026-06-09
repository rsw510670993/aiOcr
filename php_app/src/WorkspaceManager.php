<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class WorkspaceManager
{
    public function projectsRoot(): string
    {
        return rtrim((string) \app_config('projects_dir'), '/');
    }

    public function ensureProjectsRoot(): string
    {
        $root = $this->projectsRoot();
        if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('无法创建 projects 目录：' . $root);
        }
        return $root;
    }

    public function projectIdFromName(string $name): string
    {
        $name = trim($name);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? '';
        $safe = trim($safe, '._-');
        if ($safe !== '') {
            return $safe;
        }
        return 'project_' . date('Ymd_His');
    }

    public function safeFilename(string $filename, string $fallback): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($filename)) ?? '';
        $safe = trim($safe, '._-');
        return $safe !== '' ? $safe : $fallback;
    }

    public function projectRoot(string $projectId): string
    {
        return $this->projectsRoot() . '/' . $this->projectIdFromName($projectId);
    }

    public function projectExists(string $projectId): bool
    {
        return is_dir($this->projectRoot($projectId));
    }

    /** @return array<string, string> */
    public function ensureProject(string $projectId): array
    {
        $projectId = $this->projectIdFromName($projectId);
        $this->ensureProjectsRoot();
        $root = $this->projectRoot($projectId);
        $paths = [
            'project_id' => $projectId,
            'root' => $root,
            'uploads' => $root . '/uploads',
            'exported_jpg' => $root . '/exported_jpg',
            'ocr_text' => $root . '/ocr_text',
            'aigc2d_translation_text' => $root . '/aigc2d_translation_text',
            'proofread_text' => $root . '/proofread_text',
        ];

        foreach ($paths as $key => $path) {
            if ($key === 'project_id') {
                continue;
            }
            if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
                throw new RuntimeException('无法创建项目目录：' . $path);
            }
        }

        return $paths;
    }

    /** @return array<string, string> */
    public function existingProjectPaths(string $projectId): array
    {
        $projectId = $this->projectIdFromName($projectId);
        $root = $this->projectRoot($projectId);
        if (!is_dir($root)) {
            throw new RuntimeException('项目不存在：' . $projectId);
        }
        return [
            'project_id' => $projectId,
            'root' => $root,
            'uploads' => $root . '/uploads',
            'exported_jpg' => $root . '/exported_jpg',
            'ocr_text' => $root . '/ocr_text',
            'aigc2d_translation_text' => $root . '/aigc2d_translation_text',
            'proofread_text' => $root . '/proofread_text',
        ];
    }

    public function uploadPdfPath(string $projectId, string $originalName = 'source.pdf'): string
    {
        $paths = $this->ensureProject($projectId);
        return $paths['uploads'] . '/' . $this->safeFilename($originalName, 'source.pdf');
    }

    public function uploadZipPath(string $projectId, string $originalName = 'images.zip'): string
    {
        $paths = $this->ensureProject($projectId);
        return $paths['uploads'] . '/' . $this->safeFilename($originalName, 'images.zip');
    }

    /** @return list<string> */
    public function imageFiles(string $projectId): array
    {
        $paths = $this->existingProjectPaths($projectId);
        $directory = $paths['exported_jpg'];
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $files[] = $path;
            }
        }
        usort($files, static fn (string $a, string $b): int => strnatcasecmp(basename($a), basename($b)));
        return array_values($files);
    }

    /** @return list<array{id:string,path:string,updated_at:int}> */
    public function listProjects(int $limit = 50): array
    {
        $root = $this->projectsRoot();
        if (!is_dir($root)) {
            return [];
        }
        $items = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $items[] = [
                'id' => basename($path),
                'path' => $path,
                'updated_at' => filemtime($path) ?: 0,
            ];
        }
        usort($items, static fn (array $left, array $right): int => $right['updated_at'] <=> $left['updated_at']);
        return array_slice($items, 0, $limit);
    }

    /** @return list<string> */
    public function textFiles(string $projectId, string $folder): array
    {
        $paths = $this->existingProjectPaths($projectId);
        $directory = $paths[$folder] ?? null;
        if (!is_string($directory) || !is_dir($directory)) {
            return [];
        }
        $files = glob($directory . '/*.txt') ?: [];
        usort($files, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        return array_values($files);
    }
}
