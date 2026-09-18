# Stage 05 — defects in what the tests assert

276 defects across 219 files are recorded in
[`defect-ledger/defect-ledger.tsv`](../../../../defect-ledger/defect-ledger.tsv), typed and
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

## `misplaced`, `stale-doc` and `other`: 116 rows an earlier draft left without a rule

Three classes — 42% of the ledger — had no repair rule beyond the one-line
description in the table above. They have one now.

**`misplaced` (46 rows) is mostly already fixed, and the stage must predict that
before it looks.** Stage 04's entire subject was putting every test file at its
manifest owner. By file status: **37 of the 46 rows sit on files that have since
moved** (34 `moved-agreed`, plus one each `ambiguous-heirs`, `moved-rename-only`,
`WITNESSES-DISAGREE`), and only 9 are still `at-path`. The prediction is
therefore: most of this class closes as `already-fixed`, and a `misplaced` row
that survives re-confirmation is a file stage 04's invariant *permits* — which
makes it a question about the invariant, not a misfiling. Compare the predicted
share against the observed one, as the DoD requires for the test count.

**`category-wrong` (32 rows) is the opposite and the plan already says so**: 20 of
32 are still `at-path`, so the body decides, not the directory.

**`stale-doc` (8 rows)** — the docblock is rewritten to match the code, or the
code is wrong and the row is re-classified. A docblock is not deleted to resolve
the row: it is the only statement of intent the next reader gets.

**The seven classes above became eleven.** Four `other` rows in ten described a
defect none of the seven names — a `chdir()` with no restore is not "labelled
unit but does I/O" — so P0a declared `weak-oracle`, `brittle-pin`, `state-leak`
and `undeclared-subject`, each with a repair rule of the same kind as the rules
in this file. Those rules, and the one ruling five `wont-fix` rows stand on, are
in [`measurement/stage-05/other-adjudication.md`](measurement/stage-05/other-adjudication.md);
a package working a row of one of those classes works that rule.

**`other` (62 rows) is adjudicated once, by P0, not seven times.** It is the
largest class after `dupe` and its ledger description is "mixed observations,
triage before acting". Seven packages triaging independently produce seven
standards. P0 reads all 62 notes and assigns each a real class or a `wont-fix`
reason; the packages then work the assigned class. **P0 may not leave a row as
`other`** — a row still called `other` at the end of P0 is a row nobody agreed
how to judge.

## Definition of Done

**The verdict address.** `defect-ledger.tsv` is a measurement taken at
`585b7c72` and is not edited: mutating it destroys the record the stage is
judged against. Verdicts are append-only, one line per ledger row, joined on a
`row_id` **P0 mints into the ledger once** — the natural key
(`file` + `class` + `line`) does not work, because at least one row has an empty
`line`. Minting that column is the single change to the ledger P0 is allowed to
make; the other six columns and their meanings stay.

**Both live at the repository root, in `defect-ledger/`, not under this plan.**
A control that reads a planning record is a control that dies with the plan
directory, and `PlanningRecordIsolationTest` enforces exactly that: it refuses
any executable source naming a concrete plan path. The plan index states the
rule the refusal serves — a completed plan is removed once its verification
assets have moved to their permanent owners — and the ledger with its verdicts
is one such asset. The root mirrors `promise-effect/`: `export-ignore`d, with
`merge=union` on the verdict files. Each package writes
`defect-ledger/verdicts/<package>.tsv`; one file per package, because several
packages work one tree at once and an editing tool rewrites a file whole.

The verdict vocabulary is exactly three values, and nothing else is a verdict:

| Verdict         | Means                                              | Must carry          |
| --------------- | -------------------------------------------------- | ------------------- |
| `fixed`         | the defect was there and is gone                   | the commit          |
| `already-fixed` | stages 01-04 closed it in passing                  | the commit that did |
| `wont-fix`      | the row does not describe a defect worth repairing | the reason          |

- **A control makes incompleteness loud.** Today **nothing in the repository
  reads `defect-ledger.tsv`** — no script, no test — so no command can tell 276
  resolved rows from 276 untouched ones. The stage adds a governance control that
  fails while any ledger row has no verdict, and it is the DoD item that is red
  on day one. Every other machine item below is green before the stage starts,
  and therefore proves nothing about it.
- **Every row is re-confirmed against the current body before it is worked**, and
  the verdict says which of the three it got. The ledger describes `585b7c72`.
- All 24 `high` rows carry `fixed`.
- **Each tautology's replacement is proven by a tracked command, not by prose in
  a report.** The stage adds a controls stand in the shape the repository already
  uses (`composer gate:controls`, `composer directives:controls`): one declared
  case per tautology, each planting one break and requiring that it redden that
  case and no other, plus the coverage check that removing a declaration reddens
  exactly its own case. **The stand runs in an isolated clone whose `vendor` is
  copied, not symlinked** — a symlinked `vendor` resolves PSR-4 into the source
  tree and has produced five consecutive false greens in this repository — and it
  rolls a plant back from a copy taken beforehand, never with `git checkout --`.
- **The executed-test count is 9208**: the six suites under the runner's own
  exclusions. The discovery artifact reports 9210, which is 9208 plus the two
  `live-freshness` cases the aggregate never runs; say which number you mean.
  **The prediction is the orchestrator's, made once before the packages run and
  compared once after** — a package cannot attribute a global delta to itself
  while four others are running. An unexplained difference is a lost test.
- **No list ends above its ceiling and the population stays above 600.** All four
  lists are full and the floor allows 15 retirements. Neither is machine-proof
  against substitution (see below), so a package that swaps or retires says so in
  its commit.
- Pinned paths: **"108 of the 346" holds on neither side and
  `measurement/pinned-paths-impact.txt` is stale.** Measured on `56c0792f`, the
  generator pins **245** distinct `tests/` literals excluding the bare `'tests/'`
  prefix (246 with it), of which **55 are already dead**, and of the 108 paths the
  stale file lists only **66** are still pinned. Re-derive with
  `grep -o "'[^']*'" scripts/generate-modular-architecture-test-inventory.php | tr -d "'" | grep '^tests/' | grep -v '^tests/$' | sort -u`
  and do not cite the tracked file as the denominator.
- `bin/qmx directives src/` exits 0, and the dangling-name detector exits 0 —
  the second **requires first making it something `composer check` runs**, which
  is P0's, and reaching exit 0 is the orchestrator's at the end, not any
  package's: the census is a global counter that several packages move.
- `composer architecture:check` green, `composer check` green.

## Handed over by stage 04: four capped lists, every one of them full

Stage 04 capped the kinds of file its invariant does not adjudicate. **An earlier
draft of this section said there were three. There are four**, and the fourth is
the one this stage is most likely to move, because renaming a class or changing a
test's level moves the namespace/path pair it guards.

Measured on `56c0792f`; the overlap column is counted **against each row's current
heir**, not against the ledger's recorded path, and the two differ:

| List                        | Where                                               | Rows / ceiling | Overlap (by heir) | Overlap (by ledger path) |
| --------------------------- | --------------------------------------------------- | -------------: | ----------------- | ------------------------ |
| `declares_no_coverage`      | `subject-path-exceptions.php`                       | **84 / 84**    | 34 files, 42 rows | 25 files, 31 rows        |
| `covers_another_owner`      | `subject-path-exceptions.php`                       | **19 / 19**    | 5 files, 5 rows   | 5 files, 5 rows          |
| `remainder_is_not_a_prefix` | `subject-path-exceptions.php`                       | **4 / 4**      | none              | none                     |
| namespace allow-list        | `namespace-path-allow-list.php` (from stage **01**) | **55 / 55**    | 16 files, 20 rows | 16 files, 20 rows        |

An earlier draft put list A's overlap at "30 files, 38 rows", which reproduces
under neither counting method. The figure matters because list A is the likeliest
source of retirements and the population floor is tight.

- **The ceiling catches growth, not substitution.** The gate is
  `count($rows) > $ceiling`, and deriving writes `min($ceiling, count($rows))`.
  A package that retires one exception and introduces another leaves the count
  unchanged, so **the derive passes silently and no one is asked to justify the
  new one**. Saying "the lists are full, so nothing new can be parked" is
  therefore too strong: what cannot be parked is a *net additional* exception.
  Any package that swaps says so in its commit; nothing machine-checkable will.
- **The population floor leaves 15 files, not 16.**
  `assertGreaterThan(600, count($population))` against 616 means 601 is the
  lowest passing value. The comment beside that assertion says "sixteen" twice
  and is wrong by one; P0 corrects it.
- **Deriving is a write, not a check.** The script never exits 0: 4 means it
  wrote, 5-8 are refusals that leave the tracked file untouched. Reading exit 0
  as success is a misreading of a command that cannot produce it.
- **Stale rows and missing rows refuse differently.** A row whose exception no
  longer exists fails `itCarriesNoStaleExceptionRow`; a row naming a file the scan
  never reached fails `itJudgesEveryTestFileUnderTheTestsRoot`. Both are fixed by
  re-deriving, neither by hand-editing.

**List B is the adapter-exclusion principle showing through** — every
`tests/Analysis/Policy/Baseline/Functional/Baseline*CommandTest.php` covers
`Infrastructure\Console\Command\Baseline\...`. **List C is four `Analysis/Run`
files** whose path drops an interior `Contract` segment. The first three were
capped by stage 04; the namespace allow-list has been capped since stage 01
(`52eae218`), which is why an inventory that reads only stage 04's handover
misses it. Each was capped rather than adjudicated because adjudicating them is
this stage's subject.

## What stage 04 left without an owner, and what was decided about it

These are not ledger rows. Two are settled; one is work.

**The dangling-name census has rotted, exactly as its own docblock predicted.**
`dangling-test-names.py` reports **10 names, not the 9 recorded**, exits 1, and
flags one as `NEW`: `Qualimetrix\Tests\Core\Unit\Integration`, a namespace
planted inside a probe project's heredoc — the same shape as an already-pinned
entry, so the census describes one instance of a form that now has two.
**Nothing in `composer check` runs this script**, which is why it could rot.
Making it a check is P0a's; pinning the new name to quiet it is the one repair the
script's own text forbids, since a pinned name that no longer dangles exits 3.

**Both unread generator constants are deleted.** `P6_LIVE_ADDED_TEST_IDS` and
`P6_RENAMED_TEST_IDS` occur once each in the whole repository, at their own
declaration, and one record of the second is already false on both halves.

**The `closure_package` column is deleted**, as its own package after this stage.
It decides nothing: its only semantic consumer is a function that is never called
and that reads a manifest key which does not exist. Its apparent incoherence is
two different values sharing one name — a manifest field on 955 production
declarations, always a package label, and a test-side value computed separately
that can read `permanent`.

**The 14 rows promising an unowned relocation split in two.** `disposition` is
derived from whether `targetPath()` differs from the current path, so the promise
is retracted by fixing the target, not the prose. Ten are JavaScript artifacts of
a self-contained npm project that stays intact and moves to its own root after
the campaign; four are fixtures, two of which are shared across two manifest
owners and therefore need adjudication rather than a move.
