# Stage 05 — defects in what the tests assert

213 defects are recorded in [`measurement/defect-ledger.tsv`](measurement/defect-ledger.tsv),
typed and ranked. This stage fixes them. Work the ledger, not this file: prose
counts go stale, the TSV is the authority.

| Class            | Count | What it means                                                       |
| ---------------- | ----- | ------------------------------------------------------------------- |
| `other`          | 60    | mixed observations, triage before acting                            |
| `dupe`           | 47    | same SUT and same assertion as a named counterpart                  |
| `misplaced`      | 45    | file sits outside its owning subject                                |
| `category-wrong` | 32    | labelled unit but does I/O, spawns processes, or builds a container |
| `tautology`      | 13    | cannot fail, so cannot catch the defect it was written for          |
| `name-lies`      | 8     | body does not assert what the name promises                         |
| `stale-doc`      | 7     | docblock contradicts the code                                       |
| `never-runs`     | 1     | fixed in stage 01                                                   |

Severity: 9 high, 106 medium, 98 low.

## Order: tautologies first

A duplicate costs runtime. A tautology costs trust — it occupies the place where
a real guard would go and reports success forever. Two confirmed specimens show
the shape:

- `ChannelRenameMapTest::itAnswersTheSharedCorpusAsDeclared` asserts
  `array_keys($map->renames) === $map->oldNames()`, and `oldNames()` is
  implemented as `return array_keys($this->renames);`. The test compares an
  expression with itself.
- `DeclaredChannelFileScopeTest::declaredKeys()` lists the same two hardcoded
  interfaces as `DeclaredChannelFileScope::create()`. Its docblock says it
  guards against "a capability nobody wired up" — the one defect it cannot
  detect, because the omission would be edited into both lists at once.

**The repair is not deletion.** Each was written to guard something real; the
guard simply does not hold. Replace the assertion with one that derives its
expectation independently of the SUT — from the compiled container, from a
fixture, from a separate source of truth. Delete only when, after that work, the
guarded property turns out to be already covered elsewhere.

## Duplicates: 47 in the ledger, 20 groups found mechanically

[`measurement/identical-bodies.txt`](measurement/identical-bodies.txt) lists 20
groups of byte-identical method bodies — 58 methods — found by hashing
normalised bodies across the whole tree. It independently rediscovered the
`SummaryEnricherTest` duplicate that a reader had found, which is the evidence
that the instrument works.

Two groups need a ruling rather than a fix:

- `itDeliberatelyDoesNotProvideCallableMetrics` — **13 identical bodies across
  13 files**. Plausibly legitimate: one deliberate statement of intent per
  collector, of the kind a shared helper would obscure. Decide once, apply to
  all 13.
- `YamlKeyReachabilityTest` — **6 identical bodies inside one file**. Not
  defensible: six names assert one thing. Collapse to one, or give five of them
  the distinct inputs their names imply.

**Similarity is not duplication, and this cut both ways during the audit.**
Three Baseline pairs and the repetitive-looking `SuppressionCompositionBuilderTest`
and `ConfigurationErrorProjectionTest` were suspected on shape and cleared on
reading: each method hits a distinct code path. Confirm a duplicate by reading
both bodies and both SUTs; do not delete on resemblance.

## `category-wrong` — do not move without stage 01's guard

32 files are labelled with the wrong level: `Unit/` directories holding tests
that spawn real subprocesses (`DuplicationMemoryLimitProcessTest` runs
`bin/qmx`), do real filesystem work, or build the DI container.

Moving them to the right level directory is the obvious fix and it is booby-
trapped: many target `Functional/` and `Integration/` directories are not listed
in `phpunit.xml.dist`, so the moved test stops running while the build stays
green. **G2's second half is a hard precondition for this class.** For each
file the choice is to move it (and register the directory) or to relabel it in
place; either is defensible, silently disabling it is not.

## Definition of Done

- Every ledger row is resolved: fixed, or marked won't-fix with a reason in the
  TSV. A row left untouched without a verdict is not done.
- All 9 `high` rows fixed.
- For each tautology, the replacement assertion is shown to fail when the
  guarded property is broken. An assertion that cannot be made to fail is
  another tautology, and this is the only check that tells them apart.
- Executed-test count is stated before and after. It will legitimately *drop*
  here — that is the point — so the expected drop is predicted first and
  compared against the observed one; an unexplained difference is a lost test.
- `composer check` green.
