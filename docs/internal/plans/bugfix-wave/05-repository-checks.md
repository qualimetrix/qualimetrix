# Session 05: repository checks that report green without looking

Issues: #178 items 1–4, #179 item 9. Branch: `fix/repository-checks`.
Read `00-overview.md` first.

Each item fixes an existing check; none adds a standing check of another check.
Every fix is proved to bite once with a planted defect in your working tree,
which you then revert and **do not commit**. Name each planted defect and the
red result in the report.

No CHANGELOG entry and no gate run: nothing here is user-visible, and nothing
under `src/` changes except possibly one docblock (item 3).

## Files this session owns

| File                                                                                          | Item   |
| --------------------------------------------------------------------------------------------- | ------ |
| `scripts/check-private-leaks.sh`                                                              | #178-1 |
| `governance/PrivateLeakScan/PrivateLeakScanVerdictTest.php` (new group)                       | #178-1 |
| `phpunit.xml.dist` (one `<directory>` line)                                                   | #178-1 |
| `scripts/generate-modular-architecture-test-inventory.php` (one `testSuitePrefixTable()` row) | #178-1 |
| `scripts/generate-suppression-snapshot.php`                                                   | #178-2 |
| `scripts/suppression-snapshot/tests/SuppressionSnapshotKeyTest.php`                           | #178-2 |
| `governance/FindingVocabulary/FindingFieldCensusTest.php`                                     | #178-3 |
| `src/Analysis/Finding/Contract/Finding.php` (docblock only, if a test name changes)           | #178-3 |
| `governance/DirectiveVocabulary/ExecutionFingerprintFieldCoverageTest.php`                    | #178-4 |
| `governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`                | #179-9 |

## #178-1 — `check-private-leaks.sh --commits` says "clean" for a range it could not read

**Facts.** In `--commits` mode the guard is `git rev-parse "$ARG"`, which
accepts any well-formed 40-hex range; `git rev-list` then fails silently inside
the command substitution and the script prints `Commit messages clean`, exit 0.
Exit 0 with a "skipped" message for an unavailable range is the script's own
contract (CI passes `<before>..HEAD`, and `before` can be absent after a force
push). Separately, in file mode outside a Git work tree `git ls-files` fails and
the script exits 0 with no verdict. The script has no tests today.

**Change.**
- `--commits`: validate each endpoint of `A..B` (and a single ref) with
  `git rev-parse -q --verify "<ref>^{commit}"`. An absent endpoint, or a failing
  `git rev-list`, prints the existing "unavailable ... skipped" message and
  exits 0 — never `Commit messages clean`.
- File mode: when the script cannot list tracked files (not a work tree, or
  `git ls-files` fails), print a refusal to stderr and exit 2 (the script
  already uses 2 for refusals such as an unknown option).
- Keep every other mode and message unchanged.

**Where the test goes.** The leak scan is its own subject, so it gets its own
governance group `governance/PrivateLeakScan/`. A new group is exactly two
edits that must agree (`AGENTS.md`, `governance/` paragraph): a
`<directory>governance/PrivateLeakScan</directory>` line in the `Governance`
suite of `phpunit.xml.dist` (next to `governance/CommitSubjectPolicy`), and a
row `['prefix' => 'governance/PrivateLeakScan/', 'suite' => 'Governance']` in
`testSuitePrefixTable()` (next to the `CommitSubjectPolicy` row). The group has
no fixture files, so `.gitignore` and `phpstan.neon` need nothing. Files in a
group reach the repository root with `\dirname(__DIR__, 2)`.

**Tests (new `PrivateLeakScanVerdictTest`).** Copy the scratch-repository
pattern of `governance/CommitSubjectPolicy/CommitRangeVerdictTest.php`. The
script `cd`s to its own parent directory, so copy it into the scratch project's
`scripts/` and run the copy:
- scratch repo, `--commits <absent40hex>..<absent40hex>`: exit 0, output says
  skipped, does not say `clean`;
- scratch repo with two commits whose messages are clean,
  `--commits HEAD~1..HEAD`: says clean (the test proves both sides);
- scratch directory that is not a work tree (set `GIT_CEILING_DIRECTORIES` so
  no outer repository is found), file mode: exit 2.

Planted defect: restore the bare `git rev-parse "$ARG"` guard → the absent-range
case goes red.

## #178-2 — the suppression snapshot generator ignores a failed write

**Facts.** `writeSuppressionSnapshotAtomically()` calls `file_put_contents()`
and `rename()` without checking either; `finishSuppressionSnapshot()` then
writes `Wrote <path> (<n> rows).` with `fwrite(STDOUT, ...)` and returns 0.
`publishAtomically()` in `scripts/generate-modular-architecture.php` is the
pattern: check both calls, remove the temporary file on failure. The script has
no path argument; `SuppressionSnapshotKeyTest` already calls the function
directly. PHPUnit's output buffering does not capture `fwrite(STDOUT, ...)`.

**Change.** The writer reports failure (return `bool` or throw — your choice);
`finishSuppressionSnapshot()` then writes a message to stderr, writes no
`Wrote` line and returns 2. The temporary file never survives a failure.

**Tests (SuppressionSnapshotKeyTest).** Two cases, because each check needs
its own failing input:
- the target's directory does not exist → `file_put_contents()` fails;
- the target is an existing non-empty directory inside a scratch directory →
  the temporary write succeeds and `rename()` fails.
In both: return value 2, no temporary sibling left, the target unchanged. To
assert that no `Wrote` line is printed, run the call in a child process (as the
file's other subprocess helpers or `ChildProcess::run` do) or give the function
an injectable output stream — your choice; name it in the report.

Planted defects: drop the `file_put_contents()` check → first case red; drop
the `rename()` check → second case red.

## #178-3 — `FindingFieldCensusTest` cannot fail for `occurrenceKey`

**Facts.** The fixture finding never sets `occurrenceKey` (default `null`), so
the `[null, null]` comparison holds even if `reportedAsBreach()` stopped copying
it; `acceptedLevel` stays `null` too. The census covers only
`reportedAsBreach()`; `reportedUncompared()` copies the same fields and is not
covered. Non-default values are available:
`OccurrenceKey::semantic('kind', ['k' => 'v'])`, `new AcceptedLevel([7.0], 1)`.

**Change.**
- The fixture gives every constructor parameter a non-default value.
- The census runs over both `reportedAsBreach()` and `reportedUncompared()`
  (a data provider), each with its own set of rewritten fields:
  `reportedAsBreach()` rewrites `severity`, `acceptedLevel`, `uncomparedReason`;
  `reportedUncompared()` keeps `severity` and rewrites `acceptedLevel` and
  `uncomparedReason`. Every other parameter must be copied unchanged.
- Keep the reflection check that the copied and rewritten sets together equal
  the constructor's parameters.
- `Finding`'s docblock names this test; if you rename the test method, update
  that docblock and nothing else in `Finding.php`.

Planted defect: remove `occurrenceKey:` from each of the two methods in turn →
red for that method.

## #178-4 — `ExecutionFingerprintFieldCoverageTest` never compares its provider with the field lists

**Facts.** `provideMovedFields()` has 16 hand-kept keys; today they equal
`ExecutionFingerprint::IDENTITY_FIELDS` (13) ∪ `BOUNDARY_FIELDS` (3).
`addressedProducer` is excluded on purpose and has its own test. The existing
`itNamesEveryFieldAFindingCarries` already fails when a list names something
that is not a constructor parameter.

**Change.** Add a test asserting that the provider's keys equal the union of the
two lists, order-insensitive. Do not restructure the provider.

Planted defects: delete one provider key → the new test is red; add a provider
key that is in neither list → the new test is red. Revert both.

## #179-9 — a governance test pins the exact composer script graph

**Facts.** `ModularArchitectureGovernanceIntegrationTest::itRoutesFreshnessOraclesExactlyOnceThroughAggregateCheck`
calls `assertFreshnessScriptGraph()`, which asserts the literal step lists of
`test`, `test:aggregate`, `selfcheck`, `selfcheck:analysis`, `check:parallel`,
`check:code`, `check:code:tests`, `check`, `check:remaining`, `check:self` and
`check:gate` with `assertSame`. Any legitimate reordering or addition reddens
it. The method carries no group, so it runs inside `composer check`.

`check` does not reach the freshness oracles through `@script` references
alone: `check:parallel` runs `python3 scripts/check-code-parallel/check-code-parallel.py`,
whose `COMMANDS` tuple launches `composer phpstan` and `composer check:remaining`;
`check:remaining` then reaches `check:artifacts` and `check:self`.

**Change.** Replace the literal lists with the properties they protect, over a
model of execution that has two kinds of edge: a composer `@script` reference,
and the coordinator hop — when a step is the `check-code-parallel.py`
invocation, read that file's `COMMANDS` tuple (a literal; session 06 keeps it a
literal with the same entries) and follow each `composer <script>` it names.
Then:
1. Expanding `check`, every leaf step of the four check groups and of
   `check-leaks` appears exactly once: today `check-leaks`, `cs-check`,
   `test:aggregate`, `dangling-names`, `test:js`, `test:cross-tool`, `phpstan`,
   `docs:check`, `architecture:check`, `enumeration:directives:check`,
   `suppression-snapshot:check`, `html-bundle:check`,
   `configuration:merge-table:check`, `selfcheck:analysis`, `directives:audit`.
   Derive this list from the current `composer.json` before writing it, and
   report it.
2. In `check`, `check:code:tests` runs before `check:parallel` (the cheap code
   tests fail first; ADR 0112).
3. Every script that today starts with `Composer\Config::disableProcessTimeout`
   keeps it (at least `test`, `test:aggregate`, `check:parallel`,
   `check:remaining`, `check:gate`); losing it on `check:remaining` was a real
   regression once.
4. In `selfcheck`, `architecture:check` comes before `selfcheck:analysis`.
5. `test:cross-tool` contains the `phpunit-aggregate` and
   `check-code-parallel` unit-test commands.
6. Exact strings stay only where the string is the contract:
   `selfcheck:analysis` (baseline file and `--fail-on=warning`), `check:gate`
   (the group), and `test` (full suite minus the `benchmark` group).
The test must be green on the current tree before you plant defects.

Planted defects: remove `@architecture:check` from `check:artifacts` → red;
add it a second time to `check:remaining` → red; remove `composer check:remaining`
from `COMMANDS` → red; remove `composer phpstan` from `COMMANDS` → red; remove
`@cs-check` from `check:code:tests` → red; remove the timeout step from
`check:remaining` → red; move `@check:parallel` before
`@check:code:tests` in `check` → red; swap two steps inside `check:code:tests`
→ stays green.

## Session gate

After the overview ladder steps 1–4 (step 5 only if you edited `Finding.php`):

1. `vendor/bin/phpunit --no-coverage --filter itRoutesFreshnessOraclesExactlyOnceThroughAggregateCheck governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`
   once.
2. `vendor/bin/phpunit --no-coverage governance/PrivateLeakScan` once.
3. `composer check` once (overview step 7). It runs the other changed tests.

## Definition of Done

- `bash scripts/check-private-leaks.sh --commits aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa..bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb`
  exits 0 and does not print `clean`.
- `composer architecture:check` passes (the new group is registered).
- Every item names its planted defects and the red test in the report.
- The printed exit code of the final `composer check` is 0.
