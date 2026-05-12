"""MkDocs hook: generates llms-full.txt from the nav structure.

Runs on post_build, reads English .md sources listed in mkdocs.yml nav,
strips MkDocs-specific syntax, and writes a single concatenated file
into the site output directory.

New pages added to nav are picked up automatically.
"""

from __future__ import annotations

import logging
import os
import re
from pathlib import Path
from typing import Any

log = logging.getLogger("mkdocs.hooks.generate_llms_txt")

_generated = False

# Pages to skip (by source path relative to docs_dir).
#
# Two reasons a page lives here:
#
# 1. Pure tutorial / onboarding content with no reference value for an agent
#    (installation, quick-start, usage-scenarios) — the agent already knows the
#    information from llms.txt and reference pages, the narrative is for humans.
# 2. The page is auto-mirrored from another source (changelog).
SKIP_PAGES = {
    "changelog.md",
    "getting-started/installation.md",
    "getting-started/quick-start.md",
    "usage/usage-scenarios.md",
}

HEADER = """\
# Qualimetrix — Full Documentation

> This file is auto-generated from the website documentation.
> It contains the complete reference for Qualimetrix in a single file,
> optimized for consumption by LLMs and AI coding agents.
>
> For a concise overview, see llms.txt instead.
> For human-readable documentation, visit https://qualimetrix.dev/

"""

SITE_URL = "https://qualimetrix.dev/"


def on_post_build(config: dict[str, Any], **kwargs: Any) -> None:
    """Generate llms-full.txt after the site is built."""
    global _generated  # noqa: PLW0603
    if _generated:
        return
    _generated = True

    docs_dir = Path(config["docs_dir"])
    site_dir = Path(config["site_dir"])
    nav = config.get("nav", [])

    pages = _collect_pages(nav)
    log.info("Generating llms-full.txt from %d pages", len(pages))

    sections: list[str] = [HEADER]
    sections.append(_build_toc(nav))
    sections.append("")

    for page_path in pages:
        if page_path in SKIP_PAGES:
            continue

        source = docs_dir / page_path
        if not source.exists():
            log.warning("Page not found, skipping: %s", page_path)
            continue

        content = source.read_text(encoding="utf-8")
        transformed = _transform(content, page_path)
        sections.append(transformed)

    output = "\n".join(sections)
    # Collapse 3+ blank lines into 2
    output = re.sub(r"\n{4,}", "\n\n\n", output)

    out_path = site_dir / "llms-full.txt"
    out_path.write_text(output, encoding="utf-8")
    log.info("Written llms-full.txt (%d bytes)", len(output))


def _collect_pages(nav: list[Any]) -> list[str]:
    """Recursively extract page paths from the nav structure.

    External URLs (http://, https://) are skipped — they are not local pages.
    """
    pages: list[str] = []
    for item in nav:
        if isinstance(item, str):
            if not _is_external_url(item):
                pages.append(item)
        elif isinstance(item, dict):
            for value in item.values():
                if isinstance(value, str):
                    if not _is_external_url(value):
                        pages.append(value)
                elif isinstance(value, list):
                    pages.extend(_collect_pages(value))
    return pages


def _is_external_url(value: str) -> bool:
    return value.startswith(("http://", "https://"))


def _build_toc(nav: list[Any], depth: int = 0) -> str:
    """Build a table of contents from nav."""
    lines: list[str] = []
    if depth == 0:
        lines.append("## Table of Contents\n")
    indent = "  " * depth
    for item in nav:
        if isinstance(item, str):
            continue
        elif isinstance(item, dict):
            for title, value in item.items():
                if isinstance(value, str):
                    if value not in SKIP_PAGES and not _is_external_url(value):
                        lines.append(f"{indent}- {title}")
                elif isinstance(value, list):
                    lines.append(f"{indent}- {title}")
                    lines.append(_build_toc(value, depth + 1))
    return "\n".join(lines)


_SKIP_BEGIN_RE = re.compile(r"<!--\s*llms:skip-begin\s*-->")
_SKIP_END_RE = re.compile(r"<!--\s*llms:skip-end\s*-->")
_ONLY_OPEN_RE = re.compile(r"<!--\s*llms-only\s*\n")
_ONLY_BLOCK_RE = re.compile(
    r"<!--\s*llms-only\s*\n(.*?)\s*-->", flags=re.DOTALL
)


def _validate_markers(content: str, page_path: str) -> None:
    """Warn about unbalanced or malformed llms markers.

    A typo (e.g. forgetting `skip-end`) otherwise fails silently — the
    non-greedy regex below just won't match and the marker plus body leak
    into llms-full.txt. We surface these so they show up during
    `mkdocs build` instead of hiding until someone reads the output.
    """
    begins = len(_SKIP_BEGIN_RE.findall(content))
    ends = len(_SKIP_END_RE.findall(content))
    if begins != ends:
        log.warning(
            "%s: unbalanced llms:skip markers (%d begin, %d end)",
            page_path, begins, ends,
        )

    only_opens = len(_ONLY_OPEN_RE.findall(content))
    only_matched = len(_ONLY_BLOCK_RE.findall(content))
    if only_opens != only_matched:
        log.warning(
            "%s: unbalanced llms-only blocks (%d opened, %d closed)",
            page_path, only_opens, only_matched,
        )


def _strip_llms_skip_blocks(content: str) -> str:
    """Remove content between <!-- llms:skip-begin --> and <!-- llms:skip-end --> markers.

    Limitations:
    - Nested skip blocks are not supported: a stray inner `skip-end` will
      terminate the outer block early.
    """
    return re.sub(
        r"<!--\s*llms:skip-begin\s*-->.*?<!--\s*llms:skip-end\s*-->",
        "",
        content,
        flags=re.DOTALL,
    )


def _unwrap_llms_only_blocks(content: str) -> str:
    """Unwrap `<!-- llms-only ... -->` blocks.

    Content placed inside a single multi-line HTML comment with the
    `llms-only` opener (everything until the next `-->`) is hidden from the
    rendered website (the whole comment is a single HTML comment block,
    so MkDocs renders nothing) but materializes in llms-full.txt.

    Use it to emit a compact, agent-friendly version of a section while
    the verbose human-facing version stays on the rendered page.

    Note: do not use the two-marker form `<!-- llms:only-begin --> ...
    <!-- llms:only-end -->` — MkDocs treats those as two separate comments
    and the markdown between them renders on the website.

    Limitations:
    - The body must not contain `-->`: it would terminate the HTML comment
      early in browsers and also stop the non-greedy regex here. If you
      need to reference the closing comment sequence, encode it (e.g.
      `--&gt;` or wrap with backticks).
    """
    return _ONLY_BLOCK_RE.sub(lambda match: match.group(1), content)


def _transform(content: str, page_path: str) -> str:
    """Transform MkDocs-flavored markdown into plain markdown."""
    _validate_markers(content, page_path)
    content = _strip_llms_skip_blocks(content)
    content = _unwrap_llms_only_blocks(content)
    lines = content.split("\n")
    result: list[str] = []
    i = 0

    while i < len(lines):
        line = lines[i]

        # Skip horizontal rules (standalone ---)
        if re.match(r"^---\s*$", line):
            i += 1
            continue

        # Transform admonitions: !!! type "title" or !!! type
        admonition_match = re.match(
            r'^(!{3})\s+(\w+)\s*(?:"([^"]*)")?\s*$', line
        )
        if admonition_match:
            admonition_type = admonition_match.group(2).capitalize()
            admonition_title = admonition_match.group(3)
            label = admonition_title or admonition_type
            result.append(f"> **{label}:**")
            i += 1
            # Collect indented body
            while i < len(lines) and (
                lines[i].startswith("    ") or lines[i].strip() == ""
            ):
                if lines[i].strip() == "":
                    result.append(">")
                else:
                    result.append(f"> {lines[i][4:]}")
                i += 1
            result.append("")
            continue

        # Transform tabbed content: === "Tab Title"
        tab_match = re.match(r'^===\s+"([^"]+)"\s*$', line)
        if tab_match:
            tab_title = tab_match.group(1)
            result.append(f"**{tab_title}:**")
            result.append("")
            i += 1
            # Collect indented body (dedent by 4 spaces)
            while i < len(lines) and (
                lines[i].startswith("    ") or lines[i].strip() == ""
            ):
                if lines[i].strip() == "":
                    result.append("")
                else:
                    result.append(lines[i][4:])
                i += 1
            continue

        # Convert relative .md links to absolute URLs
        line = _convert_links(line, page_path)

        result.append(line)
        i += 1

    return "\n".join(result) + "\n"


def _convert_links(line: str, page_path: str) -> str:
    """Convert relative markdown links to absolute site URLs."""

    def _replace(match: re.Match[str]) -> str:
        text = match.group(1)
        href = match.group(2)

        # Skip absolute URLs and anchors
        if href.startswith(("http://", "https://", "#", "mailto:")):
            return match.group(0)

        # Resolve relative path
        page_dir = os.path.dirname(page_path)
        resolved = os.path.normpath(os.path.join(page_dir, href))
        # Strip .md extension for URL
        resolved = re.sub(r"\.md$", "/", resolved)
        resolved = resolved.replace("\\", "/")
        url = SITE_URL + resolved

        return f"[{text}]({url})"

    return re.sub(r"\[([^\]]+)\]\(([^)]+)\)", _replace, line)


