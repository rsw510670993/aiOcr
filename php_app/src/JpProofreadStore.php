<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class JpProofreadStore
{
    public function __construct(
        private readonly WorkspaceManager $workspaceManager,
        private readonly CombinedTextParser $parser,
    ) {
    }

    /** @return array{jp_path:string,status_path:string,pages:list<array{page:int,text:string}>,completed_pages:list<int>} */
    public function loadBundle(string $projectId, string $ocrPath): array
    {
        $jpPath = $this->jpPath($projectId, $ocrPath);
        if (!is_file($jpPath)) {
            $directory = dirname($jpPath);
            if (!is_dir($directory)) {
                if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
                    throw new RuntimeException('无法创建日语校对目录：' . $directory);
                }
            }
            copy($ocrPath, $jpPath);
        }

        $statusPath = $jpPath . '.status.json';
        $pages = $this->parser->parseFile($jpPath);
        $status = ['completed_pages' => []];
        if (is_file($statusPath)) {
            $decoded = json_decode((string) file_get_contents($statusPath), true);
            if (is_array($decoded)) {
                $status = array_replace($status, $decoded);
            }
        }

        $completedPages = array_values(array_unique(array_map('intval', $status['completed_pages'] ?? [])));
        sort($completedPages);

        return [
            'jp_path' => $jpPath,
            'status_path' => $statusPath,
            'pages' => $pages,
            'completed_pages' => $completedPages,
        ];
    }

    /** @param list<array{page:int,text:string}> $pages */
    /** @param list<int> $completedPages */
    /** @return array{jp_path:string,status_path:string} */
    public function save(string $projectId, string $ocrPath, array $pages, array $completedPages): array
    {
        $bundle = $this->loadBundle($projectId, $ocrPath);
        $this->parser->writeFile($bundle['jp_path'], $pages);
        file_put_contents(
            $bundle['status_path'],
            json_encode([
                'completed_pages' => array_values(array_unique(array_map('intval', $completedPages))),
                'updated_at' => date(DATE_ATOM),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return [
            'jp_path' => $bundle['jp_path'],
            'status_path' => $bundle['status_path'],
        ];
    }

    public function jpPath(string $projectId, string $ocrPath): string
    {
        $paths = $this->workspaceManager->existingProjectPaths($projectId);
        $ocrDir = $paths['ocr_text'];
        $base = basename($ocrPath);
        $name = str_ends_with($base, '.txt') ? substr($base, 0, -4) : $base;
        return $ocrDir . '/' . $name . '.jp.txt';
    }
}
