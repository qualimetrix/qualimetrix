# Stage 05 — defects in what the tests assert

276 defects across 219 files are recorded in
[`measurement/defect-ledger.tsv`](measurement/defect-ledger.tsv), typed and
ranked, with provenance in
[`measurement/ledger-provenance.md`](measurement/ledger-provenance.md). Work the
TSV, not this file: prose counts go stale, the table is the authority.

| Class            | Count | What it means                                                    |
| ---------------- | ----- | ---------------------------------------------------------------- |
| `dupe`           | 104   | same assertion as a named counterpart                            |
| `other`          | 62    | mixed observations, triage before acting                         |
| `misplaced`      | 46    | file sits outside its owning subject                             |
| `category-wrong` | 32    | labelled unit but does I/O, spawns processes, builds a container |
| `tautology`      | 14    | cannot fail, so cannot catch the defect it guards                |
| `name-lies`      | 9     | body does not assert what the name promises                      |
| `stale-doc`      | 8     | docblock contradicts the code                                    |
| `never-runs`     | 1     | fixed in stage 01                                                |

Severity: **24 high**, 150 medium, 102 low.

**The first draft of this file was built on a ledger that was not the union of
its sources.** It held 213 rows drawn only from the ten wave-1 slice reports;
the four deep-reading reports and the mechanical body-hash pass were never
merged, so both specimens this stage cites as its motivating examples were
absent from the table its DoD is written against. Review caught it. The ledger
is now the union, and `tautology` was reclassified from medium to high
throughout — a tautology cannot fail, which is exactly the "high" definition.

## The ledger's paths are not the population any more

The ledger records 276 defects against 219 paths **as they were at `585b7c72`**.
Stages 01-04 then moved the tree. Measured on `56c0792f`: **91 of the 219 paths
(42%) no longer exist**, carrying **120 of the 276 rows (43%)**. An earlier draft
of this file put the drift at "31 of 78" — that figure counted only the
`misplaced` and `category-wrong` classes, and it understates the stage by a
factor of three.

The re-derived population is
[`measurement/stage-05/population.tsv`](measurement/stage-05/population.tsv),
and how it was derived — with the three ways the derivation can lie — is
[`measurement/stage-05/population-method.md`](measurement/stage-05/population-method.md).
**Work that table, not the `file` column of the ledger.**

Three facts from it that change how this stage is executed:

- **Resolution is one-to-many.** Where a stage split a file, one heir inherits
  the git rename edge and the other is a plain addition that reuses the name.
  A single-witness map names one heir confidently and silently drops the other.
  Confirmed specimen: `ModularArchitectureGovernanceIntegrationTest` resolves by
  rename chain into `scripts/modular-architecture/tests/`, while
  `governance/ModularOwnership/` holds a second heir under the original name
  with no edge pointing at it.
- **Two witnesses are the detector, not a belt-and-braces.** Git rename closure
  and basename matching agree on 84 files and disagree on one; the disagreement
  is exactly the split above. Nine files in total need a person; the other 210
  are mechanical.
- **Neither witness reads bodies.** A row states a defect as of `585b7c72`.
  Stages 01-04 may have repaired some in passing, so **every row is
  re-confirmed against the current body before it is worked**, and a row that no
  longer holds is closed as `already-fixed` with the commit that fixed it.

Rows are located by class and method name. The ledger's `line` column is from
`585b7c72` and is stale wherever a body moved; do not navigate by it.

## Order: tautologies first

A duplicate costs runtime. A tautology costs trust: it occupies the place where
a real guard would go and reports success forever. Two confirmed specimens show
the shape:

- `ChannelRenameMapTest::itAnswersTheSharedCorpusAsDeclared` asserts
  `array_keys($map->renames) === $map->oldNames()`, and `oldNames()` is
  implemented as `return array_keys($this->renames);`. It compares an expression
  with itself.
- `DeclaredChannelFileScopeTest::declaredKeys()` lists the same two hardcoded
  interfaces as `DeclaredChannelFileScope::create()`. Its docblock says it
  guards against "a capability nobody wired up" — the one defect it cannot
  detect, because the omission would be edited into both lists at once.

**The repair is not deletion.** Each was written to guard something real; the
guard does not hold. Replace the assertion with one whose expectation is derived
independently of the SUT — from the compiled container, from a fixture, from a
separate source of truth. Delete only when, after that work, the guarded
property proves to be covered elsewhere.

## Duplicates: the mechanical half and the read half

[`measurement/identical-bodies.txt`](measurement/identical-bodies.txt) holds 20
groups of byte-identical method bodies, 58 methods, found by hashing normalised
bodies across the whole tree. They are now rows in the ledger — 57 rows, because
`ComputedMetricRuleOptionsTest`'s two methods (lines 16 and 32) collapsed into
one row whose `line` field is empty. Restore the line numbers before working
that row: a defect without a location is a defect nobody can close.

**Do not repeat the causal claim the first draft made.** It said the script
"independently rediscovered" a duplicate a reader had found, offering that as
cross-validation of the instrument. The order was the reverse — the script found
it first — so that sentence was evidence of nothing. The instrument's real
warrant is narrower and sufficient: byte-identical normalised bodies are a fact,
not a judgement.

Two groups need a ruling rather than a fix, and the first one's figures have
moved since the ledger was written:

- `itDeliberatelyDoesNotProvideCallableMetrics` — the ledger says **13 identical
  bodies in 13 files**. Measured on `56c0792f`: **14 files carry the assertion**,
  13 of them byte-identical and the fourteenth
  (`IdenticalSubExpressionCollectorTest`) making the same assertion against an
  inline instance rather than `$this->collector`. **The byte-identical
  instrument undercounts the conceptual group**, which is the shape of the
  ruling, not a detail of it: rule on the assertion, then apply to all 14.
  Plausibly legitimate — one deliberate statement of intent per collector, of
  the kind a shared helper would hide.
- `YamlKeyReachabilityTest` — the ledger says 6 identical bodies in one file. The
  file now lives at `governance/ConfigurationVocabulary/` and declares 7 test
  methods. Re-measure the group before collapsing it; six names asserting one
  thing is not defensible, but the seventh may not be one of the six.

**Similarity is not duplication, and this cut both ways during the audit.**
Three Baseline pairs, plus `SuppressionCompositionBuilderTest` and
`ConfigurationErrorProjectionTest`, were suspected on shape and cleared on
reading: each method hits a distinct code path. A whole slice — the Json/Sarif
formatters — came back with an empty defect table after full body reads, with
seven borderline cases examined and rejected by name. Confirm a duplicate by
reading both bodies and both SUTs; never delete on resemblance.

## `category-wrong`: 32 files, and what changed about them

32 files are labelled with the wrong level: `Unit/` directories holding tests
that spawn real subprocesses (`DuplicationMemoryLimitProcessTest` runs
`bin/qmx`), do real filesystem work, or build the DI container.

An earlier draft made this class conditional on a directory-coverage guard and
then on globs; both framings are withdrawn. The config enumerates directories, so
a move into a new one is a registration step, and G2's orphan check is what makes
a forgotten registration loud.

**Re-derive this class against the tree.** The counts above are from
`585b7c72`; stages 01-04 have since moved 42% of the whole ledger population, so
a file's recorded level says nothing about the level it carries today. Take the
current path from `population.tsv` and re-read the body: `category-wrong` is a
claim about what the test *does*, and only the body settles it.

## Definition of Done

- Every ledger row is resolved: fixed, or marked won't-fix with a reason in the
  TSV. A row left untouched without a verdict is not done.
- All 24 `high` rows fixed.
- For each tautology, the replacement assertion is **shown to fail** when the
  guarded property is broken. An assertion that cannot be made to fail is
  another tautology, and this is the only check that tells them apart.
- Executed-test count stated before and after. It will legitimately drop here —
  predict the drop first, compare against the observed one, and explain any
  difference. An unexplained difference is a lost test.
- Pinned paths: **the recorded figure "108 of the 346" holds on neither side and
  `measurement/pinned-paths-impact.txt` is stale** — it was written at `585b7c72`
  and the generator has moved since. Measured on `56c0792f`: the generator pins
  **245 distinct `tests/` literals**, and of the 108 paths the file lists for this
  stage **only 66 are still pinned**. Re-derive the impact list against the
  generator before working it, and do not cite the tracked file as the
  denominator. `composer architecture:check` green.
- **Every row is re-confirmed against the current body before it is worked**, and
  a row the tree no longer carries is closed as `already-fixed` naming the commit
  that fixed it. The ledger describes `585b7c72`; a row worked on trust is a
  repair to a defect that may not be there.
- **No exception list ends above its ceiling and the population stays above 600.**
  All three lists are full today and the floor allows 15 retirements, so both are
  live constraints rather than formalities. A package that needs either to move
  says so in its commit and takes it to the owner.
- **`bin/qmx directives` and the dangling-name detector both exit 0**, the second
  of which requires it to be executable by `composer check` at all — see P0.
- `composer check` green.

## Handed over by stage 04: three capped exception lists, all of them full

Stage 04's invariant control caps three kinds of file it does not adjudicate.
All three are handed over here. **Measured on `56c0792f`, every one of them sits
exactly on its ceiling**, so this stage cannot park a single new exception
anywhere:

| List (`SubjectPathExceptions::LISTS` key) | Holds                               | Rows / ceiling | Overlap with the ledger |
| ----------------------------------------- | ----------------------------------- | -------------: | ----------------------- |
| `declares_no_coverage`                    | no `#[CoversClass]` at all          | **84 / 84**    | 30 files, 38 rows       |
| `covers_another_owner`                    | covers only another owner's classes | **19 / 19**    | 5 files, 5 rows         |
| `remainder_is_not_a_prefix`               | remainder drops an interior segment | **4 / 4**      | none                    |

Four consequences, and the first two are hard limits rather than advice:

- **A ceiling can only be lowered, never raised by machine.** `derive-subject-path-exceptions.php`
  writes `min(ceiling, count(rows))` and refuses with `ABOVE_CEILING` when the
  tree carries more than the list admits, telling the caller to "raise that
  ceiling by hand and say in the commit why the tree is allowed to get worse".
  Any package that makes a file *newly* excusable is therefore a package that
  must argue for regression in its commit message, in front of the owner.
- **The population floor leaves 15 files, not 16.** The same control asserts
  `assertGreaterThan(600, count($population))` against a population of 616.
  Greater-than means 601 is the lowest passing value, so **retiring a sixteenth
  file reddens it**. Adjudicating list A is the likeliest way this stage retires
  files; count before deleting.
- **Deriving is a write, not a check.** The script never exits 0: 4 means it
  wrote, 5-8 are refusals that leave the tracked file untouched. A package that
  runs it and reads exit 0 as success has misread it.
- **Stale rows refuse loudly, missing rows refuse differently.** A row whose
  exception no longer exists fails `itCarriesNoStaleExceptionRow`; a row naming a
  file the scan never reached fails `itJudgesEveryTestFileUnderTheTestsRoot`.
  Both are recoverable by re-deriving; neither is recoverable by hand-editing.

**List B is the adapter-exclusion principle showing through** — every
`tests/Analysis/Policy/Baseline/Functional/Baseline*CommandTest.php` covers
`Infrastructure\Console\Command\Baseline\...`. **List C is four `Analysis/Run`
files** whose path drops an interior `Contract` segment. Stage 04 capped all
three rather than adjudicating them, because adjudicating them is this stage's
subject.

## Three things stage 04 left without an owner, and one that rotted

These are not ledger rows. They are decisions this stage inherits, and P0 exists
to close them before any package re-derives anything.

- **The dangling-name census has rotted, exactly as its own docblock predicted.**
  `dangling-test-names.py` reports **10 names, not the 9 recorded**, exits 1, and
  flags one as `NEW`: `Qualimetrix\Tests\Core\Unit\Integration`, a namespace
  planted inside a probe project's heredoc — the same shape as an already-pinned
  entry, so the census describes one instance of a form that now has two.
  **Nothing in `composer check` runs this script**, which is why it could rot.
  Making it a check is P0's job; pinning the new name to quiet it is the one
  repair the script's own text forbids.
- **Fourteen inventory rows promise a relocation no package performs.** They are
  the rows in `test-ownership.tsv` carrying both
  `disposition = "Move atomically with the named owner and closure package."`
  and `closure_package = permanent` — 10 JavaScript artifacts under
  `src/Reporting/Template/` (8 `*.test.js`, plus `package.json` and
  `vite.config.js`, owner `Reporting/HtmlTemplate`) and 4 fixtures (three
  `tests/Fixtures/Ast/`, one `tests/Fixtures/Schema/sarif-2.1.0.schema.json`).
  While they read `permanent`, the `closure_package` column means two different
  things at once and must not be derived as one.
- **Two generator constants are read by nothing, and one is stale.**
  `P6_LIVE_ADDED_TEST_IDS` holds 6 entries, all of which still resolve.
  `P6_RENAMED_TEST_IDS` holds 4, whose keys are pre-rename names by construction
  and **one of whose values is false on both halves** — it names a namespace the
  file does not carry and a method declared nowhere. That value half is one of
  the ten dangling names above. Neither constant is guarded by
  `assertPathLiteralsResolve()`. Whether the record survives at all is the
  owner's call, not this stage's.
