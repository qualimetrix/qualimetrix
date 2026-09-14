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

| Witness                     | Rows | Outcome                                    |
| --------------------------- | ---- | ------------------------------------------ |
| `CoversClass` (one subject) | 81   | target derived                             |
| `name-match`                | 3    | target derived                             |
| `CoversClass(multi)`        | 3    | `NEEDS DECISION` — covers several subjects |
| `none`                      | 9    | `NEEDS DECISION` — no coverage claim       |

The witness is recorded per row in the CSV's `witness` column, so the 12 rows
needing a person can be selected rather than searched for. An earlier version of
this table quoted counts the artifact could not confirm: the column existed but
was empty in all 96 rows.

The corrections are listed in
[`measurement/relocation-map-corrections.txt`](measurement/relocation-map-corrections.txt).

**What this witness still cannot see.** `#[CoversClass]` is a claim, not a proof:
a file may cover more than it declares, or declare a class it barely touches. The
3 name-match rows rest on a filename convention. And the witness says nothing
about whether the *level* segment is right — a test filed under `Unit` that
builds a container keeps its wrong level through this stage; that is stage 05's
`category-wrong` class.

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

**The map is not executable as it stands, and saying so is the point.** 12 of
the 96 rows carry `NEEDS DECISION` — 3 files whose `#[CoversClass]` names several
subjects, 9 with no coverage claim at all. The snapping rule used to resolve the
first group by taking the common ancestor, which landed them on `tests/Analysis`
and `tests/Analysis/Policy` — taxonomy nodes that ADR 0022 forbids to hold types.
It now refuses instead of choosing. A further 12 rows are owned by stages 02 and
03 (1 repo-control, 5 tooling-test, 6 mixed) and leave this stage's set before it
runs.

So the script executes only rows whose `owning_stage` is `04` and whose target is
not `NEEDS DECISION`; the rest are read by a person. Where this file's prose and
the CSV differ — the `tests/Core/Unit` flattening, the two `HookStatusCommandTest`
files, the `Functional/Console` fold — the prose wins.

Steps 1–2 over the executable remainder are a mass edit, done by script per
CLAUDE.md. Each target directory that does not yet exist must be added to
`phpunit.xml.dist`: the config enumerates directories by name (D6 was withdrawn),
and G2's orphan check is what catches a missed registration.

**16 of the files this stage moves are also recorded in the ledger as
`misplaced`.** Moving them here resolves those rows; stage 05 re-derives its set
afterwards rather than moving them again.

## Definition of Done

- `tests/Unit/`, `tests/Integration/`, `tests/Functional/` no longer exist, and
  the `<directory>` entries naming them are gone from the config.
- No row was executed whose `owning_stage` is not `04`; every `NEEDS DECISION`
  row has a target chosen by a person and written into the CSV.
- No target is a taxonomy node (`tests/Analysis`, `tests/Analysis/Evidence`,
  `tests/Analysis/Policy`).
- G3 green over the tree, with its allow-list no larger than stage 01 left it.
- **Executed-test count equals the stage-01 baseline exactly.** A relocation
  cannot change how many tests run; if it did, something was dropped.
- `composer architecture:check` green — this is where a missed pinned path bites.
- `composer check` green. The aggregate is the evidence; a green subset is not.
- Every `NEEDS DECISION` row (12) is resolved by reading the file, and the chosen
  target plus the reason is written into the CSV.
