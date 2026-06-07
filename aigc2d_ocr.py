import argparse
import base64
import json
import mimetypes
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path


SYSTEM_PROMPT = """あなたは日本語漫画のOCRエンジンです。
画像内の日本語縦書きを、上から下、右の列から左の列の順で読み取ってください。
本文だけを出力し、説明、Markdown、コードブロックは追加しないでください。
改ページや段落は空行で表し、判読不能な文字は「〓」にしてください。"""


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Send JPG images as base64 data URLs to the AIGC2D API for OCR."
    )
    parser.add_argument("images", type=Path, nargs="*", help="JPG/PNG image paths")
    parser.add_argument(
        "--image-dir",
        type=Path,
        help="Directory containing page_NNNN.jpg files; use with --pages",
    )
    parser.add_argument(
        "--pages",
        help="Inclusive page range, for example 7-56; requires --image-dir",
    )
    parser.add_argument(
        "--key-file", type=Path, default=Path("aigc2d.key"), help="API key file"
    )
    parser.add_argument(
        "--output", type=Path, default=Path("ocr_text"), help="Output directory"
    )
    parser.add_argument(
        "--combined-output",
        type=Path,
        help="Combined text file path (default: OUTPUT/pages_START-END.txt)",
    )
    parser.add_argument(
        "--base-url", default="https://next.aigc2d.com/v1/", help="AIGC2D API base URL"
    )
    parser.add_argument("--model", default="gemini-3.1-flash-lite", help="Model name")
    parser.add_argument("--max-tokens", type=int, default=8192)
    parser.add_argument(
        "--dry-run", action="store_true", help="List selected images without calling API"
    )
    return parser.parse_args()


def select_images(args: argparse.Namespace) -> list[Path]:
    images = list(args.images)
    if args.pages:
        if not args.image_dir:
            raise ValueError("--pages requires --image-dir")
        try:
            start_text, end_text = args.pages.split("-", maxsplit=1)
            start, end = int(start_text), int(end_text)
        except ValueError as exc:
            raise ValueError("--pages must use the format START-END, for example 7-56") from exc
        if start < 1 or end < start:
            raise ValueError("--pages must be a positive range with START <= END")
        images.extend(args.image_dir / f"page_{page:04d}.jpg" for page in range(start, end + 1))
    if not images:
        raise ValueError("Provide image paths, or use --image-dir together with --pages")
    return images


def image_data_url(path: Path) -> str:
    mime_type = mimetypes.guess_type(path.name)[0] or "image/jpeg"
    encoded = base64.b64encode(path.read_bytes()).decode("ascii")
    return f"data:{mime_type};base64,{encoded}"


def page_number(path: Path) -> int:
    match = re.search(r"(\d+)$", path.stem)
    if not match:
        raise ValueError(f"Image filename must end with a page number: {path.name}")
    return int(match.group(1))


def combined_output_path(args: argparse.Namespace, images: list[Path]) -> Path:
    if args.combined_output:
        return args.combined_output
    start, end = page_number(images[0]), page_number(images[-1])
    return args.output / f"pages_{start:04d}-{end:04d}.txt"


def format_combined_text(pages: list[tuple[int, str]]) -> str:
    return "\n\n".join(f"P{number}\n{text.strip()}" for number, text in pages) + "\n"


def parse_combined_text(text: str) -> list[tuple[int, str]]:
    matches = list(re.finditer(r"(?m)^P(\d+)\s*$", text))
    if not matches:
        raise ValueError("Combined file does not contain P{page} markers")

    pages = []
    for index, match in enumerate(matches):
        start = match.end()
        end = matches[index + 1].start() if index + 1 < len(matches) else len(text)
        pages.append((int(match.group(1)), text[start:end].strip()))
    return pages


def replace_combined_page(path: Path, page: int, text: str) -> None:
    if not path.is_file():
        raise FileNotFoundError(f"Combined file does not exist: {path}")
    pages = parse_combined_text(path.read_text(encoding="utf-8"))
    if page not in {number for number, _ in pages}:
        raise ValueError(f"Combined file does not contain P{page}: {path}")
    updated = [(number, text if number == page else content) for number, content in pages]
    write_combined_text(path, updated)


def write_combined_text(path: Path, pages: list[tuple[int, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(format_combined_text(pages), encoding="utf-8")


def request_ocr(
    image_path: Path,
    api_key: str,
    base_url: str,
    model: str,
    max_tokens: int,
) -> str:
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": SYSTEM_PROMPT},
            {
                "role": "user",
                "content": [
                    {
                        "type": "text",
                        "text": "この画像を指定された順序で正確に文字起こししてください。",
                    },
                    {
                        "type": "image_url",
                        "image_url": {"url": image_data_url(image_path)},
                    },
                ],
            },
        ],
        "max_tokens": max_tokens,
        "stream": False,
    }
    request = urllib.request.Request(
        f"{base_url.rstrip('/')}/chat/completions",
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        },
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=180) as response:
            result = json.load(response)
    except urllib.error.HTTPError as exc:
        details = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"AIGC2D API returned HTTP {exc.code}: {details}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"Could not connect to AIGC2D API: {exc.reason}") from exc

    try:
        return result["choices"][0]["message"]["content"]
    except (KeyError, IndexError, TypeError) as exc:
        raise RuntimeError(f"Unexpected AIGC2D response: {json.dumps(result)}") from exc


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

        api_key = args.key_file.read_text(encoding="utf-8").strip()
        if not api_key:
            raise ValueError(f"API key file is empty: {args.key_file}")
        args.output.mkdir(parents=True, exist_ok=True)

        combined_pages = []
        for image_path in images:
            print(f"OCR: {image_path}")
            text = request_ocr(
                image_path, api_key, args.base_url, args.model, args.max_tokens
            )
            combined_pages.append((page_number(image_path), text))
            output_path = args.output / f"{image_path.stem}.txt"
            output_path.write_text(text.strip() + "\n", encoding="utf-8")
            print(f"Saved: {output_path}")

        combined_path = combined_output_path(args, images)
        write_combined_text(combined_path, combined_pages)
        print(f"Saved combined text: {combined_path}")
    except (OSError, RuntimeError, ValueError) as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
