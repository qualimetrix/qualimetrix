#!/usr/bin/env python3
"""Does the commit a verdict names actually touch the row it closes?

    python3 defect-ledger/reproduce-commit-reach.py

`verdicts/README.md` records one number measured once and never re-derived,
because the check that measured it cannot run in a shallow clone. A number that
cannot be re-derived by a command is not a measurement, so the command lives
here. Run it against the full history; a `--depth 1` clone resolves none of the
hashes and the script reports every row as a miss.

A verdict is counted as reaching its row when some commit it names touches a
file whose **base name** matches the row's file, the counterpart the ledger
records for it, or the current heir `packages.tsv` gives it. Base names rather
than paths, because stages 01-04 moved most of the tree under the row and the
row still records where the defect was measured.

`wont-fix` rows are skipped: they name a reason, not a commit.

**The four misses are expected and are not defects.** Git reports a rename as
the new path only, so a commit whose whole content is "this file is now called
something else" never mentions the name the ledger recorded. Measured on
`5e11234e`: it lists `ProfilerWorkflowTest.php` and not
`ProfilerIntegrationTest.php`, which is precisely the rename the verdict
describes.

This sits beside the ledger rather than in `scripts/` because it reads
`packages.tsv`, which is a plan path, and `PlanningRecordIsolationTest` refuses
an executable under `scripts/` that names one. That refusal is the same rule
that moved the ledger out of the plan tree in the first place.
"""

import collections
import csv
import os
import re
import subprocess
import sys

HASH = re.compile(r'\b[0-9a-f]{7,40}\b')
PACKAGES = 'docs/internal/plans/test-structure/measurement/stage-05/packages.tsv'
LEDGER = 'defect-ledger/defect-ledger.tsv'
VERDICTS = 'defect-ledger/verdicts'


def touched(commit):
    """Every path the commit changed, across all parents."""
    result = subprocess.run(
        ['git', 'show', '--name-only', '--format=', '-m', commit],
        capture_output=True, text=True,
    )
    return {line for line in result.stdout.split('\n') if line.strip()}


def main():
    root = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
    os.chdir(root)

    ledger = {r['row_id']: r for r in csv.DictReader(open(LEDGER, encoding='utf-8'), delimiter='\t')}
    packages = {r['ledger_file']: r for r in csv.DictReader(open(PACKAGES, encoding='utf-8'), delimiter='\t')}

    cache = {}
    reached = 0
    missed = []

    for name in sorted(os.listdir(VERDICTS)):
        if not name.endswith('.tsv'):
            continue

        for verdict in csv.DictReader(open(os.path.join(VERDICTS, name), encoding='utf-8'), delimiter='\t'):
            if verdict['verdict'] == 'wont-fix':
                continue

            row = ledger[verdict['row_id']]
            candidates = {row['file']}
            if row['counterpart'].strip():
                candidates.add(row['counterpart'].strip())
            heirs = packages.get(row['file'], {}).get('current_heirs', '')
            candidates |= {heir.strip() for heir in heirs.split('|') if heir.strip()}
            names = {os.path.basename(candidate) for candidate in candidates}

            hit = any(
                os.path.basename(path) in names
                for commit in set(HASH.findall(verdict['evidence']))
                for path in cache.setdefault(commit, touched(commit))
            )

            if hit:
                reached += 1
            else:
                missed.append((name, verdict['row_id']))

    total = reached + len(missed)
    print(f'verdicts claiming a commit: {total}')
    print(f"  the commit touches the row's file, its counterpart or its heir: {reached}")
    print(f'  it does not: {len(missed)}')

    for source, row_id in missed:
        print(f'   miss: {source} {row_id}')

    return 0


if __name__ == '__main__':
    sys.exit(main())
