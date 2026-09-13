# Stage 2 — the cure, as packages

Base: `main` at `c755fe00`. Every package below names its own file set; the sets
do not overlap. P1, P2 and P5 are independent of each other and of the code;
P3 must land before P4.

## Constraints the cure has to satisfy

These come from the review that withdrew the previous plan and from this round's
measurements. Each is a property an implementation can violate while passing a
naive test, so each is named here and carries its own acceptance case.

**K1. The refusal must keep naming the key the author wrote.** Recognition runs
*after* the unfold and sees unfolded keys (`measurement/seams.md`, Q2); the
invariant holds today only because the value's form is checked before anything
is rewritten. Pushing a band down into a level must not move which key a refusal
names.

**K2. Feeding a level must not shadow the author's own key through an alias.**
Every door normalises nested keys recursively, so an author's `max_warning:`
arrives as `maxWarning`, while the existing shorthand branch writes the literal
`max_warning`. An implementation that merges "the block's keys plus the
shorthand's values" would leave both spellings in one array, and
`ThresholdParser` takes the primary one — the shorthand's. A unit test written
in the table's spelling cannot see this; the acceptance case must write the
block's key in the spelling a real door produces.

**K3. Feeding a level must not create a refusal the author's document does not
contain.** Pushing a `threshold` key into a level array that already carries a
written `warning` produces the same-depth mode mix and refuses a document that
has no contradiction in it. Under C2 the push does not happen at all in that
case; the implementation must make that structural, not incidental.

**K4. A level's own default is the only fallback.** The half of a band a level
named but did not complete takes that level's default, never the top-level
value and never a sibling level's.

## P1 — the corpus witness (finding-gate)

**Why first.** The corpus contains no case writing a top-level band beside a
level block: `layered-threshold` writes `threshold` *nested inside* `callable:`,
and the other four cases touching these rules write nested blocks only. So the
gate would run GREEN across this cure and that green would mean "the corpus does
not carry the subject" — the exact shape #66 was landed to remove one round ago.

**Files:** `finding-gate/cases/<new case>/**`, `finding-gate/README.md` if the
case list is enumerated there.

**Shape.** One case whose configuration carries, over its layers: a top-level
band beside a level block of the same rule in ONE layer; the same pair split
across two layers in both orientations; and a bare top-level shorthand with no
block, so B1's change is visible. It must cover both families, because their
reach differs. It must not read project code.

**DoD.** Against the pre-cure commit the case reddens — named surfaces, counted,
recorded in the package report. Before the cure lands, `composer gate --
--reference=<base>` stays GREEN with the case present (the case alone changes no
behaviour). The reddening is the evidence the case carries the subject; a case
that reddens nothing is not evidence and is not accepted.

## P2 — the stand's input (ledger, mechanisms, floor)

**Why before the product changes.** These are the stand's input. Editing them
after the cure would leave no witness of what the cure moved.

**Files:** `docs/internal/plans/promise-effect/measurement/promise-ledger.tsv`,
`docs/internal/plans/layer-value-survival/measurement/axis-b-mechanisms.tsv`,
`promise-effect/floor.tsv`.

**Content.** The seven `same-source` rows promising `refuse` move to `compose`;
the eighteen `cross-source` rows leave `DEFERRED` for `compose`. The reason is
written into the rows, not into a commit message: their own notes record that
`refuse` was ADR 0052 row 4 applied to a carrier that named no winner, and that
the later carrier named one the vocabulary could not express. This round's
carrier names composition, which the vocabulary already carries — see
`03-acceptance.md` for the row-by-row account and for why no new `coexistence`
value is added.

**DoD.** `composer promise-effect` loads the edited ledger without a
`LedgerError`; the grid is re-taken and its new numbers recorded *before* any
product change, so the cure's effect is measured against this number and not
against the brief's. No `--freeze-before`, no retake, no `pending:` conversion.

## P3 — reach, declared and acted on

**Files:** `src/Analysis/Finding/RuleConfiguration/RuleThresholdKeyGroupRegistry.php`,
`src/Analysis/Finding/RuleConfiguration/RuleOptionThresholdShorthand.php`, their
unit tests, and `RuleThresholdKeyGroupRegistryCompletenessTest`.

**What changes.** The registry gains the concept it describes in prose today and
cannot express: which levels a top-level key reaches. `unfold()` gains the
cross-path step — for each top-level group with a declared reach, push the band
into every reached level whose own band this layer left unwritten, then remove
the top-level band. `enabled` is declared the same way and pushed the same way.

**What the completeness guard becomes.** Today it proves every rule/path where
the code calls `ThresholdParser::parse()` has a registry entry. After P4 the
top-level parse call for the complexity family is gone, so an entry at `''`
stops mirroring a call site and starts declaring a reach. The guard must be
redefined to check the new claim — that every declared reach names levels the
options class actually builds, and every level an options class builds is
reachable or deliberately unreached — and must keep deriving both sides from
something other than the registry itself. A guard that only re-reads the
registry is not a guard.

**DoD.** Behavioural, not structural (the withdrawn plan asked for "no early
return remains", which is a property of source shape that a test cannot see):
for every rule × reached level × block form in `measurement/population.tsv`, the
document's outcome is the one the contract states. K1-K3 each have a case whose
input is written the way a door writes it.

## P4 — five options classes without early returns

**Files:** `ComplexityOptions`, `CognitiveComplexityOptions`,
`NpathComplexityOptions`, `CboOptions`, `InstabilityOptions`, their level
options classes if their constructors need no change but their callers do, and
the six unit tests that assert today's discard.

**What changes.** Both early branches go. `fromArray()` reads every level from a
document that no longer carries a top-level band; the six tests that assert
"the block is discarded" become tests that assert composition, one per class.

**DoD.** The six named tests assert the new outcome; `doc5`/`doc6` and the
`complexity.ccn` control/bare pair from `measurement/user-documents.md` and
`measurement/seams.md` are acceptance cases with their measured numbers; no
existing same-depth refusal test changes.

## P5 — what the product says about itself

**Files:** `website/docs/getting-started/configuration.md` and `.ru.md`,
`website/docs/rules/coupling.md` and `.ru.md`, `website/docs/rules/complexity.md`
and `.ru.md`, `CHANGELOG.md`, `docs/adr/0059-*.md`, and the named list in
`scripts/generate-modular-architecture-production-inventory.php` for every file
this round adds.

**Content.** The paragraph that documents the silent discard is rewritten, not
amended — it is currently correct prose about behaviour that is going away, in
both languages. The workaround paragraph is rewritten as the canonical form
rather than deleted: it is the shape both shipped presets use and stays
recommended. `complexity.md` gains what it never had — its own rules' top-level
shorthand and what it reaches. B1 and B2 get `Breaking` entries naming the old
and the new surface, written from the consumer's side.

## Order and parallelism

```
P1 ─┐
P2 ─┼─ (independent, any order, parallel)
P5 ─┘
        P3 ──▶ P4 ──▶ re-run: stand, gate with declared delta, composer check
```

P5 describes the contract, not the implementation, so it does not wait for P3.
Its review, however, happens with the code: prose that survives review while the
code it describes is unwritten has been wrong before.

## Subagent note

No package may run `composer promise-effect`: the run takes ~200 s and writes a
shared artifact, so two at once overwrite each other. The stand belongs to the
orchestrator, between packages.
