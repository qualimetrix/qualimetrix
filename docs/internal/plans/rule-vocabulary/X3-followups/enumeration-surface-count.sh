#!/usr/bin/env bash
# Every line of the LIVE perimeter that spells a count as a word, so the ones
# meaning "how many surfaces the gate compares" can be read out by hand. The
# TSV beside this script is this command's output, one row per line, with a
# hand-read `meaning` and `action` column: re-running the script and comparing
# the two file lists is therefore a check, not a gesture.
#
# Run from the repository root. `git grep` is scoped to the working directory,
# and this tree is a worktree where a shell can land elsewhere between commands,
# so the root is asserted rather than assumed.
#
# Both spellings of the count are searched, and so are the numerals a *derived*
# count is written in: "the other ten formats" moves when the total moves, and
# searching for the total alone does not find it.
#
# The perimeter excludes ADRs and the plans of closed заходы: those are records
# of their own time and are not edited when a count moves. Lines there that mean
# this very count are named in 03-suppressed-surface.md instead of being listed
# here as work.
#
# What the method does not see: a count written as a digit ("11 formats"), a
# count carried only by the length of a list, and a count in a language neither
# pattern covers. It also sees only the numerals in the alternation below — a
# count of something else (corpus cases, planted controls) needs its own window.
set -euo pipefail
test -f composer.json && test -d finding-gate \
    || { echo 'run from the repository root' >&2; exit 2; }
git grep -n -w -i -E 'nine|ten|eleven|twelve|thirteen|девят.*|десят.*|одиннадцат.*|двенадцат.*' -- \
    AGENTS.md CHANGELOG.md qmx.yaml \
    finding-gate scripts/finding-gate scripts/finding-gate-controls \
    docs/internal/plans/rule-vocabulary/FOLLOWUPS.md \
  | sed -E 's/^([^:]+):([0-9]+):[[:space:]]*/\1\t\2\t/'
