<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\ArtifactLocator;
use App\JobStore;
use App\PathGuard;
use App\ProcessRunner;
use App\WorkspaceManager;

$jobStore = new JobStore((string) app_config('jobs_dir'));
$runner = new ProcessRunner($jobStore);
$workspaceManager = new WorkspaceManager();
$locator = new ArtifactLocator($jobStore);
$guard = new PathGuard();
$error = '';

$defaultImageDir = get_string('image_dir');
$defaultJobId = get_string('job_id');

$defaultCombinedName = 'pages_0001-0001.txt';
if (request_method() === 'POST') {
    try {
        $imageDir = $guard->assertDir(post_string('image_dir'));
        $pages = post_string('pages', '1-1');
        $model = post_string('model', 'gemini-3.1-flash-lite');
        $timeout = max(30, (int) post_string('request_timeout', '300'));
        $retries = max(1, (int) post_string('retries', '3'));
        $resume = post_bool('resume');
        $skipConsistency = post_bool('no_consistency_check');
        $combinedName = trim(post_string('combined_name'));
        if ($combinedName === '') {
            $combinedName = preg_match('/^(\d+)-(\d+)$/', $pages, $matches)
                ? sprintf('pages_%04d-%04d.txt', (int) $matches[1], (int) $matches[2])
                : $defaultCombinedName;
        }
        $combinedName = $guard->safeFilename($combinedName, $defaultCombinedName);
        if (!str_ends_with($combinedName, '.txt')) {
            $combinedName .= '.txt';
        }

        $job = $jobStore->create('ocr', [
            'image_dir' => $imageDir,
            'pages' => $pages,
            'model' => $model,
            'request_timeout' => $timeout,
            'retries' => $retries,
        ]);
        $workspace = $workspaceManager->ensureJobWorkspace((string) $job['id']);
        $combinedOutput = $workspace['ocr_text'] . '/' . $combinedName;
        $artifacts = [
            'image_dir' => $imageDir,
            'ocr_text' => $combinedOutput,
        ];
        $job = $jobStore->merge($job, ['artifacts' => $artifacts]);

        $scriptPath = rtrim((string) app_config('project_root'), '/') . '/aigc2d_ocr.py';
        $command = escapeshellarg((string) app_config('python_bin'))
            . ' ' . escapeshellarg($scriptPath)
            . ' --image-dir ' . escapeshellarg($imageDir)
            . ' --pages ' . escapeshellarg($pages)
            . ' --combined-output ' . escapeshellarg($combinedOutput)
            . ' --model ' . escapeshellarg($model)
            . ' --request-timeout ' . $timeout
            . ' --retries ' . $retries;
        if ($resume) {
            $command .= ' --resume';
        }
        if ($skipConsistency) {
            $command .= ' --no-consistency-check';
        }

        $runner->start($job, $command, (string) app_config('project_root'), $artifacts);
        redirect_to('ocr.php', ['job_id' => $job['id'], 'image_dir' => $imageDir]);
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

$job = null;
$jobId = get_string('job_id', $defaultJobId);
if ($jobId !== '') {
    $job = $jobStore->require($jobId);
}
$imageDirChoices = $locator->recentArtifactPaths('exported_jpg');
if ($defaultImageDir !== '' && !in_array($defaultImageDir, $imageDirChoices, true)) {
    array_unshift($imageDirChoices, $defaultImageDir);
}

render_page('OCR 识别', function () use ($error, $job, $imageDirChoices, $defaultImageDir, $defaultCombinedName): void {
?>
<?php if ($job) : ?>
    <section class="panel" data-job-status data-job-id="<?= e((string) $job['id']) ?>" data-status-url="<?= e(url('jobStatus.php')) ?>">
        <h2>OCR 任务状态</h2>
        <div class="proofread-meta">
            <span>任务 ID：<code><?= e((string) $job['id']) ?></code></span>
            <span data-field="status" class="status-pill status-<?= e((string) $job['status']) ?>"><?= e((string) $job['status']) ?></span>
            <span>退出码：<code data-field="exit_code"><?= e((string) ($job['exit_code'] ?? '-')) ?></code></span>
        </div>
        <div class="grid two">
            <div>
                <h3>产物</h3>
                <div data-field="artifacts">
                    <?php foreach (($job['artifacts'] ?? []) as $key => $value) : ?>
                        <?php if (is_string($value)) : ?>
                            <div><strong><?= e((string) $key) ?>:</strong> <code><?= e(relative_project_path($value)) ?></code></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="button-row">
                    <?php if (!empty($job['artifacts']['ocr_text'])) : ?>
                        <a class="button ghost" href="<?= e(url('translate.php', ['ocr_path' => (string) $job['artifacts']['ocr_text'], 'job_id' => (string) $job['id']])) ?>">带入翻译页</a>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <h3>日志</h3>
                <pre class="console-log" data-field="log"><?= e(tail_file((string) $job['log_file'], (int) app_config('max_log_bytes'))) ?></pre>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="panel">
    <h2>提交 OCR 任务</h2>
    <?php if ($error !== '') : ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <label>图片目录
            <input type="text" name="image_dir" list="image-dir-options" value="<?= e($defaultImageDir) ?>" placeholder="例如：php_app/runtime/workspaces/.../exported_jpg" required>
            <datalist id="image-dir-options">
                <?php foreach ($imageDirChoices as $path) : ?>
                    <option value="<?= e($path) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </label>
        <label>页码范围
            <input type="text" name="pages" value="1-1" placeholder="例如：7-56" required>
        </label>
        <label>合并输出文件名
            <input type="text" name="combined_name" value="<?= e($defaultCombinedName) ?>">
        </label>
        <div class="inline-fields">
            <label>模型名
                <input type="text" name="model" value="gemini-3.1-flash-lite">
            </label>
            <label>超时秒数
                <input type="number" name="request_timeout" min="30" value="300">
            </label>
            <label>重试次数
                <input type="number" name="retries" min="1" value="3">
            </label>
        </div>
        <div class="checkbox-list">
            <label><input type="checkbox" name="resume" value="1"> 断点续传</label>
            <label><input type="checkbox" name="no_consistency_check" value="1"> 跳过一致性检查</label>
        </div>
        <button type="submit">启动 OCR</button>
    </form>
</section>
<?php
});
