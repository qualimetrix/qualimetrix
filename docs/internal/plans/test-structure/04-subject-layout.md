# Stage 04 — ADR 0022 completed

96 files remain in the pre-migration role buckets `tests/Unit`,
`tests/Integration`, `tests/Functional`. They move to `{subject}/{level}`,
joining the 583 already there.

| From                 | Files |
| -------------------- | ----- |
| `tests/Unit/`        | 83    |
| `tests/Integration/` | 7     |
| `tests/Functional/`  | 6     |

## The map, and the witness it is built on

Per-file targets:
[`measurement/legacy-relocation-snapped.csv`](measurement/legacy-relocation-snapped.csv).

**The first version of this map was wrong in 68 of 96 rows and review caught
it.** It derived the owner from each file's dominant production import. A test
for a composition root imports dozens of collaborators and only one SUT, so the
collaborators outvoted the subject: `ContainerFactoryTest` was sent to
`Analysis/Evidence/CodeSmell`, `CachedFileParserTest` to `Core`,
`CheckCommandDefinitionTest` to `Analysis/Evidence/CircularDependency`. Executing
that map by script — which this stage prescribes — would have scattered two
thirds of the moved files across foreign subjects, and **no guard would have
noticed**: G1, G2 and G3 all stay green when a test runs from the wrong owner's
directory. Correct placement is not a machine-checkable property here; it is
what the reviewer is for.

The map is now built from `#[CoversClass]`, which is the file's own statement
about its SUT, with imports only as fallback:

| Witness                    | Files |
| -------------------------- | ----- |
| `#[CoversClass]`           | 83    |
| name match                 | 3     |
| dominant import (fallback) | 7     |
| unresolved                 | 3     |

The corrections are listed in
[`measurement/relocation-map-corrections.txt`](measurement/relocation-map-corrections.txt).

**What this witness still cannot see.** `#[CoversClass]` is a claim, not a
proof: a file may cover more than it declares, or declare a class it barely
touches. The seven fallback rows carry the original defect and must be read
individually. The three unresolved rows are the `RuleVocabulary` tooling tests,
which belong to stage 03 and must not be moved here.

**The cluster in the first map was an artefact.** "24 files to
`Analysis/Finding/Unit`" was a property of the inference method, not of
ownership — Finding's types are imported by everything. Do not plan capacity
from the old numbers.

## Cases that are not a plain move

- **`tests/Analysis/Finding/Support/FindingFactory.php`** — its 8 consumers all
  live in `Baseline/Unit/`, none in Finding. By ownership it is Baseline's.
- **Two homes for console functional tests** — `tests/Functional/Console/` and
  `tests/Infrastructure/Console/Functional/`. No content overlap; the first
  folds into the second.
- **`HookStatusCommandTest.php` exists twice** — one subject split across two
  roots (one `#[CoversClass]`, one SUT: `execute()` in one file, `configure()` in
  the other). Merge.
- **`UnmatchedExcludeIntegrationTest.php` exists twice** — two different
  subjects (`architecture.unmatched-exclude` vs `discovery.unmatched-exclude`)
  that collided on a name. Keep both, rename so the name says which.
- **`tests/Core/Unit` as a target flattens the subject.** The snapping rule
  picks the deepest *existing* directory, so `Core/Util/{GlobSyntax,
  NamespaceMatcher,StringSet}Test` land in a flat `tests/Core/Unit` beside the
  existing `Core/Path/Unit` and `Core/Symbol/Unit`. Create the sub-subject
  directory instead of flattening; ADR 0016 applies to the test tree too.

## The cost review found and the first draft omitted

`scripts/generate-modular-architecture-test-inventory.php` hardcodes **346
paths under `tests/`**. This stage's moves touch **19** of them; the other
stages touch more (see
[`measurement/pinned-paths-impact.txt`](measurement/pinned-paths-impact.txt) —
123 pinned paths across all stages). Every touched path requires editing the
generator and regenerating `docs/internal/generated/modular-architecture/`, or
`composer architecture:check` reddens. The first draft named this as a single
special case (`FindingFactory.php:181`); it is a bulk step and belongs in the
work estimate.

## Relocation is three operations

1. **Move** the file.
2. **Rewrite its namespace** to match the path — PHPUnit discovers by file, so a
   stale namespace runs and misleads rather than failing.
3. **Update the pinned path** in the inventory generator where one exists.

Steps 1–2 over 96 files are a mass edit, done by script per CLAUDE.md. Directory
registration is no longer a step — D6 replaced it with globs — but this stage
owns the other half of D6: **once the 96 files are out of the role buckets, the
transitional entries `tests/Unit`, `tests/Integration` and `tests/Functional`
must be deleted from the config as this stage's last action.** Leaving them is
harmless while the directories exist and becomes a lie the moment they do not.

## Definition of Done

- `tests/Unit/`, `tests/Integration/`, `tests/Functional/` no longer exist, and
  the three transitional `<directory>` entries naming them are gone from the
  config.
- G3 green over the tree, with its allow-list no larger than stage 01 left it.
- **Executed-test count equals the stage-01 baseline exactly.** A relocation
  cannot change how many tests run; if it did, something was dropped.
- `composer architecture:check` green — this is where a missed pinned path bites.
- `composer check` green. The aggregate is the evidence; a green subset is not.
- Every row whose witness was `dominant import` or `unresolved` is confirmed by
  reading the file, and the confirmation is recorded per row.
