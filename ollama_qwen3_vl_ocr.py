import argparse
import base64
from collections import Counter
import json
import re
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

from aigc2d_ocr import (
    combined_output_path,
    page_number,
    normalize_name_separators,
    replace_combined_page,
    select_images,
    write_combined_text,
)


OCR_PROMPT = """画像内の日本語漫画テキストを正確に文字起こししてください。
縦書きは上から下へ、列は右から左へ読んでください。
原文だけを出力し、翻訳、説明、Markdownは追加しないでください。
ルビ（ふりがな）は、本文の漢字と同じ発音を示す通常の注音なら出力しないでください。
漢字本文とルビが両方見える場合は、必ず漢字本文だけを出力してください。
漢字本文を読み仮名に置き換えたり、読み仮名を漢字本文の前後に連結したりしないでください。
例：画像に「今日」とルビ「きょう」がある場合は「今日」だけを出力し、「きょう」にしないでください。
本文と異なる読み方、特別な意味、独立した文字の場合だけ出力してください。
人名の区切り記号には必ず「・」を使い、「＝」や「=」を混在させないでください。
背景の透かし「CONFIDENTIAL」は本文ではないため、絶対に出力しないでください。
段落は空行で区切り、判読不能な文字は「〓」にしてください。"""

OCR_REVIEW_PROMPT = """画像とOCR初稿を照合し、日本語漫画の文字起こしを校正してください。
誤認識、漏れ、読み順、ルビ、透かしを確認し、画像内の原文だけを出力してください。
翻訳、説明、Markdown、ページ番号は追加しないでください。
通常のルビと背景の「CONFIDENTIAL」は出力しないでください。
漢字本文とルビが両方ある場合は漢字本文を必ず残し、ルビだけを削除してください。
初稿にある漢字を仮名へ置き換えたり、仮名を漢字の前後へ追加したりしてはいけません。
校正後は初稿より漢字を減らさず、仮名を増やさないでください。
初稿が「きょうから」でも、画像に漢字本文「今日」とルビ「きょう」が見える場合は「今日から」に直してください。
人名の区切り記号は「・」に統一し、「＝」や「=」を残さないでください。
OCRの縦書きや吹き出し幅による改行を残さず、同じ吹き出し内の文章を必ず一行にまとめてください。
タイトル、別の吹き出し、別の話者、独立した効果音はそれぞれ別のsegments要素にしてください。
各segments要素の中には改行を入れないでください。"""

OCR_REVIEW_FORMAT = {
    "type": "object",
    "properties": {
        "segments": {
            "type": "array",
            "items": {"type": "string"},
        }
    },
    "required": ["segments"],
}


def format_elapsed(seconds: float) -> str:
    minutes, remainder = divmod(seconds, 60)
    hours, minutes = divmod(int(minutes), 60)
    if hours:
        return f"{hours:d}h {minutes:02d}m {remainder:04.1f}s"
    if minutes:
        return f"{minutes:d}m {remainder:04.1f}s"
    return f"{remainder:.1f}s"

LIKELY_RUBY_BEFORE_KANJI = re.compile(
    r"(?<![\u3040-\u309f])[\u3040-\u309f]{7,}(?=[\u3400-\u9fff]{2,})"
)
COMMON_KANJI_RESTORATIONS = {
    "きょうから": "今日から",
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run Qwen3-VL OCR through local Ollama.")
    parser.add_argument("images", type=Path, nargs="*", help="JPG/PNG image paths")
    parser.add_argument("--image-dir", type=Path, help="Directory containing page_NNNN.jpg")
    parser.add_argument("--pages", help="Inclusive page range, for example 7-56")
    parser.add_argument(
        "--review-page",
        type=int,
        help="Redo one page and replace it in the existing --combined-output file",
    )
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
        "--request-timeout",
        type=int,
        default=300,
        help="Timeout in seconds for each OCR or review request",
    )
    parser.add_argument(
        "--no-review",
        action="store_true",
        help="Skip the second VL proofreading request after OCR",
    )
    parser.add_argument(
        "--resume",
        action="store_true",
        help="Reuse existing per-page text files and continue missing pages",
    )
    parser.add_argument(
        "--keep-page-files",
        action="store_true",
        help="Keep per-page text files after the combined output is complete",
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
    request_timeout: int,
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
        with urllib.request.urlopen(request, timeout=request_timeout) as response:
            result = json.load(response)
    except urllib.error.HTTPError as exc:
        details = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"Ollama returned HTTP {exc.code}: {details}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"Could not connect to Ollama: {exc.reason}") from exc
    except TimeoutError as exc:
        raise RuntimeError(f"OCR request timed out after {request_timeout} seconds") from exc
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


def script_counts(text: str) -> tuple[int, int]:
    kanji = len(re.findall(r"[\u3400-\u9fff]", text))
    kana = len(re.findall(r"[\u3040-\u30ff\uff66-\uff9f]", text))
    return kanji, kana


def remove_likely_ruby(text: str) -> str:
    cleaned = LIKELY_RUBY_BEFORE_KANJI.sub("", text)
    for reading, kanji in COMMON_KANJI_RESTORATIONS.items():
        cleaned = cleaned.replace(reading, kanji)
    return normalize_name_separators(cleaned)


def invalid_review_reason(reviewed: str, draft: str) -> str | None:
    reason = invalid_output_reason(reviewed)
    if reason:
        return reason

    draft_kanji, draft_kana = script_counts(draft)
    reviewed_kanji, reviewed_kana = script_counts(reviewed)
    if reviewed_kanji < draft_kanji:
        return f"kanji decreased after proofreading ({draft_kanji} -> {reviewed_kanji})"
    if reviewed_kana > draft_kana:
        return f"kana increased after proofreading ({draft_kana} -> {reviewed_kana})"
    missing_kanji_terms = {
        term
        for term in re.findall(r"[\u3400-\u9fff]{2,}", draft)
        if term not in reviewed
    }
    if missing_kanji_terms:
        example = max(missing_kanji_terms, key=len)
        return f"kanji text disappeared after proofreading: {example}"
    return None


def request_valid_ocr(
    image_path: Path,
    model: str,
    base_url: str,
    temperature: float,
    num_predict: int,
    retries: int,
    request_timeout: int,
) -> str:
    last_reason = "unknown invalid output"
    for attempt in range(1, retries + 1):
        text = request_ocr(
            image_path, model, base_url, temperature, num_predict, request_timeout
        )
        reason = invalid_output_reason(text)
        if not reason:
            return remove_likely_ruby(text)
        last_reason = reason
        print(f"Invalid OCR output ({attempt}/{retries}): {reason}", file=sys.stderr)
    raise RuntimeError(f"OCR failed validation after {retries} attempts: {last_reason}")


def request_ocr_review(
    image_path: Path,
    draft: str,
    model: str,
    base_url: str,
    temperature: float,
    num_predict: int,
    request_timeout: int,
) -> str:
    payload = {
        "model": model,
        "messages": [
            {
                "role": "user",
                "content": f"{OCR_REVIEW_PROMPT}\n\nOCR初稿：\n{draft}",
                "images": [base64.b64encode(image_path.read_bytes()).decode("ascii")],
            }
        ],
        "think": False,
        "stream": False,
        "format": OCR_REVIEW_FORMAT,
        "options": {"temperature": temperature, "num_predict": num_predict},
    }
    request = urllib.request.Request(
        f"{base_url.rstrip('/')}/api/chat",
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=request_timeout) as response:
            result = json.load(response)
    except urllib.error.HTTPError as exc:
        details = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"OCR review returned HTTP {exc.code}: {details}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"Could not connect to OCR review Ollama: {exc.reason}") from exc
    except TimeoutError as exc:
        raise RuntimeError(
            f"OCR review request timed out after {request_timeout} seconds"
        ) from exc

    try:
        segments = json.loads(result["message"]["content"])["segments"]
        if not isinstance(segments, list) or not all(
            isinstance(segment, str) for segment in segments
        ):
            raise TypeError("segments is not a string array")
        return remove_likely_ruby("\n".join(
            segment.replace("\r", "").replace("\n", "").strip()
            for segment in segments
            if segment.strip()
        ))
    except (json.JSONDecodeError, KeyError, TypeError) as exc:
        raise RuntimeError(f"Unexpected OCR review response: {json.dumps(result)}") from exc


def ocr_and_review(image_path: Path, args: argparse.Namespace) -> str:
    page_started = time.monotonic()
    ocr_started = time.monotonic()
    text = request_valid_ocr(
        image_path,
        args.model,
        args.base_url,
        args.temperature,
        args.num_predict,
        args.retries,
        args.request_timeout,
    )
    print(f"OCR request completed in {format_elapsed(time.monotonic() - ocr_started)}")
    if args.no_review:
        print(f"Page completed in {format_elapsed(time.monotonic() - page_started)}")
        return text
    print(f"OCR proofreading: {image_path}")
    review_started = time.monotonic()
    reviewed = request_ocr_review(
        image_path,
        text,
        args.model,
        args.base_url,
        args.temperature,
        args.num_predict,
        args.request_timeout,
    )
    print(
        f"OCR proofreading completed in "
        f"{format_elapsed(time.monotonic() - review_started)}"
    )
    reason = invalid_review_reason(reviewed, text)
    if reason:
        print(
            f"Rejected OCR proofreading; using initial OCR: {reason}",
            file=sys.stderr,
        )
        print(f"Page completed in {format_elapsed(time.monotonic() - page_started)}")
        return text
    print(f"Page completed in {format_elapsed(time.monotonic() - page_started)}")
    return reviewed


def main() -> int:
    args = parse_args()
    task_started = time.monotonic()
    try:
        if args.review_page is not None:
            if args.review_page < 1:
                raise ValueError("--review-page must be greater than zero")
            if not args.image_dir or not args.combined_output:
                raise ValueError("--review-page requires --image-dir and --combined-output")
            if args.pages or args.images:
                raise ValueError("--review-page cannot be combined with --pages or image paths")
            images = [args.image_dir / f"page_{args.review_page:04d}.jpg"]
        else:
            images = select_images(args)
        for image_path in images:
            if not image_path.is_file():
                raise FileNotFoundError(f"Image does not exist: {image_path}")
        print(f"Selected {len(images)} image(s): {images[0]} through {images[-1]}")
        if args.dry_run:
            for image_path in images:
                print(image_path)
            return 0
        if args.num_predict < 1 or args.retries < 1 or args.request_timeout < 1:
            raise ValueError(
                "--num-predict, --retries and --request-timeout must be greater than zero"
            )

        args.output.mkdir(parents=True, exist_ok=True)
        combined_path = combined_output_path(args, images)
        combined_pages = []
        for image_path in images:
            output_path = args.output / f"{image_path.stem}.txt"
            if args.resume and args.review_page is None and output_path.is_file():
                text = output_path.read_text(encoding="utf-8").strip()
                reason = invalid_output_reason(text)
                if reason:
                    print(f"Redoing invalid output: {output_path} ({reason})")
                    text = ocr_and_review(image_path, args)
                    output_path.write_text(text.strip() + "\n", encoding="utf-8")
                    print(f"Saved: {output_path}")
                else:
                    print(f"Reused: {output_path}")
            else:
                print(f"OCR: {image_path}")
                text = ocr_and_review(image_path, args)
                output_path.write_text(text.strip() + "\n", encoding="utf-8")
                print(f"Saved: {output_path}")

            combined_pages.append((page_number(image_path), text))
            if args.review_page is None:
                write_combined_text(combined_path, combined_pages)

        if args.review_page is not None:
            replace_combined_page(combined_path, args.review_page, combined_pages[0][1])
            print(f"Replaced P{args.review_page} in combined text: {combined_path}")
        else:
            print(f"Saved combined text: {combined_path}")
        if not args.keep_page_files:
            for image_path in images:
                output_path = args.output / f"{image_path.stem}.txt"
                output_path.unlink(missing_ok=True)
            print(f"Deleted {len(images)} per-page intermediate text file(s)")
        print(f"Total elapsed: {format_elapsed(time.monotonic() - task_started)}")
    except (OSError, RuntimeError, ValueError) as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
