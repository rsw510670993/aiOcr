<?php
declare(strict_types=1);

namespace App;

final class ArtifactLocator
{
    public function __construct(
        private readonly JobStore $jobStore,
        private readonly WorkspaceManager $workspaceManager,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function recentJobs(?string $type = null, int $limit = 10): array
    {
        $jobs = $this->jobStore->listRecent($limit * 3);
        if ($type === null) {
            return array_slice($jobs, 0, $limit);
        }
        $filtered = array_values(array_filter($jobs, static fn (array $job): bool => ($job['type'] ?? '') === $type));
        return array_slice($filtered, 0, $limit);
    }

    /** @return list<string> */
    public function recentArtifactPaths(string $artifactKey, int $limit = 10): array
    {
        $paths = [];
        foreach ($this->jobStore->listRecent(50) as $job) {
            $candidate = $job['artifacts'][$artifactKey] ?? null;
            if (is_string($candidate) && $candidate !== '' && file_exists($candidate) && !in_array($candidate, $paths, true)) {
                $paths[] = $candidate;
            }
            if (count($paths) >= $limit) {
                break;
            }
        }
        return $paths;
    }

    /** @return array<string, mixed>|null */
    public function linkedArtifacts(?string $jobId): ?array
    {
        if (!$jobId) {
            return null;
        }
        $job = $this->jobStore->get($jobId);
        if (!$job) {
            return null;
        }
        return $job['artifacts'] ?? [];
    }

    /** @return list<array{id:string,path:string,updated_at:int}> */
    public function recentProjects(int $limit = 20): array
    {
        return $this->workspaceManager->listProjects($limit);
    }

    /** @return list<string> */
    public function projectFiles(string $projectId, string $folder): array
    {
        return $this->workspaceManager->textFiles($projectId, $folder);
    }

    /** @return list<string> */
    public function projectImages(string $projectId): array
    {
        try {
            return $this->workspaceManager->imageFiles($projectId);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, string>|null */
    public function projectArtifacts(?string $projectId): ?array
    {
        if (!$projectId) {
            return null;
        }
        try {
            return $this->workspaceManager->existingProjectPaths($projectId);
        } catch (\Throwable) {
            return null;
        }
    }
}
