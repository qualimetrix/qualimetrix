#!/usr/bin/env python3
"""The judge for stage 04's three move packages, P1 / P2 / P3.

Each of them executes one owner-partition of `relocation-map.csv` and nothing
else. "Nothing else" is the half that is easy to assert and easy to skip, so it
is asserted in both directions: every row of the partition was executed, and no
file outside the partition moved. The second half is asked twice — of git's
rename detection, and of the tracked file sets — because a file moved and
heavily edited is not a rename to git at all.

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
    """The segments before the path's one level segment, or None.

    Exactly one, not the first of several: the generator and
    `TestSubjectPaths::judge()` both refuse a path naming two, so an oracle that
    took the first match would certify a target neither would publish.
    """
    parts = path[len("tests/"):].split("/")[:-1]
    levels = [index for index, segment in enumerate(parts) if segment in LEVELS]
    return "/".join(parts[: levels[0]]) if len(levels) == 1 else None


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
        # The index minus what the working tree no longer has. `git ls-files`
        # alone answers about the index, so a file removed without git still
        # reads as tracked and every arm below agrees about a file that is gone.
        tracked = set(git("ls-files").split("\n")) - {""}
        tracked -= set(git("ls-files", "--deleted").split("\n")) - {""}
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
    # Every map row outside the package under test, as `current => target`. Both
    # endpoints are needed: the packages that ran earlier have already executed
    # their rows, so "this path is no longer where the map found it" is the
    # normal state for them and an alarm only for a row nobody has moved. An
    # earlier version of this arm held only the `current` paths and therefore
    # reported every row of every finished package — a rule stated over a
    # population that includes the work already done.
    others = {
        row["current"]: row["target"]
        for name, rows in partition.items()
        if name != arguments.package
        for row in rows
    }

    # 1. Every row of this package executed, and the file is where the map says.
    for row in mine:
        if row["current"] in tracked:
            failures.append(f"{row['current']}: still tracked at its pre-move path")
        if row["target"] not in tracked:
            failures.append(f"{row['target']}: the map's target is not tracked")

    # 2. No file outside this package went missing: another package's row is
    #    either still at its pre-move path, or already at the target the map
    #    records for it. Neither means it was moved somewhere nobody decided.
    for current, target in sorted(others.items()):
        if current not in tracked and target not in tracked:
            failures.append(f"{current}: gone from the tree, and not at the map's target {target}")

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

    # The same question asked of the file sets rather than of git's opinion of
    # them. `-M` reports a rename only while the two versions stay similar
    # enough, so a file moved *and* heavily edited arrives as a delete plus an
    # add and the loop above sees neither half — the one shape this arm exists
    # to catch is the one it was blind to. Pairing the two sets by basename
    # finds it without inventing noise: a package legitimately adds files (its
    # own report, a new control) and legitimately removes them, and reporting
    # every such path would drown the signal in work the package was asked to do.
    #
    # What stays invisible, stated rather than left to be discovered: a
    # relocation that also *renames* the file. Nothing here pairs its two halves,
    # and neither does git once the content has drifted.
    base_tracked = set(git("ls-tree", "-r", "--name-only", arguments.base).split("\n")) - {""}
    expected_gone = {row["current"] for row in mine}
    expected_new = {row["target"] for row in mine}
    gone = {path for path in base_tracked - tracked if path not in expected_gone}
    appeared = {path for path in tracked - base_tracked if path not in expected_new}
    arrivals = {}
    for path in appeared:
        arrivals.setdefault(os.path.basename(path), []).append(path)
    for path in sorted(gone):
        for arrival in sorted(arrivals.get(os.path.basename(path), [])):
            failures.append(
                f"{path} -> {arrival}: a move the map does not name."
                " git reports it as a delete and an add, so rename detection does not see it"
            )

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
        # Only a row still at its pre-move path still needs its allowance entry;
        # a package that has already run took its own rows out, which is the
        # allowance shrinking as designed rather than shrinking early.
        if path.endswith("Test.php") and path in tracked and path not in allowance:
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
    #
    #    The search reads the files here rather than shelling out to `git grep
    #    -F`, which is not a fixed-string search: it wraps the pattern in
    #    \Q…\E and hands it to PCRE2, so a name with a segment starting with E
    #    closes the quoting and the rest is read as a pattern. Measured on this
    #    repository: `git grep -l -F 'Qualimetrix\Tests\Analysis\Evidence\Size'`
    #    exits 0 having printed nothing, while the string is in three files. A
    #    sweep that silently measures nothing is the worst instrument this
    #    campaign has found, so this one has no escaping surface at all.
    record_keys = set()
    record_block = re.search(r"const P6_RENAMED_TEST_IDS = \[\n(.*?)\n\];", generator, re.S)
    if record_block is not None:
        record_keys = {
            line.split("' =>")[0].strip().lstrip("'")
            for line in record_block.group(1).split("\n")
            if "' =>" in line
        }

    generator_path = os.path.relpath(GENERATOR_PATH, REPOSITORY_ROOT)

    def is_rename_record_key(path, line, spelling):
        """True when every occurrence on this line sits left of the `=>`.

        Line-level exemption is not enough and the difference is not academic:
        a stale value half sits on the same line as a legitimate key, so
        exempting the line hides exactly the defect this arm exists for.
        """
        if path != generator_path:
            return False
        arrow = line.find("' =>")
        if arrow < 0:
            return False
        if line[:arrow].strip().lstrip("'") not in record_keys:
            return False
        occurrence = line.find(spelling)
        while occurrence >= 0:
            if occurrence > arrow:
                return False
            occurrence = line.find(spelling, occurrence + 1)
        return True

    corpus = {}
    for path in sorted(tracked):
        if path.startswith("docs/internal/plans/") or path.startswith("docs/adr/"):
            continue  # plans and ADRs record history; they are not addresses
        try:
            corpus[path] = open(os.path.join(REPOSITORY_ROOT, path), encoding="utf-8").read().split("\n")
        except (OSError, UnicodeDecodeError):
            continue

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
            for path, lines in corpus.items():
                for number, line in enumerate(lines, start=1):
                    if spelling not in line or is_rename_record_key(path, line, spelling):
                        continue
                    failures.append(
                        f"{path}:{number}: still names {old}, the pre-move class name of {row['target']}"
                    )

    # 6b. No pre-move *path* literal survives either. `04-packages.md` calls the
    #     sweep six questions; this is the one that was asked by hand in every
    #     package and lived in no tool, which is the same way the old-FQCN sweep
    #     was rewritten from scratch three times. It reaches a workflow file, a
    #     .gitattributes line or a phpstan path that no sweep over class names
    #     touches. Measured over P3's rows at the end of the stage: 0 hits, so
    #     this is a floor being nailed down rather than a backlog being opened.
    #
    #     The seventh question, a sweep by the moved class's bare basename, is
    #     deliberately not here. It belongs to a *rename*, and a move keeps the
    #     class name: measured over the same 11 rows it returns 206 legitimate
    #     hits, so as an arm of a move judge it is 206 lines of noise and an
    #     allow-list nobody would read. That channel stays uncovered and is
    #     named as uncovered in `04-packages.md`.
    for row in mine:
        for path, lines in corpus.items():
            for number, line in enumerate(lines, start=1):
                if row["current"] in line:
                    failures.append(
                        f"{path}:{number}: still names the path {row['current']}, which this package emptied"
                    )

    # 7. A fresh clone of this commit runs. git tracks no empty directory, so a
    #    <testsuite> naming a path that the clone does not have makes PHPUnit exit
    #    2 having run nothing — and a package that empties a declared directory
    #    without removing its entry is how that happens.
    configuration = open(os.path.join(REPOSITORY_ROOT, "phpunit.xml.dist"), encoding="utf-8").read()
    for declared in re.findall(r"<directory>([^<]+)</directory>", configuration):
        declared = declared.strip().rstrip("/")
        if not any(path.startswith(declared + "/") for path in tracked):
            failures.append(f"{declared}: declared in phpunit.xml.dist, but git tracks no file under it")

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
