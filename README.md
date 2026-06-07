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

创建 Python 虚拟环境并安装依赖：

```powershell
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r .\requirements.txt
```

安装 Ollama 后拉取模型：

```powershell
ollama pull qwen3-vl:30b-a3b-instruct
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

- 每页文本：`qwen3_ocr_text/page_0007.txt`
- 合并文本：`qwen3_ocr_text/pages_0007-0056.txt`
- 合并文件每页以 `P{页码}` 开头，页面之间包含一个空行

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
--resume                 复用正常结果，继续缺失或异常页面
--retries 3              异常输出重试次数
--temperature 0.0        控制输出随机性
--num-predict 2048       单页最大输出 token 数
--base-url URL           Ollama API 地址
--combined-output PATH   指定合并文本路径
--dry-run                仅检查选中的图片，不执行 OCR
```
