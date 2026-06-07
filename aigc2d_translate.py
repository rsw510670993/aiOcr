import argparse
import json
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

from aigc2d_ocr import format_elapsed, parse_combined_text, write_combined_text
from ollama_qwen3_translate import (
    glossary_prompt,
    matched_glossary_terms,
    invalid_translation_reason,
    load_glossary,
    normalize_brackets,
    strip_thinking,
)


SYSTEM_PROMPT = """你是专业的日语漫画汉化翻译。
将输入的日语漫画 OCR 文本翻译为自然、简洁的简体中文。
严格使用提供的术语表译名，不得自行改写已有对应关系。
将同一句中因 OCR 排版产生的散乱断行合并为一行；标题、不同气泡、不同说话者和拟声词分别占一行。
页面内部不得输出空白行。忽略普通注音，不要重复译文。
最终译文不得残留平假名、片假名、日语正文、解释或 Markdown。
输入包含 P{页码} 标记时，必须原样保留每一个页码标记及页码顺序，不得遗漏、增加或翻译页码。
翻译时联系所有页面的前后文，保持人物称呼、语气和术语一致。
所有括号和引号只能使用「」与『』。
只输出 JSON：{"translation":"最终中文译文"}。"""


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Translate Japanese OCR with AIGC2D API.")
    parser.add_argument("input", type=Path, help="Combined OCR text containing P{page} markers")
    parser.add_argument("--pages", help="Inclusive page range, for example 7-56")
    parser.add_argument(
        "--glossary", type=Path, default=Path("名词表.csv"), help="CSV glossary path"
    )
    parser.add_argument(
        "--key-file", type=Path, default=Path("aigc2d.key"), help="API key file"
    )
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=Path("aigc2d_translation_text"),
        help="Per-page output directory",
    )
    parser.add_argument("--combined-output", type=Path, help="Combined translation path")
    parser.add_argument(
        "--base-url", default="https://next.aigc2d.com/v1/", help="AIGC2D API base URL"
    )
    parser.add_argument("--model", default="gemini-3.1-flash-lite", help="Model name")
    parser.add_argument("--max-tokens", type=int, default=8192)
    parser.add_argument("--retries", type=int, default=3)
    parser.add_argument("--request-timeout", type=int, default=300)
    parser.add_argument(
        "--per-page",
        action="store_true",
        help="Translate page by page instead of sending all selected pages together",
    )
    parser.add_argument("--resume", action="store_true")
    parser.add_argument("--keep-page-files", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    return parser.parse_args()


def filter_pages(
    pages: list[tuple[int, str]], page_range: str | None
) -> list[tuple[int, str]]:
    if not page_range:
        return pages
    try:
        start_text, end_text = page_range.split("-", maxsplit=1)
        start, end = int(start_text), int(end_text)
    except ValueError as exc:
        raise ValueError("--pages must use START-END, for example 7-56") from exc
    selected = [(page, text) for page, text in pages if start <= page <= end]
    if start < 1 or end < start or not selected:
        raise ValueError("--pages must match a positive range in the input")
    return selected


def parse_translation(content: str) -> str:
    cleaned = strip_thinking(content).strip()
    if cleaned.startswith("```"):
        cleaned = cleaned.removeprefix("```json").removeprefix("```")
        cleaned = cleaned.removesuffix("```").strip()
    try:
        translated = json.loads(cleaned)["translation"]
    except (json.JSONDecodeError, KeyError, TypeError) as exc:
        raise RuntimeError(f"Unexpected translation content: {content}") from exc
    if not isinstance(translated, str):
        raise RuntimeError("Translation response field is not a string")
    return normalize_brackets(
        "\n".join(line.strip() for line in translated.splitlines() if line.strip())
    )


def request_translation(
    source: str,
    terms: list[tuple[str, str]],
    skills: list[str],
    api_key: str,
    args: argparse.Namespace,
) -> str:
    prompt = (
        f"术语表：\n{glossary_prompt(source, terms, skills)}\n\n"
        f"待翻译日文：\n{source}"
    )
    payload = {
        "model": args.model,
        "messages": [
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user", "content": prompt},
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
        raise RuntimeError(f"AIGC2D API returned HTTP {exc.code}: {details}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"Could not connect to AIGC2D API: {exc.reason}") from exc
    except TimeoutError as exc:
        raise RuntimeError(
            f"AIGC2D translation timed out after {args.request_timeout} seconds"
        ) from exc
    try:
        return parse_translation(result["choices"][0]["message"]["content"])
    except (KeyError, IndexError, TypeError) as exc:
        raise RuntimeError(f"Unexpected AIGC2D response: {json.dumps(result)}") from exc


def translate_with_retries(
    source: str,
    terms: list[tuple[str, str]],
    skills: list[str],
    api_key: str,
    args: argparse.Namespace,
) -> str:
    last_reason = "unknown invalid translation"
    for attempt in range(1, args.retries + 1):
        started = time.monotonic()
        text = request_translation(source, terms, skills, api_key, args)
        print(f"API translation completed in {format_elapsed(time.monotonic() - started)}")
        reason = invalid_translation_reason(text, source, terms)
        if not reason:
            return text
        last_reason = reason
        print(f"Invalid translation ({attempt}/{args.retries}): {reason}", file=sys.stderr)
    raise RuntimeError(f"Translation failed after {args.retries} attempts: {last_reason}")


def validate_combined_translation(
    text: str,
    source_pages: list[tuple[int, str]],
    terms: list[tuple[str, str]],
) -> list[tuple[int, str]]:
    translated_pages = parse_combined_text(text)
    expected = [page for page, _ in source_pages]
    actual = [page for page, _ in translated_pages]
    if actual != expected:
        raise RuntimeError(f"Translated page markers do not match: {actual} != {expected}")
    source_by_page = dict(source_pages)
    for page, translated in translated_pages:
        reason = invalid_translation_reason(translated, source_by_page[page], terms)
        if reason:
            raise RuntimeError(f"Invalid translation on P{page}: {reason}")
    return translated_pages


def translate_all(
    pages: list[tuple[int, str]],
    terms: list[tuple[str, str]],
    skills: list[str],
    api_key: str,
    args: argparse.Namespace,
) -> list[tuple[int, str]]:
    source = "\n\n".join(f"P{page}\n{text}" for page, text in pages)
    last_error = "unknown invalid translation"
    for attempt in range(1, args.retries + 1):
        started = time.monotonic()
        try:
            text = request_translation(source, terms, skills, api_key, args)
            translated = validate_combined_translation(text, pages, terms)
            print(
                f"Whole-book API translation completed in "
                f"{format_elapsed(time.monotonic() - started)}"
            )
            return translated
        except RuntimeError as exc:
            last_error = str(exc)
            print(
                f"Invalid whole-book translation ({attempt}/{args.retries}): {exc}",
                file=sys.stderr,
            )
    raise RuntimeError(
        f"Whole-book translation failed after {args.retries} attempts: {last_error}"
    )


def main() -> int:
    args = parse_args()
    task_started = time.monotonic()
    try:
        pages = filter_pages(
            parse_combined_text(args.input.read_text(encoding="utf-8")), args.pages
        )
        terms, skills = load_glossary(args.glossary)
        args.combined_output = (
            args.combined_output or args.output_dir / args.input.name
        )
        print(
            f"Selected {len(pages)} page(s): P{pages[0][0]} through P{pages[-1][0]}; "
            f"loaded {len(terms)} term mapping(s); model: {args.model}"
        )
        if args.dry_run:
            print("Mode:", "per-page" if args.per_page else "whole-book")
            for page, source in pages:
                matched = len(matched_glossary_terms(source, terms))
                print(f"P{page}: {len(source)} source characters, {matched} matched terms")
            return 0
        if args.max_tokens < 1 or args.retries < 1 or args.request_timeout < 1:
            raise ValueError(
                "--max-tokens, --retries and --request-timeout must be greater than zero"
            )
        api_key = args.key_file.read_text(encoding="utf-8").strip()
        if not api_key:
            raise ValueError(f"API key file is empty: {args.key_file}")

        args.output_dir.mkdir(parents=True, exist_ok=True)
        if not args.per_page:
            if args.resume:
                raise ValueError("--resume requires --per-page")
            print("Translating all selected pages in one API request")
            translations = translate_all(pages, terms, skills, api_key, args)
            write_combined_text(args.combined_output, translations)
            print(f"Saved combined translation: {args.combined_output}")
            print(f"Total elapsed: {format_elapsed(time.monotonic() - task_started)}")
            return 0

        translations = []
        for page, source in pages:
            page_started = time.monotonic()
            output_path = args.output_dir / f"page_{page:04d}.txt"
            if args.resume and output_path.is_file():
                text = output_path.read_text(encoding="utf-8").strip()
                reason = invalid_translation_reason(text, source, terms)
                if reason:
                    print(f"Redoing invalid translation: {output_path} ({reason})")
                    text = translate_with_retries(source, terms, skills, api_key, args)
                    output_path.write_text(text + "\n", encoding="utf-8")
                else:
                    print(f"Reused: {output_path}")
            else:
                print(f"Translating P{page}")
                text = translate_with_retries(source, terms, skills, api_key, args)
                output_path.write_text(text + "\n", encoding="utf-8")
                print(f"Saved: {output_path}")
            translations.append((page, text))
            write_combined_text(args.combined_output, translations)
            print(f"P{page} completed in {format_elapsed(time.monotonic() - page_started)}")

        print(f"Saved combined translation: {args.combined_output}")
        if not args.keep_page_files:
            for page, _ in pages:
                (args.output_dir / f"page_{page:04d}.txt").unlink(missing_ok=True)
            print(f"Deleted {len(pages)} per-page intermediate translation file(s)")
        print(f"Total elapsed: {format_elapsed(time.monotonic() - task_started)}")
    except (OSError, RuntimeError, ValueError, KeyError) as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
