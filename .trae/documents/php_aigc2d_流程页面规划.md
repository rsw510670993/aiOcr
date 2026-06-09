# PHP 页面化接入 AIGC2D 流程规划

## Summary

- 目标：在当前仅含 Python 脚本的仓库中新增一个独立 PHP 小站，把现有流程页面化为 6 个入口：主页、环境准备页、PDF 提取页、OCR 页、翻译页、校对页。
- 运行方式：部署在 Ubuntu 24.04，PHP 通过 `proc_open`/`exec` 调用现有 Python 脚本；长任务采用“同步启动 + 页面轮询日志/状态文件”。
- 后端范围：仅接入远程 AIGC2D 链路，即 `pdf_to_jpg.py`、`aigc2d_ocr.py`、`aigc2d_translate.py`；不接入本地 Ollama 链路。
- 状态存储：不引入数据库，全部使用文件系统保存上传文件、任务状态、日志、中间产物和校对稿。
- 校对方式：逐页人工校对，页面同时展示图片、OCR 文本、译文，并允许逐页保存修订结果。

## Current State Analysis

- 当前仓库只有 5 个可执行 Python 脚本与 1 个依赖文件，没有现成 PHP、前端框架或 Web 目录。
- `pdf_to_jpg.py` 负责将 PDF 输出为 `page_0001.jpg` 这类命名的图片，支持 `--password`、`--output`、`--dpi`、`--quality`。
- `aigc2d_ocr.py` 负责远程 OCR，默认读取 `aigc2d.key`，支持 `--image-dir`、`--pages`、`--review-page`、`--combined-output`、`--resume`、`--keep-page-files`、`--no-consistency-check` 等参数，并生成带 `P{页码}` 标记的合并文本。
- `aigc2d_translate.py` 负责远程翻译，默认读取 `名词表.csv` 与 `aigc2d.key`，输入为包含 `P{页码}` 标记的 OCR 合并文本，默认输出到 `aigc2d_translation_text/`。
- `.gitignore` 已忽略 `.venv/`、`*.key`、`*.pdf`、`exported_jpg/`、`ocr_text/`、`qwen3_ocr_text/`、`translation_text/`、`aigc2d_translation_text/`、`名词表.csv`，说明仓库已经接受“运行时产物不入库”的工作方式。
- README 说明了完整处理链路，但仍是命令行使用方式，缺少 Web 端任务编排、状态展示、产物选择、日志查看与人工校对能力。

## Proposed Changes

### 1. 新增独立 PHP 应用骨架

- 新增目录 `/workspace/php_app/` 作为独立 Web 应用根目录，避免污染现有 Python 脚本根目录。
- 新增 `/workspace/php_app/public/index.php` 作为主页，列出 6 个页面入口，并显示最近任务和最近产物。
- 新增 `/workspace/php_app/public/assets/` 保存极简样式文件与少量前端脚本，仅实现表单提交、日志轮询、页码切换与保存提示，不引入前端框架。
- 新增 `/workspace/php_app/src/bootstrap.php` 负责常量、路径、自动加载、统一错误处理和基础配置加载。
- 新增 `/workspace/php_app/config/app.php` 维护工作目录、Python 可执行路径、默认产物目录、轮询间隔、最大上传大小等配置。

### 2. 新增文件系统任务层

- 新增 `/workspace/php_app/src/JobStore.php`：
  - 职责：创建/读取/更新任务元数据。
  - 存储位置：`/workspace/php_app/runtime/jobs/{job_id}.json`。
  - JSON 字段固定为：`id`、`type`、`status`、`created_at`、`started_at`、`finished_at`、`command`、`cwd`、`params`、`artifacts`、`log_file`、`exit_code`、`error`。
- 新增 `/workspace/php_app/src/ProcessRunner.php`：
  - 职责：安全拼装命令、启动 Python 进程、写入 stdout/stderr 日志、更新任务状态。
  - 统一在仓库根目录 `/workspace` 下执行，保证现有脚本默认相对路径行为不变。
- 新增 `/workspace/php_app/src/PathGuard.php`：
  - 限制所有可读写路径必须位于 `/workspace` 或 `/workspace/php_app/runtime` 下。
  - 阻止路径穿越、任意文件覆盖和用户直接传入绝对系统路径。
- 新增 `/workspace/php_app/src/WorkspaceManager.php`：
  - 为每个任务建立独立工作区：`/workspace/php_app/runtime/workspaces/{job_id}/`。
  - 统一生成 `source.pdf`、`exported_jpg/`、`ocr_text/`、`aigc2d_translation_text/`、`proofread_text/` 等子目录。

### 3. 新增文本解析与校对数据层

- 新增 `/workspace/php_app/src/CombinedTextParser.php`：
  - 解析和写回 `P{页码}` 格式的 OCR/翻译合并文本。
  - 输出结构固定为 `[{page: 1, text: "..."}]`，供校对页直接消费。
- 新增 `/workspace/php_app/src/ProofreadStore.php`：
  - 保存人工校对结果到 `/workspace/php_app/runtime/workspaces/{job_id}/proofread_text/{basename}.txt`。
  - 初次进入校对页时，默认从译文文件复制出一份校对稿；后续只改校对稿，不直接覆盖原始翻译结果。
- 新增 `/workspace/php_app/src/ArtifactLocator.php`：
  - 根据任务 ID 或用户选择的文件，自动推导图片目录、OCR 合并文件、翻译合并文件、校对输出文件之间的关联。

### 4. 新增 6 个页面入口

- `/workspace/php_app/public/index.php`
  - 主页/导航页。
  - 显示 5 个实际处理页面入口、最近任务状态、关键环境提示。
  - 这是用户提出的“为当前 python 程序制作 php 执行用页面”的总站入口。

- `/workspace/php_app/public/preflight.php`
  - 环境准备页。
  - 检查项固定为：PHP 版本、`proc_open`/`exec` 可用性、`python3` 可用性、`.venv` 是否存在、`.venv/bin/python` 是否存在、`requirements.txt` 是否存在、`aigc2d.key` 是否存在且非空、`名词表.csv` 是否存在、`/workspace/php_app/runtime` 是否可写、`PyMuPDF` 是否可导入。
  - 页面不做环境安装，只做“准备状态展示 + 建议修复命令展示”；保持计划范围内为只读检查型页面。

- `/workspace/php_app/public/pdf_extract.php`
  - PDF 转图片提取页。
  - 表单字段：PDF 上传、可选密码、DPI、JPEG 质量、任务名称。
  - 提交后将 PDF 保存到任务工作区，并调用：
    - `.venv/bin/python pdf_to_jpg.py {pdf} --output {workspace/exported_jpg} [--password ...] [--dpi ...] [--quality ...]`
  - 结果页显示任务状态、日志、导出图片总页数、图片目录路径、下一步跳转按钮。

- `/workspace/php_app/public/ocr.php`
  - OCR 页。
  - 输入来源：选择一个已有 `exported_jpg/` 目录或从最近 PDF 提取任务自动带入。
  - 表单字段：页码范围、`combined-output` 文件名、模型名、超时、重试次数、是否 `resume`、是否跳过一致性检查。
  - 固定调用 `aigc2d_ocr.py`，输出放入当前任务工作区 `ocr_text/`。
  - 任务详情页显示日志、最终 OCR 文件路径、页码范围和失败信息。

- `/workspace/php_app/public/translate.php`
  - 翻译页。
  - 输入来源：选择 OCR 合并文件，默认读取 `名词表.csv` 与 `aigc2d.key`。
  - 表单字段：页码范围、模型名、超时、重试次数、是否逐页模式、是否 `resume`、是否保留逐页文件。
  - 固定调用 `aigc2d_translate.py`，输出到任务工作区 `aigc2d_translation_text/`。
  - 结果页显示日志、译文文件路径、命中的页码范围，并提供跳转校对页按钮。

- `/workspace/php_app/public/proofread.php`
  - 逐页人工校对页。
  - 输入：图片目录、OCR 合并文件、翻译合并文件；支持通过任务 ID 自动联动加载。
  - 页面布局固定为三栏或两栏扩展：
    - 左侧：当前页图片。
    - 中间：当前页 OCR 文本，只读。
    - 右侧：机器翻译文本与人工校对文本编辑框。
  - 交互固定为：页码切换、保存当前页、保存全部、标记已校、上一页/下一页跳转、显示未校页数量。
  - 保存时将当前所有页重新写回一个带 `P{页码}` 的校对合并文件，位置为 `proofread_text/{basename}.txt`。

### 5. 新增任务状态与日志接口

- 新增 `/workspace/php_app/public/job_status.php`
  - 输入 `job_id`，返回 JSON：状态、退出码、日志尾部、主要产物路径、可跳转页面。
- 新增 `/workspace/php_app/public/job_log.php`
  - 返回任务完整日志或日志尾部，供前端轮询。
- 新增 `/workspace/php_app/public/save_proofread.php`
  - 接收校对页保存请求，更新 `proofread_text/*.txt`。
- 所有 JSON 接口只服务当前 PHP 页面，不额外设计公开 API 鉴权层；如需上线公网，后续再补鉴权。

### 6. 页面间的数据流与默认联动

- PDF 提取成功后，把 `exported_jpg/` 路径写入任务 `artifacts`，供 OCR 页自动带入。
- OCR 成功后，把 `combined_output` 写入任务 `artifacts.ocr_text`。
- 翻译成功后，把译文文件写入任务 `artifacts.translation_text`。
- 校对页优先读取任务关联产物；若用户手动选择文件，则先校验页码集合是否一致：
  - 图片页码需要覆盖 OCR 页码。
  - OCR 页码与翻译页码必须完全一致。
- 所有页码列表均以 `P{页码}` 为单一事实来源，图片文件名以 `page_0001.jpg` 规则解析页码并进行匹配。

### 7. 安全与约束

- PHP 侧所有命令参数统一使用安全转义，不允许用户直接输入完整 shell 命令。
- 上传文件类型仅接受 PDF；图片页不提供任意文件上传替代 OCR 源目录。
- `aigc2d.key` 与 `名词表.csv` 不在页面中编辑，只在环境页检查存在性与可读性。
- 日志中若包含绝对路径或密钥相关错误信息，需要在展示层做脱敏处理，至少隐藏密钥文件内容。
- 由于采用文件系统存储，计划中不支持多用户隔离；默认定位为单机单操作者工具站。

## Assumptions & Decisions

- 采用原生 PHP 小站而非 Laravel/Symfony，原因是当前仓库完全没有 PHP 依赖基础，且需求以流程编排为主。
- Python 统一通过 `/workspace/.venv/bin/python` 调用；若环境页发现不存在，则提示用户先创建虚拟环境。
- 环境准备页只检查不安装，避免 PHP 页面直接执行依赖安装与系统变更。
- OCR 只接 `aigc2d_ocr.py`，翻译只接 `aigc2d_translate.py`；本地 Ollama 两个脚本暂不进入页面。
- 校对结果不覆盖原始译文，单独输出到 `proofread_text/`，以便保留“机器原稿”和“人工定稿”两份结果。
- 长任务采用“单机同步子进程 + 轮询状态文件”而非队列系统，优先满足当前复杂度与可维护性。
- 不新增数据库；任务、日志、产物路径、校对状态全部落盘在 `php_app/runtime/`。

## Verification Steps

- 打开环境准备页，确认缺少 `.venv`、`aigc2d.key` 或 `名词表.csv` 时能给出明确失败项与修复建议。
- 在 PDF 提取页上传一个受密码保护和一个不受保护的 PDF，确认都能正确调用 `pdf_to_jpg.py` 并输出 `page_0001.jpg` 风格文件。
- 在 OCR 页选择导出图片目录，分别验证正常 OCR、`resume`、跳过一致性检查、非法页码范围时报错四种情况。
- 在翻译页选择 OCR 合并文件，验证整本翻译和逐页翻译两种模式都能生成带 `P{页码}` 的输出文件。
- 在校对页加载同一任务的图片、OCR、翻译结果，确认页码可切换、内容一一对应、保存后生成 `proofread_text/*.txt`。
- 对任务状态接口做手工检查：运行中、成功、失败三种状态都能通过轮询拿到状态与日志尾部。
- 对路径校验做手工检查：尝试提交越权路径或不存在文件时，页面能拒绝请求且不执行命令。
