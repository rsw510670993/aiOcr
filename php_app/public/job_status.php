<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\JobStore;

$jobId = get_string('job_id');
if ($jobId === '') {
    json_response(['ok' => false, 'error' => '缺少 job_id'], 400);
}

$jobStore = new JobStore((string) app_config('jobs_dir'));
$job = $jobStore->require($jobId);

json_response([
    'ok' => true,
    'job' => $job,
    'log_tail' => tail_file((string) $job['log_file'], (int) app_config('max_log_bytes')),
]);
