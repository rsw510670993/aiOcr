<?php
declare(strict_types=1);

namespace App;

final class WorkspaceManager
{
    public function jobRoot(string $jobId): string
    {
        return rtrim((string) \app_config('workspaces_dir'), '/') . '/' . $jobId;
    }

    /** @return array<string, string> */
    public function ensureJobWorkspace(string $jobId): array
    {
        $root = $this->jobRoot($jobId);
        $paths = [
            'root' => $root,
            'exported_jpg' => $root . '/exported_jpg',
            'ocr_text' => $root . '/ocr_text',
            'aigc2d_translation_text' => $root . '/aigc2d_translation_text',
            'proofread_text' => $root . '/proofread_text',
        ];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }

        return $paths;
    }

    public function uploadPdfPath(string $jobId): string
    {
        return $this->jobRoot($jobId) . '/source.pdf';
    }

    public function artifactPath(string $jobId, string $folder, string $filename): string
    {
        return $this->jobRoot($jobId) . '/' . trim($folder, '/') . '/' . $filename;
    }
}
