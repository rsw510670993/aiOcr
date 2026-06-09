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
$locator = new ArtifactLocator($jobStore, $workspaceManager);
$guard = new PathGuard();
$error = '';

$defaultProjectId = get_string('project_id');
$defaultOcrPath = get_string('ocr_path');
$defaultJobId = get_string('job_id');

if (request_method() === 'POST') {
    try {
        $projectId = $workspaceManager->projectIdFromName(post_string('project_id'));
        $project = $workspaceManager->existingProjectPaths($projectId);
        $ocrPath = $guard->assertFile(post_string('ocr_path'));
        $pages = post_string('pages');
        $model = post_string('model', 'gemini-3.1-flash-lite');
        $timeout = max(30, (int) post_string('request_timeout', '300'));
        $retries = max(1, (int) post_string('retries', '3'));
        $perPage = post_bool('per_page');
        $resume = post_bool('resume');
        $keepPageFiles = post_bool('keep_page_files');
        $combinedName = trim(post_string('combined_name'));
        if ($combinedName === '') {
            $combinedName = basename($ocrPath);
        }
        $combinedName = $workspaceManager->safeFilename($combinedName, basename($ocrPath));
        if (!str_ends_with($combinedName, '.txt')) {
            $combinedName .= '.txt';
        }

        $job = $jobStore->create('translate', [
            'project_id' => $projectId,
            'ocr_path' => $ocrPath,
            'pages' => $pages,
            'model' => $model,
            'request_timeout' => $timeout,
            'retries' => $retries,
            'per_page' => $perPage,
        ]);
        $combinedOutput = $project['aigc2d_translation_text'] . '/' . $combinedName;
        $artifacts = [
            'project_id' => $projectId,
            'project_root' => $project['root'],
            'image_dir' => $project['exported_jpg'],
            'ocr_text' => $ocrPath,
            'translation_text' => $combinedOutput,
        ];
        $job = $jobStore->merge($job, ['artifacts' => $artifacts]);

        $scriptPath = rtrim((string) app_config('project_root'), '/') . '/aigc2d_translate.py';
        $command = escapeshellarg((string) app_config('python_bin'))
            . ' ' . escapeshellarg($scriptPath)
            . ' ' . escapeshellarg($ocrPath)
            . ' --combined-output ' . escapeshellarg($combinedOutput)
            . ' --model ' . escapeshellarg($model)
            . ' --request-timeout ' . $timeout
            . ' --retries ' . $retries;
        if ($pages !== '') {
            $command .= ' --pages ' . escapeshellarg($pages);
        }
        if ($perPage) {
            $command .= ' --per-page';
        }
        if ($resume) {
            $command .= ' --resume';
        }
        if ($keepPageFiles) {
            $command .= ' --keep-page-files';
        }

        $runner->start($job, $command, (string) app_config('project_root'), $artifacts);
        redirect_to('translate.php', ['job_id' => $job['id'], 'project_id' => $projectId, 'ocr_path' => $ocrPath]);
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

$job = null;
$jobId = get_string('job_id', $defaultJobId);
if ($jobId !== '') {
    $job = $jobStore->require($jobId);
    $defaultProjectId = $defaultProjectId !== '' ? $defaultProjectId : (string) (($job['artifacts']['project_id'] ?? '') ?: ($job['params']['project_id'] ?? ''));
    $defaultOcrPath = $defaultOcrPath !== '' ? $defaultOcrPath : (string) (($job['artifacts']['ocr_text'] ?? '') ?: ($job['params']['ocr_path'] ?? ''));
}
$projectChoices = $locator->recentProjects(20);
$ocrChoices = $defaultProjectId !== '' ? $locator->projectFiles($defaultProjectId, 'ocr_text') : [];
$project = $defaultProjectId !== '' ? $locator->projectArtifacts($defaultProjectId) : null;
if ($defaultOcrPath === '' && $ocrChoices !== []) {
    $defaultOcrPath = $ocrChoices[0];
}

render_page('翻译', function () use ($error, $job, $projectChoices, $defaultProjectId, $defaultOcrPath, $ocrChoices, $project): void {
?>
<?php if ($job) : ?>
    <section class="panel" data-job-status data-job-id="<?= e((string) $job['id']) ?>" data-status-url="<?= e(url('jobStatus.php')) ?>">
        <h2>翻译任务状态</h2>
        <div class="proofread-meta">
            <span>任务 ID：<code><?= e((string) $job['id']) ?></code></span>
            <span>项目：<code><?= e((string) ($job['artifacts']['project_id'] ?? $defaultProjectId)) ?></code></span>
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
                    <?php if (!empty($job['artifacts']['project_id']) && !empty($job['artifacts']['translation_text'])) : ?>
                        <a class="button ghost" href="<?= e(url('proofread.php', ['project_id' => (string) $job['artifacts']['project_id'], 'translation_path' => (string) $job['artifacts']['translation_text'], 'ocr_path' => (string) ($job['artifacts']['ocr_text'] ?? '')])) ?>">进入校对</a>
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
    <h2>提交翻译任务</h2>
    <?php if ($error !== '') : ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <label>项目 ID
            <input type="text" name="project_id" list="project-id-options" value="<?= e($defaultProjectId) ?>" placeholder="例如：book_01" required>
            <datalist id="project-id-options">
                <?php foreach ($projectChoices as $item) : ?>
                    <option value="<?= e($item['id']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </label>
        <?php if ($project) : ?>
            <div class="alert">
                图片目录：<code><?= e(relative_project_path($project['exported_jpg'])) ?></code><br>
                译文输出目录：<code><?= e(relative_project_path($project['aigc2d_translation_text'])) ?></code>
            </div>
        <?php endif; ?>
        <label>OCR 合并文件
            <input type="text" name="ocr_path" list="ocr-path-options" value="<?= e($defaultOcrPath) ?>" placeholder="请选择项目内 OCR 文件" required>
            <datalist id="ocr-path-options">
                <?php foreach ($ocrChoices as $path) : ?>
                    <option value="<?= e($path) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </label>
        <label>页码范围（可选）
            <input type="text" name="pages" placeholder="例如：7-56，不填则使用全部页码">
        </label>
        <label>译文输出文件名
            <input type="text" name="combined_name" placeholder="默认与 OCR 文件同名">
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
            <label><input type="checkbox" name="per_page" value="1"> 逐页模式</label>
            <label><input type="checkbox" name="resume" value="1"> 断点续传</label>
            <label><input type="checkbox" name="keep_page_files" value="1"> 保留逐页文件</label>
        </div>
        <button type="submit">启动翻译</button>
    </form>
</section>
<?php
});
