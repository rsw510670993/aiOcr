<?php
        declare(strict_types=1);

        require __DIR__ . '/../src/bootstrap.php';

        $pythonBin = (string) app_config('python_bin');
        $runtimeRoot = (string) app_config('runtime_root');
        $projectRoot = (string) app_config('project_root');

        $checkCommand = static function (string $command): array {
            $output = [];
            $exitCode = 1;
            exec($command . ' 2>&1', $output, $exitCode);
            return [$exitCode === 0, trim(implode(PHP_EOL, $output))];
        };

        [$pythonAvailable, $pythonVersion] = $checkCommand('python3 --version');
        $pythonForImport = is_file($pythonBin) ? escapeshellarg($pythonBin) : 'python3';
        [$pymupdfReady, $pymupdfOutput] = $checkCommand($pythonForImport . ' -c ' . escapeshellarg('import pymupdf; print(pymupdf.__doc__ or "ok")'));

        $checks = [
            [
                'name' => 'PHP 版本',
                'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
                'detail' => PHP_VERSION,
                'hint' => '建议 PHP 8.1 及以上。',
            ],
            [
                'name' => 'proc_open 可用',
                'ok' => function_exists('proc_open'),
                'detail' => function_exists('proc_open') ? '可用' : '不可用',
                'hint' => '若不可用，PHP 无法可靠启动后台任务。',
            ],
            [
                'name' => 'exec 可用',
                'ok' => function_exists('exec'),
                'detail' => function_exists('exec') ? '可用' : '不可用',
                'hint' => '若不可用，无法启动 Python 子进程。',
            ],
            [
                'name' => 'python3 可用',
                'ok' => $pythonAvailable,
                'detail' => $pythonVersion !== '' ? $pythonVersion : '未检测到 python3',
                'hint' => 'Ubuntu 24.04 通常可用 `sudo apt install python3 python3-venv`。',
            ],
            [
                'name' => '.venv 目录',
                'ok' => is_dir($projectRoot . '/.venv'),
                'detail' => is_dir($projectRoot . '/.venv') ? '已存在' : '缺失',
                'hint' => '执行 `python3 -m venv /workspace/.venv`。',
            ],
            [
                'name' => '.venv/bin/python',
                'ok' => is_file($pythonBin),
                'detail' => is_file($pythonBin) ? $pythonBin : '缺失',
                'hint' => '创建虚拟环境后安装依赖。',
            ],
            [
                'name' => 'requirements.txt',
                'ok' => is_file($projectRoot . '/requirements.txt'),
                'detail' => $projectRoot . '/requirements.txt',
                'hint' => '当前仓库应保留此文件。',
            ],
            [
                'name' => 'aigc2d.key',
                'ok' => is_file($projectRoot . '/aigc2d.key') && trim((string) @file_get_contents($projectRoot . '/aigc2d.key')) !== '',
                'detail' => is_file($projectRoot . '/aigc2d.key') ? '已找到' : '缺失',
                'hint' => '将 API Key 保存到 `/workspace/aigc2d.key`。',
            ],
            [
                'name' => '名词表.csv',
                'ok' => is_file($projectRoot . '/名词表.csv'),
                'detail' => is_file($projectRoot . '/名词表.csv') ? '已找到' : '缺失',
                'hint' => '翻译页默认读取 `/workspace/名词表.csv`。',
            ],
            [
                'name' => 'runtime 可写',
                'ok' => is_dir($runtimeRoot) && is_writable($runtimeRoot),
                'detail' => $runtimeRoot,
                'hint' => '确认 PHP 进程对 `php_app/runtime` 有写权限。',
            ],
            [
                'name' => 'PyMuPDF 可导入',
                'ok' => $pymupdfReady,
                'detail' => $pymupdfOutput !== '' ? $pymupdfOutput : '导入失败',
                'hint' => '执行 `/workspace/.venv/bin/python -m pip install -r /workspace/requirements.txt`。',
            ],
        ];

        $allReady = count(array_filter($checks, static fn (array $check): bool => $check['ok'])) === count($checks);

        render_page('环境准备', function () use ($checks, $allReady, $projectRoot): void {
        ?>
        <section class="panel">
            <h2>环境检查</h2>
            <div class="alert <?= $allReady ? 'success' : 'error' ?>">
                <?= $allReady ? '环境已满足当前 PHP 小站运行条件。' : '仍有缺失项，建议先修复后再运行 OCR / 翻译任务。' ?>
            </div>
            <table>
                <thead>
                <tr>
                    <th>检查项</th>
                    <th>状态</th>
                    <th>详情</th>
                    <th>建议</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($checks as $check) : ?>
                    <tr>
                        <td><?= e($check['name']) ?></td>
                        <td><span class="status-pill status-<?= $check['ok'] ? 'success' : 'failed' ?>"><?= $check['ok'] ? '通过' : '缺失' ?></span></td>
                        <td><code><?= e($check['detail']) ?></code></td>
                        <td><?= e($check['hint']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>建议命令</h2>
            <pre>python3 -m venv <?= e($projectRoot) ?>/.venv
<?= e($projectRoot) ?>/.venv/bin/python -m pip install -r <?= e($projectRoot) ?>/requirements.txt
# 手动放置以下文件
<?= e($projectRoot) ?>/aigc2d.key
<?= e($projectRoot) ?>/名词表.csv</pre>
        </section>
        <?php
        });
