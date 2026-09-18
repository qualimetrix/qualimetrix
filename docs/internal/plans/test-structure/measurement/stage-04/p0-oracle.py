#!/usr/bin/env python3
"""P0's judge: what the regenerated test inventory must say, cell by cell.

P0 rewrites the owner derivation, so "the artifact is byte-identical" is a
Definition of Done it cannot meet. This script states the expected value of
every cell that is allowed to move and of every cell that is not, and exits
non-zero on the first disagreement. A column allowed to change without an
expected value is not checked, it is excused.

It is deliberately not derived from the implementation: the owner of a row that
moves comes from `relocation-map.csv`, whose witness is each file's own
`#[CoversClass]` resolved through the manifest — an independent derivation from
the path parse P0 introduces. For a row that does not move the parse is
reproduced here, which is the same rule twice; that half is a regression guard,
not proof.

Usage:

    git show <pre-P0-commit>:docs/internal/generated/modular-architecture/test-ownership.tsv > /tmp/baseline.tsv
    php scripts/generate-modular-architecture-test-inventory.php
    python3 docs/internal/plans/test-structure/measurement/stage-04/p0-oracle.py \
        --baseline=/tmp/baseline.tsv \
        --actual=docs/internal/generated/modular-architecture/test-ownership.tsv

Exit codes: 0 agreed, 1 disagreed, 2 an input could not be read.
"""

import argparse
import csv
import json
import os
import sys

LEVELS = {"Unit", "Integration", "Functional"}
RETAIN = "Retain at the materialized subject-owned path."
MOVE = "Move atomically with the named owner and closure package."
ALLOWANCE_PACKAGE = "stage-04"
UNCHANGED_COLUMNS = [
    "kind",
    "classes",
    "discovered_classes",
    "discovered_test_cases",
    "current_suite",
    "target_suite",
]

REPOSITORY_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "..", "..", "..", ".."))
MAP_PATH = os.path.join(os.path.dirname(__file__), "relocation-map.csv")
MANIFEST_PATH = os.path.join(REPOSITORY_ROOT, "docs", "internal", "modular-architecture-manifest.json")


def owner_path(dotted):
    """The manifest spells owners with dots; the tree spells them with slashes.

    Core.Neutral is the one owner whose name is not a namespace: its tests live
    at tests/Core, not tests/Core/Neutral.
    """
    return "Core" if dotted == "Core.Neutral" else dotted.replace(".", "/")


def read_tsv(path):
    with open(path, newline="", encoding="utf-8") as handle:
        return {row["current_path"]: row for row in csv.DictReader(handle, delimiter="\t")}


def parse_owner(path):
    """The rule P0 installs: the segments before the one level segment name the owner.

    Exactly one, not the first of several: a path naming two levels is refused by
    the generator and by `TestSubjectPaths::judge()`, and an oracle that answered
    it the lenient way would agree with a row neither of them would publish.
    """
    parts = path[len("tests/"):].split("/")[:-1]
    levels = [index for index, segment in enumerate(parts) if segment in LEVELS]
    if len(levels) != 1:
        return None
    return "/".join(parts[: levels[0]])


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--baseline", required=True)
    parser.add_argument("--actual", required=True)
    parser.add_argument("--max-failures", type=int, default=80, help="0 prints them all")
    args = parser.parse_args()

    try:
        baseline = read_tsv(args.baseline)
        actual = read_tsv(args.actual)
        manifest = json.load(open(MANIFEST_PATH, encoding="utf-8"))
        with open(MAP_PATH, newline="", encoding="utf-8") as handle:
            relocation = list(csv.DictReader(handle))
    except OSError as error:
        print(f"cannot read an input: {error}", file=sys.stderr)
        return 2

    owners = {owner_path(owner) for owner in manifest["owners"]}

    # The allowance's population is the test classes, which is the population
    # the invariant is about. The two support rows of the map are not in it:
    # the surviving ladder already returns their map owner and map target, so an
    # allowance row for them would refuse nothing. They still move with their
    # package.
    allowance = {
        row["current"]: row
        for row in relocation
        if row["current"].endswith("Test.php")
    }
    support_rows = [row["current"] for row in relocation if not row["current"].endswith("Test.php")]

    failures = []
    if len(allowance) + len(support_rows) != len(relocation):
        failures.append(f"map partition is not total: {len(allowance)} + {len(support_rows)} != {len(relocation)}")

    added = sorted(set(actual) - set(baseline))
    dropped = sorted(set(baseline) - set(actual))
    for path in added:
        failures.append(f"row added: {path}")
    for path in dropped:
        failures.append(f"row dropped: {path}")

    changed = {"subject_owner": 0, "target_path": 0, "disposition": 0, "closure_package": 0}

    for path, before in baseline.items():
        after = actual.get(path)
        if after is None:
            continue

        is_test_class = path.startswith("tests/") and path.endswith("Test.php")
        if path in allowance:
            row = allowance[path]
            expected = {
                "subject_owner": owner_path(row["owner"]),
                "target_path": row["target"],
                "disposition": MOVE,
                "closure_package": ALLOWANCE_PACKAGE,
            }
            # An allowance row for a path the parse would already accept is a
            # row that does not describe anything unmoved.
            if parse_owner(path) in owners:
                failures.append(f"{path}: is in the allowance but already conforms")
        elif is_test_class:
            owner = parse_owner(path)
            if owner not in owners:
                failures.append(
                    f"{path}: parses to owner {owner!r}, which is not one of the {len(owners)} manifest owners,"
                    " and it is in no allowance"
                )
                continue
            expected = {
                "subject_owner": owner,
                "target_path": path,
                "disposition": RETAIN,
                "closure_package": "permanent",
            }
        else:
            expected = {column: before[column] for column in changed}

        for column, want in expected.items():
            got = after[column]
            if got != want:
                failures.append(f"{path}: {column} is {got!r}, expected {want!r}")
            elif got != before[column]:
                changed[column] += 1

        for column in UNCHANGED_COLUMNS:
            if after[column] != before[column]:
                failures.append(
                    f"{path}: {column} moved from {before[column]!r} to {after[column]!r}; P0 moves no file"
                )

    print(f"rows {len(baseline)} baseline, {len(actual)} actual")
    print(f"allowance {len(allowance)} test classes, {len(support_rows)} support rows outside it")
    print("cells changed as expected: " + ", ".join(f"{k} {v}" for k, v in changed.items()))

    if failures:
        print(f"\n{len(failures)} disagreement(s):", file=sys.stderr)
        shown = failures if args.max_failures == 0 else failures[: args.max_failures]
        for failure in shown:
            print("  " + failure, file=sys.stderr)
        if len(shown) < len(failures):
            print(f"  ... and {len(failures) - len(shown)} more", file=sys.stderr)
        return 1

    print("agreed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
