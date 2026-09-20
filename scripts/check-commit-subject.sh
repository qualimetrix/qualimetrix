#!/bin/bash
# Judges commit subjects against this repository's Conventional Commits rule.
#
# The rule is owned here rather than in the hook, because two layers apply it:
# the commit-msg hook, which sees a message before it becomes a commit, and CI,
# which sees the commits a pull request adds. A pattern spelled twice is a
# pattern that drifts.
#
# THE RULE: A SUBJECT IS JUDGED WHEN A PERSON WROTE IT
#
# commit-msg is invoked by git-commit and git-merge, and by nothing else
# (githooks(5)). The clean path of `git revert` and `git cherry-pick` therefore
# writes its commit without ever consulting a hook, while the SAME operation,
# having hit a conflict, finishes through `git commit` and does consult one.
# A hook that judged those subjects would answer differently for identical
# content depending on whether the merge conflicted — a verdict decided by a
# condition the rule does not name.
#
# So the hook judges what a person typed and declines what git composed.
# Detection is by git's own marker files, not by matching the word "Merge" in
# a subject, which an ordinary hand-written commit is free to contain.
#
# The three markers are an APPROXIMATION of that rule, not the rule itself, and
# the places they miss are named here rather than discovered later:
#
#   * `git merge -m "..."` is a person writing a subject, and MERGE_HEAD
#     exempts it. Merge commits are out of scope at both layers by
#     construction — CI excludes them by parent count too.
#   * `git rebase` replay and `git rebase --continue` reach no hook at all, and
#     carry a subject already judged when it was first written. `git rebase -i`
#     with `reword` or `squash` DOES reach the hook with no marker present —
#     correctly, because a person is typing that subject now.
#   * `git am` reaches `applypatch-msg`, not this hook, so a mailed patch's
#     subject is unseen here. Inside a pull request CI still judges it.
#
# The same reasoning at one layer up: GitHub's merge and squash buttons write
# subjects server-side, where no local hook exists. Those subjects are the
# repository's separate, deliberate convention (a prose PR title) and are not
# judged here either. See docs/adr/0072-commit-subject-authority.md.
#
# Usage:
#   check-commit-subject.sh --message FILE   judge a commit message (commit-msg hook)
#   check-commit-subject.sh --commits RANGE  judge non-merge commits in a range
#   check-commit-subject.sh --pull-request   judge what this pull request adds (CI)
#
# Portable to bash 3.2 (macOS system bash): no mapfile, no associative arrays.

set -uo pipefail

TYPES="feat fix refactor test docs chore style perf ci build"

# One list, two uses. Spelling the types twice let a type be dropped from the
# pattern while the help text still offered it.
TYPE_ALTERNATION=$(echo "$TYPES" | tr ' ' '|')

# `!` is the Conventional Commits breaking-change marker, and `Breaking` is a
# live CHANGELOG category here.
CONVENTIONAL="($TYPE_ALTERNATION)(\(.+\))?!?: .+"

PATTERN="^$CONVENTIONAL"

# Advisory only. Enforcing it would be a separate change: most of this
# repository's history exceeds it, so a hard limit would redden CI on contact.
SUBJECT_LENGTH_LIMIT=72

MODE=""
ARG=""
case "${1:-}" in
    --message)      MODE="message"; ARG="${2:-}" ;;
    --commits)      MODE="commits"; ARG="${2:-}" ;;
    --pull-request) MODE="pull-request" ;;
    *)              echo "Usage: $0 --message FILE | --commits RANGE | --pull-request" >&2; exit 2 ;;
esac

# ---------------------------------------------------------------------------
# The sequencer states git marks. Each marker file is present for exactly the
# operation it names, including while a conflict is being resolved, and absent
# for a hand-authored commit.
# ---------------------------------------------------------------------------
SEQUENCER_MARKERS="MERGE_HEAD REVERT_HEAD CHERRY_PICK_HEAD"

# Rebase is marked by a directory, not a file, and only for the length of the
# operation. REBASE_HEAD deliberately is NOT in the list above: measured, it
# survives the rebase that wrote it, so keying on it would silence the hook
# permanently after anyone's first rebase.
#
# This exempts `rebase -i` with `reword`, where a person IS typing the subject.
# Accepted knowingly: the alternative is refusing a conflicted rebase finished
# with `git commit` while `git rebase --continue` — which reaches no hook at
# all — lands the same subject unseen. That is the conflict deciding the
# verdict again. CI still judges a reworded subject inside a pull request.
SEQUENCER_DIRECTORIES="rebase-merge rebase-apply"

sequencer_state() {
    local git_dir marker directory
    git_dir=$(git rev-parse --git-dir 2>/dev/null) || return 0
    for marker in $SEQUENCER_MARKERS; do
        if [ -f "$git_dir/$marker" ]; then
            printf '%s' "$marker"
            return 0
        fi
    done
    for directory in $SEQUENCER_DIRECTORIES; do
        if [ -d "$git_dir/$directory" ]; then
            printf '%s/' "$directory"
            return 0
        fi
    done
}

# Prefixes git composes around a subject it did not invent. Stripped to a fixed
# point, so a revert of a revert of a revert is judged by what it finally wraps
# — a regex cannot express that nesting, and depth 3 was refused before this.
# `fixup!`/`squash!`/`amend!` are likewise git's words, dissolved later by
# `rebase --autosquash`; refusing them would ban that workflow outright.
normalize_subject() {
    local subject="$1" previous=""
    while [ "$subject" != "$previous" ]; do
        previous="$subject"
        case "$subject" in
            'Revert "'*'"')  subject="${subject#Revert \"}";  subject="${subject%\"}" ;;
            'Reapply "'*'"') subject="${subject#Reapply \"}"; subject="${subject%\"}" ;;
            'fixup! '*)      subject="${subject#fixup! }" ;;
            'squash! '*)     subject="${subject#squash! }" ;;
            'amend! '*)      subject="${subject#amend! }" ;;
        esac
    done
    printf '%s' "$subject"
}

explain_format() {
    echo ""
    echo "Expected format: <type>: <description>"
    echo "or:              <type>(scope): <description>"
    echo ""
    echo "Types: $TYPES"
    echo ""
    echo "Examples:"
    echo "  feat: add cyclomatic complexity collector"
    echo "  fix(parser): handle anonymous classes correctly"
    echo "  docs: update ARCHITECTURE.md"
}

# $1 = subject, $2 = label. Returns 1 when the subject is refused.
judge_subject() {
    local subject="$1" label="$2" judged

    judged=$(normalize_subject "$subject")

    if ! printf '%s' "$judged" | grep -qE "$PATTERN"; then
        echo ""
        echo "❌ Invalid commit message format!"
        echo "$label: $subject"
        explain_format
        return 1
    fi

    if [ ${#subject} -gt $SUBJECT_LENGTH_LIMIT ]; then
        echo ""
        echo "⚠️  Warning: subject is longer than $SUBJECT_LENGTH_LIMIT characters (${#subject})"
        echo "   $label: $subject"
        echo "   Consider making it shorter for better readability in git log"
    fi

    return 0
}

# ---------------------------------------------------------------------------
# Hook mode
# ---------------------------------------------------------------------------
if [ "$MODE" = "message" ]; then
    [ -f "$ARG" ] || { echo "Commit message file not found: $ARG" >&2; exit 2; }

    STATE=$(sequencer_state)
    if [ -n "$STATE" ]; then
        echo "ℹ️  $STATE present — git is writing this subject, not you; format not judged here."
        echo "   CI judges the finished commits, so the answer cannot depend on a conflict."
        exit 0
    fi

    judge_subject "$(head -n 1 "$ARG")" "Your message" || exit 1
    exit 0
fi

# ---------------------------------------------------------------------------
# Pull-request mode
#
# The range lives here rather than in the workflow, where no control can reach
# it: a step that judged `HEAD..HEAD` would pass every pull request and every
# structural assertion about the workflow with it.
#
# On a `pull_request` event the checkout is the merge ref, whose first parent is
# the base tip it was computed against and whose second is the pull request's
# head. That pair is exactly the commits this pull request adds. The event
# payload's `base.sha` is NOT: it is the base tip as of the payload, while the
# merge ref is recomputed against the current one, so once the base branch moves
# the payload range also spans its newer commits — whose subjects the merge and
# squash buttons wrote, and which this rule deliberately does not judge.
# ---------------------------------------------------------------------------
if [ "$MODE" = "pull-request" ]; then
    if ! git rev-parse -q --verify HEAD^2 >/dev/null 2>&1; then
        # Refuse rather than fall back to the payload range. A checkout pinned
        # to the head commit instead of the merge ref leaves no way to name the
        # base, and guessing one is how the wrong commits get judged.
        echo "✖ HEAD is not a merge ref, so this pull request's own commits cannot be named."
        echo "  actions/checkout must leave the default pull_request merge ref in place."
        exit 1
    fi
    ARG="HEAD^1..HEAD^2"
    echo "Judging the commit subjects this pull request adds ($ARG)"
fi

# ---------------------------------------------------------------------------
# Range mode
# ---------------------------------------------------------------------------
# A range this script cannot resolve is a broken invocation, not an empty
# question. Passing on it would be the silent skip: green because nothing was
# looked at. The caller fetches full history, so no benign case reaches here.
case "$ARG" in
    *..*) ;;
    *)
        echo "✖ '$ARG' is not a commit range. A bare ref would judge the whole"
        echo "  history reachable from it, including subjects written server-side."
        exit 2
        ;;
esac

# Each endpoint has to be an object this clone actually holds. `git rev-parse`
# is not that check: handed a well-formed 40-hex SHA it does not have, it
# succeeds and echoes it back, and `git rev-list` then walks nothing. The range
# would report "nothing to judge" and exit 0 — the silent skip wearing the
# empty-set answer instead of the unresolvable one. Measured, not assumed.
# Quoted: an unquoted expansion would glob and word-split a range that
# happened to contain a metacharacter.
for endpoint in "${ARG%%..*}" "${ARG##*..}"; do
    if [ -z "$endpoint" ]; then
        # git would default the empty side to HEAD. No caller here means to do
        # that, so it is a malformed range rather than a benign shorthand.
        echo "✖ Range '$ARG' has an empty endpoint."
        exit 2
    fi
    if ! git rev-parse -q --verify "$endpoint^{commit}" >/dev/null 2>&1; then
        echo "✖ '$endpoint' in range '$ARG' is not a commit this checkout holds."
        echo "  actions/checkout needs fetch-depth: 0 for the range to exist."
        exit 1
    fi
done

status=0
judged=0

# --no-merges is git's structural definition of a merge (more than one parent),
# not a guess from the subject line. It puts merge commits out of scope at both
# layers: the hook exempts them on MERGE_HEAD and CI skips them here. A merge
# subject typed by hand through `git merge -m` is therefore judged by nobody —
# accepted deliberately, because the merges reaching this history are written
# by GitHub's merge button.
# The walk's own exit code, which a `$(...)` inside the heredoc would discard:
# `git rev-list` failing would then read as "the range holds no commits".
revisions=$(git rev-list --no-merges "$ARG") || {
    echo "✖ Could not walk '$ARG'."
    exit 1
}

while IFS= read -r sha; do
    [ -z "$sha" ] && continue
    judged=$((judged + 1))
    judge_subject "$(git log -1 --format='%s' "$sha")" "Commit $sha" || status=1
done <<EOF
$revisions
EOF

if [ "$judged" -eq 0 ]; then
    # Legitimate — a pull request can add merge commits only — but it is not
    # the same statement as "everything conformed" and must not read like it.
    echo "ℹ️  No non-merge commits in '$ARG'; nothing was judged."
    exit 0
fi

if [ $status -eq 0 ]; then
    echo "✅ $judged commit subject(s) conform"
fi

exit $status
