import argparse
import base64
import csv
import json
import re
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

from aigc2d_ocr import parse_combined_text, replace_combined_page


SYSTEM_PROMPT = """你是专业的日语漫画汉化翻译。
将输入的日语漫画 OCR 文本翻译为自然、简洁的简体中文。
严格使用提供的术语表译名，不得自行改写已有对应关系。
保留原文的对话顺序、换行、拟声词、标点和语气；不要添加解释、注释、Markdown 或页码。
如果单独一行假名只是相邻汉字行的普通读音，请完全忽略该假名行，只翻译包含汉字的正文行。
纯假名的正文、助词、语气词、拟声词仍须正常翻译，不得因为没有汉字而忽略。
不要逐行分析、解释翻译过程、复述要求或重复原文；将跨行组成的一句话合并为自然中文。
输出前删除由注音造成的重复译文。例如相邻的“感想 / かんそう”只能输出一次“感想”。
输出前逐字检查，最终译文不得残留任何平假名、片假名或未翻译的日语正文。
所有括号和引号只能使用「」与『』；普通引用使用「」，嵌套引用或作品名使用『』。
只在 JSON 的 translation 字段中输出最终中文译文。"""

TRANSLATION_FORMAT = {
    "type": "object",
    "properties": {"translation": {"type": "string"}},
    "required": ["translation"],
}

VL_REVIEW_FORMAT = {
    "type": "object",
    "properties": {
        "segments": {
            "type": "array",
            "items": {"type": "string"},
        }
    },
    "required": ["segments"],
}

VL_REVIEW_PROMPT = """你是专业的漫画汉化校对。
请结合漫画图片、日语 OCR 原文和中文初译，输出校对后的简体中文译文。
修正错译、漏译、人物语气和标点；严格沿用提供的术语表。
初译与原文意思一致时不要擅自改变含义，只调整断句和表达。
不要沿用 OCR 为适应竖排和气泡宽度产生的断行。每个对话框中的完整句子必须合并为一行。
不同对话框、不同说话者、标题、拟声词之间仅使用一个换行分隔，不得输出空白行，也不要把整页合并成一段。
输出前逐段检查：如果某段中的连续行能够组成一句语法完整的话，必须删除这些行间换行。
忽略普通注音，不要重复译文，不要添加解释、Markdown、页码或原文。
必须翻译初译中残留的所有日语；最终结果不得包含任何平假名或片假名。
所有括号和引号只能使用「」与『』；普通引用使用「」，嵌套引用或作品名使用『』。
将标题、每个对话框和每个独立拟声词分别放入 JSON 的 segments 数组；每个数组元素内部不得换行。"""


def format_elapsed(seconds: float) -> str:
    minutes, remainder = divmod(seconds, 60)
    hours, minutes = divmod(int(minutes), 60)
    if hours:
        return f"{hours:d}h {minutes:02d}m {remainder:04.1f}s"
    if minutes:
        return f"{minutes:d}m {remainder:04.1f}s"
    return f"{remainder:.1f}s"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Translate page-marked Japanese OCR text with local Qwen3-30B-A3B."
    )
    parser.add_argument(
        "input",
        type=Path,
        help="Combined OCR text, or existing translation when using --vl-review-only",
    )
    parser.add_argument(
        "--ocr-input",
        type=Path,
        help="OCR text for --vl-review-only (default: qwen3_ocr_text/INPUT_NAME)",
    )
    parser.add_argument("--pages", help="Inclusive page range, for example 7-56")
    parser.add_argument(
        "--review-page",
        type=int,
        help="Redo one page and replace it in the existing --combined-output file",
    )
    parser.add_argument(
        "--glossary", type=Path, default=Path("名词表.csv"), help="CSV glossary path"
    )
    parser.add_argument(
        "--output-dir", type=Path, default=Path("translation_text"), help="Per-page output"
    )
    parser.add_argument(
        "--combined-output",
        type=Path,
        help="Combined translation path (default: translation_text/INPUT_NAME)",
    )
    parser.add_argument("--model", default="qwen3:30b", help="Ollama model name")
    parser.add_argument("--base-url", default="http://localhost:11434", help="Ollama URL")
    parser.add_argument(
        "--image-dir",
        type=Path,
        default=Path("exported_jpg"),
        help="Directory containing page_NNNN.jpg for VL proofreading",
    )
    parser.add_argument(
        "--vl-model",
        default="qwen3-vl:30b-a3b-instruct",
        help="Ollama vision model used for proofreading",
    )
    parser.add_argument(
        "--vl-base-url",
        default="http://localhost:11436",
        help="Ollama URL for VL proofreading",
    )
    parser.add_argument(
        "--no-vl-review",
        action="store_true",
        help="Skip the Qwen3-VL proofreading stage",
    )
    parser.add_argument(
        "--vl-review-only",
        action="store_true",
        help="Proofread and update the existing translation INPUT in place",
    )
    parser.add_argument("--temperature", type=float, default=0.1)
    parser.add_argument("--num-predict", type=int, default=4096)
    parser.add_argument("--retries", type=int, default=3)
    parser.add_argument(
        "--request-timeout",
        type=int,
        default=300,
        help="Timeout in seconds for each translation or VL proofreading request",
    )
    parser.add_argument("--resume", action="store_true", help="Reuse existing page translations")
    parser.add_argument(
        "--keep-page-files",
        action="store_true",
        help="Keep per-page translation files after the combined output is complete",
    )
    parser.add_argument("--dry-run", action="store_true", help="Show pages and glossary only")
    return parser.parse_args()


def filter_pages(pages: list[tuple[int, str]], page_range: str | None) -> list[tuple[int, str]]:
    if not page_range:
        return pages
    try:
        start_text, end_text = page_range.split("-", maxsplit=1)
        start, end = int(start_text), int(end_text)
    except ValueError as exc:
        raise ValueError("--pages must use the format START-END, for example 7-56") from exc
    if start < 1 or end < start:
        raise ValueError("--pages must be a positive range with START <= END")
    selected = [(page, text) for page, text in pages if start <= page <= end]
    if not selected:
        raise ValueError(f"No input pages matched --pages {page_range}")
    return selected


def load_glossary(path: Path) -> tuple[list[tuple[str, str]], list[str]]:
    if not path.is_file():
        raise FileNotFoundError(f"Glossary does not exist: {path}")

    with path.open(encoding="utf-8-sig", newline="") as glossary_file:
        rows = list(csv.DictReader(glossary_file))
    if not rows or "日语名" not in rows[0] or "中文名" not in rows[0]:
        raise ValueError("Glossary must contain headers: 日语名 and 中文名")

    terms: list[tuple[str, str]] = []
    skills: list[str] = []
    for row in rows:
        japanese = row.get("日语名")
        chinese = row.get("中文名")
        if japanese and chinese:
            terms.append((japanese.strip(), chinese.strip()))
        skill_text = row.get("角色的技能名")
        if skill_text:
            skills.extend(
                line.strip()
                for line in skill_text.splitlines()
                if line.strip()
            )
    return terms, skills


def glossary_prompt(source: str, terms: list[tuple[str, str]], skills: list[str]) -> str:
    relevant = [(jp, zh) for jp, zh in terms if jp in source]
    lines = [f"{jp} => {zh}" for jp, zh in relevant]
    if skills:
        lines.append("既有技能译名参考：" + "；".join(skills))
    return "\n".join(lines) if lines else "本页没有匹配到术语表条目。"


def strip_thinking(text: str) -> str:
    stripped = text.strip()
    if "</think>" in stripped.lower():
        closing_index = stripped.lower().rfind("</think>")
        return stripped[closing_index + len("</think>") :].strip()
    return re.sub(r"(?is)^\s*<think>.*?</think>\s*", "", stripped).strip()


def normalize_brackets(text: str) -> str:
    translation = str.maketrans(
        {
            "“": "「",
            "”": "」",
            "‘": "「",
            "’": "」",
            "(": "『",
            ")": "』",
            "（": "『",
            "）": "』",
            "[": "『",
            "]": "』",
            "【": "『",
            "】": "』",
            "《": "『",
            "》": "』",
            "〈": "『",
            "〉": "』",
            "{": "『",
            "}": "』",
            "｛": "『",
            "｝": "』",
            "<": "『",
            ">": "』",
        }
    )
    normalized = text.translate(translation)
    parts = normalized.split('"')
    if len(parts) == 1:
        return normalized
    return "".join(
        part + ("「" if index % 2 == 0 else "」")
        for index, part in enumerate(parts[:-1])
    ) + parts[-1]


def request_translation(
    source: str,
    terms: list[tuple[str, str]],
    skills: list[str],
    model: str,
    base_url: str,
    temperature: float,
    num_predict: int,
    request_timeout: int,
) -> str:
    prompt = (
        f"/no_think\n术语表：\n{glossary_prompt(source, terms, skills)}\n\n"
        f"待翻译日文：\n{source}"
    )
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user", "content": prompt},
        ],
        "think": False,
        "stream": False,
        "format": TRANSLATION_FORMAT,
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
        raise RuntimeError(
            f"Translation request timed out after {request_timeout} seconds"
        ) from exc

    try:
        message = result["message"]
        content = strip_thinking(message.get("content") or "")
        translated = json.loads(content)["translation"]
        if not isinstance(translated, str):
            raise TypeError("translation is not a string")
        return normalize_brackets(translated.strip())
    except (json.JSONDecodeError, KeyError, TypeError) as exc:
        raise RuntimeError(f"Unexpected Ollama response: {json.dumps(result)}") from exc


def request_vl_review(
    image_path: Path,
    source: str,
    draft: str,
    terms: list[tuple[str, str]],
    skills: list[str],
    args: argparse.Namespace,
    request_timeout: int,
) -> str:
    prompt = (
        f"/no_think\n术语表：\n{glossary_prompt(source, terms, skills)}\n\n"
        f"日语 OCR 原文：\n{source}\n\n中文初译：\n{draft}"
    )
    payload = {
        "model": args.vl_model,
        "messages": [
            {"role": "system", "content": VL_REVIEW_PROMPT},
            {
                "role": "user",
                "content": prompt,
                "images": [base64.b64encode(image_path.read_bytes()).decode("ascii")],
            },
        ],
        "think": False,
        "stream": False,
        "format": VL_REVIEW_FORMAT,
        "options": {"temperature": args.temperature, "num_predict": args.num_predict},
    }
    request = urllib.request.Request(
        f"{args.vl_base_url.rstrip('/')}/api/chat",
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=request_timeout) as response:
            result = json.load(response)
    except urllib.error.HTTPError as exc:
        details = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"VL proofreading returned HTTP {exc.code}: {details}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"Could not connect to VL proofreading Ollama: {exc.reason}") from exc
    except TimeoutError as exc:
        raise RuntimeError(
            f"VL proofreading request timed out after {request_timeout} seconds"
        ) from exc

    try:
        content = strip_thinking(result["message"].get("content") or "")
        segments = json.loads(content)["segments"]
        if not isinstance(segments, list) or not all(
            isinstance(segment, str) for segment in segments
        ):
            raise TypeError("segments is not a string array")
        cleaned = [
            re.sub(r"[\r\n]+", "", segment).strip()
            for segment in segments
            if segment.strip()
        ]
        return normalize_brackets("\n".join(cleaned))
    except (json.JSONDecodeError, KeyError, TypeError) as exc:
        raise RuntimeError(f"Unexpected VL proofreading response: {json.dumps(result)}") from exc


def invalid_translation_reason(text: str, source: str | None = None) -> str | None:
    stripped = text.strip()
    if not stripped:
        return "empty translation"
    if "<think>" in stripped.lower():
        return "thinking output detected"
    if re.search(r"(?m)^P\d+\s*$", stripped):
        return "unexpected page marker detected"
    japanese_match = re.search(r"[\u3040-\u30ff\uff66-\uff9f]", stripped)
    if japanese_match:
        return f"untranslated Japanese kana detected: {japanese_match.group(0)}"
    disallowed_bracket = re.search(
        r'["“”‘’()（）\[\]【】《》〈〉{}｛｝<>]', stripped
    )
    if disallowed_bracket:
        return f"disallowed bracket detected: {disallowed_bracket.group(0)}"
    analysis_markers = (
        "首先，用户要求",
        "我需要翻译",
        "分析文本",
        "翻译步骤",
        "最终翻译",
        "现在，写输出",
        "提供的术语表",
    )
    if any(marker in stripped for marker in analysis_markers):
        return "analysis or explanation detected"
    if source and len(stripped) > max(1000, len(source) * 8):
        return (
            f"translation is too long relative to source "
            f"({len(stripped)} vs {len(source)} characters)"
        )
    if len(stripped) > 10000:
        return f"translation is abnormally long ({len(stripped)} characters)"
    return None


def translate_with_retries(
    source: str,
    terms: list[tuple[str, str]],
    skills: list[str],
    args: argparse.Namespace,
    deadline: float | None = None,
) -> str:
    last_reason = "unknown invalid translation"
    deadline = deadline or time.monotonic() + args.request_timeout
    for attempt in range(1, args.retries + 1):
        remaining = int(deadline - time.monotonic())
        if remaining < 1:
            raise RuntimeError(
                f"Translation page timed out after {args.request_timeout} seconds"
            )
        request_started = time.monotonic()
        text = request_translation(
            source,
            terms,
            skills,
            args.model,
            args.base_url,
            args.temperature,
            args.num_predict,
            remaining,
        )
        print(
            f"Translation request completed in "
            f"{format_elapsed(time.monotonic() - request_started)}"
        )
        reason = invalid_translation_reason(text, source)
        if not reason:
            return text.strip()
        last_reason = reason
        print(f"Invalid translation ({attempt}/{args.retries}): {reason}", file=sys.stderr)
    raise RuntimeError(f"Translation failed after {args.retries} attempts: {last_reason}")


def review_translation(
    page: int,
    source: str,
    draft: str,
    terms: list[tuple[str, str]],
    skills: list[str],
    args: argparse.Namespace,
    request_timeout: int | None = None,
) -> str:
    image_path = args.image_dir / f"page_{page:04d}.jpg"
    if not image_path.is_file():
        raise FileNotFoundError(f"VL proofreading image does not exist: {image_path}")
    timeout = request_timeout if request_timeout is not None else args.request_timeout
    print(f"VL proofreading P{page}")
    started = time.monotonic()
    text = request_vl_review(image_path, source, draft, terms, skills, args, timeout)
    print(f"VL proofreading completed in {format_elapsed(time.monotonic() - started)}")
    reason = invalid_translation_reason(text, source)
    if reason:
        raise RuntimeError(f"VL proofreading produced invalid translation: {reason}")
    return text


def translate_and_review(
    page: int,
    source: str,
    initial_draft: str | None,
    terms: list[tuple[str, str]],
    skills: list[str],
    args: argparse.Namespace,
) -> str:
    deadline = time.monotonic() + args.request_timeout
    draft = initial_draft
    last_error = "unknown proofreading failure"
    for attempt in range(1, args.retries + 1):
        remaining = int(deadline - time.monotonic())
        if remaining < 1:
            raise RuntimeError(
                f"Translation and VL proofreading page timed out after "
                f"{args.request_timeout} seconds"
            )
        if draft is None:
            print(f"Retranslating P{page} before VL proofreading ({attempt}/{args.retries})")
            draft = translate_with_retries(source, terms, skills, args, deadline=deadline)
        remaining = int(deadline - time.monotonic())
        if remaining < 1:
            raise RuntimeError(
                f"Translation and VL proofreading page timed out after "
                f"{args.request_timeout} seconds"
            )
        try:
            return review_translation(
                page, source, draft, terms, skills, args, request_timeout=remaining
            )
        except RuntimeError as exc:
            last_error = str(exc)
            print(
                f"VL proofreading failed ({attempt}/{args.retries}); "
                f"will retranslate: {exc}",
                file=sys.stderr,
            )
            draft = None
    raise RuntimeError(
        f"Translation and VL proofreading failed after {args.retries} attempts: "
        f"{last_error}"
    )


def write_combined(path: Path, translations: list[tuple[int, str]]) -> None:
    content = "\n\n".join(f"P{page}\n{text.strip()}" for page, text in translations) + "\n"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")


def main() -> int:
    args = parse_args()
    task_started = time.monotonic()
    try:
        if args.vl_review_only:
            if args.combined_output and args.combined_output.resolve() != args.input.resolve():
                raise ValueError(
                    "--vl-review-only updates INPUT in place; do not use "
                    "--combined-output for a different file"
                )
            args.combined_output = args.input
            source_path = args.ocr_input or Path("qwen3_ocr_text") / args.input.name
        else:
            if args.ocr_input:
                raise ValueError("--ocr-input can only be used with --vl-review-only")
            source_path = args.input
            args.combined_output = (
                args.combined_output or Path("translation_text") / args.input.name
            )

        input_pages = parse_combined_text(source_path.read_text(encoding="utf-8"))
        if args.review_page is not None:
            if args.review_page < 1:
                raise ValueError("--review-page must be greater than zero")
            if args.pages:
                raise ValueError("--review-page cannot be combined with --pages")
            if not args.combined_output.is_file():
                raise FileNotFoundError(
                    f"--review-page requires an existing combined output: {args.combined_output}"
                )
            pages = [(page, text) for page, text in input_pages if page == args.review_page]
            if not pages:
                raise ValueError(f"Input does not contain P{args.review_page}: {source_path}")
        else:
            pages = filter_pages(input_pages, args.pages)
        terms, skills = load_glossary(args.glossary)
        print(
            f"Selected {len(pages)} page(s): P{pages[0][0]} through P{pages[-1][0]}; "
            f"loaded {len(terms)} term mapping(s)"
        )
        if args.dry_run:
            for page, source in pages:
                matched = sum(1 for japanese, _ in terms if japanese in source)
                print(f"P{page}: {len(source)} source characters, {matched} matched terms")
            return 0
        if args.num_predict < 1 or args.retries < 1 or args.request_timeout < 1:
            raise ValueError(
                "--num-predict, --retries and --request-timeout must be greater than zero"
            )
        existing_translations = {}
        existing_translation_pages = []
        if args.vl_review_only:
            if args.no_vl_review:
                raise ValueError("--vl-review-only cannot be combined with --no-vl-review")
            if args.review_page is not None:
                raise ValueError("--vl-review-only cannot be combined with --review-page")
            if not args.combined_output.is_file():
                raise FileNotFoundError(
                    f"--vl-review-only requires an existing combined output: "
                    f"{args.combined_output}"
                )
            existing_translation_pages = parse_combined_text(
                args.combined_output.read_text(encoding="utf-8")
            )
            existing_translations = dict(existing_translation_pages)
            missing = [page for page, _ in pages if page not in existing_translations]
            if missing:
                raise ValueError(
                    f"Combined translation is missing page(s): "
                    f"{', '.join(f'P{page}' for page in missing)}"
                )

        args.output_dir.mkdir(parents=True, exist_ok=True)
        translations = []
        for page, source in pages:
            page_started = time.monotonic()
            output_path = args.output_dir / f"page_{page:04d}.txt"
            if args.vl_review_only:
                print(f"VL proofreading existing P{page}")
                text = translate_and_review(
                    page,
                    source,
                    existing_translations[page],
                    terms,
                    skills,
                    args,
                )
                if args.keep_page_files:
                    output_path.write_text(text + "\n", encoding="utf-8")
                    print(f"Saved: {output_path}")
            elif args.resume and args.review_page is None and output_path.is_file():
                text = output_path.read_text(encoding="utf-8").strip()
                reason = invalid_translation_reason(text, source)
                if reason:
                    print(f"Redoing invalid translation: {output_path} ({reason})")
                    if not args.no_vl_review:
                        text = translate_and_review(page, source, None, terms, skills, args)
                    else:
                        text = translate_with_retries(source, terms, skills, args)
                    output_path.write_text(text + "\n", encoding="utf-8")
                else:
                    print(f"Reused: {output_path}")
            else:
                print(f"Translating P{page}")
                if not args.no_vl_review:
                    text = translate_and_review(page, source, None, terms, skills, args)
                else:
                    text = translate_with_retries(source, terms, skills, args)
                output_path.write_text(text + "\n", encoding="utf-8")
                print(f"Saved: {output_path}")

            translations.append((page, text))
            if args.vl_review_only:
                existing_translations[page] = text
                write_combined(
                    args.combined_output,
                    [
                        (number, existing_translations[number])
                        for number, _ in existing_translation_pages
                    ],
                )
                if not args.keep_page_files:
                    output_path.unlink(missing_ok=True)
                print(f"Updated P{page} in combined translation")
            elif args.review_page is None:
                write_combined(args.combined_output, translations)
            print(f"P{page} completed in {format_elapsed(time.monotonic() - page_started)}")

        if args.review_page is not None:
            replace_combined_page(args.combined_output, args.review_page, translations[0][1])
            print(
                f"Replaced P{args.review_page} in combined translation: "
                f"{args.combined_output}"
            )
        else:
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
