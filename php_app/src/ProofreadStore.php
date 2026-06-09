<?php
declare(strict_types=1);

namespace App;

final class ProofreadStore
{
    public function __construct(
        private readonly WorkspaceManager $workspaceManager,
        private readonly CombinedTextParser $parser,
    ) {
    }

    /** @return array{proofread_path:string,status_path:string,pages:list<array{page:int,text:string}>,completed_pages:list<int>} */
    public function loadBundle(string $jobId, string $translationPath): array
    {
        $proofreadPath = $this->proofreadPath($jobId, $translationPath);
        if (!is_file($proofreadPath)) {
            $directory = dirname($proofreadPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            copy($translationPath, $proofreadPath);
        }

        $statusPath = $proofreadPath . '.status.json';
        $pages = $this->parser->parseFile($proofreadPath);
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
            'proofread_path' => $proofreadPath,
            'status_path' => $statusPath,
            'pages' => $pages,
            'completed_pages' => $completedPages,
        ];
    }

    /** @param list<array{page:int,text:string}> $pages */
    /** @param list<int> $completedPages */
    /** @return array{proofread_path:string,status_path:string} */
    public function save(string $jobId, string $translationPath, array $pages, array $completedPages): array
    {
        $bundle = $this->loadBundle($jobId, $translationPath);
        $this->parser->writeFile($bundle['proofread_path'], $pages);
        file_put_contents(
            $bundle['status_path'],
            json_encode([
                'completed_pages' => array_values(array_unique(array_map('intval', $completedPages))),
                'updated_at' => date(DATE_ATOM),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return [
            'proofread_path' => $bundle['proofread_path'],
            'status_path' => $bundle['status_path'],
        ];
    }

    public function proofreadPath(string $jobId, string $translationPath): string
    {
        return $this->workspaceManager->jobRoot($jobId) . '/proofread_text/' . basename($translationPath);
    }
}
