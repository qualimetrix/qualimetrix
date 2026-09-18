#!/usr/bin/env python3
"""Prove a column removal moved nothing else.

Cuts the named column out of the BEFORE copy of each TSV and compares the
result byte-for-byte with AFTER. Equality is the proof: any движение in a
surviving column shows up as a mismatch, because the cut version of BEFORE
is exactly what AFTER must be.

Usage: column-drop-oracle.py <before-dir> <after-dir> <column-name>...
Exit 0 agreed, 1 disagreed, 2 could not compare.
"""
import sys, pathlib

def cut(path, names):
    if path.suffix != '.tsv':
        return path.read_text().splitlines(), None   # non-carrier: must be identical
    rows = path.read_text().splitlines()
    if not rows:
        return None, 'empty file'
    header = rows[0].split('\t')
    idx = [i for i, h in enumerate(header) if h in names]
    if not idx:
        return rows, None          # file does not carry the column; must be identical
    drop = set(idx)
    return ['\t'.join(c for i, c in enumerate(r.split('\t')) if i not in drop)
            for r in rows], None

def main():
    if len(sys.argv) < 4:
        print(__doc__); return 2
    before, after, names = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]), set(sys.argv[3:])
    # Every generated file, not just the TSVs: a non-carrier must come out
    # byte-identical, and a .txt sibling is exactly where a dropped column
    # would hide from a .tsv-only sweep.
    bf = sorted(p.relative_to(before) for p in before.rglob('*') if p.is_file())
    af = sorted(p.relative_to(after) for p in after.rglob('*') if p.is_file())
    if bf != af:
        print(f'FILE SET CHANGED\n  only before: {sorted(set(bf)-set(af))}\n  only after:  {sorted(set(af)-set(bf))}')
        return 1
    bad = 0
    for rel in bf:
        expected, err = cut(before / rel, names)
        if err:
            print(f'{rel}: cannot compare: {err}'); bad += 1; continue
        actual = (after / rel).read_text().splitlines()
        if expected == actual:
            continue
        bad += 1
        print(f'DISAGREED {rel}')
        if rel.suffix != '.tsv':
            # No header to name a column with: report by line.
            diff = [i for i, (e, a) in enumerate(zip(expected, actual), 1) if e != a]
            print(f'  {len(diff)} differing line(s), first at line {diff[0]}' if diff
                  else f'  row count: expected {len(expected)}, actual {len(actual)}')
            continue
        ehdr = expected[0].split('\t') if expected else []
        ahdr = actual[0].split('\t') if actual else []
        if ehdr != ahdr:
            print(f'  header: expected {ehdr}\n          actual   {ahdr}')
            continue
        # header agrees -> locate the moving column by name, not by count
        moved = {}
        for ln, (e, a) in enumerate(zip(expected, actual), 1):
            for i, (ec, ac) in enumerate(zip(e.split('\t'), a.split('\t'))):
                if ec != ac:
                    moved.setdefault(ehdr[i] if i < len(ehdr) else f'#{i}', []).append(ln)
        for col, lines in moved.items():
            print(f'  column {col!r}: {len(lines)} row(s), first at line {lines[0]}')
        if len(expected) != len(actual):
            print(f'  row count: expected {len(expected)}, actual {len(actual)}')
    print(f'\n{"AGREED" if not bad else "DISAGREED"}: {len(bf)} file(s), {bad} mismatching')
    return 1 if bad else 0

sys.exit(main())
