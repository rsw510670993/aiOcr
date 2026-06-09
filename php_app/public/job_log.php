<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\JobStore;

$jobId = get_string('job_id');
if ($jobId === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo '缺少 job_id';
    exit;
}

$jobStore = new JobStore((string) app_config('jobs_dir'));
$job = $jobStore->require($jobId);

header('Content-Type: text/plain; charset=utf-8');
echo tail_file((string) $job['log_file'], (int) app_config('max_log_bytes'));
