<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\ArtifactLocator;
use App\CombinedTextParser;
use App\JobStore;
use App\JpProofreadStore;
use App\PathGuard;
use App\WorkspaceManager;

$workspaceManager = new WorkspaceManager();
$jobStore = new JobStore((string) app_config('jobs_dir'));
$locator = new ArtifactLocator($jobStore, $workspaceManager);
$guard = new PathGuard();
$parser = new CombinedTextParser();
$jpStore = new JpProofreadStore($workspaceManager, $parser);

$error = '';
$projectId = get_string('project_id');
$ocrPath = get_string('ocr_path');
$projectChoices = $locator->recentProjects(20);
$payload = null;
$project = null;
$ocrChoices = [];

if ($projectId !== '') {
    $projectId = $workspaceManager->projectIdFromName($projectId);
    $project = $locator->projectArtifacts($projectId);
    if ($project) {
        $ocrChoices = array_values(array_filter(
            $locator->projectFiles($projectId, 'ocr_text'),
            static fn (string $path): bool => str_ends_with($path, '.txt') && !str_ends_with($path, '.jp.txt')
        ));
        if ($ocrPath !== '' && str_ends_with(basename($ocrPath), '.jp.txt')) {
            $base = basename($ocrPath);
            $candidate = $project['ocr_text'] . '/' . substr($base, 0, -7) . '.txt';
            $ocrPath = is_file($candidate) ? $candidate : '';
        }
        if ($ocrPath === '' && $ocrChoices !== []) {
            $ocrPath = $ocrChoices[0];
        }
    }
}

if ($projectId !== '' && $ocrPath !== '') {
    try {
        if (!$project) {
            throw new RuntimeException('项目不存在：' . $projectId);
        }
        $imageDir = $guard->assertDir($project['exported_jpg']);
        $ocrPath = $guard->assertFile($ocrPath);
        if (str_ends_with($ocrPath, '.jp.txt')) {
            throw new RuntimeException('请选择原始 OCR 合并文件（.txt），而不是日语校对文件（.jp.txt）。');
        }

        $ocrPages = $parser->parseFile($ocrPath);
        $ocrMap = [];
        foreach ($ocrPages as $page) {
            $ocrMap[$page['page']] = $page['text'];
        }
        $ocrNumbers = array_keys($ocrMap);
        sort($ocrNumbers);

        $bundle = $jpStore->loadBundle($projectId, $ocrPath);
        $jpMap = [];
        foreach ($bundle['pages'] as $page) {
            $jpMap[$page['page']] = $page['text'];
        }
        $jpNumbers = array_keys($jpMap);
        sort($jpNumbers);
        if ($ocrNumbers !== $jpNumbers) {
            throw new RuntimeException('OCR 与日语校对文件页码不一致，无法进入校对。');
        }

        $pages = [];
        foreach ($ocrNumbers as $pageNumber) {
            $imagePath = sprintf('%s/page_%04d.jpg', $imageDir, $pageNumber);
            if (!is_file($imagePath)) {
                throw new RuntimeException('图片目录缺少页面图片：' . $imagePath);
            }
            $pages[] = [
                'page' => $pageNumber,
                'image_url' => url('file.php', ['path' => $imagePath]),
                'ocr_text' => $ocrMap[$pageNumber],
                'proofread_text' => $jpMap[$pageNumber],
            ];
        }

        $payload = [
            'kind' => 'jp',
            'project_id' => $projectId,
            'project_root' => $project['root'],
            'image_dir' => $imageDir,
            'ocr_path' => $ocrPath,
            'jp_path' => $bundle['jp_path'],
            'completed_pages' => $bundle['completed_pages'],
            'pages' => $pages,
            'save_url' => url('saveJpProofread.php'),
        ];
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

render_page('日语校对', function () use ($error, $projectId, $ocrPath, $projectChoices, $ocrChoices, $payload): void {
?>
<section class="panel">
    <h2>加载日语校对数据</h2>
    <?php if ($error !== '') : ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="get" data-project-files-form data-project-files-url="<?= e(url('projectFiles.php')) ?>" data-ocr-files-key="raw_ocr_files">
        <label>项目 ID
            <input data-project-id-input type="text" name="project_id" list="project-id-options" value="<?= e($projectId) ?>" placeholder="例如：book_01" required>
            <datalist id="project-id-options">
                <?php foreach ($projectChoices as $item) : ?>
                    <option value="<?= e($item['id']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </label>
        <label>原始 OCR 合并文件
            <select data-ocr-path-input name="ocr_path" data-current-value="<?= e($ocrPath) ?>">
                <option value="">请选择 OCR 合并文件</option>
                <?php foreach ($ocrChoices as $path) : ?>
                    <option value="<?= e($path) ?>" <?= $ocrPath === $path ? 'selected' : '' ?>><?= e(basename($path)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">加载日语校对页</button>
    </form>
</section>

<?php if ($payload) : ?>
    <section class="panel">
        <h2>当前日语校对稿</h2>
        <div class="proofread-meta">
            <span>项目：<code><?= e($payload['project_id']) ?></code></span>
            <span>OCR：<code><?= e(basename((string) $payload['ocr_path'])) ?></code></span>
            <span>日语校对：<code><?= e(basename((string) $payload['jp_path'])) ?></code></span>
        </div>
        <div class="button-row">
            <a class="button ghost" href="<?= e(url('translate.php', ['project_id' => (string) $payload['project_id'], 'ocr_path' => (string) $payload['jp_path']])) ?>">进入翻译</a>
        </div>
    </section>

    <section class="panel" data-proofread-app>
        <script type="application/json"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
        <div class="proofread-meta">
            <span id="page-indicator" class="page-indicator">P1</span>
            <span id="page-counter">第 1 / 1 页</span>
            <label><input type="checkbox" id="completed-toggle"> 已校</label>
            <span id="proofread-status-text" class="muted"></span>
            <span id="save-message" class="small"></span>
        </div>
        <div class="button-row">
            <button type="button" class="secondary" id="prev-page">上一页</button>
            <button type="button" class="secondary" id="next-page">下一页</button>
            <button type="button" class="success" id="save-current">保存当前页</button>
            <button type="button" class="warn" id="save-all">保存全部</button>
        </div>
        <div class="proofread-layout proofread-layout-jp">
            <div class="proofread-image">
                <h3>图片</h3>
                <img id="proofread-image" alt="当前漫画页">
            </div>
            <div class="proofread-stack">
                <div>
                    <h3>日语校对稿</h3>
                    <textarea id="proofread-text" data-auto-resize="true"></textarea>
                </div>
                <div>
                    <h3>OCR 文本</h3>
                    <textarea id="ocr-text" readonly></textarea>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>
<?php
});
