# Stage 2 — the cure, as packages

Base: `main` at `c755fe00`. Every package below names its own file set; the sets
do not overlap. P1, P2 and P5 are independent of each other and of the code;
P3 must land before P4; P6 closes the accounting and can only run after P4.

Revision 2, after review: K5 is new, K1 now says which path's form is checked,
`scope` is excluded from the push-down, and the floor edit has been moved out of
P2 into its own package because the two would otherwise write the same file at
different times.

## Constraints the cure has to satisfy

Each is a property an implementation can violate while passing a naive test, so
each is named here and carries its own acceptance case.

**K1. A refusal must keep naming the key the author wrote, and the form checked
must be the form of the path written into.** Recognition runs *after* the unfold
and sees unfolded keys (`measurement/seams.md`, Q2); the invariant holds today
only because the value's form is checked before anything is rewritten. Two
distinct requirements follow, and revision 1 only stated the first:

- the push-down must not move which key a refusal names;
- ADR 0058 put the declared form **at the use site**, so a value that is
  well-formed for the top-level group is not automatically well-formed for the
  level it is pushed into. The push-down must check the target level's declared
  form and, when it does not fit, leave the value where the author wrote it so
  the refusal speaks about that key. Measured: writing `abc` into a level makes
  the product say `Option "warning" of rule "complexity.ccn" at level
  "callable"` — a key and a level the author never mentioned.

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
named but did not complete takes that level's default, never the top-level value
and never a sibling level's.

**K5. The cure must not remove a refusal that exists today.** A level key whose
value is neither a map nor `~` refuses now — `complexity.ccn: {threshold: 5,
class: 5}` exits 3 with `Level "class" … takes a map of options, got int`, and
the refusal rests on recognition alone, because `fromArray()` silently turns the
scalar into an empty map. A push-down that overwrites the slot with a map
deletes that refusal without anyone noticing. The push-down therefore writes only
into a level key that is absent, `~`, or a map, and leaves every other value
exactly where it stands.

K1 and K5 are the same class of defect in opposite directions: one invents a
refusal about a key nobody wrote, the other deletes a refusal about a key
somebody did.

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
across two layers in both orientations; a bare top-level shorthand with no block,
so B1's single-layer face is visible; a preset-written `class:` block under a
higher-layer shorthand, so B1's cross-layer face is visible; and a top-level
`enabled: true` on `complexity.npath`, so B3 is visible. It must cover both
families, because their reach differs. It must not read project code.

**DoD.** Against the pre-cure commit the case reddens — named surfaces, counted,
recorded in the package report. Before the cure lands, `composer gate --
--reference=<base>` stays GREEN with the case present. The reddening is the
evidence the case carries the subject; a case that reddens nothing is not
evidence and is not accepted.

## P2 — the stand's input (ledger, mechanisms)

**Why before the product changes.** These are the stand's input. Editing them
after the cure would leave no witness of what the cure moved.

**Files:** `docs/internal/plans/promise-effect/measurement/promise-ledger.tsv`,
`docs/internal/plans/layer-value-survival/measurement/axis-b-mechanisms.tsv`,
`scripts/promise-effect/Ledger.php`, `scripts/promise-effect/Classifier.php`,
`tests/Unit/PromiseEffect/LedgerVocabularyTest.php`.

**Content.** The row-by-row account in `03-acceptance.md` — which rows take
`compose`, which take `one-wins:<key>`, which need the new `deeper-wins:<key>`
value — plus that value's constant, its classifier branch and its vocabulary
test. `scripts/promise-effect/` is in this package's file set because the new
value cannot be added to the ledger without the branch that awards it: the guard
landed by #68 refuses a value no branch recognises, deliberately.

**DoD.** `composer promise-effect` loads the edited ledger without a
`LedgerError`; the grid is re-taken and its numbers recorded *before* any product
change; the classifier probe's negative controls are carried into
`LedgerVocabularyTest` so that a branch which greens everything cannot pass. No
`--freeze-before`, no retake, no `pending:` conversion.

## P3 — reach, declared and acted on

**Files:** `src/Analysis/Finding/RuleConfiguration/RuleThresholdKeyGroupRegistry.php`,
`src/Analysis/Finding/RuleConfiguration/RuleOptionThresholdShorthand.php`, their
unit tests, and `RuleThresholdKeyGroupRegistryCompletenessTest`.

**What changes.** The registry gains the concept it describes in prose today and
cannot express: which levels a top-level key reaches. `unfold()` gains the
cross-path step — for each top-level group with a declared reach, push the value
into every reached level whose own group this layer left unwritten, then remove
the top-level key. `enabled` is declared and pushed the same way. **`scope` is
not**: `CboOptions::fromArray()` composes it by key already, and pushing it here
too would apply it twice.

**What the completeness guard becomes.** Today it proves every rule/path where
the code calls `ThresholdParser::parse()` has a registry entry. After P4 the
top-level parse call for the complexity family is gone, so an entry at `''` stops
mirroring a call site and starts declaring a reach. The guard must be redefined
to check the new claim — that every declared reach names levels the options class
actually builds, and every level an options class builds is reachable or
deliberately unreached — and must keep deriving both sides from something other
than the registry itself. A guard that only re-reads the registry is not a guard.

**DoD.** Behavioural, not structural: for every rule × reached level × block form
in `measurement/population.tsv` and `population-gap.tsv`, the document's outcome
is the one the contract states. K1, K3 and K5 each have a case whose input is
written the way a door writes it; K2's case writes the block's key in the
spelling the door produces, not the spelling of the table.

## P4 — five options classes without early returns

**Files:** `ComplexityOptions`, `CognitiveComplexityOptions`,
`NpathComplexityOptions`, `CboOptions`, `InstabilityOptions`, their level options
classes if their callers need it, and the six unit tests that assert today's
discard.

**What changes.** Both early branches go. `fromArray()` reads every level from a
document that no longer carries a top-level band; the six tests that assert "the
block is discarded" become tests that assert composition, one per class. The
`scope` composition inside `CboOptions::fromArray()` is preserved deliberately,
with a test that fails if it is dropped along with the branches around it.

**DoD.** The six named tests assert the new outcome; `doc5`/`doc6`, the
`complexity.ccn` control/bare pair, and B3's `E1`/`E2` pair are acceptance cases
with their measured numbers. The level flag is observed **directly**, through
`bin/qmx directives --format=json`, which distinguishes `effective`, `inert` and
`producer-disabled` — not inferred from an absence of findings, which is the
half of the claim that has misled this programme before. No existing same-depth
or malformed-level refusal test changes.

## P5 — what the product says about itself

**Files:** `website/docs/getting-started/configuration.md` and `.ru.md`,
`website/docs/rules/coupling.md` and `.ru.md`, `website/docs/rules/complexity.md`
and `.ru.md`, `CHANGELOG.md`, `docs/adr/0059-*.md`, and the named list in
`scripts/generate-modular-architecture-production-inventory.php` for every file
this round adds.

**Content.** The paragraph documenting the silent discard is rewritten, not
amended — it is currently correct prose about behaviour that is going away, in
both languages. The workaround paragraph is rewritten as the canonical form
rather than deleted: it is the shape both shipped presets use and stays
recommended. `complexity.md` gains what it never had — its own rules' top-level
shorthand and what it reaches.

B1, B2 and B3 get `Breaking` entries **with a migration recipe written from the
consumer's side**, which the project's policy requires and revision 1 omitted:
what a document that relied on the old behaviour looks like, what to write
instead, and how to tell whether a given `qmx.yaml` is affected. B1's entry must
carry its cross-layer face by name — `--preset=strict` plus a top-level
shorthand now reports class-level findings at the preset's thresholds.

## P6 — closing the accounting

**Files:** `promise-effect/floor.tsv`, `finding-gate/declared-delta.tsv`.

Separate from P2 because both edits name a commit that does not exist until the
cure is committed, and because P2 and this package would otherwise write
different files at different times under one owner. The standing floor row
becomes a fifth `pending:` row; `declared-delta.tsv` gains one row per surface
P1's case moves, with the reason.

## Order and parallelism

```
P1 ─┐
P2 ─┼─ (independent, any order, parallel)
P5 ─┘
        P3 ──▶ P4 ──▶ P6 ──▶ stand, gate, composer check
```

P5 describes the contract, not the implementation, so it does not wait for P3.
Its review, however, happens with the code: prose that survives review while the
code it describes is unwritten has been wrong before.

## Subagent note

No package may run `composer promise-effect`: the run takes ~200 s and writes a
shared artifact, so two at once overwrite each other. The stand belongs to the
orchestrator, between packages. A package needing a copy of the tree must build
it with `git ls-files` plus `rsync`: `git archive HEAD` silently drops `docs/`,
`scripts/`, `tests/` and `website/`, which are marked `export-ignore`.
