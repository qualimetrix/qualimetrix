#!/usr/bin/env python3
"""Derives the post-ban table from today's measurement, by the rule in the plan.

The prediction is the point of package 1. A stand written after the product was
changed confirms itself; an expectation filled in from the changed product's own
output does the same thing, only less visibly. So the table is derived here,
mechanically, from two inputs and nothing else:

  * `stand-today-forms.tsv` — what the untouched tree does, measured by
    `stand-directive-forms.py`;
  * the six-item rule of `01-forms-stand.md`, transcribed below one function per
    item, with the item number carried into the table so every cell is traceable.

Nothing here reads the product. Package 2 may not add a row: a measured row that
disagrees with the predicted one is a stop signal, not an edit.

Usage:
    python3 stand-predict-forms.py [--input=<today.tsv>] [--output=<file>]

Exit codes: 0 written, 3 the input could not be read or a row is not classified.
"""

from __future__ import annotations

import argparse
import importlib.util
import sys
from pathlib import Path

PLAN_DIR = Path(__file__).resolve().parent

# The stand is the sibling file, and its name has hyphens in it, so it is loaded
# by path rather than imported. Its rendering and its two return-code formulas
# are reused deliberately: a second copy of either would drift.
sys.dont_write_bytecode = True
_SPEC = importlib.util.spec_from_file_location("x4_stand", PLAN_DIR / "stand-directive-forms.py")
_STAND = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(_STAND)

CHANNEL = _STAND.CHANNEL
exit_check_of = _STAND.exit_check_of
exit_directives_of = _STAND.exit_directives_of
render_table = _STAND.render_table
render_row = _STAND.render_row
parse_tsv = _STAND.parse_tsv

REFUSAL_CHANNEL = "annotation.unresolved-directive"
CONFIG_ERROR_PREFIX = "annotation."


class PredictionError(RuntimeError):
    """A row the rule does not decide. Named, never guessed."""


# --------------------------------------------------------------------------
# Reading an authored target the way the product's grammar reads it
# --------------------------------------------------------------------------


def split_level(target: str) -> tuple[str, str | None]:
    if ":" in target:
        selector, level = target.rsplit(":", 1)
        return selector, level
    return target, None


def covers_channel(selector: str) -> bool:
    """`NameSelector`: equality, or `X.*` for the strict descendants of `X`."""
    if selector.endswith(".*"):
        return CHANNEL.startswith(selector[:-1])
    return selector == CHANNEL


# --------------------------------------------------------------------------
# The rule, one function per item
# --------------------------------------------------------------------------


def classify(row: dict) -> tuple[int, str]:
    """Answers (rule item, why), from the authored form alone."""
    target = row["authored target"]
    authored = row["authored line"]

    if "@qmx-threshold" in authored:
        return 4, "a threshold, either spelling"

    selector, level = split_level(target)

    if selector == "*":
        return 2, "no rule filter"

    if level is not None and level != "file":
        return 3, f"a pair with level {level!r}, which the channel does not report at"

    if covers_channel(selector):
        return 1, "names or covers the banned channel"

    if selector.startswith(CONFIG_ERROR_PREFIX):
        return 5, "a neighbouring annotation channel (a configuration error)"

    return 6, "another channel entirely"


def predict_refused(row: dict) -> dict:
    """Item 1. The directive is refused.

    The staleness finding on the directive's own line is replaced by the refusal
    on the same line; `suppressed` goes to zero, so everything the directive was
    silencing comes back — the victim's complaint included, except the
    directive's own, which the refusal has taken the place of.
    """
    tested = row["tested_line"]
    kept = [
        finding
        for finding in row["findings"]
        if not (finding["line"] == tested and finding["channel"] == CHANNEL)
    ]
    returning = [
        finding
        for finding in row["suppressed_findings"]
        if not (finding["line"] == tested and finding["channel"] == CHANNEL)
    ]
    refusal = {"line": tested, "channel": REFUSAL_CHANNEL, "severity": "error", "quoted": None}
    findings = kept + returning + [refusal]

    predicted = dict(row)
    predicted["findings"] = findings
    predicted["suppressed"] = 0
    predicted["verdict"] = "unmeasured / already-refused"
    return finish(predicted)


def predict_no_filter(row: dict) -> dict:
    """Item 2. Not refused — it names nothing — but it no longer covers the
    banned channel, so that finding leaves `suppressed` and returns to the
    report. The verdict is unchanged: the audit still declines to judge a form
    that addresses every channel.
    """
    returning = [finding for finding in row["suppressed_findings"] if finding["channel"] == CHANNEL]
    predicted = dict(row)
    predicted["findings"] = row["findings"] + returning
    predicted["suppressed"] = row["suppressed"] - len(returning)
    return finish(predicted)


def predict_unchanged(row: dict) -> dict:
    """Items 3, 4, 5 and 6. Nothing moves, down to the text of the diagnostic.

    For item 3 this is a requirement rather than a consequence: the new refusal
    stands after the `channel:level` grammar precisely so that these eight forms
    keep the diagnostic they have today.
    """
    return finish(dict(row))


def finish(predicted: dict) -> dict:
    """Both return codes follow from the cells above by the formulas the stand
    validated against every measured row."""
    predicted["exit check"] = exit_check_of(predicted["findings"])
    predicted["exit directives"] = exit_directives_of([predicted["verdict"], predicted["victim verdict"]])
    return predicted


RULES = {
    1: predict_refused,
    2: predict_no_filter,
    3: predict_unchanged,
    4: predict_unchanged,
    5: predict_unchanged,
    6: predict_unchanged,
}


# --------------------------------------------------------------------------


def moves(today: dict, predicted: dict) -> bool:
    """Whether the ban is predicted to move this row at all — read off the two
    tables, not off the rule item: item 2 leaves a row where it stands when the
    directive had no coverage of the banned channel to lose."""
    return render_row(today) != render_row(predicted)


def document(rows: list[dict], today: dict[str, dict], derivation: dict[str, tuple[int, str]]) -> str:
    changed = [row["case"] for row in rows if moves(today[row["case"]], row)]
    lines = [
        "<!-- Generated by stand-predict-forms.py. Do not edit by hand. -->",
        "",
        "# X4 — the predicted post-ban table",
        "",
        "Derived by `stand-predict-forms.py` from `stand-today-forms.tsv` and the",
        "six-item rule of `01-forms-stand.md`, before the product was touched. Each row",
        "names the item it was derived by.",
        "",
        "This is the expectation package 2 is judged against:",
        "",
        "```",
        "python3 stand-directive-forms.py --no-measurement-check \\",
        "        --expect=stand-predicted-forms.md",
        "```",
        "",
        "A measured row that disagrees with a predicted one means the **rule** is wrong.",
        "That is a conversation, not a table edit: package 2 may not add or move a row",
        "here.",
        "",
        "## Table",
        "",
    ]
    lines += render_table(rows)
    lines += [
        "",
        "## How each row was derived",
        "",
        "| case | rule item | why | moves |",
        "| ---- | --------- | --- | ----- |",
    ]
    for row in rows:
        item, why = derivation[row["case"]]
        moved = "yes" if moves(today[row["case"]], row) else "no"
        lines.append(f'| `{row["case"]}` | {item} | {why} | {moved} |')
    lines += [
        "",
        "## The cells the rule gives least obviously",
        "",
        "Written out here because a reader should not have to re-derive them:",
        "",
        "- the file forms that name the channel go `suppressed` 2 -> 0 and the next-line",
        "  forms 1 -> 0 (item 1): the victim's complaint returns to the report, and the",
        "  directive's own is replaced by the refusal on the same line;",
        "- `SELF-only`, whose single file directive silences nothing but its own",
        "  complaint, has its pair of return codes **inverted**, 0 / 2 -> 2 / 0 (item 1):",
        "  the verdict stops being `inert`, and `directives` exits 2 only on an inert",
        "  directive;",
        "- the forms without a rule filter keep `exit check` (item 2): the finding that",
        "  comes back carries severity `info`, not `error`;",
        "- a form without a rule filter that covers nothing does **not** acquire a",
        "  staleness complaint of its own, and keeps the verdict",
        "  `unmeasured / addresses-every-channel` (item 2). That is measured rather than",
        "  assumed: `NF-idle` is exactly that directive today, and it is what `F-nofilter`",
        "  is predicted to become — the silently dead form the overview names as an",
        "  accepted cost;",
        "- `S-control-live` does not move (item 6). It is the positive control: were the",
        "  symbol form dead in general rather than dead against this channel, the whole",
        "  first behavioural class of the measurement would mean something else.",
        "",
        "## Rows that move at all",
        "",
        f"{len(changed)} of {len(rows)}: " + ", ".join(f"`{case}`" for case in changed) + ".",
        "",
        "Every other row is a claim that the ban left it alone, and is worth exactly as",
        "much as the rows that move.",
        "",
    ]
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="Derive the post-ban table from today's measurement.")
    parser.add_argument("--input", type=Path, default=PLAN_DIR / "stand-today-forms.tsv")
    parser.add_argument("--output", type=Path, default=PLAN_DIR / "stand-predicted-forms.md")
    arguments = parser.parse_args()

    if not arguments.input.is_file():
        print(f"stand-predict-forms: {arguments.input.name} is missing; run the stand first", file=sys.stderr)
        return 3

    today = parse_tsv(arguments.input.read_text())
    if not today:
        print(f"stand-predict-forms: {arguments.input.name} carries no rows", file=sys.stderr)
        return 3

    rows = []
    derivation = {}
    for row in today:
        try:
            item, why = classify(row)
        except PredictionError as error:
            print(f"stand-predict-forms: {error}", file=sys.stderr)
            return 3
        derivation[row["case"]] = (item, why)
        rows.append(RULES[item](row))

    arguments.output.write_text(document(rows, {row["case"]: row for row in today}, derivation) + "\n")
    print(f"wrote {arguments.output.name}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
