<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\ArtifactLocator;
use App\JobStore;
use App\ProcessRunner;
use App\WorkspaceManager;

$jobStore = new JobStore((string) app_config('jobs_dir'));
$workspaceManager = new WorkspaceManager();
$runner = new ProcessRunner($jobStore);
$locator = new ArtifactLocator($jobStore);
$error = '';

if (request_method() === 'POST') {
    try {
        if (!isset($_FILES['pdf']) || !is_array($_FILES['pdf'])) {
            throw new RuntimeException('请上传 PDF 文件。');
        }
        $upload = $_FILES['pdf'];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('PDF 上传失败，错误码：' . (string) ($upload['error'] ?? 'unknown'));
        }
        $originalName = (string) ($upload['name'] ?? 'source.pdf');
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new RuntimeException('只允许上传 PDF 文件。');
        }

        $dpi = max(36, min(600, (int) post_string('dpi', '150')));
        $quality = max(1, min(100, (int) post_string('quality', '90')));
        $password = post_string('password');
        $taskName = post_string('task_name', pathinfo($originalName, PATHINFO_FILENAME));

        $job = $jobStore->create('pdf_extract', [
            'task_name' => $taskName,
            'source_name' => $originalName,
            'dpi' => $dpi,
            'quality' => $quality,
        ]);
        $workspace = $workspaceManager->ensureJobWorkspace((string) $job['id']);
        $pdfPath = $workspaceManager->uploadPdfPath((string) $job['id']);
        if (!move_uploaded_file((string) $upload['tmp_name'], $pdfPath)) {
            throw new RuntimeException('无法保存上传的 PDF 文件。');
        }

        $artifacts = [
            'pdf' => $pdfPath,
            'exported_jpg' => $workspace['exported_jpg'],
        ];
        $job = $jobStore->merge($job, ['artifacts' => $artifacts]);

        $command = escapeshellarg((string) app_config('python_bin'))
            . ' ' . escapeshellarg('/workspace/pdf_to_jpg.py')
            . ' ' . escapeshellarg($pdfPath)
            . ' --output ' . escapeshellarg($workspace['exported_jpg'])
            . ' --dpi ' . $dpi
            . ' --quality ' . $quality;
        if ($password !== '') {
            $command .= ' --password ' . escapeshellarg($password);
        }

        $runner->start($job, $command, (string) app_config('project_root'), $artifacts);
        redirect_to('pdf_extract.php', ['job_id' => $job['id']]);
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

$job = null;
$jobId = get_string('job_id');
if ($jobId !== '') {
    $job = $jobStore->require($jobId);
}
$recentJobs = $locator->recentJobs('pdf_extract');

render_page('PDF 提取', function () use ($error, $job, $recentJobs): void {
?>
<section class="grid two">
    <div class="panel">
        <h2>上传 PDF</h2>
        <?php if ($error !== '') : ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <label>任务名称
                <input type="text" name="task_name" placeholder="例如：第01卷提取任务">
            </label>
            <label>PDF 文件
                <input type="file" name="pdf" accept="application/pdf" required>
            </label>
            <label>PDF 密码（可选）
                <input type="text" name="password" placeholder="有密码时填写">
            </label>
            <div class="inline-fields">
                <label>DPI
                    <input type="number" name="dpi" min="36" max="600" value="150">
                </label>
                <label>JPEG 质量
                    <input type="number" name="quality" min="1" max="100" value="90">
                </label>
            </div>
            <button type="submit">启动 PDF 提取</button>
        </form>
    </div>

    <div class="panel">
        <h2>最近 PDF 提取任务</h2>
        <ul class="list-reset">
            <?php foreach ($recentJobs as $recentJob) : ?>
                <li class="job-card">
                    <strong><?= e((string) $recentJob['id']) ?></strong>
                    <div><span class="status-pill status-<?= e((string) $recentJob['status']) ?>"><?= e((string) $recentJob['status']) ?></span></div>
                    <div class="small"><?= e(format_time($recentJob['created_at'] ?? null)) ?></div>
                    <div><a href="<?= e(url('pdf_extract.php', ['job_id' => $recentJob['id']])) ?>">查看任务</a></div>
                </li>
            <?php endforeach; ?>
            <?php if ($recentJobs === []) : ?><li class="muted">暂无记录。</li><?php endif; ?>
        </ul>
    </div>
</section>

<?php if ($job) : ?>
    <section class="panel" data-job-status data-job-id="<?= e((string) $job['id']) ?>" data-status-url="<?= e(url('job_status.php')) ?>">
        <h2>任务状态</h2>
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
                    <?php if (!empty($job['artifacts']['exported_jpg'])) : ?>
                        <a class="button ghost" href="<?= e(url('ocr.php', ['image_dir' => (string) $job['artifacts']['exported_jpg']])) ?>">带入 OCR 页</a>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <h3>日志</h3>
                <pre data-field="log"><?= e(tail_file((string) $job['log_file'], (int) app_config('max_log_bytes'))) ?></pre>
            </div>
        </div>
    </section>
<?php endif; ?>
<?php
});
