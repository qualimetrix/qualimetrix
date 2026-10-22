# Bug-fix wave: overview and common rules

This wave closes the defect issues that need no product decision from the
owner. Every decision the work needs is already made in the session file;
the session executes it. Read this file first, then your session file.

Base: `origin/main` at the time you start (it was `b2ebb2b61` when the plan
was written). Each session is one branch and one pull request.

## Scope

| Session file                      | Issues and items                                                            |
| --------------------------------- | --------------------------------------------------------------------------- |
| `01-cli-refusals.md`              | #160 items 1 (`--fail-on`), 2 (`--class`), 3 (`--clear-cache`); #167        |
| `02-report-text.md`               | #160 items 4 (zero own classes), 5 (duplicate rows), 6 (project scope line) |
| `03-metrics-and-rules.md`         | #161 items 2 (enum RFC), 3 (NPath `switch`); #165                           |
| `04-cache-and-file-namespaces.md` | #162; #163                                                                  |
| `05-repository-checks.md`         | #178 items 1–4; #179 item 9 (composer script graph test)                    |
| `06-developer-tooling.md`         | #179 items 1–8                                                              |

Out of scope, not to be touched by any session: #159, #164, #166, #168,
#169, #170, #171–#177, and #161 item 1 (health coupling coverage). #161
item 1 reverses a decision that `CoverageReader::measured()` documents on
purpose ("That is the case worth publishing loudest") and that
`HealthScoreCoverageTest::itPublishesAMeasuredZeroWhenTheAggregateNeverReachedTheProject`
pins; it waits for the owner.

Issue texts hold the reproductions. Read your issues with
`gh issue view <n> --repo qualimetrix/qualimetrix` before you start.

## The six sessions are independent

No session needs another session's result. They can run at the same time and
merge in any order. Each session owns a disjoint set of files (listed in its
file). The only files several sessions touch are the shared files below; each
session edits a different part of them.

### Shared files and how to resolve their conflicts

When your branch conflicts with `main` while rebasing, resolve these files by
rule, never by guessing:

| File                                                      | Sessions   | Rule                                                                                                                                               |
| --------------------------------------------------------- | ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CHANGELOG.md`                                            | 01–04      | Keep every bullet from both sides under one `### Fixed` heading inside `## [Unreleased]`. Never two `### Fixed` headings.                          |
| `docs/internal/generated/**`, `qmx.yaml`                  | all        | Do not merge by hand. Take `main`'s version, run `composer architecture:generate`, commit the result.                                              |
| `docs/internal/modular-architecture-manifest.json`        | 01, 03     | Hand-authored JSON: keep both sides' entries, keep it valid, then regenerate as above.                                                             |
| `docs/adr/0102-detector-verdicts-and-finding-identity.md` | 03, 04     | Each session appends its own `## Amendment, <date>: ...` section at the end; keep both.                                                            |
| `docs/adr/README.md`                                      | 03, 04, 06 | Each session adds its own `amended <date>: ...` clause to an index line; keep every clause.                                                        |
| `src/Reporting/README.md`                                 | 01, 02, 04 | Different paragraphs (01: class drill-down binding, ~645–649; 02: project scope line; 04: file namespaces, ~187–199 and ~637–640); keep all edits. |
| `src/Reporting/DrillDown/DrillDownBinding.php`            | 01, 04     | 01 changes `classDeclarationSelection()`; 04 changes only the `NAMED_LEVELS` docblock sentence about files without declarations. Keep both.        |
| `website/docs/usage/output-formats.md` and `.ru.md`       | 02, 04     | Different sections (02: project scope; 04: namespace selection, ~line 127); keep both edits.                                                       |

Rebase onto `origin/main` once, right before you open the pull request, and
again only if GitHub reports a conflict.

## Architecture rules the executor must not break

These are the rules a fix most easily breaks without noticing. They come from
`AGENTS.md`; read its "Project Structure" and "Critical Rules" sections once.

1. **Put a change where its subject lives.** Each session file names the
   owning class for every change. Do not move logic into a neighbouring
   class, a new `Util`/`Helper` bucket, or `Core/`. If the named owner
   looks wrong, stop and report (see "When to stop").
2. **The manifest is the import and ownership authority.** A new production
   class needs a hand-written entry in
   `docs/internal/modular-architecture-manifest.json` (keyed by FQCN; copy the
   shape of a neighbouring entry of the same owner, visibility `internal`,
   consumers `[]` unless another owner imports it). A new import of a class
   that belongs to another owner needs that class's `consumers` list to name
   the importing class. Then run `composer architecture:generate`; check with
   `composer architecture:check`. The plan avoids new classes; adding one the
   session file does not allow is a stop condition.
3. **No new public contract across owners** unless the session file names it.
4. **Rules stay stateless; collectors do the AST work** (`AGENTS.md`
   Critical Rule 2). Never traverse an AST from a rule or a formatter.
5. **No compatibility shims, aliases or deprecation layers** (Backward
   Compatibility Policy in `AGENTS.md`).
6. **Self-analysis findings are fixed by refactoring.** If `composer
   selfcheck` reports a new finding on code you touched, refactor that code.
   Never add `@qmx-ignore`, never raise an `@qmx-threshold`, never add a
   `suppress_*` entry, never edit `qmx-baseline.json`, never run
   `baseline:generate`. If `selfcheck` turns red only because your session
   changed a metric's definition and the value moved on code you did not
   touch, stop and report.
7. **Accepted ADRs are not rewritten.** Change a decision by appending a
   section `## Amendment, YYYY-MM-DD: <what changed>` to the ADR (see the
   amendments at the end of `docs/adr/0017-baseline-ceiling.md`) and adding
   `amended YYYY-MM-DD: <one clause>` to that ADR's line in
   `docs/adr/README.md`. Use the date of your commit.
8. **Comments carry only what the code cannot say**: the reason for a
   non-obvious choice. No comments that narrate the code, and no references
   to this plan or to issue numbers in code comments.
9. **Test naming:** `#[Test] public function itXxx(): void`. Tests go into
   the existing test file named by the session file. Scratch paths in tests
   use `bin2hex(random_bytes(...))`, never `uniqid()` or the clock.
10. **English only** in code, comments, docs, commit messages and CLI output.
    No absolute home-directory paths in tracked files (`scripts/check-private-leaks.sh` refuses them).

## Setup of a fresh checkout

A new worktree has no dependencies. Before the first test run, once:

```bash
composer install --no-interaction
npm ci --prefix html-report
```

`composer docs:check` and the planning-record governance test need the
documentation Python environment. Either create it in your checkout
(`python3 -m venv website/.venv && website/.venv/bin/pip install -r website/requirements.txt`)
or point `QMX_MKDOCS` at an existing `mkdocs` executable (the session prompt
names one). Do not install anything globally.

## Cleanup

Disk space is limited. Before you finish the session (and after a failed
attempt you abandon), delete what you created outside the tracked tree:

- every scratch directory you made with `mktemp -d` or under the system temp
  directory (scratch projects, cache directories passed with `--cache-dir`,
  probe copies);
- the logs and reports under `/tmp/qmx-*` once their results are in your
  report and pull request;
- build output you produced that is not tracked (for example a scratch
  `vite build --outDir`, `.playwright-cli` if any tool created it).

If the session prompt had you create your own worktree, remove it as the very
last step, after the branch is pushed, the pull request is open and the report
is written: `git worktree remove <path>` from the main checkout (it also removes
that worktree's `vendor/` and `node_modules/`). If `git worktree remove`
refuses because of uncommitted changes, stop and report instead of forcing it.

Otherwise never delete tracked files, `vendor/`, `node_modules/` or a worktree
you did not create, and never run a recursive delete on a path you did not
create in this session. List what you removed in the report.

## Environment facts

- On the owner's machine PHP loads **Xdebug in develop mode with
  `xdebug.max_nesting_level=512`**, and composer scripts run with it. Any PHP
  recursion deeper than about 512 frames throws there and not in CI (CI runs
  without Xdebug). So: production code that walks deep trees must not depend on
  PHP call depth, and a test must pass both with and without Xdebug. Run
  manual experiments with very deep source files as
  `XDEBUG_MODE=off bin/qmx ...`.
- `bin/qmx` refuses paths outside the project root. Run scratch projects as
  `bin/qmx check . --working-dir=<scratch dir> ...`.
- `sys_get_temp_dir()` on macOS is under `/var/folders/...`, not `/tmp`.

## Test discipline (read this twice)

Running the whole suite to check a two-line change wastes ten minutes per run.
The ladder below is the complete list of what to run. Run nothing else.

While working on an item:

1. Run only the test files the session file names for that item:
   `vendor/bin/phpunit --no-coverage <file>`. Add `--filter <method>` while
   iterating on one test.
2. When a focused test fails, fix and rerun **that test only**. Do not rerun
   tests that already passed and whose subject you did not change since.
3. Style and static analysis on touched PHP files only:
   `vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php <files>` and
   `vendor/bin/phpstan analyse --no-progress --memory-limit=1G <files>`.

After all items of the session are done, once, in this order (stop at the first
failure, fix, and resume from that step):

4. If you added, renamed or removed a test method, a test file or a
   production class, or changed the manifest: `composer architecture:generate`,
   then `composer architecture:check`.
5. If you changed `src/`: `composer selfcheck` (about a minute).
6. The steps your session file lists under "Session gate".
7. The full check, **exactly once**, with its exit code captured correctly
   (a pipe into `tee` would report `tee`'s status):

   ```bash
   composer check > /tmp/qmx-check-<session>.log 2>&1; echo "exit=$?"
   ```

   It takes several minutes. Judge it by the printed exit code, then read the
   end of the log for failures. If it fails, fix the cause, rerun the failing
   test directly to confirm, and run the full check once more at the end. Never
   more than three full checks in a session.

Never run `composer test`, `composer test:aggregate`, `vendor/bin/phpunit`
without a path, or a whole PHPUnit suite. `composer check` already runs them.
CI runs `composer check` again on the pull request; that is enough.

A PHPStan run that reports errors in files you never touched: delete
`.phpstan.cache` and rerun once before acting on them.

### The finding gate (sessions 01–04)

`composer gate` compares the findings and outputs of your branch with its base
on an external corpus. It takes about **11 minutes**. Run it exactly once, after
step 5 and before step 7, never at the same time as `composer check`:

```bash
composer gate -- --reference=$(git merge-base HEAD origin/main) --report=/tmp/qmx-gate-<session>.json > /tmp/qmx-gate-<session>.log 2>&1; echo "exit=$?"
```

Exit 0 (GREEN) or 2 (PARTIAL) means the corpus did not change. Exit 1 (RED)
lists the differing cases and records; compare them with the "expected gate
differences" in your session file. **Do not author declarations**
(`finding-gate/declared-*.tsv`): a declaration belongs to one reference
comparison and turns stale once the change is merged, which would break the
other sessions' comparisons. Instead, put the gate verdict and the full list
of differences in the pull request body, each marked as expected or not. A
difference your session file does not list as expected is a defect to fix (or,
if you are sure it is intended, a stop-and-report).

## Git and pull request

- `git fetch origin && git switch -c <branch from your session file> origin/main`.
- One Conventional Commit per item or coherent change, scoped by domain:
  `fix(cli): ...`, `fix(reporting): ...`, `fix(coupling): ...`,
  `fix(complexity): ...`, `fix(code-smell): ...`, `fix(cache): ...`,
  `test(governance): ...`, `chore(tooling): ...`. Commit messages in English.
  Hooks run php-cs-fixer, PHPStan and the leak scan on commit; fix what they
  report instead of bypassing them.
- Push the branch and open a pull request against `main`. The body lists the
  issue items it fixes as `Refs #<n> (item x)` — do **not** write `Closes`,
  because several issues are split across sessions; the owner closes them.
  Include the gate verdict and differences (sessions 01–04).
- Do not merge the pull request.

## CHANGELOG

User-visible fixes get one line each under `## [Unreleased]` → `### Fixed`
(create the heading if it is missing), written from the user's point of view.
Repository-internal changes (sessions 05 and 06) get no entry.

## When to stop and report instead of finishing

Decide implementation details yourself and name each decision in the report.
Stop and report, without finishing the item, only when:

- the change needs a file outside your session's file list and outside the
  shared files above (exception: a test that pins a text or value your item
  intentionally changes, found by the grep your session file gives — update it
  and list it in the report);
- you would need a new production class, a new cross-owner contract, or a
  changed signature the session file does not name;
- a fact the plan states turns out false when you check it (a method that
  does not exist, a test that pins something else, a count that differs);
- `composer selfcheck` reports a finding you cannot remove by refactoring
  your own change, or one rule 6 above sends to the owner;
- the gate shows a difference your session file does not expect and you
  believe it is intended;
- the Definition of Done cannot be met as written.

## Report

End the session with a short report:

1. Branch name and pull request URL.
2. Per item: done / not done, and the commit.
3. Decisions you made, each with the rejected alternative and the reason.
4. Assumptions you made without checking them.
5. Deviations from this plan.
6. For every claim in 3–5, the exact command that proves it (a test name, a
   `grep`, a CLI run), so a reviewer can rerun it verbatim.
7. The printed exit code of the final `composer check`, and the gate verdict
   with its differences (sessions 01–04).
8. What you deleted in "Cleanup".

Do not paste diffs or retell the code.
