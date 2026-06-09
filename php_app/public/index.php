<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\ArtifactLocator;
use App\JobStore;

$jobStore = new JobStore((string) app_config('jobs_dir'));
$locator = new ArtifactLocator($jobStore);
$jobs = $locator->recentJobs(null, 12);
$imageDirs = $locator->recentArtifactPaths('exported_jpg');
$ocrFiles = $locator->recentArtifactPaths('ocr_text');
$translationFiles = $locator->recentArtifactPaths('translation_text');

render_page('主页', function () use ($jobs, $imageDirs, $ocrFiles, $translationFiles): void {
?>
<section class="panel">
    <h2>流程入口</h2>
    <p class="muted">当前版本只接入 AIGC2D 远程 OCR 与翻译链路，PHP 负责上传、启动任务、查看日志与人工校对。</p>
    <div class="grid three">
        <div class="job-card">
            <h3>1. 环境准备</h3>
            <p>检查 `.venv`、`aigc2d.key`、`名词表.csv`、`PyMuPDF` 与运行目录是否就绪。</p>
            <a class="button" href="<?= e(url('preflight.php')) ?>">打开准备页</a>
        </div>
        <div class="job-card">
            <h3>2. PDF 提取</h3>
            <p>上传 PDF，调用 `pdf_to_jpg.py` 输出 `page_0001.jpg` 风格图片。</p>
            <a class="button" href="<?= e(url('pdfExtract.php')) ?>">打开提取页</a>
        </div>
        <div class="job-card">
            <h3>3. OCR 识别</h3>
            <p>选择图片目录，调用 `aigc2d_ocr.py` 生成带 `P{页码}` 的合并 OCR 文本。</p>
            <a class="button" href="<?= e(url('ocr.php')) ?>">打开 OCR 页</a>
        </div>
        <div class="job-card">
            <h3>4. 翻译</h3>
            <p>选择 OCR 合并文件，调用 `aigc2d_translate.py` 生成译文。</p>
            <a class="button" href="<?= e(url('translate.php')) ?>">打开翻译页</a>
        </div>
        <div class="job-card">
            <h3>5. 校对</h3>
            <p>按页对照图片、OCR 与译文，并把人工修订写入单独校对稿。</p>
            <a class="button" href="<?= e(url('proofread.php')) ?>">打开校对页</a>
        </div>
    </div>
</section>

<section class="grid two">
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
                    <th>主要产物</th>
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
                        <td>
                            <?php foreach (($job['artifacts'] ?? []) as $key => $value) : ?>
                                <?php if (is_string($value) && $value !== '') : ?>
                                    <div><strong><?= e((string) $key) ?>:</strong> <code><?= e(relative_project_path($value)) ?></code></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <div class="panel">
        <h2>最近产物</h2>
        <div class="grid">
            <div>
                <h3>图片目录</h3>
                <ul class="list-reset">
                    <?php foreach ($imageDirs as $path) : ?>
                        <li><code><?= e(relative_project_path($path)) ?></code></li>
                    <?php endforeach; ?>
                    <?php if ($imageDirs === []) : ?><li class="muted">暂无。</li><?php endif; ?>
                </ul>
            </div>
            <div>
                <h3>OCR 文件</h3>
                <ul class="list-reset">
                    <?php foreach ($ocrFiles as $path) : ?>
                        <li><code><?= e(relative_project_path($path)) ?></code></li>
                    <?php endforeach; ?>
                    <?php if ($ocrFiles === []) : ?><li class="muted">暂无。</li><?php endif; ?>
                </ul>
            </div>
            <div>
                <h3>译文文件</h3>
                <ul class="list-reset">
                    <?php foreach ($translationFiles as $path) : ?>
                        <li><code><?= e(relative_project_path($path)) ?></code></li>
                    <?php endforeach; ?>
                    <?php if ($translationFiles === []) : ?><li class="muted">暂无。</li><?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</section>
<?php
});
