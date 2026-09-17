#!/usr/bin/env python3
"""Every `Qualimetrix\\Tests\\…` name in the tree that no file declares.

This detector has been written from scratch three times inside this stage — once
per package that needed it, each time in a session scratchpad that then vanished
— which is how a measurement becomes a claim nobody can reproduce. It is tracked
so the next package runs it instead of rewriting it.

What it is for. A reference spelled with a class name is reachable by no sweep
over paths and by no sweep over namespace prefixes, and a reference spelled with
a name from an *earlier* rename is reachable by neither of those nor by a sweep
for the name a file carries today. The only thing that finds one is asking the
opposite question: which names in the tree resolve to no file at all.

Both spellings are read. PHP source writes a fully qualified name with every
backslash doubled, so a single-backslash sweep silently returns nothing for a
stale literal inside a PHP string.

    python3 docs/internal/plans/test-structure/measurement/stage-04/dangling-test-names.py
    python3 .../dangling-test-names.py --names-like NamespaceTree   # only these

A name is reported only when no file declares it **and** nothing is declared
beneath it: a namespace with live classes under it is a namespace, not a stale
class reference. That distinction is the difference between two measurements of
this tree that disagreed — 9 names against 27 — and it is why the rule is stated
here rather than left to whoever reads the output.

Exit codes: 0 nothing dangles, 1 something does, 2 an input could not be read.
It is a measurement, not a control: at the end of stage 04 the tree carries 9,
of which 8 are stale references older than this stage and 1 is a namespace a
refusal control plants on purpose. So a non-zero exit is a prompt to read the
list, not a failure — and making it a control means adjudicating those 9 first.
"""

import argparse
import os
import re
import subprocess
import sys

REPOSITORY_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), *[".."] * 6))
NAME = re.compile(r"Qualimetrix(?:\\\\|\\)Tests(?:(?:\\\\|\\)\w+)+")


def git(*arguments):
    result = subprocess.run(["git", *arguments], cwd=REPOSITORY_ROOT, capture_output=True, text=True)
    if result.returncode != 0:
        raise OSError(f"git {' '.join(arguments)} failed: {result.stderr.strip()}")
    return result.stdout


def declared_names():
    """Every fully qualified name the tree actually declares."""
    declared = set()
    for path in git("ls-files", "*.php").split("\n"):
        if not path:
            continue
        try:
            source = open(os.path.join(REPOSITORY_ROOT, path), encoding="utf-8").read()
        except OSError:
            continue
        namespace = re.search(r"^namespace\s+([^;]+);", source, re.M)
        if namespace is None:
            continue
        prefix = namespace.group(1).strip()
        for match in re.finditer(
            r"^(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait|interface|enum)\s+(\w+)", source, re.M
        ):
            declared.add(prefix + "\\" + match.group(1))
    return declared


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--names-like", help="report only names containing this substring")
    parser.add_argument(
        "--include-history",
        action="store_true",
        help="also report plans, ADRs and generated artifacts, which record names rather than address them",
    )
    arguments = parser.parse_args()

    try:
        declared = declared_names()
        tracked = [path for path in git("ls-files").split("\n") if path]
    except OSError as error:
        print(f"cannot read the tree: {error}", file=sys.stderr)
        return 2

    # Plans, ADRs and generated artifacts name what the tree used to carry; that
    # is the record working, not an address rotting. Everything else that spells
    # a name is asserting the name exists.
    history = ("docs/internal/plans/", "docs/adr/", "docs/internal/generated/", "CHANGELOG.md")

    dangling = {}
    for path in tracked:
        if not arguments.include_history and path.startswith(history):
            continue
        try:
            source = open(os.path.join(REPOSITORY_ROOT, path), encoding="utf-8").read()
        except (OSError, UnicodeDecodeError):
            continue
        for line_number, line in enumerate(source.split("\n"), start=1):
            for match in NAME.finditer(line):
                name = match.group(0).replace("\\\\", "\\")
                if name in declared:
                    continue
                # A namespace is not a class reference: it dangles only when no
                # declared name lives beneath it.
                if any(other.startswith(name + "\\") for other in declared):
                    continue
                if arguments.names_like and arguments.names_like not in name:
                    continue
                dangling.setdefault(name, []).append(f"{path}:{line_number}")

    for name in sorted(dangling):
        print(f"{name}")
        for carrier in sorted(set(dangling[name])):
            print(f"    {carrier}")
    print(f"\n{len(dangling)} name(s) across {len({c for v in dangling.values() for c in v})} carrier(s)")

    return 1 if dangling else 0


if __name__ == "__main__":
    sys.exit(main())
