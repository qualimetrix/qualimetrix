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
here rather than left to whoever reads the output. **It has one blind spot and
this is where it is closed**: a deleted class whose name is also a live
namespace prefix would be exculpated by that very rule, so a match written the
way a class is written — followed by `::`, or preceded by `new`, `extends`,
`implements`, `instanceof`, `use` — is reported however many namespaces live
beneath it. What stays invisible is a stale name of that shape mentioned in
prose or in a bare string.

**The nine are pinned, in KNOWN below.** Nine names in a report is a number, and
a tenth is indistinguishable from the nine until someone diffs two outputs by
hand. Pinned, the tenth prints under its own heading. The population is fixed
in both directions: a pinned name that stops dangling is its own verdict, on the
same terms as this repository's other tracked lists, because a pin describing
nothing hides the next name that would need one.

Exit codes: 0 the tree carries exactly the pinned nine, 1 a name nobody pinned,
2 an input could not be read, 3 a pinned name that no longer dangles. A filtered
run (`--names-like`, `--include-history`) is a query over another population and
says so instead of judging: 0 nothing matched, 1 something did.

**Pinned is not guarded.** Nothing in `composer check` runs this, so the census
below can rot exactly the way `P6_RENAMED_TEST_IDS` did — a declaration whose
literals nobody re-reads. It is run by hand, by the package that needs it.
"""

import argparse
import os
import re
import subprocess
import sys

REPOSITORY_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), *[".."] * 6))
NAME = re.compile(r"Qualimetrix(?:\\\\|\\)Tests(?:(?:\\\\|\\)\w+)+")

# Written left of a name, these make it a class however many namespaces live
# beneath it. Without them the prefix exculpation below reads a genuine stale
# reference as an innocuous namespace mention whenever the deleted class's name
# happens to be a live namespace prefix.
CLASS_SHAPED_BEFORE = ("new ", "extends ", "implements ", "instanceof ", "use ")

# The names this tree is known to carry, each with why. Pinning them is what
# makes a tenth one distinguishable from the nine: the tool was a measurement
# whose output had to be diffed by hand against a number in a report, and a
# number in a report is not a set.
KNOWN = {
    "Qualimetrix\\Tests\\Architecture\\Unit\\Configuration\\Allow\\AllowAliasExpanderTest":
        "a stale reference from an epoch before stage 04, in a live governance control; stage 05's",
    "Qualimetrix\\Tests\\Infrastructure\\Unit\\ChannelDeclarationCompilerPassTest":
        "a stale reference from stage 02/03, in a live test docblock; stage 05's",
    "Qualimetrix\\Tests\\Integration\\Configuration\\YamlKeyReachabilityTest":
        "a stale reference from an epoch before stage 04, in a live governance control; stage 05's",
    "Qualimetrix\\Tests\\Integration\\Infrastructure\\Rule\\ChannelDeclarationFixtureDriftTest":
        "a stale reference from an epoch before stage 04, in two live carriers; stage 05's",
    "Qualimetrix\\Tests\\Unit\\Infrastructure\\DependencyInjection\\CompilerPass\\RuleCompilerPassTest":
        "a P6_RENAMED_TEST_IDS key: a pre-rename name by construction, and rewriting it would assert"
        " the test was always called what it is called now",
    "Qualimetrix\\Tests\\Integration\\DependencyInjection\\ContainerFactoryTest":
        "a P6_RENAMED_TEST_IDS key, as above",
    "Qualimetrix\\Tests\\Integration\\Infrastructure\\Console\\RuleExclusionStatsWiringTest":
        "a P6_RENAMED_TEST_IDS key, as above",
    "Qualimetrix\\Tests\\Infrastructure\\Integration\\RuleExclusionStatsWiringTest":
        "the *value* half of that same record, and stale: the file has not carried this namespace since"
        " stage 02/03. The owner's call is whether the record survives at all",
    "Qualimetrix\\Tests\\Reporting\\GraphProjection\\Functional":
        "the namespace ModularArchitectureGeneratorRefusalTest plants on purpose inside an isolated"
        " project, so nothing declares it here and nothing should",
}


def used_as_a_class(line, match):
    """True when this occurrence is written the way a class is written."""
    return line[match.end():].startswith("::") or line[: match.start()].rstrip("\\").endswith(CLASS_SHAPED_BEFORE)


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
                # declared name lives beneath it — unless the site uses it as a
                # class anyway, which is the one case where being a live
                # namespace prefix proves nothing about the name written here.
                if any(other.startswith(name + "\\") for other in declared) and not used_as_a_class(line, match):
                    continue
                if arguments.names_like and arguments.names_like not in name:
                    continue
                dangling.setdefault(name, []).append(f"{path}:{line_number}")

    for name in sorted(dangling):
        print(f"{name}")
        for carrier in sorted(set(dangling[name])):
            print(f"    {carrier}")
    print(f"\n{len(dangling)} name(s) across {len({c for v in dangling.values() for c in v})} carrier(s)")

    if arguments.names_like or arguments.include_history:
        # A filtered run is a query over a different population, so it cannot
        # answer the census question. Say so rather than returning a verdict
        # about a set nobody measured.
        print("filtered run: the pinned census was not compared")
        return 1 if dangling else 0

    new = sorted(set(dangling) - set(KNOWN))
    departed = sorted(set(KNOWN) - set(dangling))
    for name in new:
        print(f"NEW  {name} — not in the pinned census; adjudicate it, do not pin it to make this quiet")
    for name in departed:
        print(f"GONE {name} — pinned as {KNOWN[name]}, and no longer dangles; drop the pin")
    if new:
        return 1
    if departed:
        return 3

    print(f"{len(KNOWN)} name(s), exactly the pinned census")
    return 0


if __name__ == "__main__":
    sys.exit(main())
