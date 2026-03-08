#!/usr/bin/env python3
"""
Format markdown tables for consistent alignment and readability.

Handles Unicode (emoji, CJK), escaped pipes, pipes inside backtick spans,
and preserves tables inside fenced code blocks.
"""

import argparse
import os
import re
import sys
import tempfile
import unicodedata
from collections import namedtuple
from typing import List, Optional


Block = namedtuple('Block', ['type', 'lines'])


def display_width(text: str) -> int:
    """Calculate the display width of a string, accounting for Unicode."""
    width = 0
    chars = list(text)
    for i, ch in enumerate(chars):
        # Zero-width joiner
        if ch == '\u200D':
            continue
        # Variation selectors
        if ch in ('\uFE0E', '\uFE0F'):
            continue
        # Combining marks
        if unicodedata.category(ch).startswith('M'):
            continue

        # Check if followed by emoji presentation selector
        if i + 1 < len(chars) and chars[i + 1] == '\uFE0F':
            width += 2
            continue

        eaw = unicodedata.east_asian_width(ch)
        if eaw in ('W', 'F'):
            width += 2
        else:
            width += 1
    return width


def split_row(line: str) -> List[str]:
    """Split a table row by pipes, respecting escaped pipes and backtick spans."""
    # Strip leading/trailing whitespace
    stripped = line.strip()

    # Strip leading and trailing pipes
    if stripped.startswith('|'):
        stripped = stripped[1:]
    if stripped.endswith('|') and not stripped.endswith('\\|'):
        stripped = stripped[:-1]

    cells: List[str] = []
    current: List[str] = []
    i = 0
    in_backtick = False
    backtick_count = 0

    while i < len(stripped):
        ch = stripped[i]

        if not in_backtick and ch == '`':
            # Count consecutive backticks
            count = 0
            while i < len(stripped) and stripped[i] == '`':
                count += 1
                i += 1
            in_backtick = True
            backtick_count = count
            current.append('`' * count)
            continue

        if in_backtick and ch == '`':
            # Check if this closes the backtick span
            count = 0
            j = i
            while j < len(stripped) and stripped[j] == '`':
                count += 1
                j += 1
            if count == backtick_count:
                current.append('`' * count)
                i = j
                in_backtick = False
                backtick_count = 0
                continue
            else:
                current.append('`' * count)
                i = j
                continue

        if not in_backtick and ch == '|':
            if i > 0 and stripped[i - 1] == '\\':
                current.append(ch)
                i += 1
                continue
            cells.append(''.join(current).strip())
            current = []
            i += 1
            continue

        current.append(ch)
        i += 1

    cells.append(''.join(current).strip())
    return cells


def is_separator_row(cells: List[str]) -> bool:
    """Check if cells form a valid separator row."""
    if not cells:
        return False
    for cell in cells:
        stripped = cell.strip()
        if not re.match(r'^:?-{1,}:?$', stripped):
            return False
    return True


def parse_alignment(cell: str) -> str:
    """Parse alignment from a separator cell."""
    stripped = cell.strip()
    left = stripped.startswith(':')
    right = stripped.endswith(':')
    if left and right:
        return 'center'
    if right:
        return 'right'
    if left:
        return 'left'
    return 'none'


def build_separator_cell(alignment: str, width: int) -> str:
    """Build a separator cell of given width preserving alignment markers."""
    width = max(width, 3)
    if alignment == 'center':
        return ':' + '-' * (width - 2) + ':'
    if alignment == 'right':
        return '-' * (width - 1) + ':'
    if alignment == 'left':
        return ':' + '-' * (width - 1)
    return '-' * width


def format_table(lines: List[str]) -> List[str]:
    """Format a markdown table with aligned columns."""
    if len(lines) < 2:
        return lines

    # Split all rows into cells
    all_cells = [split_row(line) for line in lines]

    # Validate: line 2 must be separator
    if not is_separator_row(all_cells[1]):
        return lines

    # All rows must have the same column count
    col_count = len(all_cells[0])
    for row in all_cells:
        if len(row) != col_count:
            return lines

    # Extract alignment from separator row
    alignments = [parse_alignment(cell) for cell in all_cells[1]]

    # Calculate max display_width per column (excluding separator)
    col_widths = [3] * col_count
    for i, row in enumerate(all_cells):
        if i == 1:  # skip separator
            continue
        for j, cell in enumerate(row):
            w = display_width(cell)
            if w > col_widths[j]:
                col_widths[j] = w

    # Build formatted lines
    result: List[str] = []
    for i, row in enumerate(all_cells):
        if i == 1:
            # Separator row
            parts = [build_separator_cell(alignments[j], col_widths[j]) for j in range(col_count)]
            result.append('| ' + ' | '.join(parts) + ' |')
        else:
            # Data row: pad each cell
            parts: List[str] = []
            for j, cell in enumerate(row):
                padding = col_widths[j] - display_width(cell)
                parts.append(cell + ' ' * padding)
            result.append('| ' + ' | '.join(parts) + ' |')

    return result


def parse_document(content: str) -> List[Block]:
    """Split document into blocks of text, code, and table."""
    lines = content.split('\n')
    blocks: List[Block] = []
    current_lines: List[str] = []
    current_type: Optional[str] = None
    in_code_block = False
    code_fence_pattern = re.compile(r'^(\s*)(```|~~~)')

    def flush_block() -> None:
        nonlocal current_lines, current_type
        if current_lines:
            if current_type == 'table':
                # Validate table
                cells_per_row = [split_row(line) for line in current_lines]
                valid = (
                    len(current_lines) >= 2
                    and is_separator_row(cells_per_row[1])
                    and len(set(len(r) for r in cells_per_row)) == 1
                )
                if not valid:
                    current_type = 'text'
            blocks.append(Block(type=current_type or 'text', lines=list(current_lines)))
            current_lines = []
            current_type = None

    for line in lines:
        # Check for code fence
        if code_fence_pattern.match(line):
            if in_code_block:
                # Closing fence
                current_lines.append(line)
                flush_block()
                in_code_block = False
                continue
            else:
                # Opening fence
                flush_block()
                in_code_block = True
                current_type = 'code'
                current_lines.append(line)
                continue

        if in_code_block:
            current_lines.append(line)
            continue

        # Check if line looks like a table row
        is_table_line = bool(re.match(r'^\s*\|', line))

        if is_table_line:
            if current_type != 'table':
                flush_block()
                current_type = 'table'
            current_lines.append(line)
        else:
            if current_type == 'table':
                flush_block()
            if current_type is None:
                current_type = 'text'
            current_lines.append(line)

    flush_block()
    return blocks


def process_file(path: str, check_only: bool, in_place: bool) -> bool:
    """Process a single file. Returns True if changes were made (or needed)."""
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    blocks = parse_document(content)

    output_parts: List[str] = []
    for block in blocks:
        if block.type == 'table':
            output_parts.append('\n'.join(format_table(block.lines)))
        else:
            output_parts.append('\n'.join(block.lines))

    result = '\n'.join(output_parts)

    changed = result != content

    if check_only:
        return changed

    if in_place:
        if changed:
            dir_name = os.path.dirname(os.path.abspath(path))
            fd, tmp_path = tempfile.mkstemp(dir=dir_name, suffix='.tmp')
            try:
                with os.fdopen(fd, 'w', encoding='utf-8') as f:
                    f.write(result)
                os.replace(tmp_path, path)
            except Exception:
                os.unlink(tmp_path)
                raise
        return changed

    # Default: output to stdout
    sys.stdout.write(result)
    return changed


def main() -> None:
    """Entry point for the markdown table formatter."""
    parser = argparse.ArgumentParser(
        description='Format markdown tables for consistent alignment.'
    )
    parser.add_argument('files', nargs='+', metavar='FILE', help='Markdown files to format')
    parser.add_argument('--check', action='store_true',
                        help='Check if files need formatting (exit 1 if yes)')
    group = parser.add_mutually_exclusive_group()
    group.add_argument('--in-place', '-i', action='store_true',
                       help='Edit files in place')

    args = parser.parse_args()

    any_changed = False
    for path in args.files:
        changed = process_file(path, check_only=args.check, in_place=args.in_place)
        if changed:
            any_changed = True
            if args.check:
                print(f'{path}: needs formatting', file=sys.stderr)

    if args.check and any_changed:
        sys.exit(1)


if __name__ == '__main__':
    main()
