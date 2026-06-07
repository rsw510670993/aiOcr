import argparse
import base64
from collections import Counter
import json
import mimetypes
import re
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path


SYSTEM_PROMPT = """あなたは日本語漫画のOCRエンジンです。
画像内の日本語縦書きを、上から下、右の列から左の列の順で読み取ってください。
本文だけを出力し、説明、Markdown、コードブロックは追加しないでください。
同じ吹き出し内の文章は、縦書きや吹き出し幅による改行を残さず、必ず一行にまとめてください。
タイトル、別の吹き出し、別の話者、独立した効果音は、それぞれ一行で出力してください。
人名の区切り記号には必ず「・」を使い、「＝」や「=」を混在させないでください。
行と行の間に空白行を入れないでください。判読不能な文字は「〓」にしてください。"""

CONSISTENCY_PROMPT = """你是日语漫画 OCR 全文一致性检查器。
检查带有 P{页码} 标记的完整 OCR 文本，找出跨页人物名、专有名词、称呼明显不一致的页面。
姓名分隔符统一使用「・」，出现「＝」或「=」时应修正为「・」。
例如前页持续出现「リン・リーチェ」，下一页突然出现形近但不合理的「リン・ハリナ」，应标记下一页重新识别。
只标记明显可疑的页面，不要因为正常简称、敬称变化或不同人物而误报。
只输出 JSON：{"issues":[{"page":49,"reason":"人物名与前文不一致","expected":"リン・リーチェ"}]}。
没有问题时输出：{"issues":[]}。"""

LIKELY_RUBY_BEFORE_KANJI = re.compile(
    r"(?<![\u3040-\u309f])[\u3040-\u309f]{7,}(?=[\u3400-\u9fff]{2,})"
)
COMMON_KANJI_RESTORATIONS = {"きょうから": "今日から"}
NAME_SEPARATOR_PATTERN = re.compile(
    r"(?<=[\u30a0-\u30ffー])(?:＝|=)(?=[\u30a0-\u30ffー])"
)


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
        "--review-page",
        type=int,
        help="Redo one page and replace it in the existing --combined-output file",
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
    parser.add_argument("--retries", type=int, default=3, help="Retries for invalid output")
    parser.add_argument(
        "--request-timeout",
        type=int,
        default=300,
        help="Timeout in seconds for each API request",
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
        "--no-consistency-check",
        action="store_true",
        help="Skip the whole-text consistency check after OCR",
    )
    parser.add_argument(
        "--consistency-check-only",
        action="store_true",
        help="Check an existing --combined-output and redo only inconsistent pages",
    )
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


def format_elapsed(seconds: float) -> str:
    minutes, remainder = divmod(seconds, 60)
    hours, minutes = divmod(int(minutes), 60)
    if hours:
        return f"{hours:d}h {minutes:02d}m {remainder:04.1f}s"
    if minutes:
        return f"{minutes:d}m {remainder:04.1f}s"
    return f"{remainder:.1f}s"


def remove_likely_ruby(text: str) -> str:
    cleaned = LIKELY_RUBY_BEFORE_KANJI.sub("", text)
    for reading, kanji in COMMON_KANJI_RESTORATIONS.items():
        cleaned = cleaned.replace(reading, kanji)
    return normalize_name_separators(cleaned)


def normalize_name_separators(text: str) -> str:
    return NAME_SEPARATOR_PATTERN.sub("・", text)


def normalize_ocr_layout(text: str) -> str:
    return "\n".join(line.strip() for line in text.splitlines() if line.strip())


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


def request_ocr(
    image_path: Path,
    api_key: str,
    base_url: str,
    model: str,
    max_tokens: int,
    request_timeout: int,
    context_hint: str | None = None,
) -> str:
    instruction = "この画像を指定された順序で正確に文字起こししてください。"
    if context_hint:
        instruction += (
            "\n全文整合性チェックで次の疑いが見つかりました。画像を再確認し、"
            "前後の文脈と固有名詞の表記を慎重に照合してください。"
            f"\n{context_hint}"
        )
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": SYSTEM_PROMPT},
            {
                "role": "user",
                "content": [
                    {
                        "type": "text",
                        "text": instruction,
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
        with urllib.request.urlopen(request, timeout=request_timeout) as response:
            result = json.load(response)
    except urllib.error.HTTPError as exc:
        details = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"AIGC2D API returned HTTP {exc.code}: {details}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"Could not connect to AIGC2D API: {exc.reason}") from exc
    except TimeoutError as exc:
        raise RuntimeError(
            f"AIGC2D request timed out after {request_timeout} seconds"
        ) from exc

    try:
        return result["choices"][0]["message"]["content"]
    except (KeyError, IndexError, TypeError) as exc:
        raise RuntimeError(f"Unexpected AIGC2D response: {json.dumps(result)}") from exc


def request_valid_ocr(
    image_path: Path,
    api_key: str,
    args: argparse.Namespace,
    context_hint: str | None = None,
) -> str:
    last_reason = "unknown invalid output"
    for attempt in range(1, args.retries + 1):
        started = time.monotonic()
        text = request_ocr(
            image_path,
            api_key,
            args.base_url,
            args.model,
            args.max_tokens,
            args.request_timeout,
            context_hint,
        )
        print(f"API request completed in {format_elapsed(time.monotonic() - started)}")
        text = normalize_ocr_layout(remove_likely_ruby(text))
        reason = invalid_output_reason(text)
        if not reason:
            return text.strip()
        last_reason = reason
        print(f"Invalid OCR output ({attempt}/{args.retries}): {reason}", file=sys.stderr)
    raise RuntimeError(f"OCR failed validation after {args.retries} attempts: {last_reason}")


def parse_json_content(content: str) -> dict:
    cleaned = content.strip()
    if cleaned.startswith("```"):
        cleaned = cleaned.removeprefix("```json").removeprefix("```")
        cleaned = cleaned.removesuffix("```").strip()
    try:
        result = json.loads(cleaned)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"Unexpected consistency-check content: {content}") from exc
    if not isinstance(result, dict):
        raise RuntimeError("Consistency-check response is not a JSON object")
    return result


def request_consistency_check(
    pages: list[tuple[int, str]],
    api_key: str,
    args: argparse.Namespace,
) -> list[dict]:
    combined = format_combined_text(pages)
    payload = {
        "model": args.model,
        "messages": [
            {"role": "system", "content": CONSISTENCY_PROMPT},
            {"role": "user", "content": combined},
        ],
        "max_tokens": args.max_tokens,
        "stream": False,
    }
    request = urllib.request.Request(
        f"{args.base_url.rstrip('/')}/chat/completions",
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=args.request_timeout) as response:
            result = json.load(response)
    except urllib.error.HTTPError as exc:
        details = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(
            f"AIGC2D consistency check returned HTTP {exc.code}: {details}"
        ) from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(
            f"Could not connect for AIGC2D consistency check: {exc.reason}"
        ) from exc
    except TimeoutError as exc:
        raise RuntimeError(
            f"AIGC2D consistency check timed out after {args.request_timeout} seconds"
        ) from exc

    try:
        issues = parse_json_content(result["choices"][0]["message"]["content"]).get(
            "issues", []
        )
    except (KeyError, IndexError, TypeError) as exc:
        raise RuntimeError(
            f"Unexpected AIGC2D consistency response: {json.dumps(result)}"
        ) from exc
    if not isinstance(issues, list):
        raise RuntimeError("Consistency-check issues field is not a list")
    valid_pages = {page for page, _ in pages}
    canonical_names: dict[str, tuple[str, int]] = {}
    for page, text in pages:
        for name in re.findall(r"[\u30a0-\u30ffー]+[・＝=][\u30a0-\u30ffー]+", text):
            normalized = normalize_consistency_term(name)
            if normalized not in canonical_names:
                canonical_names[normalized] = (name, page)
                continue
            canonical, canonical_page = canonical_names[normalized]
            if name != canonical:
                issues.append(
                    {
                        "page": page,
                        "reason": (
                            f"人物名表記「{name}」がP{canonical_page}の"
                            f"「{canonical}」と不一致"
                        ),
                        "expected": canonical,
                    }
                )

    grouped: dict[int, dict] = {}
    for issue in issues:
        if (
            not isinstance(issue, dict)
            or not isinstance(issue.get("page"), int)
            or issue["page"] not in valid_pages
        ):
            continue
        page = issue["page"]
        entry = grouped.setdefault(page, {"page": page, "reasons": [], "expected": []})
        reason = str(issue.get("reason", "")).strip()
        expected = str(issue.get("expected", "")).strip()
        if reason and reason not in entry["reasons"]:
            entry["reasons"].append(reason)
        if expected and expected not in entry["expected"]:
            entry["expected"].append(expected)
    return list(grouped.values())


def normalize_consistency_term(text: str) -> str:
    without_separators = re.sub(r"[・＝=]", "", text)
    return re.sub(
        r"[^\u3040-\u30ff\u3400-\u9fffA-Za-z0-9]", "", without_separators
    )


def recheck_consistency_issues(
    issues: list[dict],
    pages: list[tuple[int, str]],
    images_by_page: dict[int, Path],
    api_key: str,
    args: argparse.Namespace,
    combined_path: Path,
) -> list[tuple[int, str]]:
    page_text = dict(pages)
    for issue in issues:
        page = issue["page"]
        reasons = issue.get("reasons") or ["跨页内容不一致"]
        expected = issue.get("expected") or []
        hint = f"対象ページ: P{page}\n疑い:\n" + "\n".join(
            f"- {reason}" for reason in reasons
        )
        if expected:
            hint += "\n前後文から予想される正確な表記:\n" + "\n".join(
                f"- {term}" for term in expected
            )
            hint += "\n画像に該当人物・用語がある場合、この表記を一字も変えず使用してください。"
        print(f"Consistency recheck P{page}: {'; '.join(reasons)}")
        original_normalized = normalize_consistency_term(page_text[page])
        required_expected = [
            term
            for term in expected
            if normalize_consistency_term(term) in original_normalized
        ]
        last_missing = []
        for attempt in range(1, args.retries + 1):
            text = request_valid_ocr(
                images_by_page[page], api_key, args, context_hint=hint
            )
            last_missing = [term for term in required_expected if term not in text]
            if not last_missing:
                break
            print(
                f"Consistency recheck missing expected term(s) "
                f"({attempt}/{args.retries}): {', '.join(last_missing)}",
                file=sys.stderr,
            )
        if last_missing:
            raise RuntimeError(
                f"Consistency recheck P{page} did not contain expected term(s): "
                f"{', '.join(last_missing)}"
            )
        page_text[page] = text
        output_path = args.output / f"page_{page:04d}.txt"
        output_path.write_text(text + "\n", encoding="utf-8")
        write_combined_text(
            combined_path, [(number, page_text[number]) for number, _ in pages]
        )
        print(f"Updated P{page} after consistency recheck")
    return [(number, page_text[number]) for number, _ in pages]


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
            if args.consistency_check_only:
                raise ValueError(
                    "--review-page cannot be combined with --consistency-check-only"
                )
            images = [args.image_dir / f"page_{args.review_page:04d}.jpg"]
        else:
            images = select_images(args)
        for image_path in images:
            if not image_path.is_file():
                raise FileNotFoundError(f"Image does not exist: {image_path}")

        print(
            f"Selected {len(images)} image(s): {images[0]} through {images[-1]}; "
            f"model: {args.model}"
        )
        if args.dry_run:
            for image_path in images:
                print(image_path)
            return 0

        api_key = args.key_file.read_text(encoding="utf-8").strip()
        if not api_key:
            raise ValueError(f"API key file is empty: {args.key_file}")
        if args.max_tokens < 1 or args.retries < 1 or args.request_timeout < 1:
            raise ValueError(
                "--max-tokens, --retries and --request-timeout must be greater than zero"
            )
        args.output.mkdir(parents=True, exist_ok=True)

        combined_path = combined_output_path(args, images)
        images_by_page = {page_number(image): image for image in images}
        if args.consistency_check_only:
            if args.no_consistency_check:
                raise ValueError(
                    "--consistency-check-only cannot be combined with "
                    "--no-consistency-check"
                )
            if not combined_path.is_file():
                raise FileNotFoundError(
                    f"Consistency-check combined file does not exist: {combined_path}"
                )
            combined_pages = parse_combined_text(
                combined_path.read_text(encoding="utf-8")
            )
            missing_images = [
                page for page, _ in combined_pages if page not in images_by_page
            ]
            if missing_images:
                raise ValueError(
                    "Selected images do not cover combined page(s): "
                    + ", ".join(f"P{page}" for page in missing_images)
                )
            started = time.monotonic()
            print("Checking whole-text consistency")
            issues = request_consistency_check(combined_pages, api_key, args)
            print(
                f"Consistency check completed in "
                f"{format_elapsed(time.monotonic() - started)}; "
                f"found {len(issues)} issue(s)"
            )
            if issues:
                recheck_consistency_issues(
                    issues,
                    combined_pages,
                    images_by_page,
                    api_key,
                    args,
                    combined_path,
                )
            if not args.keep_page_files:
                for issue in issues:
                    (args.output / f"page_{issue['page']:04d}.txt").unlink(
                        missing_ok=True
                    )
            print(f"Total elapsed: {format_elapsed(time.monotonic() - task_started)}")
            return 0

        combined_pages = []
        for image_path in images:
            page_started = time.monotonic()
            output_path = args.output / f"{image_path.stem}.txt"
            if args.resume and output_path.is_file():
                text = output_path.read_text(encoding="utf-8").strip()
                normalized = normalize_ocr_layout(remove_likely_ruby(text))
                if normalized != text:
                    text = normalized
                    output_path.write_text(text + "\n", encoding="utf-8")
                    print(f"Normalized: {output_path}")
                reason = invalid_output_reason(text)
                if reason:
                    print(f"Redoing invalid output: {output_path} ({reason})")
                    text = request_valid_ocr(image_path, api_key, args)
                    output_path.write_text(text + "\n", encoding="utf-8")
                    print(f"Saved: {output_path}")
                else:
                    print(f"Reused: {output_path}")
            else:
                print(f"OCR: {image_path}")
                text = request_valid_ocr(image_path, api_key, args)
                output_path.write_text(text + "\n", encoding="utf-8")
                print(f"Saved: {output_path}")

            combined_pages.append((page_number(image_path), text))
            if args.review_page is None:
                write_combined_text(combined_path, combined_pages)
            print(
                f"P{page_number(image_path)} completed in "
                f"{format_elapsed(time.monotonic() - page_started)}"
            )

        if args.review_page is not None:
            replace_combined_page(combined_path, args.review_page, combined_pages[0][1])
            print(f"Replaced P{args.review_page} in combined text: {combined_path}")
        else:
            print(f"Saved combined text: {combined_path}")
        if (
            args.review_page is None
            and not args.no_consistency_check
            and len(combined_pages) > 1
        ):
            started = time.monotonic()
            print("Checking whole-text consistency")
            issues = request_consistency_check(combined_pages, api_key, args)
            print(
                f"Consistency check completed in "
                f"{format_elapsed(time.monotonic() - started)}; "
                f"found {len(issues)} issue(s)"
            )
            if issues:
                combined_pages = recheck_consistency_issues(
                    issues,
                    combined_pages,
                    images_by_page,
                    api_key,
                    args,
                    combined_path,
                )
        if not args.keep_page_files:
            for image_path in images:
                (args.output / f"{image_path.stem}.txt").unlink(missing_ok=True)
            print(f"Deleted {len(images)} per-page intermediate text file(s)")
        print(f"Total elapsed: {format_elapsed(time.monotonic() - task_started)}")
    except (OSError, RuntimeError, ValueError) as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
