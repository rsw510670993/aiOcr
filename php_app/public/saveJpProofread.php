<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\CombinedTextParser;
use App\JpProofreadStore;
use App\PathGuard;
use App\WorkspaceManager;

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    json_response(['ok' => false, 'error' => '请求体必须为 JSON。'], 400);
}

try {
    $projectId = trim((string) ($payload['project_id'] ?? ''));
    $ocrPath = trim((string) ($payload['ocr_path'] ?? ''));
    $pages = $payload['pages'] ?? null;
    $completedPages = $payload['completed_pages'] ?? [];
    if ($projectId === '' || $ocrPath === '' || !is_array($pages)) {
        throw new RuntimeException('缺少 project_id、ocr_path 或 pages。');
    }

    $guard = new PathGuard();
    $ocrPath = $guard->assertFile($ocrPath);
    if (str_ends_with($ocrPath, '.jp.txt')) {
        throw new RuntimeException('ocr_path 必须为原始 OCR 合并文件（.txt），不能为 .jp.txt。');
    }

    $normalizedPages = [];
    foreach ($pages as $page) {
        if (!is_array($page) || !isset($page['page'], $page['text'])) {
            throw new RuntimeException('pages 数据格式不正确。');
        }
        $normalizedPages[] = [
            'page' => (int) $page['page'],
            'text' => trim((string) $page['text']),
        ];
    }
    $completedPages = array_values(array_unique(array_map('intval', is_array($completedPages) ? $completedPages : [])));

    $store = new JpProofreadStore(new WorkspaceManager(), new CombinedTextParser());
    $result = $store->save($projectId, $ocrPath, $normalizedPages, $completedPages);

    json_response([
        'ok' => true,
        'jp_path' => $result['jp_path'],
        'status_path' => $result['status_path'],
        'completed_pages' => $completedPages,
    ]);
} catch (Throwable $throwable) {
    json_response(['ok' => false, 'error' => $throwable->getMessage()], 422);
}

