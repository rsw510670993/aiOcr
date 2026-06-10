<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\JobStore;
use App\ArtifactLocator;
use App\WorkspaceManager;

$projectId = get_string('project_id');
if ($projectId === '') {
    json_response(['ok' => false, 'error' => '缺少 project_id'], 400);
}

$workspaceManager = new WorkspaceManager();
$jobStore = new JobStore((string) app_config('jobs_dir'));
$locator = new ArtifactLocator($jobStore, $workspaceManager);

$projectId = $workspaceManager->projectIdFromName($projectId);
$project = $locator->projectArtifacts($projectId);
if (!$project) {
    json_response(['ok' => false, 'error' => '项目不存在：' . $projectId], 404);
}

json_response([
    'ok' => true,
    'project_id' => $projectId,
    'ocr_files' => $locator->projectFiles($projectId, 'ocr_text'),
    'translation_files' => $locator->projectFiles($projectId, 'aigc2d_translation_text'),
]);

