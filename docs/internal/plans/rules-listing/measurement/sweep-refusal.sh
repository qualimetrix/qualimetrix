#!/usr/bin/env bash
# The second witness: the allowed set the SHIPPED BINARY names in its refusal,
# for every (rule, depth) the declarations admit. It shares no code path with
# dump-declared-options.php — it reads the product's answer, not its classes.
#
#   docs/internal/plans/rules-listing/measurement/sweep-refusal.sh \
#     > docs/internal/plans/rules-listing/measurement/runtime-refusal.tsv
#
# `set -e` is deliberately absent: the product exits 3 on exactly the refusal
# this sweep asks for, so a non-zero status is the expected answer here and an
# errexit shell would abort on the first successful probe. Failure is judged by
# the empty-answer test below instead.
set -uo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$here/../../../../.." && pwd)"
table="$here/declared-options.tsv"

# A rule option is refused while the configuration is read, before any file is
# analysed, so the subject only has to exist.
probe="$(mktemp -d)"
trap 'rm -rf "$probe"' EXIT
printf '<?php\nclass A {}\n' > "$probe/A.php"

printf 'rule\tlevel\toptions_here\n'

pairs="$(tail -n +2 "$table" | awk -F'\t' '{ print $1 "\t" ($4 == "level-slot" ? $3 : "-") }' | sort -u)"

while IFS=$'\t' read -r rule level; do
    [ -n "$rule" ] || continue

    if [ "$level" = "-" ]; then key='zzNotAnOption'; else key="$level.zzNotAnOption"; fi

    raw="$("$root/bin/qmx" check "$probe" --rule-opt="$rule:$key=1" --workers=0 --no-progress 2>&1)"
    answer="$(printf '%s\n' "$raw" | grep -o 'Options[a-z ]*: [^.]*' | sed 's/^Options[a-z ]*: //')"

    if [ -z "$answer" ]; then
        printf 'no refusal for %s [%s]\n' "$rule" "$level" >&2
        exit 1
    fi

    printf '%s\t%s\t%s\n' "$rule" "$level" "$answer"
done <<< "$pairs"
