<?php
declare(strict_types=1);

return [
    'app_name' => 'AIGC2D 漫画流程台',
    'project_root' => '/workspace',
    'python_bin' => '/workspace/.venv/bin/python',
    'runtime_root' => dirname(__DIR__) . '/runtime',
    'jobs_dir' => dirname(__DIR__) . '/runtime/jobs',
    'logs_dir' => dirname(__DIR__) . '/runtime/logs',
    'uploads_dir' => dirname(__DIR__) . '/runtime/uploads',
    'workspaces_dir' => dirname(__DIR__) . '/runtime/workspaces',
    'poll_interval_ms' => 2000,
    'max_log_bytes' => 12000,
    'max_upload_size_mb' => 200,
];
