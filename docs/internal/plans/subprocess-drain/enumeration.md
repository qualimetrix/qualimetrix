# Every `proc_open` call site in tracked PHP

Taken on base `0465fdd8`.

## How obtained

`git ls-files '*.php' | xargs grep -n 'proc_open'` — 41 matches — then each match read with
its surrounding function, and every `$command`/`$argv`/`$descriptors` variable traced back
to its construction site. Matches that are comments or string literals are listed below the
table rather than dropped.

Taken **twice, independently**: once by the orchestrator and once by an agent that could not
see the orchestrator's table and was told to classify from code alone. A single party that
fills both a table and the oracle over it errs consistently and passes its own guard.

**Result of the reconciliation:** the two takings agree on the row set (the same 30 call
sites and the same 11 non-call-site matches) and on 29 of 30 verdicts — but that agreement
was reached under a **definition of `S` that was itself wrong** ("at most one pipe"). Row 29
is the one it mis-verdicted, and both witnesses mis-verdicted it identically, which is a
failure of the shared definition rather than a confirmation. Its current `D` has **one**
witness: the rewrite that changed the definition. Only rows 29 and 30 use a non-pipe
descriptor, and row 30 drains both, so the definition change reaches no other row — but that
containment is also single-witness. They disagreed on
nothing except their own summary arithmetic — both hand-written totals were wrong, in
different ways. The counts below are therefore *derived from the rows*, never typed: a
hardcoded count asserted over a table is one of the two spellings a path-and-name sweep is
blind to.

## What this method cannot see

- **Other subprocess channels.** The line between them is the *direction* of the stream the
  caller is left holding, not how many there are — an earlier version of this bullet said
  "each exposes at most one stream, so none can carry this defect", and that reasoning was
  wrong for `popen()`.
  - `exec()`, `shell_exec()`, `system()`, `passthru()` and backticks hand the caller no live
    stream at all: PHP reads the child's output to EOF itself or passes it straight through
    to this process's stdout. Nothing is left unserviced, so the shape cannot occur. Swept
    separately; out of scope by construction.
  - `popen()` **does** carry it. It hands over exactly one stream, and its mode decides the
    direction. Opened for reading, leaving it unread is survivable — closing an unread read
    pipe gives the child `EPIPE`. Opened for **writing** it is the same defect as the stdin
    row in `measurements.md`: measured on this platform, a parent that wrote 1 MB to a child
    that never reads stdin sat blocked inside `fwrite()` for the child's entire 30 s life and
    was released only by the child's exit turning the write into `EPIPE`. A child that never
    exits blocks it forever.
    Swept by substring over tracked PHP: seven occurrences outside the control itself, two in
    doc comments and five string literals — the command-injection rule's data and its
    fixtures — and **no live call**. Nothing to migrate, so no row here; but the class is
    real, so the stage 03 control refuses that name too, and those five literals take entries
    there.
- **`Symfony\Component\Process`** — swept separately by import. It drains concurrently, so
  those sites are correct. (It is also unavailable to the vendor-less callers and is a dev-only
  dependency — see `00-overview.md`.)
- **A dynamically named call** (`$fn = 'proc_open'; $fn(...)`). Swept: none present.
- **Non-PHP callers** — python under `scripts/`, shell under `.githooks/`. Outside this
  defect class as stated.

## Verdicts

- **C** — already correct: every blocking stream drained concurrently.
- **S** — safe by construction: **at most one blocking stream**, so the parent can never be
  blocked reading stream A while the child blocks writing stream B.
- **D** — deadlock-capable: two or more blocking streams with a sequential read, or a
  blocking stream never read.

**"Blocking stream" means `pipe`, `pty` or `socket` — not just `pipe`.** An earlier version
of this table said "at most one pipe" and mis-verdicted row 29 as a result. A `['pty']`
descriptor gives the parent a kernel-buffered stream that blocks the child exactly as a pipe
does; measured, not assumed — see `measurements.md`
beside this file: a child flooding an unread pty while the parent reads a stdout pipe left
the parent blocked at 5 s (SIGKILL, exit 137).
Safe descriptor kinds are `file`, a passthrough constant (`STDIN`/`STDOUT`/`STDERR`), or no
descriptor at all.

`D` is a statement about the **shape**, never about the current child's output volume. The
"worst case" column is recorded because it explains why this went unnoticed — it does **not**
grade the row, and no disposition is chosen from it.

## Dispositions

1. `ChildProcess::run()` — the default: start, capture, wait.
2. *(withdrawn)* — an interleaving public `drain()` had no executable caller; see
   `01-runner.md`.
3. **Remove the extra blocking stream** — redirect it to a file, or do not open the
   descriptor. Makes the deadlock impossible by construction rather than by discipline.
4. **Keep its own `proc_open` under a declared allowlist entry** — the module itself and the
   named specializations, each with a reason.
5. **Not a call in this file** — embedded PHP source or rule data; still needs an entry,
   because a nowdoc can be, and here is, executed by a spawned child.
6. **Moves with its test to `scripts/subprocess/tests/`** — and takes an entry there. The
   module is the single file `ChildProcess.php`, so its test directory is *not* "inside the
   module"; a `proc_open` that lands there is refused like any other unless declared.

| #   | site                                                                                     | descriptors                                | read shape                                                                           | child                                                                                                               | worst case on the at-risk stream                                                                                      | verdict        | disposition |
| --- | ---------------------------------------------------------------------------------------- | ------------------------------------------ | ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- | -------------- | ----------- |
| 1   | governance/DistributedPackage/HtmlReportShipsOnlyWhatItReadsTest.php:151                 | 0r,1w,2w                                   | stdout to EOF; **stderr closed unread**                                              | `git archive --worktree-attributes --format=tar HEAD html-report`                                                   | stderr bounded by git absent errors                                                                                   | D              | 1           |
| 2   | governance/FormatOptionKeys/OutputFormatObservation.php:187                              | 1w,2w                                      | sequential                                                                           | `bin/qmx check src` per format                                                                                      | stderr second; real analysis run                                                                                      | D              | 1           |
| 3   | governance/GeneratedArtifactFreshness/SuppressionSnapshotFreshnessTest.php:43            | 1w,2w                                      | sequential, concatenated                                                             | `generate-suppression-snapshot.php --check`, which wraps row 17                                                     | stderr second                                                                                                         | D              | 1           |
| 4   | governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php:181         | 1w,2w                                      | sequential, concatenated                                                             | `generate-modular-architecture.php --check` — the exact child that wrote 2.6 MB to stderr in the confirmed instance | stderr second; **measured at 2.6 MB**                                                                                 | D              | 1           |
| 5   | governance/PlanningRecords/PlanningRecordIsolationTest.php:395                           | 1w,2w                                      | sequential                                                                           | `git ls-files -z`                                                                                                   | stderr second; git advice only                                                                                        | D              | 1           |
| 6   | governance/TestSuiteHygiene/RegisteredDirectoriesReachTrackedFilesTest.php:273           | 1w,2w                                      | sequential                                                                           | `git ls-files -z`                                                                                                   | stderr second; git advice only                                                                                        | D              | 1           |
| 7   | governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:810                             | 1w,2w                                      | sequential                                                                           | PHPUnit `--list-tests` per aggregated suite command                                                                 | stderr second; bootstrap warnings unbounded                                                                           | D              | 1           |
| 8   | scripts/benchmark-regression.php:244                                                     | 1w only, `2>/dev/null` in the shell string | single read                                                                          | `bin/qmx check --format=metrics`                                                                                    | no second pipe exists                                                                                                 | S              | 1           |
| 9   | scripts/collect-benchmark-data.php:161                                                   | 1w, 2→file `/dev/null`                     | single read                                                                          | `bin/qmx check --format=metrics`                                                                                    | no second pipe exists                                                                                                 | S              | 1           |
| 10  | scripts/finding-gate-controls/Shell.php:89                                               | 1w,2w                                      | global `stream_select` across every live child                                       | supervised `git`, `bin/qmx`, workers                                                                                | —                                                                                                                     | C              | 4           |
| 11  | scripts/finding-gate/ProcessHandle.php:61                                                | 1w,2w                                      | non-blocking `fread` under a bounded scheduler                                       | gate case workers                                                                                                   | —                                                                                                                     | C              | 4           |
| 12  | scripts/finding-gate/ProcessHandle.php:202                                               | STDIN/STDOUT/STDERR constants              | passthrough                                                                          | the process-group launcher                                                                                          | no pipes at all                                                                                                       | S              | 5           |
| 13  | scripts/finding-gate/SelfTest.php:2894                                                   | 1w,2w                                      | non-blocking `fgets` on stdout with a 30 s deadline; **stderr closed unread**        | the self-test's own announcer script — three short stdout lines, one short stderr line on error                     | stderr never read; every wait is deadline-bounded with a `SIGKILL` backstop, so this stalls rather than hangs forever | D              | 3 + entry   |
| 14  | scripts/generate-modular-architecture-production-inventory.php:2339                      | 0r,1w,2w                                   | stdin closed, then sequential                                                        | `git ls-files` over a markdown-only pathspec                                                                        | stderr second; small filtered listing                                                                                 | D              | 1           |
| 15  | scripts/generate-modular-architecture-test-inventory.php:595                             | 0r,1w,2w                                   | `ProcessOutput::drain`                                                               | git/qmx                                                                                                             | —                                                                                                                     | C              | 1           |
| 16  | scripts/generate-modular-architecture.php:137                                            | 0r,1w,2w                                   | `ProcessOutput::drain`                                                               | git/qmx                                                                                                             | —                                                                                                                     | C              | 1           |
| 17  | scripts/generate-suppression-snapshot.php:210                                            | 1w,2w                                      | sequential                                                                           | `bin/qmx check --format=suppressed` — a full self-analysis, ~12.5 MB of JSON on stdout                              | stderr second, across a long full-tree run                                                                            | D              | 1           |
| 18  | scripts/input-doors/Runner.php:307                                                       | 1w,2w                                      | sequential                                                                           | `bin/qmx` per input door, plus `git init/add/commit` fixture setup                                                  | stderr second; fixture-scale                                                                                          | D              | 1           |
| 19  | scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php:601       | 1w,2w                                      | `ProcessOutput::drain` — #102's truncation test                                      | `php -r` flooding stderr with 512 KB                                                                                | —                                                                                                                     | C              | 6           |
| 20  | scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php:685       | 1→file, 2→file                             | nothing read; `proc_get_status` polling                                              | the generator under test                                                                                            | no pipes at all                                                                                                       | S              | 6           |
| 21  | scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php:912       | 1w,2w                                      | `ProcessOutput::drain`                                                               | generator refusal scenarios                                                                                         | —                                                                                                                     | C              | 1           |
| 22  | scripts/promise-effect-corpus.php:224                                                    | 1w,2w                                      | sequential                                                                           | `bin/qmx check` over real `src/Core` under ci/legacy/strict presets                                                 | stderr second; real production-tree analysis                                                                          | D              | 1           |
| 23  | scripts/promise-effect/ProcessProbe.php:177                                              | 1w,2w                                      | sequential                                                                           | `bin/qmx check src --format=json`, optionally `--log-level=debug`                                                   | stderr second; full-tree run with debug logging available                                                             | D              | 1           |
| 24  | src/Infrastructure/Git/GitRepositoryLocator.php:56                                       | 0r,1w,2w                                   | stdin closed, stdout read, **stderr closed unread**                                  | `git rev-parse --git-dir`                                                                                           | stderr never read; git's own message is short and fixed                                                               | D (production) | 3           |
| 25  | tests/Analysis/Evidence/Duplication/Functional/DuplicationMemoryLimitProcessTest.php:176 | 1w,2w                                      | sequential                                                                           | `bin/qmx` under a 128 MB cap over a fixture of ~6,400 near-duplicate functions in 82 files                          | stderr second; the fixture exists to exhaust memory, and a PHP fatal prints there                                     | D              | 1           |
| 26  | tests/Analysis/Finding/Support/CorpusCaseRun.php:156                                     | 1w,2w                                      | sequential                                                                           | `bin/qmx check` per corpus case, twelve formats                                                                     | stderr second; fixture-scale                                                                                          | D              | 1           |
| 27  | tests/Analysis/Policy/Baseline/Integration/BaselineChannelRenamerTest.php:630            | 1w,2w                                      | interleaved with a lock the parent holds, then stdout read; **stderr closed unread** | the test's own `carry.php`, which contains no `fwrite(STDERR, …)` on any path                                       | stderr never read; child writes zero bytes there                                                                      | D              | 3 + entry   |
| 28  | tests/Infrastructure/Console/Functional/ApplicationRefusalTest.php:273                   | 0r,1w,2w                                   | stdin written and closed **before any read**, then sequential                        | `bin/qmx` refusal paths and two throwing harness scripts                                                            | stderr second; stdin is `''` at every call site, so the mirrored hazard is inert today                                | D              | 1           |
| 29  | tests/Infrastructure/Console/Support/PseudoTerminalRun.php:29                            | 0pty,1w,2pty                               | all three closed unread                                                              | `php -r 'exit(0);'`                                                                                                 | three blocking streams, none read; safe **only** because this child writes nothing — the forbidden basis              | D              | 3 + entry   |
| 30  | tests/Infrastructure/Console/Support/PseudoTerminalRun.php:68                            | 0pty,1w,2pty                               | `stream_select` over both                                                            | real `bin/qmx`                                                                                                      | —                                                                                                                     | C              | 4           |

## Counts, and the command that derives them

Do not trust the numbers in this section without re-running the commands beside them; both
hand-written totals in the original takings were wrong, which is the whole reason this
section names its derivation. The commands are anchored on **column position**, not on end
of line: an earlier version tallied verdicts with a `$`-anchored `grep`, and adding the
disposition column silently reduced it to matching nothing — a check that could no longer
fail, in the very section written against that hazard.

```bash
E=docs/internal/plans/subprocess-drain/enumeration.md
grep -cE '^\| [0-9]+ \|' $E                                            # rows
awk -F'|' '/^\| [0-9]+ \|/ {gsub(/^ +| +$/,"",$(NF-2)); print $(NF-2)}' $E | sort | uniq -c   # verdicts
awk -F'|' '/^\| [0-9]+ \|/ {gsub(/^ +| +$/,"",$(NF-1)); print $(NF-1)}' $E | sort | uniq -c   # dispositions
awk -F'|' '/^\| [0-9]+ \|/ && $(NF-1) ~ /^ *$/' $E | wc -l             # empty dispositions: must be 0
awk -F'|' '/^\| [0-9]+ \|/ {gsub(/^ +| +$/,"",$(NF-1)); if ($(NF-1) !~ /^[1-6]( \+ entry)?$/) print}' $E | wc -l
                                                                        # dispositions outside the vocabulary: must be 0
```

As of base `0465fdd8`: 30 rows — 19 `D` (one of them production), 4 `S`, 7 `C`. Every row carries a disposition; the count of empty disposition cells must be zero.

## Not a call site

Listed so nothing is silently dropped: `scripts/collect-benchmark-data.php:158`,
`scripts/directive-narrow-control.php:72`, `scripts/finding-gate/SelfTest.php:2802`,
`src/Analysis/Evidence/Security/CommandInjectionDetector.php:15` and `:27`,
`src/Analysis/Evidence/Security/CommandInjectionRule.php:11`,
`tests/Analysis/Evidence/Security/Unit/CommandInjectionDetectorTest.php:59`,
`tests/Analysis/Evidence/Security/Unit/SecurityPatternVisitorTest.php:351` and `:352`,
`tests/Infrastructure/Console/Functional/ErrorStreamPseudoTerminalTest.php:31`,
`tests/Infrastructure/Console/Support/RestoresShellVerbosityEnvironment.php:20`.

Eleven matches: four doc comments, one skip message, and six string literals that are the
function *name* as rule data or PHP source inside a fixture.

## The ten formerly unsure rows are closed

The earlier taking left ten rows unresolved because their child's worst-case output could
not be settled. Both takings have now resolved every child back to its construction site,
so nothing is unresolved — but that is not why they are closed. They are closed because
the question does not decide anything: a child's stderr volume is a property of the child's
*future*, and `generate-modular-architecture.php` did not look like a 2.6 MB writer either.
Every `D` row is dispositioned on shape in `02-migration.md`.
