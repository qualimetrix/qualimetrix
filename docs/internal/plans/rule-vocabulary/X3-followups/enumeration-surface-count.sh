#!/usr/bin/env bash
# Every line of the LIVE perimeter that could be restating how many surfaces the
# gate compares, so the ones that are can be read out by hand. The TSV beside
# this script is a snapshot of this command's output on the tree as committed:
# at rest the two agree address for address, and that comparison is the check.
# It does not hold *during* an edit — moving a line moves its number, and
# removing a count removes its row — so mid-edit the check is the line-by-line
# one named in 03-suppressed-surface.md.
#
# Run from the repository root. `git grep` is scoped to the working directory,
# and this tree is a worktree where a shell can land elsewhere between commands,
# so the root is asserted rather than assumed.
#
# Two passes, because a count hides in two shapes and neither pass finds both:
#
#   1. the total itself, in the numerals it is plausibly written in;
#   2. any spelled numeral from two upwards on a line that also names the
#      subject (format, surface). "One surface" is left out: it is a singular,
#      not a count that moves. This is what finds a *derived* count
#      -- "the other ten formats", "the other eight formats" -- whose numeral is
#      nowhere near the total. The first pass missed `RenameMaps.php` on exactly
#      this shape, and the second pass exists because of it.
#
# What the method does not see: a count written as a digit ("11 formats"); a
# count carried only by the length of a list; a count on a line that names the
# subject one line away, unless its numeral falls in pass 1's window; a count of
# something else entirely (corpus cases, planted controls) -- those need their
# own subject word; and a count in a language neither pattern covers.
#
# The perimeter is what is still edited when a count moves. That excludes ADRs
# and plans — of closed заходы and of the open one alike: a package plan states
# the count as of its own package and is superseded by its next revision rather
# than edited, which is why the revisions are numbered. It also excludes this
# script and its own table, which would otherwise enumerate themselves and grow
# by doing so. `FOLLOWUPS.md` is in: an open entry is a live claim about the
# tree, not a record.
set -euo pipefail
test -f composer.json && test -d finding-gate \
    || { echo 'run from the repository root' >&2; exit 2; }

PERIMETER=(AGENTS.md CHANGELOG.md qmx.yaml
           finding-gate scripts/finding-gate scripts/finding-gate-controls
           docs/internal/plans/rule-vocabulary/FOLLOWUPS.md)
WIDE='two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty'
TOTAL='nine|ten|eleven|twelve|thirteen|девят.*|десят.*|одиннадцат.*|двенадцат.*'

{
    git grep -n -w -i -E "$TOTAL" -- "${PERIMETER[@]}"
    git grep -n -w -i -E "$WIDE|одиннадцат.*|двенадцат.*|десят.*" -- "${PERIMETER[@]}" \
      | grep -i -E 'format|surface|формат|поверхност'
} | sort -u -t: -k1,1 -k2,2n \
  | sed -E 's/^([^:]+):([0-9]+):[[:space:]]*/\1\t\2\t/'
