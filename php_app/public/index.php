<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\ArtifactLocator;
use App\JobStore;
use App\WorkspaceManager;

$workspaceManager = new WorkspaceManager();
$jobStore = new JobStore((string) app_config('jobs_dir'));
$locator = new ArtifactLocator($jobStore, $workspaceManager);
$jobs = $locator->recentJobs(null, 12);
$projects = $locator->recentProjects(12);

render_page('主页', function () use ($jobs, $projects): void {
?>
<section class="panel">
    <h2>流程入口</h2>
    <p class="muted">当前版本以 `projects/项目名/` 为唯一工作目录，项目内统一管理 `exported_jpg`、`ocr_text`、`aigc2d_translation_text` 与 `proofread_text`。</p>
    <div class="grid three">
        <div class="job-card">
            <h3>1. 环境准备</h3>
            <p>检查 `.venv`、`aigc2d.key`、`名词表.csv`、`PyMuPDF` 与目录权限是否就绪。</p>
            <a class="button" href="<?= e(url('preflight.php')) ?>">打开准备页</a>
        </div>
        <div class="job-card">
            <h3>2. 创建项目</h3>
            <p>上传 PDF 或文件夹 ZIP，自动把页面图片整理到项目的 `exported_jpg/`。</p>
            <a class="button" href="<?= e(url('pdfExtract.php')) ?>">打开创建页</a>
        </div>
        <div class="job-card">
            <h3>3. OCR 识别</h3>
            <p>按项目执行 `aigc2d_ocr.py`，输出合并 OCR 文本到项目目录。</p>
            <a class="button" href="<?= e(url('ocr.php')) ?>">打开 OCR 页</a>
        </div>
        <div class="job-card">
            <h3>4. 日语校对</h3>
            <p>对 OCR 日文原文逐页校对，输出 `.jp.txt` 文件。</p>
            <a class="button" href="<?= e(url('jpProofread.php')) ?>">打开日语校对页</a>
        </div>
        <div class="job-card">
            <h3>5. 翻译</h3>
            <p>按项目选择日语校对后的 OCR 文件，调用 `aigc2d_translate.py` 输出译文。</p>
            <a class="button" href="<?= e(url('translate.php')) ?>">打开翻译页</a>
        </div>
        <div class="job-card">
            <h3>6. 校对</h3>
            <p>基于项目加载图片、日语校对稿、译文，按页人工校对并保存定稿。</p>
            <a class="button" href="<?= e(url('proofread.php')) ?>">打开校对页</a>
        </div>
    </div>
</section>

<section class="grid two">
    <div class="panel">
        <h2>最近项目</h2>
        <ul class="list-reset">
            <?php foreach ($projects as $project) : ?>
                <li class="job-card">
                    <strong><?= e($project['id']) ?></strong>
                    <div><code><?= e(relative_project_path($project['path'])) ?></code></div>
                    <div class="button-row">
                        <a class="button ghost" href="<?= e(url('ocr.php', ['project_id' => $project['id']])) ?>">OCR</a>
                        <a class="button ghost" href="<?= e(url('jpProofread.php', ['project_id' => $project['id']])) ?>">日语校对</a>
                        <a class="button ghost" href="<?= e(url('translate.php', ['project_id' => $project['id']])) ?>">翻译</a>
                        <a class="button ghost" href="<?= e(url('proofread.php', ['project_id' => $project['id']])) ?>">校对</a>
                    </div>
                </li>
            <?php endforeach; ?>
            <?php if ($projects === []) : ?><li class="muted">还没有项目记录。</li><?php endif; ?>
        </ul>
    </div>
    <div class="panel">
        <h2>最近任务</h2>
        <?php if ($jobs === []) : ?>
            <p class="muted">还没有任务记录。</p>
        <?php else : ?>
            <table>
                <thead>
                <tr>
                    <th>任务</th>
                    <th>状态</th>
                    <th>创建时间</th>
                    <th>项目</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $job) : ?>
                    <tr>
                        <td>
                            <strong><?= e((string) $job['type']) ?></strong><br>
                            <span class="small"><?= e((string) $job['id']) ?></span>
                        </td>
                        <td><span class="status-pill status-<?= e((string) $job['status']) ?>"><?= e((string) $job['status']) ?></span></td>
                        <td><?= e(format_time($job['created_at'] ?? null)) ?></td>
                        <td><code><?= e((string) (($job['artifacts']['project_id'] ?? '') ?: ($job['params']['project_id'] ?? '-'))) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>
<?php
});
