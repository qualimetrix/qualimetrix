#!/usr/bin/env python3
"""The judge for stage 04's three move packages, P1 / P2 / P3.

Each of them executes one owner-partition of `relocation-map.csv` and nothing
else. "Nothing else" is the half that is easy to assert and easy to skip, so it
is asserted in both directions: every row of the partition was executed, and no
file outside the partition moved.

The package's own rows are read from the map, which is the independent witness
(each file's `#[CoversClass]` resolved through the manifest). The pre-move fully
qualified class names are read from the base commit, not from memory, because
the reference sweep needs the name as it was, and that name is exactly what no
longer exists to be grepped for.

Usage, from the repository root:

    python3 docs/internal/plans/test-structure/measurement/stage-04/move-oracle.py \
        --package=P1 --base=<the commit the package starts from>

Exit codes: 0 agreed, 1 disagreed, 2 an input could not be read.
"""

import argparse
import csv
import json
import os
import re
import subprocess
import sys

LEVELS = {"Unit", "Integration", "Functional"}
RETAIN = "Retain at the materialized subject-owned path."
MOVE = "Move atomically with the named owner and closure package."

REPOSITORY_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), *[".."] * 6))
MAP_PATH = os.path.join(os.path.dirname(__file__), "relocation-map.csv")
MANIFEST_PATH = os.path.join(REPOSITORY_ROOT, "docs", "internal", "modular-architecture-manifest.json")
INVENTORY_PATH = os.path.join(
    REPOSITORY_ROOT, "docs", "internal", "generated", "modular-architecture", "test-ownership.tsv"
)
GENERATOR_PATH = os.path.join(REPOSITORY_ROOT, "scripts", "generate-modular-architecture-test-inventory.php")

# Which owners each package carries. The partition is total over the map and the
# three sets are disjoint; the script asserts both rather than trusting them.
PACKAGES = {
    "P1": lambda owner: owner.startswith("Infrastructure."),
    "P2": lambda owner: owner == "Reporting",
    "P3": lambda owner: owner == "Core.Neutral" or owner.startswith("Analysis."),
}


def owner_path(dotted):
    return "Core" if dotted == "Core.Neutral" else dotted.replace(".", "/")


def git(*arguments):
    result = subprocess.run(["git", *arguments], cwd=REPOSITORY_ROOT, capture_output=True, text=True)
    if result.returncode != 0:
        raise OSError(f"git {' '.join(arguments)} failed: {result.stderr.strip()}")
    return result.stdout


def declared_class(source, path):
    """The fully qualified name a PHP file declares, or None."""
    namespace = re.search(r"^namespace\s+([^;]+);", source, re.M)
    name = re.search(r"^(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait|interface|enum)\s+(\w+)", source, re.M)
    if namespace is None or name is None:
        return None
    return namespace.group(1).strip() + "\\" + name.group(1)


def expected_namespace(path):
    """PSR-4: Qualimetrix\\Tests\\ maps to tests/."""
    return "Qualimetrix\\Tests\\" + os.path.dirname(path[len("tests/"):]).replace("/", "\\")


def parse_owner(path):
    parts = path[len("tests/"):].split("/")
    levels = [index for index, segment in enumerate(parts) if segment in LEVELS]
    return "/".join(parts[: levels[0]]) if levels else None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--package", required=True, choices=sorted(PACKAGES))
    parser.add_argument("--base", required=True, help="the commit this package starts from")
    parser.add_argument("--max-failures", type=int, default=80, help="0 prints them all")
    arguments = parser.parse_args()

    try:
        with open(MAP_PATH, newline="", encoding="utf-8") as handle:
            relocation = list(csv.DictReader(handle))
        manifest = json.load(open(MANIFEST_PATH, encoding="utf-8"))
        with open(INVENTORY_PATH, newline="", encoding="utf-8") as handle:
            inventory = {row["current_path"]: row for row in csv.DictReader(handle, delimiter="\t")}
        generator = open(GENERATOR_PATH, encoding="utf-8").read()
        tracked = set(git("ls-files").split("\n")) - {""}
    except OSError as error:
        print(f"cannot read an input: {error}", file=sys.stderr)
        return 2

    owners = {owner_path(owner) for owner in manifest["owners"]}
    failures = []

    partition = {name: [row for row in relocation if predicate(row["owner"])] for name, predicate in PACKAGES.items()}
    covered = sum(len(rows) for rows in partition.values())
    if covered != len(relocation):
        failures.append(
            f"the three packages cover {covered} of the map's {len(relocation)} rows;"
            " a row belonging to no package would be moved by nobody"
        )
    mine = partition[arguments.package]
    others = {row["current"] for name, rows in partition.items() if name != arguments.package for row in rows}

    # 1. Every row of this package executed, and the file is where the map says.
    for row in mine:
        if row["current"] in tracked:
            failures.append(f"{row['current']}: still tracked at its pre-move path")
        if row["target"] not in tracked:
            failures.append(f"{row['target']}: the map's target is not tracked")

    # 2. No file outside this package moved. The map's other rows must be exactly
    #    where they were; anything else that git records as a rename is a file
    #    this package had no business touching.
    for path in others:
        if path not in tracked:
            failures.append(f"{path}: moved, but it belongs to another package")

    # Against the working tree, not against HEAD: a package is judged before it
    # is committed, and `base..HEAD` on an uncommitted package compares a commit
    # with itself and reports no rename at all — a vacuous arm that always agrees.
    renamed = set()
    for line in git("diff", "--name-status", "-M", arguments.base).split("\n"):
        fields = line.split("\t")
        if fields[0].startswith("R") and len(fields) == 3:
            renamed.add((fields[1], fields[2]))
    expected_renames = {(row["current"], row["target"]) for row in mine}
    for rename in sorted(renamed - expected_renames):
        failures.append(f"{rename[0]} -> {rename[1]}: a rename the map does not name")

    # 3. The regenerated inventory agrees about every moved file.
    for row in mine:
        entry = inventory.get(row["target"])
        if entry is None:
            failures.append(f"{row['target']}: no row in the generated inventory")
            continue
        want_owner = owner_path(row["owner"])
        if entry["subject_owner"] != want_owner:
            failures.append(f"{row['target']}: inventory owner {entry['subject_owner']!r}, map says {want_owner!r}")
        if entry["target_path"] != row["target"]:
            failures.append(f"{row['target']}: inventory still records a move to {entry['target_path']!r}")
        if row["target"].endswith("Test.php"):
            if entry["disposition"] != RETAIN:
                failures.append(f"{row['target']}: disposition {entry['disposition']!r}, expected {RETAIN!r}")
            if entry["closure_package"] != "permanent":
                failures.append(f"{row['target']}: closure package {entry['closure_package']!r}, expected 'permanent'")
            if parse_owner(row["target"]) not in owners:
                failures.append(f"{row['target']}: does not parse to a manifest owner")
        if row["current"] in inventory:
            failures.append(f"{row['current']}: the inventory still carries the pre-move path")

    # 4. The allowance shrank by exactly this package's test classes.
    block = re.search(r"const LEGACY_UNMOVED = \[\n(.*?)\n\];", generator, re.S)
    if block is None and arguments.package != "P3":
        failures.append("the generator declares no LEGACY_UNMOVED; the allowance closes in P4, not here")
    allowance = set(re.findall(r"'([^']+)' =>", block.group(1))) if block else set()
    for row in mine:
        if row["current"] in allowance:
            failures.append(f"{row['current']}: still in LEGACY_UNMOVED after its package moved it")
    for path in sorted(others):
        if path.endswith("Test.php") and path not in allowance:
            failures.append(f"{path}: left LEGACY_UNMOVED early — its package has not run")

    # 5. Namespace follows path. PHPUnit discovers by file, so a stale namespace
    #    runs and misleads rather than failing.
    for row in mine:
        if not row["target"].endswith(".php"):
            continue
        try:
            source = open(os.path.join(REPOSITORY_ROOT, row["target"]), encoding="utf-8").read()
        except OSError:
            continue
        declared = re.search(r"^namespace\s+([^;]+);", source, re.M)
        want = expected_namespace(row["target"])
        if declared is None or declared.group(1).strip() != want:
            got = declared.group(1).strip() if declared else "none"
            failures.append(f"{row['target']}: namespace {got!r}, path implies {want!r}")

    # 6. No reference to a pre-move class name survives anywhere in the tree —
    #    the channel a sweep by path and a sweep by namespace prefix both miss.
    #    Both spellings are swept: PHP source writes a fully qualified name with
    #    every backslash doubled, so a single-backslash sweep returns nothing for
    #    a stale literal inside a PHP string and reads as clean.
    #
    #    The one exemption is a rename record's key. `P6_RENAMED_TEST_IDS` maps
    #    an old test id to its current one; its keys are pre-rename names by
    #    construction, and rewriting one would assert that a test was always
    #    called what it is called now. The exemption is by position — left of the
    #    `=>` inside that constant — not by file, so a stale name anywhere else
    #    in the same file is still reported.
    record_keys = set()
    record_block = re.search(r"const P6_RENAMED_TEST_IDS = \[\n(.*?)\n\];", generator, re.S)
    if record_block is not None:
        record_keys = {
            line.split("' =>")[0].strip().lstrip("'")
            for line in record_block.group(1).split("\n")
            if "' =>" in line
        }

    def is_rename_record_key(path, line, spelling):
        """True when every occurrence on this line sits left of the `=>`.

        Line-level exemption is not enough and the difference is not academic:
        a stale value half sits on the same line as a legitimate key, so
        exempting the line hides exactly the defect this arm exists for.
        """
        if path != os.path.relpath(GENERATOR_PATH, REPOSITORY_ROOT):
            return False
        arrow = line.find("' =>")
        if arrow < 0:
            return False
        key = line[:arrow].strip().lstrip("'")
        if key not in record_keys:
            return False
        occurrence = line.find(spelling)
        while occurrence >= 0:
            if occurrence > arrow:
                return False
            occurrence = line.find(spelling, occurrence + 1)
        return True

    for row in mine:
        if not row["current"].endswith(".php"):
            continue
        try:
            before = git("show", f"{arguments.base}:{row['current']}")
        except OSError:
            failures.append(f"{row['current']}: not present at the base commit, so its old name cannot be checked")
            continue
        old = declared_class(before, row["current"])
        if old is None:
            continue
        for spelling in (old, old.replace("\\", "\\\\")):
            found = subprocess.run(
                ["git", "grep", "-n", "-F", spelling], cwd=REPOSITORY_ROOT, capture_output=True, text=True
            ).stdout.split("\n")
            for hit in found:
                if not hit:
                    continue
                path, _, rest = hit.partition(":")
                number, _, line = rest.partition(":")
                if path.startswith("docs/internal/plans/") or path.startswith("docs/adr/"):
                    continue  # plans and ADRs record history; they are not addresses
                if is_rename_record_key(path, line, spelling):
                    continue
                failures.append(f"{path}:{number}: still names {old}, the pre-move class name of {row['target']}")

    print(f"package {arguments.package}: {len(mine)} rows, allowance now {len(allowance)}")
    if failures:
        print(f"\n{len(failures)} disagreement(s):", file=sys.stderr)
        shown = failures if arguments.max_failures == 0 else failures[: arguments.max_failures]
        for failure in shown:
            print("  " + failure, file=sys.stderr)
        if len(shown) < len(failures):
            print(f"  ... and {len(failures) - len(shown)} more", file=sys.stderr)
        return 1

    print("agreed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
