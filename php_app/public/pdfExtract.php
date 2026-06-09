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
$locator = new ArtifactLocator($jobStore, $workspaceManager);
$error = '';
$success = '';

$writeLog = static function (array $job, string $content): void {
    file_put_contents((string) $job['log_file'], trim($content) . PHP_EOL, FILE_APPEND);
};

$deleteTree = static function (string $path): void {
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
};

$clearDirectory = static function (string $path): void {
    if (!is_dir($path)) {
        return;
    }
    $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            $deleteTree($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
};

$extractZipImages = static function (string $zipPath, string $targetDir, string $tempDir) use ($deleteTree): int {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('无法打开 ZIP 文件。');
    }
    if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
        $zip->close();
        throw new RuntimeException('无法创建 ZIP 临时目录。');
    }
    if (!$zip->extractTo($tempDir)) {
        $zip->close();
        $deleteTree($tempDir);
        throw new RuntimeException('ZIP 解压失败。');
    }
    $zip->close();

    $images = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $extension = strtolower($file->getExtension());
        if (in_array($extension, ['jpg', 'jpeg'], true)) {
            $images[] = $file->getPathname();
        }
    }

    sort($images, SORT_NATURAL | SORT_FLAG_CASE);
    if ($images === []) {
        $deleteTree($tempDir);
        throw new RuntimeException('ZIP 中未找到 JPG/JPEG 图片。');
    }

    $index = 1;
    foreach ($images as $imagePath) {
        $destination = sprintf('%s/page_%04d.jpg', $targetDir, $index);
        if (!copy($imagePath, $destination)) {
            $deleteTree($tempDir);
            throw new RuntimeException('无法写入导出图片：' . $destination);
        }
        $index++;
    }

    $deleteTree($tempDir);
    return count($images);
};

$resetProjectImages = static function (array $project) use ($clearDirectory): void {
    foreach (['exported_jpg', 'ocr_text', 'aigc2d_translation_text', 'proofread_text'] as $key) {
        if (!empty($project[$key]) && is_string($project[$key])) {
            $clearDirectory($project[$key]);
        }
    }
};

if (request_method() === 'POST') {
    try {
        $action = post_string('action', 'import_source');
        $postedProjectId = $workspaceManager->projectIdFromName(post_string('project_name'));

        if ($action === 'create_empty') {
            if ($postedProjectId === '') {
                throw new RuntimeException('请填写项目名称。');
            }
            if ($workspaceManager->projectExists($postedProjectId)) {
                throw new RuntimeException('项目已存在，请更换项目名称：' . $postedProjectId);
            }
            $workspaceManager->ensureProject($postedProjectId);
            $success = '空项目已创建：' . $postedProjectId;
            redirect_to('pdfExtract.php', ['project_id' => $postedProjectId, 'success' => $success]);
        }

        if ($action === 'delete_image') {
            $projectId = $workspaceManager->projectIdFromName(post_string('project_id'));
            $imageName = basename(post_string('image_name'));
            if ($projectId === '' || $imageName === '') {
                throw new RuntimeException('缺少项目或图片名称。');
            }
            $project = $workspaceManager->existingProjectPaths($projectId);
            $imagePath = $project['exported_jpg'] . '/' . $imageName;
            if (!is_file($imagePath)) {
                throw new RuntimeException('图片不存在：' . $imageName);
            }
            if (!@unlink($imagePath)) {
                throw new RuntimeException('无法删除图片：' . $imageName);
            }
            redirect_to('pdfExtract.php', ['project_id' => $projectId, 'success' => '已删除图片：' . $imageName]);
        }

        if (!isset($_FILES['source']) || !is_array($_FILES['source'])) {
            throw new RuntimeException('请上传 PDF 或 ZIP 文件。');
        }
        $upload = $_FILES['source'];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('文件上传失败，错误码：' . (string) ($upload['error'] ?? 'unknown'));
        }

        $originalName = (string) ($upload['name'] ?? 'source');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['pdf', 'zip'], true)) {
            throw new RuntimeException('只允许上传 PDF 或 ZIP 文件。');
        }

        $projectName = post_string('project_name', pathinfo($originalName, PATHINFO_FILENAME));
        $projectId = $workspaceManager->projectIdFromName($projectName);
        $isReimport = $action === 'reimport_source';
        if ($projectId === '') {
            throw new RuntimeException('请填写项目名称。');
        }
        if ($isReimport) {
            if (!$workspaceManager->projectExists($projectId)) {
                throw new RuntimeException('要重新导入的项目不存在：' . $projectId);
            }
        } elseif ($workspaceManager->projectExists($projectId)) {
            throw new RuntimeException('项目已存在，请更换项目名称或使用重新导入。');
        }
        $project = $workspaceManager->ensureProject($projectId);
        if ($isReimport) {
            $resetProjectImages($project);
        }

        $jobType = $isReimport ? 'project_reimport' : 'project_setup';
        $job = $jobStore->create($jobType, [
            'project_id' => $projectId,
            'source_name' => $originalName,
            'source_type' => $extension,
        ], [
            'project_id' => $projectId,
            'project_root' => $project['root'],
            'image_dir' => $project['exported_jpg'],
        ]);

        if ($extension === 'pdf') {
            $dpi = max(36, min(600, (int) post_string('dpi', '150')));
            $quality = max(1, min(100, (int) post_string('quality', '90')));
            $password = post_string('password');
            $pdfPath = $workspaceManager->uploadPdfPath($projectId, $originalName);
            if (!move_uploaded_file((string) $upload['tmp_name'], $pdfPath)) {
                throw new RuntimeException('无法保存上传的 PDF 文件。');
            }

            $artifacts = [
                'project_id' => $projectId,
                'project_root' => $project['root'],
                'pdf' => $pdfPath,
                'image_dir' => $project['exported_jpg'],
                'exported_jpg' => $project['exported_jpg'],
            ];
            $job = $jobStore->merge($job, ['artifacts' => $artifacts]);

            if ($isReimport) {
                $writeLog($job, '已清空项目图片与衍生文本目录，开始重新提取 PDF。');
            }

            $scriptPath = rtrim((string) app_config('project_root'), '/') . '/pdf_to_jpg.py';
            $command = escapeshellarg((string) app_config('python_bin'))
                . ' ' . escapeshellarg($scriptPath)
                . ' ' . escapeshellarg($pdfPath)
                . ' --output ' . escapeshellarg($project['exported_jpg'])
                . ' --dpi ' . $dpi
                . ' --quality ' . $quality;
            if ($password !== '') {
                $command .= ' --password ' . escapeshellarg($password);
            }

            $runner->start($job, $command, (string) app_config('project_root'), $artifacts);
            redirect_to('pdfExtract.php', ['job_id' => $job['id'], 'project_id' => $projectId]);
        }

        $zipPath = $workspaceManager->uploadZipPath($projectId, $originalName);
        if (!move_uploaded_file((string) $upload['tmp_name'], $zipPath)) {
            throw new RuntimeException('无法保存上传的 ZIP 文件。');
        }

        $job = $jobStore->merge($job, [
            'status' => 'running',
            'started_at' => date(DATE_ATOM),
            'artifacts' => array_replace($job['artifacts'] ?? [], [
                'source_zip' => $zipPath,
                'exported_jpg' => $project['exported_jpg'],
            ]),
        ]);
        if ($isReimport) {
            $writeLog($job, '已清空项目图片与衍生文本目录，开始重新解压 ZIP。');
        }
        $writeLog($job, '开始解压 ZIP 到项目目录：' . $project['root']);
        $tempDir = $project['root'] . '/__zip_extract';
        $imageCount = $extractZipImages($zipPath, $project['exported_jpg'], $tempDir);
        $writeLog($job, 'ZIP 导入完成，共写入 ' . $imageCount . ' 张图片到 ' . $project['exported_jpg']);
        $jobStore->merge($job, [
            'status' => 'success',
            'finished_at' => date(DATE_ATOM),
            'exit_code' => 0,
            'artifacts' => array_replace($job['artifacts'] ?? [], [
                'project_id' => $projectId,
                'project_root' => $project['root'],
                'image_dir' => $project['exported_jpg'],
                'exported_jpg' => $project['exported_jpg'],
                'image_count' => (string) $imageCount,
            ]),
        ]);
        redirect_to('pdfExtract.php', ['job_id' => $job['id'], 'project_id' => $projectId]);
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

$job = null;
$jobId = get_string('job_id');
$projectId = get_string('project_id');
$success = get_string('success');
if ($jobId !== '') {
    $job = $jobStore->require($jobId);
    $projectId = $projectId !== '' ? $projectId : (string) (($job['artifacts']['project_id'] ?? '') ?: ($job['params']['project_id'] ?? ''));
}
$recentProjects = $locator->recentProjects(12);
$selectedProject = $projectId !== '' ? $locator->projectArtifacts($projectId) : null;
$projectImages = $projectId !== '' ? $locator->projectImages($projectId) : [];

render_page('创建项目', function () use ($error, $success, $job, $projectId, $recentProjects, $selectedProject, $projectImages): void {
?>
<?php if ($job) : ?>
    <section class="panel" data-job-status data-job-id="<?= e((string) $job['id']) ?>" data-status-url="<?= e(url('jobStatus.php')) ?>">
        <h2>项目任务状态</h2>
        <div class="proofread-meta">
            <span>任务 ID：<code><?= e((string) $job['id']) ?></code></span>
            <span>项目：<code><?= e((string) ($job['artifacts']['project_id'] ?? $projectId)) ?></code></span>
            <span data-field="status" class="status-pill status-<?= e((string) $job['status']) ?>"><?= e((string) $job['status']) ?></span>
            <span>退出码：<code data-field="exit_code"><?= e((string) ($job['exit_code'] ?? '-')) ?></code></span>
        </div>
        <div class="grid two">
            <div>
                <h3>产物</h3>
                <div data-field="artifacts">
                    <?php foreach (($job['artifacts'] ?? []) as $key => $value) : ?>
                        <?php if (is_scalar($value) && (string) $value !== '') : ?>
                            <div><strong><?= e((string) $key) ?>:</strong> <code><?= e(relative_project_path((string) $value)) ?></code></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="button-row">
                    <?php if (!empty($job['artifacts']['project_id'])) : ?>
                        <a class="button ghost" href="<?= e(url('ocr.php', ['project_id' => (string) $job['artifacts']['project_id']])) ?>">进入 OCR</a>
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

<section class="grid two">
    <div class="panel">
        <h2>创建项目</h2>
        <?php if ($error !== '') : ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== '') : ?>
            <div class="alert success"><?= e($success) ?></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <label>项目名称
                <input type="text" name="project_name" value="<?= e($projectId) ?>" placeholder="例如：book_01">
            </label>
            <label>上传源文件（PDF 或 ZIP）
                <input type="file" name="source" accept="application/pdf,.zip,application/zip">
            </label>
            <div class="inline-fields">
                <label>DPI（仅 PDF 生效）
                    <input type="number" name="dpi" min="36" max="600" value="150">
                </label>
                <label>JPEG 质量（仅 PDF 生效）
                    <input type="number" name="quality" min="1" max="100" value="90">
                </label>
            </div>
            <label>PDF 密码（仅 PDF 生效）
                <input type="text" name="password" placeholder="有密码时填写">
            </label>
            <div class="button-row">
                <button type="submit" name="action" value="create_empty" class="secondary">创建空项目</button>
                <button type="submit" name="action" value="import_source">创建并导入</button>
                <?php if ($selectedProject) : ?>
                    <button type="submit" name="action" value="reimport_source" class="warn">重新导入并清空旧图</button>
                <?php endif; ?>
            </div>
        </form>
        <div class="alert">
            <strong>规则</strong><br>
            允许先创建空项目；上传 PDF 会提取到项目的 `exported_jpg/`；上传文件夹 ZIP 会把其中 JPG/JPEG 图片整理为 `page_0001.jpg` 风格文件放入 `exported_jpg/`；重新导入时会先清空现有图片以及 OCR/翻译/校对文本目录。
        </div>
    </div>

    <div class="panel">
        <h2>最近项目</h2>
        <ul class="list-reset">
            <?php foreach ($recentProjects as $project) : ?>
                <li class="job-card">
                    <strong><?= e($project['id']) ?></strong>
                    <div><code><?= e(relative_project_path($project['path'])) ?></code></div>
                    <div class="button-row">
                        <a class="button ghost" href="<?= e(url('pdfExtract.php', ['project_id' => $project['id']])) ?>">管理项目</a>
                        <a class="button ghost" href="<?= e(url('ocr.php', ['project_id' => $project['id']])) ?>">OCR</a>
                        <a class="button ghost" href="<?= e(url('translate.php', ['project_id' => $project['id']])) ?>">翻译</a>
                        <a class="button ghost" href="<?= e(url('proofread.php', ['project_id' => $project['id']])) ?>">校对</a>
                    </div>
                </li>
            <?php endforeach; ?>
            <?php if ($recentProjects === []) : ?><li class="muted">暂无项目。</li><?php endif; ?>
        </ul>
    </div>
</section>

<?php if ($selectedProject) : ?>
<section class="panel">
    <h2>项目图片管理</h2>
    <div class="proofread-meta">
        <span>项目：<code><?= e($selectedProject['project_id']) ?></code></span>
        <span>图片目录：<code><?= e(relative_project_path($selectedProject['exported_jpg'])) ?></code></span>
        <span>图片数量：<strong><?= count($projectImages) ?></strong></span>
    </div>
    <?php if ($projectImages === []) : ?>
        <div class="alert">当前项目还没有图片。你可以手动上传图片到服务器目录，或使用上方“创建并导入 / 重新导入并清空旧图”。</div>
    <?php else : ?>
        <div class="image-grid">
            <?php foreach ($projectImages as $imagePath) : ?>
                <div class="image-card">
                    <div class="image-card-preview">
                        <img src="<?= e(url('file.php', ['path' => $imagePath])) ?>" alt="<?= e(basename($imagePath)) ?>">
                    </div>
                    <div class="image-card-meta">
                        <strong><?= e(basename($imagePath)) ?></strong>
                        <span class="small muted"><?= e(format_bytes((int) filesize($imagePath))) ?></span>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="delete_image">
                        <input type="hidden" name="project_id" value="<?= e($selectedProject['project_id']) ?>">
                        <input type="hidden" name="image_name" value="<?= e(basename($imagePath)) ?>">
                        <button type="submit" class="warn">删除图片</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php
});
