#!/usr/bin/env python3
"""The X4 stand of authored directive forms: measures today's table.

A tool of this push, not a standing gate. The standing guards of the ban are the
tests and probes of package 2; this stand exists so that the expectation the ban
is judged against was written before the product was touched.

What it does, per case:

  * writes a one-file fixture outside the repository (the project analyses
    itself, so a fixture inside `src/` would move the input with the step being
    measured);
  * runs three observations — `check --format=json`, `check --format=suppressed
    --show-suppressed`, `directives --format=json` — keeping stdout and stderr
    apart, because the suppression prose goes to stderr and an earlier
    measurement lost it by discarding that stream;
  * refuses loudly on anything it cannot read. A silently skipped row would make
    the comparison green by absence.

It then prints the table, compares it with §1 of `measurement-directive-forms.md`
(the measurement this stand must reproduce), and optionally with an expected
table given by `--expect` — which is how package 2 holds the ban against the
prediction in `stand-predicted-forms.md`.

Usage:
    python3 stand-directive-forms.py [--output=<file>] [--expect=<table.md>]
                                     [--keep=<dir>] [--only=<case,...>]

Exit codes: 0 agreed, 1 disagreed with a compared table, 3 the stand could not
run a case or read its output.
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parents[5]
PLAN_DIR = Path(__file__).resolve().parent
MEASUREMENT = PLAN_DIR / "measurement-directive-forms.md"

CHANNEL = "annotation.unused-directive"
VICTIM_LINE = "// @qmx-ignore-file complexity.cyclomatic -- victim"

QUOTE_PATTERN = re.compile(r'Suppression "([^"]+)" matched nothing')
PROSE_PATTERN = re.compile(r"^(\d+) violation\(s\) suppressed by @qmx-ignore tags", re.M)

COLUMNS = [
    "case",
    "authored target",
    "check findings",
    "suppressed",
    "verdict",
    "victim verdict",
    "exit check",
    "exit directives",
]


class StandError(RuntimeError):
    """The stand cannot answer for a case. Never downgraded to a skipped row."""


# --------------------------------------------------------------------------
# The grid
# --------------------------------------------------------------------------
#
# Every fixture is thirteen lines, and the line a directive sits on is part of
# the observation: §1 of the measurement names lines, so the layout is fixed
# here rather than left to chance.
#
#   1  <?php                        6  (blank)          10  final class Subject
#   2  (blank)                      7  directive        11  {
#   3  declare(strict_types=1);     8  directive        12      public function m
#   4  (blank)                      9  directive        13  }
#   5  namespace X4Fix;
#
# `symbol-method` moves the class up so that its method docblock lands on 11,
# which is where §1 observed it.

HEADER = ["<?php", "", "declare(strict_types=1);", "", "namespace X4Fix;", ""]
CLASS_BODY = ["final class Subject", "{", "    public function m(): void {}", "}"]


def build_fixture(layout: str, text: str | None) -> tuple[list[str], int | None, int | None]:
    """Returns (lines, tested line, victim line)."""
    if layout == "victim-only":
        # The only directive in the file is the victim, so it is also the one
        # under test: §1 reads its verdict in the tested column.
        return HEADER + [VICTIM_LINE, "", ""] + CLASS_BODY, 7, 7
    if layout == "file":
        return HEADER + [VICTIM_LINE, text, ""] + CLASS_BODY, 8, 7
    if layout == "self-only":
        return HEADER + [text, "", ""] + CLASS_BODY, 7, None
    if layout == "next-line":
        return HEADER + [text, VICTIM_LINE, ""] + CLASS_BODY, 7, 8
    if layout == "symbol":
        return HEADER + [VICTIM_LINE, "", text] + CLASS_BODY, 9, 7
    if layout == "symbol-method":
        return (
            HEADER
            + [
                VICTIM_LINE,
                "",
                "final class Subject",
                "{",
                "    " + text,
                "    public function m(): void {}",
                "}",
            ],
            11,
            7,
        )
    raise StandError(f"unknown layout: {layout}")


def suppression_text(layout: str, target: str) -> str:
    if layout in ("symbol", "symbol-method"):
        return f"/** @qmx-ignore {target} -- tested */"
    if layout == "next-line":
        return f"// @qmx-ignore-next-line {target} -- tested"
    if layout in ("file", "self-only"):
        if target == "*":
            # The file form spells "no rule filter" by omitting the argument.
            return "// @qmx-ignore-file -- tested"
        return f"// @qmx-ignore-file {target} -- tested"
    raise StandError(f"no suppression spelling for layout {layout}")


def threshold_text(target: str) -> str:
    return f"/** @qmx-threshold {target} 10 */"


def cases() -> list[dict]:
    grid: list[dict] = []

    def add(case_id: str, layout: str, target: str | None, *, coupling: bool = False, note: str = "") -> None:
        if layout == "victim-only":
            text = None
        elif layout == "threshold":
            text = threshold_text(target)
            layout = "symbol"
        else:
            text = suppression_text(layout, target)
        grid.append(
            {
                "id": case_id,
                "layout": layout,
                "target": target,
                "text": text,
                "coupling": coupling,
                "note": note,
            }
        )

    add("B0-victim-only", "victim-only", None)

    forms = [
        ("exact", CHANNEL),
        ("exact-file", f"{CHANNEL}:file"),
        ("exact-class", f"{CHANNEL}:class"),
        ("group", "annotation.*"),
        ("group-file", "annotation.*:file"),
        ("nofilter", "*"),
    ]
    for prefix, layout in (("S", "symbol"), ("N", "next-line"), ("F", "file")):
        for suffix, target in forms:
            add(f"{prefix}-{suffix}", layout, target)

    # The four levels the channel does not report at, on a fixed tag: §2 of the
    # measurement reads them as one equivalence class, and eight identical rows
    # are what makes that readable rather than asserted.
    for selector_name, selector in (("exact", CHANNEL), ("group", "annotation.*")):
        for level in ("callable", "class", "namespace", "project"):
            add(f"LVL-{selector_name}-{level}", "file", f"{selector}:{level}")

    add("S-method", "symbol-method", CHANNEL)
    add("SELF-only", "self-only", CHANNEL)
    add("S-control", "symbol", "coupling.class-rank")
    add(
        "S-control-live",
        "symbol",
        "coupling.class-rank",
        coupling=True,
        note="positive control, producer enabled — new row, not in §1",
    )
    add(
        "NF-idle",
        "self-only",
        "*",
        note=(
            "a form without a rule filter and nothing to silence — new row, not in §1; "
            "it is what a form without a filter looks like once the ban takes its only coverage away"
        ),
    )
    add(
        "NEIGH-exact",
        "file",
        "annotation.unresolved-directive",
        note="a neighbouring configuration-error channel — new row, not in §1",
    )
    add("TH-channel", "threshold", CHANNEL)
    add("TH-producer", "threshold", "annotation.directive")

    return grid


# --------------------------------------------------------------------------
# Running
# --------------------------------------------------------------------------


def write_fixtures(root: Path, grid: list[dict]) -> None:
    (root / "qmx.yaml").write_text("paths:\n  - .\n")
    for case in grid:
        lines, tested, victim = build_fixture(case["layout"], case["text"])
        if len(lines) != 13:
            raise StandError(f"{case['id']}: fixture is {len(lines)} lines, expected 13")
        case["tested_line"] = tested
        case["victim_line"] = victim
        directory = root / "cases" / case["id"]
        directory.mkdir(parents=True, exist_ok=True)
        (directory / "Subject.php").write_text("\n".join(lines) + "\n")


def run(command: list[str], out: Path, err: Path) -> int:
    with out.open("wb") as stdout, err.open("wb") as stderr:
        return subprocess.call(command, cwd=REPO, stdout=stdout, stderr=stderr)


def read_json(path: Path, case_id: str, what: str) -> dict:
    text = path.read_text()
    if text.strip() == "":
        raise StandError(f"{case_id}: {what} produced no output at all")
    try:
        return json.loads(text)
    except json.JSONDecodeError as error:
        raise StandError(f"{case_id}: {what} did not produce readable JSON: {error}") from error


def observe(case: dict, root: Path, out_dir: Path) -> dict:
    case_path = str(root / "cases" / case["id"])
    config = str(root / "qmx.yaml")
    common = ["php", str(REPO / "bin" / "qmx")]
    disable = [] if case["coupling"] else ["--disable-rule=coupling.*"]

    check_out = out_dir / f"{case['id']}.check.json"
    check_err = out_dir / f"{case['id']}.check.err"
    exit_check = run(
        common
        + ["check", case_path, "-c", config, "--format=json", "--workers=0", "--no-cache", "--no-progress"]
        + disable,
        check_out,
        check_err,
    )

    suppressed_out = out_dir / f"{case['id']}.suppressed.json"
    suppressed_err = out_dir / f"{case['id']}.suppressed.err"
    exit_suppressed = run(
        common
        + [
            "check",
            case_path,
            "-c",
            config,
            "--format=suppressed",
            "--workers=0",
            "--no-cache",
            "--no-progress",
            "--show-suppressed",
        ]
        + disable,
        suppressed_out,
        suppressed_err,
    )

    directives_out = out_dir / f"{case['id']}.directives.json"
    directives_err = out_dir / f"{case['id']}.directives.err"
    exit_directives = run(
        common + ["directives", case_path, "-c", config, "--format=json", "--no-progress"] + disable,
        directives_out,
        directives_err,
    )

    for name, code in (("check", exit_check), ("suppressed", exit_suppressed), ("directives", exit_directives)):
        if code not in (0, 2):
            raise StandError(
                f"{case['id']}: the {name} run exited {code}, which is neither a clean run nor findings; "
                f"its stderr is in {case['id']}.{name}.err"
            )

    check = read_json(check_out, case["id"], "check --format=json")
    suppressed = read_json(suppressed_out, case["id"], "check --format=suppressed")
    directives = read_json(directives_out, case["id"], "directives --format=json")

    coverage = check.get("coverage", {})
    if coverage.get("complete") is not True or coverage.get("analyzed") != 1:
        raise StandError(f"{case['id']}: check did not analyse the single fixture file: {coverage}")
    if directives.get("scope", {}).get("complete") is not True:
        raise StandError(f"{case['id']}: the directives run is incomplete: {directives.get('scope')}")

    machine = suppressed.get("byMechanism", {}).get("suppression")
    if not isinstance(machine, int):
        raise StandError(f"{case['id']}: --format=suppressed carries no suppression count")

    prose = PROSE_PATTERN.search(suppressed_err.read_text())
    prose_count = int(prose.group(1)) if prose else 0
    if prose_count != machine:
        raise StandError(
            f"{case['id']}: the suppression prose says {prose_count} and the machine format says {machine}"
        )

    listed = {entry["line"]: entry for entry in directives.get("directives", [])}
    for role, line in (("tested", case["tested_line"]), ("victim", case["victim_line"])):
        if line is not None and line not in listed:
            raise StandError(
                f"{case['id']}: the {role} directive on line {line} is absent from the directives report "
                f"(lines seen: {sorted(listed)})"
            )

    findings = []
    for violation in check.get("violations", []):
        quoted = QUOTE_PATTERN.search(violation.get("message", ""))
        findings.append(
            {
                "line": violation["line"],
                "channel": violation["channel"],
                "severity": violation["severity"],
                "quoted": quoted.group(1) if quoted else None,
            }
        )

    returning = []
    for entry in suppressed.get("suppressed", []):
        if entry.get("mechanism") != "suppression":
            continue
        line = entry["line"]
        quoted = None
        if entry["channel"] == CHANNEL:
            source = listed.get(line)
            if source is None:
                raise StandError(
                    f"{case['id']}: a suppressed {CHANNEL} finding sits on line {line}, "
                    "where the directives report knows no directive"
                )
            quoted = source["target"]
        returning.append(
            {
                "line": line,
                "channel": entry["channel"],
                "severity": entry["severity"],
                "quoted": quoted,
            }
        )

    tested = listed.get(case["tested_line"]) if case["tested_line"] is not None else None
    victim = listed.get(case["victim_line"]) if case["victim_line"] is not None else None

    return {
        "case": case["id"],
        "authored target": tested["target"] if tested else "(none)",
        "authored line": case["text"] or VICTIM_LINE,
        "findings": findings,
        "suppressed_findings": returning,
        "suppressed": machine,
        "verdict": verdict_of(tested),
        "victim verdict": verdict_of(victim),
        "exit check": exit_check,
        "exit directives": exit_directives,
        "tested_line": case["tested_line"],
        "victim_line": case["victim_line"],
        "note": case["note"],
    }


def verdict_of(directive: dict | None) -> str:
    if directive is None:
        return "n/a"
    reason = directive.get("reason")
    return f"{directive['effect']} / {reason}" if reason else directive["effect"]


# --------------------------------------------------------------------------
# Rendering
# --------------------------------------------------------------------------


def render_finding(finding: dict) -> str:
    quoted = f' "{finding["quoted"]}"' if finding["quoted"] else ""
    return f'L{finding["line"]} {finding["channel"]} [{finding["severity"]}]{quoted}'


def parse_finding(text: str) -> dict:
    match = re.fullmatch(r'L(\d+) (\S+) \[(\w+)\](?: "(.*)")?', text.strip())
    if match is None:
        raise StandError(f"unreadable finding cell entry: {text!r}")
    return {
        "line": int(match.group(1)),
        "channel": match.group(2),
        "severity": match.group(3),
        "quoted": match.group(4),
    }


def render_findings(findings: list[dict]) -> str:
    if not findings:
        return "(none)"
    ordered = sorted(findings, key=lambda finding: (finding["line"], finding["channel"]))
    return "; ".join(render_finding(finding) for finding in ordered)


def render_row(row: dict) -> list[str]:
    return [
        f'`{row["case"]}`',
        f'`{row["authored target"]}`',
        render_findings(row["findings"]),
        str(row["suppressed"]),
        row["verdict"],
        row["victim verdict"],
        str(row["exit check"]),
        str(row["exit directives"]),
    ]


def render_table(rows: list[dict]) -> list[str]:
    body = [render_row(row) for row in rows]
    widths = [max(len(COLUMNS[i]), *(len(cells[i]) for cells in body)) for i in range(len(COLUMNS))]
    lines = ["| " + " | ".join(name.ljust(widths[i]) for i, name in enumerate(COLUMNS)) + " |"]
    lines.append("| " + " | ".join("-" * widths[i] for i in range(len(COLUMNS))) + " |")
    for cells in body:
        lines.append("| " + " | ".join(cells[i].ljust(widths[i]) for i in range(len(COLUMNS))) + " |")
    return lines


TSV_COLUMNS = [
    "case",
    "authored_target",
    "authored_line",
    "tested_line",
    "victim_line",
    "findings",
    "suppressed_findings",
    "suppressed",
    "verdict",
    "victim_verdict",
    "exit_check",
    "exit_directives",
    "note",
]


def render_tsv(rows: list[dict]) -> str:
    """The same observation, machine-readable, in the enumeration format this
    plan directory already uses. The prediction reads this file: once the ban
    has landed the pre-ban measurement cannot be produced again."""
    lines = ["\t".join(TSV_COLUMNS)]
    for row in rows:
        lines.append(
            "\t".join(
                [
                    row["case"],
                    row["authored target"],
                    row["authored line"],
                    str(row["tested_line"] or ""),
                    str(row["victim_line"] or ""),
                    render_findings(row["findings"]),
                    render_findings(row["suppressed_findings"]),
                    str(row["suppressed"]),
                    row["verdict"],
                    row["victim verdict"],
                    str(row["exit check"]),
                    str(row["exit directives"]),
                    row["note"],
                ]
            )
        )
    return "\n".join(lines) + "\n"


def parse_tsv(text: str) -> list[dict]:
    lines = text.splitlines()
    header = lines[0].split("\t")
    if header != TSV_COLUMNS:
        raise StandError(f"unexpected columns in the measured table: {header}")
    rows = []
    for line in lines[1:]:
        cells = line.split("\t")
        if len(cells) != len(TSV_COLUMNS):
            raise StandError(f"a row of the measured table has {len(cells)} cells: {line!r}")
        cell = dict(zip(TSV_COLUMNS, cells))
        rows.append(
            {
                "case": cell["case"],
                "authored target": cell["authored_target"],
                "authored line": cell["authored_line"],
                "tested_line": int(cell["tested_line"]) if cell["tested_line"] else None,
                "victim_line": int(cell["victim_line"]) if cell["victim_line"] else None,
                "findings": parse_findings(cell["findings"]),
                "suppressed_findings": parse_findings(cell["suppressed_findings"]),
                "suppressed": int(cell["suppressed"]),
                "verdict": cell["verdict"],
                "victim verdict": cell["victim_verdict"],
                "exit check": int(cell["exit_check"]),
                "exit directives": int(cell["exit_directives"]),
                "note": cell["note"],
            }
        )
    return rows


def parse_findings(cell: str) -> list[dict]:
    if cell == "(none)":
        return []
    return [parse_finding(part) for part in cell.split(";")]


def parse_table(text: str) -> dict[str, dict]:
    """Reads back a table written by `render_table`, keyed by case."""
    rows: dict[str, dict] = {}
    for line in text.splitlines():
        if not line.startswith("|"):
            continue
        cells = [cell.strip() for cell in line.strip().strip("|").split("|")]
        if len(cells) != len(COLUMNS):
            continue
        case_id = cells[0].strip("`")
        if case_id in ("case", "") or set(cells[0]) <= {"-"}:
            continue
        findings = [] if cells[2] == "(none)" else [parse_finding(part) for part in cells[2].split(";")]
        rows[case_id] = {
            "case": case_id,
            "authored target": cells[1].strip("`"),
            "findings": findings,
            "suppressed": int(cells[3]),
            "verdict": cells[4],
            "victim verdict": cells[5],
            "exit check": int(cells[6]),
            "exit directives": int(cells[7]),
        }
    return rows


# --------------------------------------------------------------------------
# Comparison against §1 of the measurement
# --------------------------------------------------------------------------


def parse_measurement(path: Path) -> dict[str, dict]:
    rows: dict[str, dict] = {}
    for line in path.read_text().splitlines():
        if not line.startswith("| `"):
            continue
        cells = [cell.strip() for cell in line.strip().strip("|").split("|")]
        if len(cells) != 6:
            continue
        case_id = cells[0].strip("`")
        prints = cells[2]
        entries = [] if prints == "(none)" else [part.strip() for part in prints.split(";")]
        rows[case_id] = {
            "prints": sorted(entries),
            "suppressed": 0 if cells[3] == "no suppression prose" else int(cells[3].split()[0]),
            "verdict": cells[4].strip("*"),
            "exit check": int(cells[5].split("/")[0].strip()),
            "exit directives": int(cells[5].split("/")[1].strip()),
        }
    return rows


def measurement_view(row: dict) -> list[str]:
    """Today's row, rendered the way §1 renders it: unused-directive only."""
    return sorted(
        f'L{finding["line"]} {finding["quoted"]}'
        for finding in row["findings"]
        if finding["channel"] == CHANNEL
    )


def compare_with_measurement(rows: list[dict], measured: dict[str, dict], partial: bool = False) -> list[str]:
    problems = []
    by_case = {row["case"]: row for row in rows}
    for case_id, expected in measured.items():
        if case_id not in by_case:
            if not partial:
                problems.append(f"{case_id}: §1 has this row and the stand does not")
            continue
        row = by_case[case_id]
        seen = measurement_view(row)
        if seen != expected["prints"]:
            problems.append(f"{case_id}: check prints {seen} but §1 says {expected['prints']}")
        for column in ("suppressed", "verdict", "exit check", "exit directives"):
            if str(row[column]) != str(expected[column]):
                problems.append(f"{case_id}: {column} is {row[column]} but §1 says {expected[column]}")
    return problems


def compare_with_expected(rows: list[dict], expected: dict[str, dict], label: str) -> list[str]:
    problems = []
    by_case = {row["case"]: row for row in rows}
    for case_id in sorted(set(by_case) | set(expected)):
        if case_id not in expected:
            problems.append(f"{case_id}: measured, but {label} has no such row")
            continue
        if case_id not in by_case:
            problems.append(f"{case_id}: {label} has this row and the stand did not measure it")
            continue
        row, want = by_case[case_id], expected[case_id]
        if render_findings(row["findings"]) != render_findings(want["findings"]):
            problems.append(
                f"{case_id}: check findings are {render_findings(row['findings'])} "
                f"but {label} says {render_findings(want['findings'])}"
            )
        for column in ("authored target", "suppressed", "verdict", "victim verdict", "exit check", "exit directives"):
            if str(row[column]) != str(want[column]):
                problems.append(f"{case_id}: {column} is {row[column]} but {label} says {want[column]}")
    return problems


# --------------------------------------------------------------------------
# Formulas, validated against today's measurement before anyone predicts with them
# --------------------------------------------------------------------------


def exit_check_of(findings: list[dict]) -> int:
    return 2 if any(finding["severity"] == "error" for finding in findings) else 0


def exit_directives_of(verdicts: list[str]) -> int:
    return 2 if any(verdict.startswith("inert") or verdict.startswith("overrun") for verdict in verdicts) else 0


def check_formulas(rows: list[dict]) -> list[str]:
    problems = []
    for row in rows:
        if exit_check_of(row["findings"]) != row["exit check"]:
            problems.append(
                f"{row['case']}: the exit-code formula says {exit_check_of(row['findings'])} "
                f"for check and the run said {row['exit check']}"
            )
        verdicts = [row["verdict"], row["victim verdict"]]
        if exit_directives_of(verdicts) != row["exit directives"]:
            problems.append(
                f"{row['case']}: the exit-code formula says {exit_directives_of(verdicts)} "
                f"for directives and the run said {row['exit directives']}"
            )
    return problems


# --------------------------------------------------------------------------


def document(rows: list[dict]) -> str:
    lines = [
        "<!-- Generated by stand-directive-forms.py. Do not edit by hand. -->",
        "",
        "# X4 — today's table of authored directive forms",
        "",
        "Measured by `stand-directive-forms.py` on an untouched tree. It reproduces §1",
        "of `measurement-directive-forms.md` — the comparison is part of the run — and",
        "adds the columns the ban needs: every printed finding rather than one channel's,",
        "the machine suppression count, and the verdict of the victim directive.",
        "",
        "A tool of this push, not a standing gate. The standing guards of the ban are the",
        "tests and probes of package 2.",
        "",
        "## Columns",
        "",
        "- `check findings` — every finding `check --format=json` printed, as",
        "  `L<line> <channel> [<severity>]` plus, for a staleness finding, the target its",
        "  message quotes;",
        "- `suppressed` — `byMechanism.suppression` from `check --format=suppressed`. The",
        "  prose of `--show-suppressed` is read from stderr and must agree with it, or the",
        "  stand refuses;",
        "- `verdict` / `victim verdict` — `effect` and, when there is one, `reason` from",
        "  `directives --format=json`, for the directive under test and for the victim;",
        "- `exit check` / `exit directives` — both return codes.",
        "",
        "## Table",
        "",
    ]
    lines += render_table(rows)
    lines += [
        "",
        "## Normalization",
        "",
        "Named here, and not by default. The stand compares only what the columns above",
        "carry; everything else in the raw output is deliberately not compared:",
        "",
        "- absolute paths (`file`, `subject`, `symbol`, `suppressor`) — the fixtures live",
        "  outside the repository, so every path is machine-specific;",
        "- `meta.timestamp`, `meta.version`, `summary.duration` — one moves per run, one",
        "  per tree, one per machine;",
        "- the order of findings — compared as a set, ordered by line and channel;",
        "- `scope.produced_findings` of the `directives` report. Package 2 moves it by",
        "  design: unsplicing the late channel changes the universe of verdicts. It is",
        "  named here as normalized rather than left out silently, so that the shift is",
        "  declared in the package that causes it and not hidden by the oracle;",
        "- the autoload warning on stderr (`Analyzed paths do not cover all autoload",
        "  entries`), which the fixture provokes by living outside the project.",
        "",
        "## Fixtures",
        "",
        "One file per case, thirteen lines, generated by the stand outside the",
        "repository — the project analyses itself, so a fixture inside it would move the",
        "input along with the step being measured. Line 5 opens `namespace X4Fix;`, lines",
        "7-9 carry the directives and line 10 opens the class: a file form sits on line 8,",
        "a symbol form in the class docblock on line 9, a next-line form on line 7 with",
        "the victim pushed down to 8. `S-method` is the one exception — its class starts",
        "on line 9 so that the method docblock lands on 11, where §1 observed it.",
        "",
        "The victim is always",
        "`" + VICTIM_LINE + "`;",
        "`SELF-only` and `NF-idle` are the two cases written without one.",
        "",
        "| case | tested line | authored directive |",
        "| ---- | ----------- | ------------------ |",
    ]
    for row in rows:
        tested = str(row["tested_line"]) if row["tested_line"] else "—"
        lines.append(f'| `{row["case"]}` | {tested} | `{row["authored line"]}` |')
    lines += [
        "",
        "## Rows that are not in §1",
        "",
    ]
    for row in rows:
        if row["note"]:
            lines.append(f'- `{row["case"]}` — {row["note"]}')
    lines += [
        "",
        "## Formulas the prediction is allowed to use",
        "",
        "Both are checked against every measured row before the prediction may lean on",
        "them; the stand fails if either fails to reproduce a measured return code.",
        "",
        "- `exit check` is 2 exactly when some printed finding has severity `error`;",
        "- `exit directives` is 2 exactly when some directive is `inert` or `overrun`.",
        "",
    ]
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="Measure today's table of authored directive forms.")
    parser.add_argument("--output", type=Path, default=PLAN_DIR / "stand-today-forms.md")
    parser.add_argument(
        "--tsv",
        type=Path,
        default=PLAN_DIR / "stand-today-forms.tsv",
        help="the same rows, machine-readable; the prediction reads this file",
    )
    parser.add_argument("--expect", type=Path, default=None, help="a table this run must reproduce")
    parser.add_argument("--keep", type=Path, default=None, help="keep fixtures and raw output here")
    parser.add_argument("--only", default=None, help="comma-separated case ids")
    parser.add_argument(
        "--no-measurement-check",
        action="store_true",
        help="skip the comparison with §1 (for a tree where the ban has landed)",
    )
    arguments = parser.parse_args()

    grid = cases()
    if arguments.only:
        wanted = set(arguments.only.split(","))
        unknown = wanted - {case["id"] for case in grid}
        if unknown:
            print(f"unknown case ids: {sorted(unknown)}", file=sys.stderr)
            return 3
        grid = [case for case in grid if case["id"] in wanted]

    with tempfile.TemporaryDirectory(prefix="x4-forms-") as temporary:
        root = Path(arguments.keep) if arguments.keep else Path(temporary)
        root.mkdir(parents=True, exist_ok=True)
        out_dir = root / "out"
        out_dir.mkdir(exist_ok=True)
        write_fixtures(root, grid)

        rows = []
        try:
            for case in grid:
                print(f"  {case['id']}", file=sys.stderr, flush=True)
                rows.append(observe(case, root, out_dir))
        except StandError as error:
            print(f"stand-directive-forms: {error}", file=sys.stderr)
            return 3

        text = document(rows)
        leaked = [part for part in (str(root), str(REPO), "/Users", "/home/") if part in text]
        if leaked:
            print(f"stand-directive-forms: the rendered table leaks a path: {leaked}", file=sys.stderr)
            return 3

        if arguments.output:
            arguments.output.write_text(text + "\n")
            print(f"wrote {arguments.output.name}", file=sys.stderr)
        else:
            print(text)

        if arguments.tsv and not arguments.only:
            arguments.tsv.write_text(render_tsv(rows))
            print(f"wrote {arguments.tsv.name}", file=sys.stderr)

        problems = check_formulas(rows)
        if not arguments.no_measurement_check:
            measured = parse_measurement(MEASUREMENT)
            if len(measured) != 32 and not arguments.only:
                print(
                    f"stand-directive-forms: §1 of the measurement parsed as {len(measured)} rows, expected 32",
                    file=sys.stderr,
                )
                return 3
            problems += compare_with_measurement(rows, measured, partial=bool(arguments.only))
        if arguments.expect:
            expected = parse_table(arguments.expect.read_text())
            if not expected:
                print(f"stand-directive-forms: no table found in {arguments.expect}", file=sys.stderr)
                return 3
            problems += compare_with_expected(rows, expected, arguments.expect.name)

        if problems:
            print(f"\nDISAGREED ({len(problems)}):", file=sys.stderr)
            for problem in problems:
                print(f"  - {problem}", file=sys.stderr)
            return 1

        print("AGREED", file=sys.stderr)
        return 0


if __name__ == "__main__":
    sys.exit(main())
