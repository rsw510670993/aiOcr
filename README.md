# 日语漫画 OCR 工具

本项目将 PDF 逐页导出为 JPG，并使用本地 Ollama 模型
`qwen3-vl:30b-a3b-instruct` 识别日语漫画文本。

OCR 阅读顺序为从上到下、从右到左。普通且与正文汉字发音一致的注音应忽略；
不同读法、特殊含义或独立文字会保留。页面中的 `CONFIDENTIAL` 水印会被忽略。

## 环境

- Windows PowerShell
- Python 3
- Ollama
- NVIDIA RTX 3080 Ti 12GB 或相近配置
- Ollama 模型：`qwen3-vl:30b-a3b-instruct`
- 翻译模型：`qwen3:30b`
- CSV 术语表：`名词表.csv`

创建 Python 虚拟环境并安装依赖：

```powershell
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r .\requirements.txt
```

安装 Ollama 后拉取模型：

```powershell
ollama pull qwen3-vl:30b-a3b-instruct
ollama pull qwen3:30b
```

## 启动 Ollama

30B 模型需要为图片编码预留额外显存。启动监听 `11436` 端口的 Ollama 服务：

```powershell
$env:OLLAMA_HOST = "127.0.0.1:11436"
$env:OLLAMA_GPU_OVERHEAD = "3GiB"
ollama serve
```

保持该 PowerShell 窗口运行，再打开另一个 PowerShell 窗口执行 OCR。

## PDF 导出 JPG

```powershell
.\.venv\Scripts\python.exe .\pdf_to_jpg.py "输入文件.pdf" `
  --password "PDF密码" `
  --output .\exported_jpg
```

默认使用 150 DPI、JPEG 质量 90，图片命名为 `page_0001.jpg`。

## 批量 OCR

识别第 7 至 56 页：

```powershell
.\.venv\Scripts\python.exe .\ollama_qwen3_vl_ocr.py `
  --image-dir .\exported_jpg `
  --pages 7-56 `
  --combined-output .\qwen3_ocr_text\pages_0007-0056.txt
```

输出内容：

- 合并文本：`qwen3_ocr_text/pages_0007-0056.txt`
- 合并文件每页以 `P{页码}` 开头，页面之间包含一个空行
- 每页文本用于中断恢复，全部识别完成后默认自动删除
- 每页初次识别后会再次调用 Qwen3-VL 校对，将同一气泡的断句合并为一行
- 校对后汉字减少或假名增加时会拒绝校对结果，避免正文汉字退化为注音

需要保留每页文本时添加 `--keep-page-files`。
不需要第二次 OCR 校对时添加 `--no-review`。

重新校对某一页并原位更新现有合并 OCR 文件：

```powershell
.\.venv\Scripts\python.exe .\ollama_qwen3_vl_ocr.py `
  --image-dir .\exported_jpg `
  --review-page 8 `
  --combined-output .\qwen3_ocr_text\pages_0007-0056.txt
```

## 断点续传

中断后使用 `--resume` 继续。已有正常页面会被复用，缺失或检测为异常的页面会重新识别：

```powershell
.\.venv\Scripts\python.exe .\ollama_qwen3_vl_ocr.py `
  --image-dir .\exported_jpg `
  --pages 7-56 `
  --resume `
  --combined-output .\qwen3_ocr_text\pages_0007-0056.txt
```

脚本会自动拒绝并重试以下异常结果：

- 空文本
- `CONFIDENTIAL` 水印文本
- `<think>` 思考过程
- 异常超长文本
- 同一行大量重复

可通过 `--retries` 调整每页的最大尝试次数：

```powershell
.\.venv\Scripts\python.exe .\ollama_qwen3_vl_ocr.py `
  --image-dir .\exported_jpg `
  --pages 14-14 `
  --retries 5
```

## 常用参数

```text
--pages 7-56             指定包含首尾页的页码范围
--review-page 8          重做指定页并替换现有合并文件中的该页
--resume                 复用正常结果，继续缺失或异常页面
--keep-page-files        完成后保留每页 OCR 中间文本
--no-review              跳过 OCR 完成后的第二次 VL 校对
--retries 3              异常输出重试次数
--request-timeout 300    单次 OCR 或校对请求超时秒数
--temperature 0.0        控制输出随机性
--num-predict 2048       单页最大输出 token 数
--base-url URL           Ollama API 地址
--combined-output PATH   指定合并文本路径
--dry-run                仅检查选中的图片，不执行 OCR
```

## 使用 Qwen3 翻译

翻译工具读取带有 `P{页码}` 标记的合并 OCR 文本，并按页调用本地
`qwen3:30b`。每页初译完成后，默认调用一次 `qwen3-vl:30b-a3b-instruct`
结合对应漫画图片进行校对，将同一句中的散乱断行合并，同时保留不同气泡之间的分段。
关键名词从 `名词表.csv` 读取；CSV 须包含 `日语名` 和 `中文名`
两列，可选的 `角色的技能名` 列会作为已有译名参考。

先检查页码和术语匹配情况，不调用模型：

```powershell
.\.venv\Scripts\python.exe .\ollama_qwen3_translate.py `
  .\qwen3_ocr_text\pages_0007-0056.txt `
  --dry-run
```

翻译第 7 至 56 页：

```powershell
.\.venv\Scripts\python.exe .\ollama_qwen3_translate.py `
  .\qwen3_ocr_text\pages_0007-0056.txt
```

输出文件会自动使用 `translation_text/pages_0007-0056.txt`。术语表默认读取
`名词表.csv`，校对图片默认读取 `exported_jpg`。

每页译文在处理中用于断点恢复，成功生成合并译文后默认自动删除。
添加 `--keep-page-files` 可保留逐页译文；中断后添加 `--resume` 可复用已有结果。
不需要图片校对时添加 `--no-vl-review`。可通过 `--vl-model` 和
`--vl-base-url` 指定校对模型及 Ollama 服务地址。
每次单页文本翻译或 VL 校对默认最多等待 300 秒，可通过
`--request-timeout` 调整。
控制台会输出每次模型请求、每页处理以及整个任务的耗时。

仅使用 VL 对现有合并译文进行批量校对，不重新运行文本初译：

```powershell
.\.venv\Scripts\python.exe .\ollama_qwen3_translate.py `
  .\translation_text\pages_0007-0056.txt `
  --vl-review-only
```

校对模式直接原位更新位置参数中的译文文件，并自动读取
`qwen3_ocr_text/同名文件`。目录或文件名不对应时，使用 `--ocr-input`
指定 OCR 合并文件。

VL 批量校对会将每个气泡或独立文本整理为一行，页面内部不保留空白行。
每页完成后会立即更新合并译文，并默认删除该页中间文件；因此后续页面
超时或任务中断时，已完成页面也不会残留逐页文本。

校对结果会自动拒绝并重试仍含平假名或片假名的未翻译内容。所有括号和
引号统一规范为「」与『』。

VL 校对失败时不会使用相同初译连续重试校对，而是重新调用文本翻译，
再使用新译文进行下一次 VL 校对。默认最多执行 3 轮翻译与校对，且整页
处理总时间仍受 `--request-timeout` 限制。

重新翻译某一页并原位更新现有合并译文：

```powershell
.\.venv\Scripts\python.exe .\ollama_qwen3_translate.py `
  .\qwen3_ocr_text\pages_0007-0056.txt `
  --review-page 8 `
  --combined-output .\translation_text\pages_0007-0056.txt
```
