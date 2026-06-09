<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\ArtifactLocator;
use App\CombinedTextParser;
use App\JobStore;
use App\PathGuard;
use App\ProofreadStore;
use App\WorkspaceManager;

$workspaceManager = new WorkspaceManager();
$jobStore = new JobStore((string) app_config('jobs_dir'));
$locator = new ArtifactLocator($jobStore, $workspaceManager);
$guard = new PathGuard();
$parser = new CombinedTextParser();
$proofreadStore = new ProofreadStore($workspaceManager, $parser);

$error = '';
$projectId = get_string('project_id');
$ocrPath = get_string('ocr_path');
$translationPath = get_string('translation_path');
$projectChoices = $locator->recentProjects(20);
$proofreadPayload = null;
$project = null;
$ocrChoices = [];
$translationChoices = [];

if ($projectId !== '') {
    $projectId = $workspaceManager->projectIdFromName($projectId);
    $project = $locator->projectArtifacts($projectId);
    if ($project) {
        $ocrChoices = $locator->projectFiles($projectId, 'ocr_text');
        $translationChoices = $locator->projectFiles($projectId, 'aigc2d_translation_text');
        if ($ocrPath === '' && $ocrChoices !== []) {
            $ocrPath = $ocrChoices[0];
        }
        if ($translationPath === '' && $translationChoices !== []) {
            $translationPath = $translationChoices[0];
        }
    }
}

if ($projectId !== '' && $ocrPath !== '' && $translationPath !== '') {
    try {
        if (!$project) {
            throw new RuntimeException('项目不存在：' . $projectId);
        }
        $imageDir = $guard->assertDir($project['exported_jpg']);
        $ocrPath = $guard->assertFile($ocrPath);
        $translationPath = $guard->assertFile($translationPath);

        $ocrPages = $parser->parseFile($ocrPath);
        $translationPages = $parser->parseFile($translationPath);
        $ocrMap = [];
        foreach ($ocrPages as $page) {
            $ocrMap[$page['page']] = $page['text'];
        }
        $translationMap = [];
        foreach ($translationPages as $page) {
            $translationMap[$page['page']] = $page['text'];
        }
        $ocrNumbers = array_keys($ocrMap);
        $translationNumbers = array_keys($translationMap);
        sort($ocrNumbers);
        sort($translationNumbers);
        if ($ocrNumbers !== $translationNumbers) {
            throw new RuntimeException('OCR 与翻译文件页码不一致，无法进入校对。');
        }

        $bundle = $proofreadStore->loadBundle($projectId, $translationPath);
        $proofreadMap = [];
        foreach ($bundle['pages'] as $page) {
            $proofreadMap[$page['page']] = $page['text'];
        }

        $pages = [];
        foreach ($translationNumbers as $pageNumber) {
            $imagePath = sprintf('%s/page_%04d.jpg', $imageDir, $pageNumber);
            if (!is_file($imagePath)) {
                throw new RuntimeException('图片目录缺少页面图片：' . $imagePath);
            }
            $pages[] = [
                'page' => $pageNumber,
                'image_url' => url('file.php', ['path' => $imagePath]),
                'ocr_text' => $ocrMap[$pageNumber],
                'translation_text' => $translationMap[$pageNumber],
                'proofread_text' => $proofreadMap[$pageNumber] ?? $translationMap[$pageNumber],
            ];
        }

        $proofreadPayload = [
            'project_id' => $projectId,
            'project_root' => $project['root'],
            'image_dir' => $imageDir,
            'ocr_path' => $ocrPath,
            'translation_path' => $translationPath,
            'proofread_path' => $bundle['proofread_path'],
            'completed_pages' => $bundle['completed_pages'],
            'pages' => $pages,
            'save_url' => url('saveProofread.php'),
        ];
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

render_page('校对', function () use ($error, $projectId, $ocrPath, $translationPath, $projectChoices, $ocrChoices, $translationChoices, $proofreadPayload): void {
?>
<section class="panel">
    <h2>加载校对数据</h2>
    <?php if ($error !== '') : ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="get">
        <label>项目 ID
            <input type="text" name="project_id" list="project-id-options" value="<?= e($projectId) ?>" placeholder="例如：book_01" required>
            <datalist id="project-id-options">
                <?php foreach ($projectChoices as $item) : ?>
                    <option value="<?= e($item['id']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </label>
        <div class="inline-fields">
            <label>OCR 合并文件
                <input type="text" name="ocr_path" list="ocr-path-options" value="<?= e($ocrPath) ?>">
                <datalist id="ocr-path-options">
                    <?php foreach ($ocrChoices as $path) : ?>
                        <option value="<?= e($path) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </label>
            <label>翻译合并文件
                <input type="text" name="translation_path" list="translation-path-options" value="<?= e($translationPath) ?>">
                <datalist id="translation-path-options">
                    <?php foreach ($translationChoices as $path) : ?>
                        <option value="<?= e($path) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </label>
        </div>
        <button type="submit">加载校对页</button>
    </form>
</section>

<?php if ($proofreadPayload) : ?>
    <section class="panel">
        <h2>当前校对稿</h2>
        <div class="proofread-meta">
            <span>项目：<code><?= e($proofreadPayload['project_id']) ?></code></span>
            <span>OCR：<code><?= e(relative_project_path($proofreadPayload['ocr_path'])) ?></code></span>
            <span>译文：<code><?= e(relative_project_path($proofreadPayload['translation_path'])) ?></code></span>
            <span>校对稿：<code><?= e(relative_project_path($proofreadPayload['proofread_path'])) ?></code></span>
        </div>
    </section>

    <section class="panel" data-proofread-app>
        <script type="application/json"><?= json_encode($proofreadPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
        <div class="proofread-meta">
            <span id="page-indicator" class="page-indicator">P1</span>
            <span id="page-counter">第 1 / 1 页</span>
            <label><input type="checkbox" id="completed-toggle"> 标记当前页已校</label>
            <span id="proofread-status-text" class="muted"></span>
            <span id="save-message" class="small"></span>
        </div>
        <div class="button-row">
            <button type="button" class="secondary" id="prev-page">上一页</button>
            <button type="button" class="secondary" id="next-page">下一页</button>
            <button type="button" class="success" id="save-current">保存当前页</button>
            <button type="button" class="warn" id="save-all">保存全部</button>
        </div>
        <div class="proofread-layout">
            <div class="proofread-image">
                <h3>图片</h3>
                <img id="proofread-image" alt="当前漫画页">
            </div>
            <div>
                <h3>OCR 文本</h3>
                <textarea id="ocr-text" readonly></textarea>
                <h3>机器译文</h3>
                <textarea id="translation-text" readonly></textarea>
            </div>
            <div>
                <h3>人工校对稿</h3>
                <textarea id="proofread-text"></textarea>
            </div>
        </div>
    </section>
<?php endif; ?>
<?php
});
