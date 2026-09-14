# Stage 04 — ADR 0022 completed

96 files remain in the pre-migration role buckets. They move to
`{subject}/{level}`, joining the 583 already there. Per-file targets are in
[`measurement/legacy-relocation-snapped.csv`](measurement/legacy-relocation-snapped.csv).

| From                 | Files |
| -------------------- | ----- |
| `tests/Unit/`        | 83    |
| `tests/Integration/` | 7     |
| `tests/Functional/`  | 6     |

The targets cluster: 24 to `Analysis/Finding/Unit`, 11 to
`Analysis/Evidence/ComputedMetrics/Unit`, 7 to `Core/Path/Unit`, the rest in
ones and twos across 26 further directories. A handful of level-directories do
not exist yet and are created.

**How the targets were derived, and what that misses.** The owner was inferred
from each file's production imports, then snapped to the deepest already-existing
test directory for that owner. It cannot place a file whose imports do not name
its owner, and it will mis-place a test whose imports are dominated by a
collaborator rather than its SUT. The per-file `SUT` column in the slice reports
is the second witness; where the two disagree, read the file. Three files could
not be resolved mechanically at all — they are the `RuleVocabulary` controls,
handled in stage 03, and must not be moved here.

## Relocation is three operations, not one

1. **Move** the file.
2. **Rewrite its namespace** to match the new path. PHPUnit discovers by file,
   so a stale namespace runs and misleads rather than failing — which is how the
   tree already acquired `RuleExclusionStatsTest` declaring
   `Tests\Unit\Analysis\RuleExecution` under `Finding/Unit`.
3. **Register the target directory** in `phpunit.xml.dist` if it is not already
   listed. This is the trap: the config enumerates directories by name, and 39
   level-directories of existing capabilities are currently listed nowhere. A
   move into one of them disables the test at a green `composer check`.

Steps 1 and 2 over 96 files are a mass edit, so they are done by script per
CLAUDE.md, not by hand. Step 3 is verified by G2, not by reading the diff.

## Cases that are not a plain move

- **`tests/Analysis/Finding/Support/FindingFactory.php`** — a helper in the
  Finding slice whose 8 consumers all live in `Baseline/Unit/`, none in Finding.
  By ownership it belongs to Baseline. **Its path is hardcoded in
  `scripts/generate-modular-architecture-test-inventory.php:181` inside
  `P6_A_FINDING_TEST_PATHS`; moving it without editing that script reddens
  `architecture:check`.**
- **Two homes for console functional tests** — `tests/Functional/Console/` and
  `tests/Infrastructure/Console/Functional/`. No content overlap; this is the
  same two-layout split in miniature. The first folds into the second.
- **`HookStatusCommandTest.php` exists twice** — one subject split across two
  roots (one `#[CoversClass]`, one SUT: `execute()` in one, `configure()` in the
  other). Merge into one file at the subject location.
- **`UnmatchedExcludeIntegrationTest.php` exists twice** — two genuinely
  different subjects (`architecture.unmatched-exclude` versus
  `discovery.unmatched-exclude`) that collided on a name. Keep both; rename so
  the name says which.

## Definition of Done

- `tests/Unit/`, `tests/Integration/`, `tests/Functional/` no longer exist.
- G3 (namespace equals path) green over the whole tree — closing the
  pre-existing mismatches as well as the ones this stage could introduce.
- G2 green: zero orphans, and no increase in suite-uncovered directories.
- `composer architecture:check` green.
- **Executed-test count before equals count after.** A relocation cannot change
  how many tests run; if it did, something was dropped or disabled. State both
  numbers. This is the only DoD item that distinguishes a correct move from a
  move that silently lost files, and a green `composer check` does not show it.
