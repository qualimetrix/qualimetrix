# Stage 04 — every test file sits at its manifest owner

The three pre-migration role buckets `tests/Unit`, `tests/Integration` and
`tests/Functional` cease to exist. 114 files move. But the deliverable of this
stage is not the batch of moves — it is the invariant the moves make true, and a
control that keeps it true:

> **Every PHPUnit test class lives at `tests/{manifest owner}/{level}/{namespace
> remainder below the owner}/{basename}`, where the owner is one of the 37 in
> `docs/internal/modular-architecture-manifest.json` and the level is one of
> `Unit`, `Integration`, `Functional`.**

A batch of moves goes stale the moment the next file is written. An invariant with
a control does not, and it is what lets stage 05 stop re-deriving maps: after this
stage, a file's path *is* the declaration of its owner, and the manifest is what
that declaration is checked against.

## The placement rule, and the two maps it replaces

This file previously prescribed executing
`measurement/legacy-relocation-snapped.csv` by script. **Do not.** That map is
stale — 9 of its 96 rows name files stages 02 and 03 already moved, it never
enumerated one file, and its targets collide with three directories that already
exist. A second map exists in the tree and is also wrong: the `target_path` column
of the generated `test-ownership.tsv`, whose owner vocabulary includes
`Infrastructure`, `Reporting`, `Core/Neutral` and `Infrastructure/GitHook` — none
of which is a manifest owner. The two disagree with each other in 83 of 88 rows.

Neither is authoritative because both are downstream of a rule nobody had stated.
The rule is stated above. Its witness is each file's own `#[CoversClass]`,
resolved through the file's `use` statements to a fully qualified name and looked
up in the manifest.

**The rule was self-checked before being adopted**, against the test files that
were already correct: **its owner and level parts** reproduce 504 of the 529
non-legacy test files exactly, and the 25 it does not reproduce all have the same
shape — a sub-namespace of an owner given its own test root above the level segment
— which is this stage's residue package, not evidence against the rule.

That figure supports the owner and level parts and **nothing more**. It was
originally cited as if it validated the whole placement rule including the
remainder; it does not, and the section below says what measurement the remainder
actually survives.

**Why the level sits directly under the owner**, rather than at the leaf
(`tests/Reporting/Formatter/Html/Unit`):

- `AGENTS.md` already says test level, fixtures and support are subdivisions
  *inside* the owning subject. The owner is the subject; `Formatter/Html` is an
  internal folder of `Reporting`, and putting a level under it presents that
  folder as a module boundary, which ADR 0022 says it is not.
- Measured: level-under-owner needs **48** `<directory>` entries across the whole
  tree, level-at-leaf needs **136**. The config holds 46 for the product tests
  today. Every such entry is an address that can go stale, and stale addresses are
  what this campaign keeps finding. Tripling them to buy a nicer-looking tree is a
  bad trade.

The consequence to accept knowingly: `Reporting` is a single manifest owner, so
roughly forty formatter tests land under `tests/Reporting/Unit/Formatter/...`
rather than in per-formatter roots. If `Reporting` should be several owners, that
is a change to the manifest, and it will pull the tests along by itself.

## The map

Per-file targets, witness, current and target suite:
[`measurement/stage-04/relocation-map.csv`](measurement/stage-04/relocation-map.csv)
— 114 rows. 103 derived from `#[CoversClass]`; 11 had no single manifest owner
(8 with no coverage claim, 3 covering two owners), were read one by one, and carry
`DECIDED:` plus the reason in the `note` column. No row is left undecided.

The artifact also states what its method cannot see. Read that section before
trusting a row.

## Decisions this stage takes

**The residue is in scope.** 26 of the 114 rows are not in the legacy buckets:
they are files already sitting under a non-manifest owner
(`tests/Infrastructure/{Unit,Integration}`, `tests/Reporting/FindingProjection`,
`tests/Reporting/Formatter/{Sarif,Suppressed}`,
`tests/Analysis/Finding/RuleConfiguration`). Leaving them would have this stage
*create* a second home for a subject with its own hands —
`tests/Reporting/Unit/Formatter/Sarif/` next to the surviving
`tests/Reporting/Formatter/Sarif/Integration/` — under a green DoD. It would also
make the invariant unstatable, and the invariant is the point.

**The owner derivation is rewritten, not patched.** `classifyOwner()` is a ladder
of ~84 string prefixes; 103 of the 320 path literals in that file are dead, and
the ladder is the one place that is not guarded by `assertPathLiteralsResolve()`,
so a dead prefix there is silent. After this stage the path is the declaration, so
the function becomes a path parse validated against the 37 owners, and a dead
prefix stops being expressible.

This is not optional bookkeeping and it cannot follow the moves. Measured in
[`measurement/stage-04/generator-probe.md`](measurement/stage-04/generator-probe.md):
five of six post-move paths fed to the generator's `--classification-probe` mode
are classified wrong today, four of them **silently**, because the generator
compares its artifact to its own regeneration. A stage that moved first would end
green with an inventory asserting every moved file is misplaced.

**`HookStatusCommandTest` is not merged**, against this file's previous prose. That
prose argued one subject was split across two roots. After the rule the root is
one — `tests/Infrastructure/Console/` — and the two files are separated by *level*:
`Unit/Command/` covers `configure()`, `Functional/Command/` covers `execute()`.
That is the normal shape of this tree. Merging would destroy a level distinction
to remove a name collision that the move already removes.

**`UnmatchedExcludeIntegrationTest` is renamed, not deduplicated.** The two copies
were separated by subject in an earlier stage
(`Analysis/Policy/Architecture/Integration` and
`Analysis/Run/Integration/ExcludeBinding`); only the name still fails to say which
is which.

**`FindingFactory` moves to Baseline.** All eight consumers are in
`tests/Analysis/Policy/Baseline/Unit/`; none is in Finding.

## The invariant, and the shape measurement forced on it

Two drafts of this section were wrong before this one, and both failures are the same
failure: a claim about a population, published without checking that the parts sum to
the population.

The first stated part 3 as *the path below the level equals the `#[CoversClass]`
namespace minus the owner*, and justified the whole rule with "it reproduces 504 of
529 existing files". **That figure tested only the first two parts.** The strict third
part holds for 377 of 616.

The second weakened part 3 to a prefix test and published four buckets that summed to
**612 of 616**. The four missing files were real part-3 violations the derivation had
miscounted into another bucket, and the control built from that table would have been
red on its first run.

Both are measured in
[`measurement/stage-04/invariant-shape.md`](measurement/stage-04/invariant-shape.md),
whose reproducing script now exits non-zero when the buckets do not sum to the
population — the check the two drafts lacked.

**Population:** `tests/**/*Test.php`, stated as a pattern. Support and fixture files
have no level segment and are excluded deliberately, not by omission — an omitted
population is how `tests/Reporting/Support/StubChannelPresentation.php` would be
refused by a control that never meant to judge it.

1. **Owner** — the segments before the level name one of the 37 manifest owners.
   `Core.Neutral` maps to `tests/Core`, the one owner whose name is not a namespace,
   written in the control rather than implied. *616 of 616 after this stage.*
2. **Level** — exactly one segment is `Unit`, `Integration` or `Functional`, and it is
   the segment immediately after the owner. *616 of 616.*
3. **Remainder is a prefix** — the path below the level is a segment-wise prefix of the
   covered class's namespace remainder, for at least one `#[CoversClass]` whose owner
   equals the path owner. *509 of 616, the rest in three capped lists.*

Part 3 is a prefix test because filing a test flat under `{owner}/{level}/` while its
subject sits in a sub-namespace is this tree's convention: 132 already-correct files
are filed that way. The prefix form still refuses what an equality form was introduced
for — `tests/Reporting/Unit/Formatter/Whatever/` covering `Reporting\Formatter\Html\X`
gives remainder `Formatter/Whatever` against an actual `Formatter/Html`.

| Part 3, after this stage                                                                                           | Count   |
| ------------------------------------------------------------------------------------------------------------------ | ------: |
| remainder exact                                                                                                    | 377     |
| remainder a prefix — flattened, nothing invented                                                                   | 132     |
| **list A** — no `#[CoversClass]` at all, **ceiling 84** (5 of them declare `#[CoversNothing]`; 79 declare nothing) | 84      |
| **list B** — covers only classes owned by someone else, **ceiling 19**                                             | 19      |
| **list C** — path owner is among the covered owners but the remainder drops an interior segment, **ceiling 4**     | 4       |
| **total**                                                                                                          | **616** |

All three ceilings are **derived, never hand-written**, on the terms
`governance/TestSuiteHygiene/namespace-path-allow-list.php` already uses: the derive
command writes the rows, deriving only lowers a ceiling, raising one is a hand edit and
therefore a decision, and a row that no longer describes a real exception is refused as
loudly as a missing one.

**List C is four `tests/Analysis/Run/Unit/...` files** whose path drops an interior
`Contract` segment — `Unit/Collection/` against an actual `Contract/Collection`. They
truncate in the middle rather than from the right, which a reader scanning the tree
would not notice and a prefix test does.

**List B is a real signal, and it is stage 05's.** Its members are tests filed under one
owner whose only coverage claim names another — every
`tests/Analysis/Policy/Baseline/Functional/Baseline*CommandTest.php` covers
`Infrastructure\Console\Command\Baseline\...`, the adapter-exclusion principle showing
through.

**All three lists overlap stage 05's ledger, and list A by far the most** — A: 30 files
and 38 ledger rows; B: 5 and 5; C: none. This stage caps all three so they cannot grow
unnoticed; stage 05 adjudicates them and must know the caps exist before re-deriving its
own population. The handoff table is in
[`05-content-defects.md`](05-content-defects.md); an earlier draft handed over only list
B, which is the one that overlaps least.

## Packages

Work breakdown, file sets, order and per-package Definition of Done:
[`04-packages.md`](04-packages.md).

The short form: **P0** rewrites the owner derivation and opens a transitional
allowance for the not-yet-moved paths; **P1–P3** move the files, partitioned by
manifest owner, each retiring the `<directory>` entries its own moves emptied — which
is where the three buckets go; **P4** closes the allowance; **P5** lands the invariant
control; **P6** carries the two remaining named cases.

**All seven are sequential.** Their test-file sets are disjoint, but every one of them
edits `phpunit.xml.dist` or the inventory generator, and each move package must retire
the pinned literals naming the files *it* moves. Isolation here is temporal, not
spatial. An earlier draft ran P5 and P6 in parallel and had both rewriting the
generator, which is the stage's own isolation rule broken by the stage itself.

## Files in the subject that no package owns

Enumerated so that none leaks: the check is not that the package sets do not
overlap — that is trivially true — but that their union covers the subject.

| File or group                                                                                                 | Disposition                                                                                                                                                                                         |
| ------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 114 rows of `relocation-map.csv`                                                                              | P1, P2, P3 by owner; the partition is total and is asserted in P4's DoD                                                                                                                             |
| `phpunit.xml.dist`, `scripts/generate-modular-architecture-test-inventory.php`                                | shared; each of P0–P4 owns its own rows, sequentially                                                                                                                                               |
| `governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`                                | P4 — it carries hardcoded counts over generated artifacts, an address no path sweep reaches                                                                                                         |
| `governance/RuleOptionKeys/DeclaredOptionKeysCoverReadKeysTest.php`, `scripts/enumerate-rule-option-keys.php` | **P3** — they import `FromArrayKeyReader`, which P3 moves. A consumer belongs to the package that moves what it consumes; holding them for a later package makes the moving package's own build red |
| `tests/TestSupport/**`, `tests/Fixtures/**`, every `Support/` and `Fixtures/` directory not named in the map  | **out of scope.** The invariant is about level directories; support and fixture placement is not asserted by this stage and no package touches them                                                 |
| `governance/SolePrimitiveOwnership/**`                                                                        | **out of scope**, moved to a new stage 06 — see below                                                                                                                                               |
| `docs/internal/plans/test-structure/measurement/**` from stages 01–03                                         | historical record, not edited                                                                                                                                                                       |

## Definition of Done for the stage

Every number below names the population it was measured over and the command that
measures it. A number without both is how the ceiling of 8 got into the first draft:
it was true of the 114-row map and false of the tree the control judges.

- `tests/Unit/`, `tests/Integration/` and `tests/Functional/` do not exist, and no
  `<directory>` names them.
- **Every row of `relocation-map.csv` was executed and nothing else moved**, checked in
  both directions — the tree diffed against the map's `target` column, and the map's
  `current` column against what git records as moved. Outside the map: one file moves
  (`FindingFactory`) and two are renamed in place (the two
  `UnmatchedExcludeIntegrationTest`). All three are P6's, listed there, and are the only
  admitted exceptions.
- **Per-suite counts** over the population `--testsuite=<S>` with the runner's own
  exclusions (`--exclude-group=benchmark --exclude-group=live-freshness`), checked
  after every package against its row in
  [`measurement/stage-04/prediction.md`](measurement/stage-04/prediction.md), not
  only at the end. After P4: Unit 6705, Integration 383, Functional 152,
  Infrastructure 1029, Tooling 179, Governance 748, total 9196. **P5 moves the
  Governance figure** — its DoD carries `748 + N`, where N is the new group's case
  count measured by `--list-tests` on the new directory.
- **No file keeps a namespace its path contradicts**, which is
  `TestNamespacesFollowTheirPathTest` plus its allow-list, not the path diff. The
  path diff cannot see this: PHPUnit discovers by file, so a stale namespace runs and
  misleads rather than failing.
- **No repository-wide grep, for namespaces or for paths.** Both come back dirty for
  reasons this stage does not create, and a DoD that has to be argued with is not a
  DoD. **30** files this stage does not move keep a
  `Qualimetrix\Tests\{Unit,Integration,Functional}` namespace — the allow-list
  already guards that side, and its 55 is a row count and a ceiling, not a file count.
  The legacy *paths* still appear in two ADRs, in other plans, in the generated
  inventory and in two docblocks. What this stage checks instead is exact and
  machine-comparable: the tree diffed against the map's `target` column in both
  directions, plus each move package's grep for the old fully-qualified class names of
  the files **it** moved, which is scoped to that package and must come back empty.
- The invariant control (P5) is green over `tests/**/*Test.php` **and has been
  observed to refuse** under each planted breakage its own DoD names. A control that
  has never gone red is not evidence.
- `composer architecture:check` green, and the regenerated inventory reports no moved
  file as needing relocation.
- `composer check` green. The aggregate is the evidence; a green group is evidence
  about that group. Separately, the two `live-freshness` controls that the aggregate
  excludes are run by hand where a package edits them.
- The transitional allowance opened in P0 is empty and its constant is deleted.

## Findings outside this stage

Recorded because an unrecorded finding is a lost one.

- **`scripts/enumerate-rule-option-keys.php` imports `FromArrayKeyReader` from the
  test tree.** A production tool depending on a test-support class. This stage
  moves the class and fixes the import; it does not resolve whether the class
  belongs in `scripts/` or whether the tool should stop needing it. Owner's call.
- **`governance/SolePrimitiveOwnership` is a role bucket** and fails all three
  cohesion tests: its name states a form of assertion, its seven controls
  co-change with seven different subjects, and under independent development each
  moves wholesale to its own subject with nothing duplicated. The verdict is
  settled; the work is not, because it needs four or five new governance groups
  *named*, which is a naming decision and not move mechanics. Split out as
  **stage 06**, listed in `00-overview.md`.
  `RepositoryEntrypoints` and `FindingVocabulary` were examined on the same three
  tests and are **not** defects: both name subjects, and `FindingVocabulary` has
  six siblings in an established family.
- **Two governance controls never run in CI.**
  `ModularArchitectureGovernanceIntegrationTest` and
  `SuppressionSnapshotFreshnessTest` carry `#[Group('live-freshness')]`, and
  `scripts/phpunit-aggregate.py` excludes that group. `composer check` reaches
  PHPUnit only through the aggregate, so neither runs there or in CI. This stage
  works around it — P4 runs the first one by hand, because P4 edits it — but does
  not resolve whether a control nothing schedules should exist in that form. Owner's
  call.
- **The Infrastructure suite holds every level.** After this stage that is 1029
  cases, a third of them unit tests. Inherited, not introduced, but much more
  visible. Whether the config should declare per-level Infrastructure directories
  is stage 05's question.
