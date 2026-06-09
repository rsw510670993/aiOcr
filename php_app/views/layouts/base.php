<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - <?= e((string) app_config('app_name')) ?></title>
    <link rel="icon" href="<?= e(asset_url('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_url('style.css')) ?>">
</head>
<body>
<header class="site-header">
    <div class="container header-row">
        <div>
            <h1><?= e((string) app_config('app_name')) ?></h1>
            <p class="muted">PHP 页面编排现有 Python PDF -> OCR -> 翻译 -> 校对流程</p>
        </div>
        <nav class="nav-links">
            <a href="<?= e(url('index.php')) ?>">主页</a>
            <a href="<?= e(url('preflight.php')) ?>">环境准备</a>
            <a href="<?= e(url('pdfExtract.php')) ?>">PDF 提取</a>
            <a href="<?= e(url('ocr.php')) ?>">OCR</a>
            <a href="<?= e(url('translate.php')) ?>">翻译</a>
            <a href="<?= e(url('proofread.php')) ?>">校对</a>
        </nav>
    </div>
</header>
<main class="container">
    <?= $body ?>
</main>
<script>
    window.APP_CONFIG = <?= json_encode([
        'pollIntervalMs' => (int) app_config('poll_interval_ms', 2000),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= e(asset_url('app.js')) ?>"></script>
</body>
</html>
