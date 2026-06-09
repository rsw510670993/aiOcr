<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__);
$projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname($appRoot);

return [
    'app_name' => 'AIGC2D 漫画流程台',
    'project_root' => $projectRoot,
    'python_bin' => $projectRoot . '/.venv/bin/python',
    'projects_dir' => $projectRoot . '/projects',
    'runtime_root' => $appRoot . '/runtime',
    'jobs_dir' => $appRoot . '/runtime/jobs',
    'logs_dir' => $appRoot . '/runtime/logs',
    'uploads_dir' => $appRoot . '/runtime/uploads',
    'poll_interval_ms' => 2000,
    'max_log_bytes' => 12000,
    'max_upload_size_mb' => 200,
];
