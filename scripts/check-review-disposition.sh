#!/bin/bash
# Checks that a plan has disposed of every review finding raised against it.
#
# Three review rounds of the rule-vocabulary plan dropped findings silently —
# three, then three more — and every time the omission was caught by a human
# re-reading the list. A dropped finding is invisible by construction: the
# disposition section looks complete because it only contains what was written
# into it. This script makes the omission machine-detectable.
#
# It also enforces the form the check needs: finding ids must be spelled in
# full. An earlier revision compressed them to "claude-01, -03, -08", which
# reads fine and defeats any cross-check.
#
# The id pattern is deliberately permissive about how many name segments precede
# the number. It used to accept only one plus an optional round marker, and the
# implementation review of Ш1 walked straight into that: 12 findings named
# `native-claude-NN` matched nothing, so the script reported every finding
# disposed of while ignoring two thirds of them. A checker that silently sees
# fewer findings than exist is worse than no checker — it is the same failure
# mode it was written to catch, wearing the checker's authority.
#
# Usage:
#   scripts/check-review-disposition.sh <plan.md> <findings-dir>...
#
# The findings directories are working artifacts and are typically git-ignored;
# when none of them exists the script reports that and exits 0, because "the
# review artifacts are not on this machine" is not a defect of the plan.

set -uo pipefail

if [ "$#" -lt 2 ]; then
    echo "usage: $0 <plan.md> <findings-dir>..." >&2
    exit 2
fi

plan="$1"
shift

if [ ! -f "$plan" ]; then
    echo "plan not found: $plan" >&2
    exit 2
fi

present=0
for dir in "$@"; do
    [ -d "$dir" ] && present=1
done

if [ "$present" -eq 0 ]; then
    echo "No review artifacts found in: $* — nothing to cross-check, skipping."
    echo "(This is expected on a fresh clone: the findings are kept locally.)"
    exit 0
fi

missing=0
total=0
ambiguous=0
seen_ids=""

for dir in "$@"; do
    [ -d "$dir" ] || continue
    for file in "$dir"/*.md; do
        [ -f "$file" ] || continue
        while read -r id; do
            [ -n "$id" ] || continue
            total=$((total + 1))

            # An id reused by a later round defeats the cross-check: the plan
            # cites the old finding, the grep below matches, and the new one is
            # reported as disposed of while nobody has read it. Round 7 walked
            # into exactly this — bare `codex-01`/`claude-01` collided with
            # round 1 — and the checker said "all disposed". Ids must therefore
            # be unique across the whole set of findings directories, which in
            # practice means carrying their round.
            case " $seen_ids " in
                *" $id="*)
                    previous=$(printf '%s' "$seen_ids" | tr ' ' '\n' | grep -F -- "$id=" | head -1 | cut -d= -f2-)
                    if [ "$previous" != "$file" ]; then
                        echo "AMBIGUOUS: $id is used by both ${previous#./} and ${file#./}"
                        echo "           — citing it in the plan cannot distinguish the two findings."
                        ambiguous=$((ambiguous + 1))
                    fi
                    ;;
                *)
                    seen_ids="$seen_ids $id=$file"
                    ;;
            esac

            # Exact match only: an id must appear spelled out, not abbreviated.
            if ! grep -qF -- "$id" "$plan"; then
                echo "NOT DISPOSED: $id (${file#./})"
                missing=$((missing + 1))
            fi
        done < <(grep -ohE '^#+ +[a-z][a-z0-9]*(-[a-z][a-z0-9]*)*-[0-9]+' "$file" | sed -E 's/^#+ +//')
    done
done

if [ "$ambiguous" -gt 0 ]; then
    echo
    echo "$ambiguous id(s) are shared by two findings files, so the cross-check below"
    echo "cannot tell whether the plan disposed of the old finding or the new one."
    exit 1
fi

if [ "$missing" -gt 0 ]; then
    echo
    echo "$missing of $total findings are cited nowhere in $plan."
    echo "Spell every id in full — an abbreviated list is not cross-checkable."
    exit 1
fi

echo "All $total review findings are disposed of in $plan."
