import argparse
import base64
from collections import Counter
import json
import sys
import urllib.error
import urllib.request
from pathlib import Path

from aigc2d_ocr import (
    combined_output_path,
    page_number,
    select_images,
    write_combined_text,
)


OCR_PROMPT = """画像内の日本語漫画テキストを正確に文字起こししてください。
縦書きは上から下へ、列は右から左へ読んでください。
原文だけを出力し、翻訳、説明、Markdownは追加しないでください。
ルビ（ふりがな）は、本文の漢字と同じ発音を示す通常の注音なら出力しないでください。
本文と異なる読み方、特別な意味、独立した文字の場合だけ出力してください。
背景の透かし「CONFIDENTIAL」は本文ではないため、絶対に出力しないでください。
段落は空行で区切り、判読不能な文字は「〓」にしてください。"""


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run Qwen3-VL OCR through local Ollama.")
    parser.add_argument("images", type=Path, nargs="*", help="JPG/PNG image paths")
    parser.add_argument("--image-dir", type=Path, help="Directory containing page_NNNN.jpg")
    parser.add_argument("--pages", help="Inclusive page range, for example 7-56")
    parser.add_argument(
        "--model", default="qwen3-vl:30b-a3b-instruct", help="Ollama model name"
    )
    parser.add_argument(
        "--output", type=Path, default=Path("qwen3_ocr_text"), help="Output directory"
    )
    parser.add_argument("--combined-output", type=Path, help="Combined text file path")
    parser.add_argument("--base-url", default="http://localhost:11436", help="Ollama URL")
    parser.add_argument("--temperature", type=float, default=0.0)
    parser.add_argument("--num-predict", type=int, default=2048)
    parser.add_argument("--retries", type=int, default=3, help="Retries for invalid output")
    parser.add_argument(
        "--resume",
        action="store_true",
        help="Reuse existing per-page text files and continue missing pages",
    )
    parser.add_argument(
        "--dry-run", action="store_true", help="List selected images without calling Ollama"
    )
    return parser.parse_args()


def request_ocr(
    image_path: Path,
    model: str,
    base_url: str,
    temperature: float,
    num_predict: int,
) -> str:
    payload = {
        "model": model,
        "messages": [
            {
                "role": "user",
                "content": OCR_PROMPT,
                "images": [base64.b64encode(image_path.read_bytes()).decode("ascii")],
            }
        ],
        "think": False,
        "stream": False,
        "options": {"temperature": temperature, "num_predict": num_predict},
    }
    request = urllib.request.Request(
        f"{base_url.rstrip('/')}/api/chat",
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=1800) as response:
            result = json.load(response)
    except urllib.error.HTTPError as exc:
        details = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"Ollama returned HTTP {exc.code}: {details}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"Could not connect to Ollama: {exc.reason}") from exc
    try:
        message = result["message"]
        return message.get("content") or message.get("thinking") or ""
    except (KeyError, TypeError) as exc:
        raise RuntimeError(f"Unexpected Ollama response: {json.dumps(result)}") from exc


def invalid_output_reason(text: str) -> str | None:
    stripped = text.strip()
    if not stripped:
        return "empty output"
    if "CONFIDENTIAL" in stripped.upper():
        return "watermark text detected"
    if "<think>" in stripped.lower():
        return "thinking output detected"
    if len(stripped) > 5000:
        return f"output is abnormally long ({len(stripped)} characters)"

    lines = [line.strip() for line in stripped.splitlines() if line.strip()]
    if lines:
        repeated_line, count = Counter(lines).most_common(1)[0]
        if count >= 8:
            return f"line repeated {count} times: {repeated_line[:40]}"
    return None


def request_valid_ocr(
    image_path: Path,
    model: str,
    base_url: str,
    temperature: float,
    num_predict: int,
    retries: int,
) -> str:
    last_reason = "unknown invalid output"
    for attempt in range(1, retries + 1):
        text = request_ocr(image_path, model, base_url, temperature, num_predict)
        reason = invalid_output_reason(text)
        if not reason:
            return text
        last_reason = reason
        print(f"Invalid OCR output ({attempt}/{retries}): {reason}", file=sys.stderr)
    raise RuntimeError(f"OCR failed validation after {retries} attempts: {last_reason}")


def main() -> int:
    args = parse_args()
    try:
        images = select_images(args)
        for image_path in images:
            if not image_path.is_file():
                raise FileNotFoundError(f"Image does not exist: {image_path}")
        print(f"Selected {len(images)} image(s): {images[0]} through {images[-1]}")
        if args.dry_run:
            for image_path in images:
                print(image_path)
            return 0
        if args.num_predict < 1 or args.retries < 1:
            raise ValueError("--num-predict and --retries must be greater than zero")

        args.output.mkdir(parents=True, exist_ok=True)
        combined_path = combined_output_path(args, images)
        combined_pages = []
        for image_path in images:
            output_path = args.output / f"{image_path.stem}.txt"
            if args.resume and output_path.is_file():
                text = output_path.read_text(encoding="utf-8").strip()
                reason = invalid_output_reason(text)
                if reason:
                    print(f"Redoing invalid output: {output_path} ({reason})")
                    text = request_valid_ocr(
                        image_path,
                        args.model,
                        args.base_url,
                        args.temperature,
                        args.num_predict,
                        args.retries,
                    )
                    output_path.write_text(text.strip() + "\n", encoding="utf-8")
                    print(f"Saved: {output_path}")
                else:
                    print(f"Reused: {output_path}")
            else:
                print(f"OCR: {image_path}")
                text = request_valid_ocr(
                    image_path,
                    args.model,
                    args.base_url,
                    args.temperature,
                    args.num_predict,
                    args.retries,
                )
                output_path.write_text(text.strip() + "\n", encoding="utf-8")
                print(f"Saved: {output_path}")

            combined_pages.append((page_number(image_path), text))
            write_combined_text(combined_path, combined_pages)

        print(f"Saved combined text: {combined_path}")
    except (OSError, RuntimeError, ValueError) as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
