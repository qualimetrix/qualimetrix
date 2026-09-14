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

Two groups need a ruling rather than a fix:

- `itDeliberatelyDoesNotProvideCallableMetrics` — **13 identical bodies in 13
  files**. Plausibly legitimate: one deliberate statement of intent per
  collector, of the kind a shared helper would hide. Decide once, apply to all 13.
- `YamlKeyReachabilityTest` — **6 identical bodies inside one file**. Not
  defensible: six names assert one thing. Collapse, or give five of them the
  distinct inputs their names imply.

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

**Re-derive this class after stages 02–04.** Of the 78 ledger files whose defect
is `misplaced` or `category-wrong`, 24 are moved by stage 04 and 15 by stage 02
or 03. Their ledger paths will not exist by the time this stage runs, and a third
of the work will already be done.

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
- Pinned paths: this stage touches 103 of the 346 paths hardcoded in
  `scripts/generate-modular-architecture-test-inventory.php`
  (see [`measurement/pinned-paths-impact.txt`](measurement/pinned-paths-impact.txt)).
  `composer architecture:check` green.
- `composer check` green.
