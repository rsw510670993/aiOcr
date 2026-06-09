<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class ProcessRunner
{
    public function __construct(private readonly JobStore $jobStore)
    {
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $artifacts
     * @return array<string, mixed>
     */
    public function start(array $job, string $command, string $cwd, array $artifacts = []): array
    {
        $job['command'] = $command;
        $job['cwd'] = $cwd;
        $job['status'] = 'running';
        $job['started_at'] = date(DATE_ATOM);
        $job['finished_at'] = null;
        $job['exit_code'] = null;
        $job['error'] = null;
        $job['artifacts'] = array_replace($job['artifacts'] ?? [], $artifacts);
        $this->jobStore->save($job);

        $logFile = (string) $job['log_file'];
        $pidFile = (string) $job['pid_file'];
        $exitFile = (string) $job['exit_code_file'];
        @unlink($pidFile);
        @unlink($exitFile);
        file_put_contents($logFile, '');

        $script = 'cd ' . escapeshellarg($cwd) . ' && ('
            . 'printf "%s\n" ' . escapeshellarg('[' . date('Y-m-d H:i:s') . '] 启动任务') . '; '
            . 'printf "%s\n" ' . escapeshellarg('命令: ' . $command) . '; '
            . $command . '; '
            . 'status=$?; '
            . 'printf "[%s] Exit code: %s\n" "$(date "+%F %T")" "$status"; '
            . 'echo "$status" > ' . escapeshellarg($exitFile) . '; '
            . ') >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $! > ' . escapeshellarg($pidFile);

        exec('/bin/sh -lc ' . escapeshellarg($script), $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException('后台任务启动失败。');
        }

        return $this->jobStore->require((string) $job['id']);
    }
}
