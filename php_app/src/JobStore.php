<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class JobStore
{
    public function __construct(private readonly string $jobsDir)
    {
        if (!is_dir($this->jobsDir)) {
            mkdir($this->jobsDir, 0775, true);
        }
    }

    /** @param array<string, mixed> $params */
    /** @param array<string, mixed> $artifacts */
    /** @return array<string, mixed> */
    public function create(string $type, array $params = [], array $artifacts = []): array
    {
        $id = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $job = [
            'id' => $id,
            'type' => $type,
            'status' => 'queued',
            'created_at' => date(DATE_ATOM),
            'started_at' => null,
            'finished_at' => null,
            'command' => null,
            'cwd' => null,
            'params' => $params,
            'artifacts' => $artifacts,
            'log_file' => rtrim((string) \app_config('logs_dir'), '/') . '/' . $id . '.log',
            'pid_file' => rtrim((string) \app_config('jobs_dir'), '/') . '/' . $id . '.pid',
            'exit_code_file' => rtrim((string) \app_config('jobs_dir'), '/') . '/' . $id . '.exit',
            'exit_code' => null,
            'error' => null,
        ];
        $this->save($job);
        return $job;
    }

    /** @param array<string, mixed> $job */
    public function save(array $job): void
    {
        $path = $this->pathForId((string) $job['id']);
        file_put_contents(
            $path,
            json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        $path = $this->pathForId($id);
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<array<string, mixed>> */
    public function listRecent(int $limit = 20): array
    {
        $items = [];
        foreach (glob($this->jobsDir . '/*.json') ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $items[] = $this->refresh($decoded);
            }
        }
        usort($items, static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });
        return array_slice($items, 0, $limit);
    }

    /** @param array<string, mixed> $job */
    /** @param array<string, mixed> $changes */
    /** @return array<string, mixed> */
    public function merge(array $job, array $changes): array
    {
        $updated = array_replace_recursive($job, $changes);
        $this->save($updated);
        return $updated;
    }

    /** @param array<string, mixed> $job */
    /** @return array<string, mixed> */
    public function refresh(array $job): array
    {
        $status = (string) ($job['status'] ?? 'queued');
        if (!in_array($status, ['queued', 'running'], true)) {
            return $job;
        }

        $changed = false;
        $exitFile = (string) ($job['exit_code_file'] ?? '');
        if ($exitFile !== '' && is_file($exitFile)) {
            $exitCode = (int) trim((string) file_get_contents($exitFile));
            $job['exit_code'] = $exitCode;
            $job['status'] = $exitCode === 0 ? 'success' : 'failed';
            $job['finished_at'] = $job['finished_at'] ?: date(DATE_ATOM);
            $changed = true;
        } elseif (($job['pid_file'] ?? '') && is_file((string) $job['pid_file'])) {
            $job['status'] = 'running';
        }

        if ($changed) {
            $this->save($job);
        }
        return $job;
    }

    /** @return array<string, mixed> */
    public function require(string $id): array
    {
        $job = $this->get($id);
        if ($job === null) {
            throw new RuntimeException('任务不存在：' . $id);
        }
        return $this->refresh($job);
    }

    private function pathForId(string $id): string
    {
        return $this->jobsDir . '/' . $id . '.json';
    }
}
