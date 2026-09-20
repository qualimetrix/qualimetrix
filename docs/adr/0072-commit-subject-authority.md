# 0072. The Commit-Subject Rule Names the Commits It Can Judge

**Date:** 2026-09-20
**Status:** Accepted

## Context

`.githooks/commit-msg` enforced Conventional Commits on every subject it was
handed, with no statement of which subjects those were. Measured on git 2.55,
with a refusing hook installed via `core.hooksPath` in five isolated
repositories:

| Channel                                   | Hook consulted?       | Landed on main                  |
| ----------------------------------------- | --------------------- | ------------------------------- |
| `git commit`, hand-authored               | yes                   | 1196 conforming                 |
| `git merge`, clean, git writes the commit | yes                   | —                               |
| `git merge`, conflicted, `git commit`     | yes                   | —                               |
| `git revert`, clean                       | **no**                | 0                               |
| `git revert`, conflicted, `--continue`    | yes                   | 0                               |
| `git cherry-pick`, clean                  | **no**                | 0                               |
| `git rebase`, replay or `--continue`      | **no**                | —                               |
| `git rebase`, conflict then `git commit`  | yes                   | —                               |
| `git rebase -i`, `reword`                 | yes                   | —                               |
| `git am`                                  | no (`applypatch-msg`) | 0                               |
| `--no-verify`, or hooks never installed   | no                    | not knowable per commit         |
| GitHub's merge button                     | no                    | 68 of 90 merges                 |
| GitHub's squash button                    | no                    | 41 commits, +1 rebase-and-merge |

Two findings follow, and they point in opposite directions.

**The hook answered differently for identical content.** `githooks(5)` states
that commit-msg is invoked by `git-commit` and `git-merge`, and names no other
command. The sequencer's clean path therefore writes its commit without ever
consulting a hook, while the same operation, having hit a conflict, finishes
through `git commit` and does consult one. A `Revert "..."` subject was
accepted when the revert applied cleanly and refused when it conflicted — a
verdict decided by a condition the rule did not name, which is the class this
repository's governance exists to refuse.

A note on the report that opened this work: it placed the asymmetry at `merge`,
on the grounds that plain `Merge ...` subjects sit in history. Both merge paths
were measured to run the hook and be refused, so those subjects arrived by some
other channel — `--no-verify`, hooks not installed, or the merge button — and
which one is not recoverable from a commit object. The asymmetry is real; it is
at `revert` and `cherry-pick`.

**The hook was never the authority it behaved as.** All 42 non-merge subjects on
main that the pattern refuses were written by GitHub's squash button, which
appends ` (#N)` to a PR title; not one was authored locally. Together with the
82 merge-button commits, 124 subjects entered main through a channel no local
hook can reach. The hook was not failing at what it could see — it was claiming
what it could not.

## Decision

The rule states its own scope, and a second layer covers what a hook cannot.

1. **One owner.** `scripts/check-commit-subject.sh` holds the pattern and the
   length rule, with `--message FILE` for the hook and `--commits RANGE` for CI.
   This mirrors `check-private-leaks.sh`, which the same two layers already
   share.

2. **The hook judges what a person typed and declines what git composed.**
   When `MERGE_HEAD`, `REVERT_HEAD` or `CHERRY_PICK_HEAD` is present under
   `git rev-parse --git-dir`, the subject is git's, and the hook says so and
   exits 0. Detection is by git's own marker files rather than by matching the
   word "Merge", which an ordinary hand-written subject is free to contain —
   and which is refused, as before. `--git-dir` rather than `.git` because this
   repository is developed in linked worktrees, where the markers live in
   `.git/worktrees/<name>/`.

   The markers **approximate** that rule, and where they miss is recorded
   rather than left to be rediscovered. `git merge -m` is a person writing a
   subject that `MERGE_HEAD` exempts. `git rebase` replay and `--continue` reach
   no hook at all, while a conflicted rebase finished with `git commit`, and
   `rebase -i` with `reword`, do reach it. Rebase is therefore exempted on its
   `rebase-merge`/`rebase-apply` directory: that costs judging a reword and buys
   the case that matters — without it, the conflict decides the verdict again.
   `REBASE_HEAD` is deliberately not the key; measured, it outlives the rebase
   that wrote it, so keying on it would silence the hook for every commit
   afterwards. `git am` reaches `applypatch-msg` instead, so a mailed patch is
   unseen locally. CI judges all of these inside a pull request.

   `fixup!`, `squash!` and `amend!` prefixes, and nested `Revert "` / `Reapply "`
   wrappers, are stripped to a fixed point before judging, so what git composed
   around a subject does not change the verdict on the subject itself. A regex
   cannot express that nesting: a revert of a reapply was refused before it.

3. **CI judges the commits a pull request adds**, excluding merges by parent
   count. This covers `--no-verify`, a contributor who never installed the hook,
   and both sequencer paths at once: the hook declines on a clean revert and on
   a conflicted one alike, and CI returns the same answer for both. The conflict
   no longer changes the verdict.

   A revert conforms when what it wraps conforms. `git revert` composes
   `Revert "<subject>"`, and `Reapply "<subject>"` when it reverts a revert
   (git 2.36+, measured on 2.55). Neither opens an editor on the clean path, so
   refusing them would ban a first-class git operation after the commit exists,
   with no point at which a person could have reworded it.

4. **Only on `pull_request` events, over the merge ref's own parents.** A push
   range on main opens with the merge or squash commit GitHub has just written,
   and judging it would redden main after every merge. The range is
   `HEAD^1..HEAD^2` rather than `base.sha..HEAD`: `base.sha` is the base tip as
   of the event payload, while the merge ref is recomputed against the current
   base tip, so once main moves the payload range also contains main's newer
   commits — whose subjects the merge button wrote.

   A range whose endpoints are not commits this clone holds is refused, not
   skipped. `git rev-parse` is not that check: handed a well-formed 40-hex SHA
   it does not have, it succeeds and echoes it back, and the walk then finds
   nothing. Measured; the first implementation here had exactly that hole, with
   the silent skip wearing the empty-set answer. An empty range is reported as
   having judged nothing rather than as having passed.

5. **The 72-character rule stays advisory** at both layers. 300 of main's non-merge subjects
   exceed it; enforcing it is a separate change with its own blast radius.

Option (b) — requiring every commit to conform — was rejected as
unimplementable rather than undesirable. It needs the clean sequencer path,
which no hook can reach, and the GitHub merge and squash buttons, which write
server-side. Reaching them would mean retiring the prose PR title that 42
commits use deliberately.

## Consequences

- A revert of a non-conforming commit, and a cherry-picked non-conforming
  subject, are refused by CI inside a pull request in both the clean and the
  conflicted case. Today that population is empty.
- Merge commits are judged by nobody: the hook exempts them on `MERGE_HEAD` and
  CI excludes them by parent count. A subject typed through `git merge -m` is
  therefore unjudged, which is accepted because the merges that reach this
  history are written by GitHub's merge button.
- A direct push to main is not covered — the subject check runs on
  `pull_request` events only — so the layer that covers the sequencer's clean
  path depends on main staying protected. That setting does not live in this
  repository and no control here can see it.
- The merge and squash buttons keep writing subjects the pattern would refuse.
  That is the repository's second, deliberate convention, and it is now stated
  rather than merely tolerated.
- `governance/CommitSubjectPolicy/` holds the two controls. One is structural —
  the hook delegates, CI invokes the same script in range mode, all three
  markers stay named. The other runs the real hook in a throwaway repository and
  reads its exit code, because a structural control cannot see a hook that calls
  the script and discards its answer; that mutation survived nine other planted
  breakages before the behavioural control was added.
- Neither control walks history. The PHP matrix checks out at depth 1, where a
  history walk judges one commit and an assertion over an empty set passes.
- The CI job's display name is pinned: branch protection requires the context
  `Commit messages (private terms)`, so renaming the job retires the context and
  leaves open pull requests waiting for a check that never reports. The name now
  under-describes the job; renaming it and the protection setting together is
  left as a separate, coordinated step.
