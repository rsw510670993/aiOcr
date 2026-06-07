import argparse
import sys
from pathlib import Path

import pymupdf


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Render each PDF page as a JPG file.")
    parser.add_argument("pdf", type=Path, help="Path to the input PDF")
    parser.add_argument("-o", "--output", type=Path, help="Output directory")
    parser.add_argument("-p", "--password", help="PDF password, when required")
    parser.add_argument("--dpi", type=int, default=150, help="Render DPI (default: 150)")
    parser.add_argument(
        "--quality",
        type=int,
        default=90,
        choices=range(1, 101),
        metavar="1-100",
        help="JPEG quality (default: 90)",
    )
    return parser.parse_args()


def pdf_to_jpg(
    pdf_path: Path,
    output_dir: Path,
    password: str | None,
    dpi: int,
    quality: int,
) -> int:
    if not pdf_path.is_file():
        raise FileNotFoundError(f"PDF does not exist: {pdf_path}")
    if dpi <= 0:
        raise ValueError("DPI must be greater than zero")

    with pymupdf.open(pdf_path) as document:
        if document.needs_pass and not document.authenticate(password or ""):
            raise PermissionError("The PDF is encrypted; provide the correct --password")

        page_count = document.page_count
        padding = max(4, len(str(page_count)))
        output_dir.mkdir(parents=True, exist_ok=True)

        for index, page in enumerate(document):
            pixmap = page.get_pixmap(dpi=dpi, colorspace=pymupdf.csRGB, alpha=False)
            output_path = output_dir / f"page_{index + 1:0{padding}d}.jpg"
            pixmap.save(output_path, jpg_quality=quality)

            completed = index + 1
            if completed == 1 or completed % 10 == 0 or completed == page_count:
                print(f"Exported {completed}/{page_count}: {output_path}")

    return page_count


def main() -> int:
    args = parse_args()
    output_dir = args.output or args.pdf.with_name(f"{args.pdf.stem}_jpg")

    try:
        page_count = pdf_to_jpg(
            args.pdf,
            output_dir,
            args.password,
            args.dpi,
            args.quality,
        )
    except (FileNotFoundError, PermissionError, ValueError, pymupdf.FileDataError) as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1

    print(f"Done. Exported {page_count} pages to: {output_dir.resolve()}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
