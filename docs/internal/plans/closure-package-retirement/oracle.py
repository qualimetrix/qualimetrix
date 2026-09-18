#!/usr/bin/env python3
"""Prove a column removal moved nothing else.

Cuts the named columns out of the BEFORE copy of each generated file and
compares the result with AFTER. Equality is the proof: a change in any
surviving column cannot cancel out, because the cut BEFORE is exactly what
AFTER has to be.

Comparison is on bytes, not on decoded lines: a trailing-newline change or a
CRLF would otherwise pass, and those are real differences in a file the
repository checks for freshness. Every file in the directory is compared, not
only the .tsv ones - a non-carrier must come out byte-identical, and the .txt
siblings are exactly where a dropped column would hide from a TSV-only sweep.

Usage: oracle.py <before-dir> <after-dir> <column-name>...
Exit 0 agreed, 1 disagreed, 2 could not compare.
"""
import sys
import pathlib


def cut(path, names):
    """Return the file's bytes with the named columns removed.

    Splitting on b'\\n' and rejoining preserves the exact byte layout,
    including whether the file ends with a newline.
    """
    raw = path.read_bytes()
    if path.suffix != '.tsv':
        return raw
    rows = raw.split(b'\n')
    if not rows or not rows[0]:
        return raw
    header = rows[0].split(b'\t')
    drop = {i for i, h in enumerate(header) if h.decode('utf-8', 'replace') in names}
    if not drop:
        return raw
    return b'\n'.join(
        b'\t'.join(c for i, c in enumerate(r.split(b'\t')) if i not in drop) if r else r
        for r in rows
    )


def describe(rel, expected, actual):
    """Say where the two sides part company, naming a column when there is one."""
    elines = expected.split(b'\n')
    alines = actual.split(b'\n')
    if len(elines) != len(alines):
        print(f'  row count: expected {len(elines)}, actual {len(alines)}')
    if rel.suffix != '.tsv':
        diff = [i for i, (e, a) in enumerate(zip(elines, alines), 1) if e != a]
        if diff:
            print(f'  {len(diff)} differing line(s), first at line {diff[0]}')
        return
    ehdr = elines[0].split(b'\t')
    ahdr = alines[0].split(b'\t')
    if ehdr != ahdr:
        print(f'  header: expected {[h.decode() for h in ehdr]}')
        print(f'          actual   {[h.decode() for h in ahdr]}')
        return
    moved = {}
    for ln, (e, a) in enumerate(zip(elines, alines), 1):
        for i, (ec, ac) in enumerate(zip(e.split(b'\t'), a.split(b'\t'))):
            if ec != ac:
                name = ehdr[i].decode() if i < len(ehdr) else f'#{i}'
                moved.setdefault(name, []).append(ln)
    for col, lines in moved.items():
        print(f'  column {col!r}: {len(lines)} row(s), first at line {lines[0]}')


def main():
    if len(sys.argv) < 4:
        print(__doc__)
        return 2
    before = pathlib.Path(sys.argv[1])
    after = pathlib.Path(sys.argv[2])
    names = set(sys.argv[3:])
    for side in (before, after):
        if not side.is_dir():
            print(f'not a directory: {side}')
            return 2

    bf = sorted(p.relative_to(before) for p in before.rglob('*') if p.is_file())
    af = sorted(p.relative_to(after) for p in after.rglob('*') if p.is_file())
    if bf != af:
        print('FILE SET CHANGED')
        print(f'  only before: {sorted(set(bf) - set(af))}')
        print(f'  only after:  {sorted(set(af) - set(bf))}')
        return 1
    if not bf:
        print('nothing to compare: both directories are empty')
        return 2

    bad = 0
    for rel in bf:
        expected = cut(before / rel, names)
        actual = (after / rel).read_bytes()
        if expected == actual:
            continue
        bad += 1
        print(f'DISAGREED {rel}')
        describe(rel, expected, actual)

    print(f'\n{"AGREED" if not bad else "DISAGREED"}: {len(bf)} file(s), {bad} mismatching')
    return 1 if bad else 0


sys.exit(main())
