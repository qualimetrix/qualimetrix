# Stage 2 — the cure, as packages

Base: `main` at `c755fe00`. Every package below names its own file set. The sets
of packages that may run AT THE SAME TIME do not overlap; `scripts/promise-effect/Stand.php`
is written by both P0 and P2 and therefore belongs to one owner across two strictly
sequential packages — P2 may not start until P0 has landed. P1 and P5 are
independent of each other and of the code; P3 must land before P4; P6 closes the
accounting and can only run after P4.

Revision 3, after two rounds of review. Round 1: K5 is new, K1 says which path's
form is checked, the floor edit moved into its own package. Round 2: K5 is
generalised from one instance to the requirement, with the top-level spelling mix
as its second and measured instance; `scope` is pushed down rather than excluded;
P0's DoD is split into the three different requirements it had run together; and
P3 names the `LONE_THRESHOLD` gate it has to open for the complexity family.

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

**K5. The cure must remove no refusal that exists today.** Revision 2 stated this
about one case and was caught missing a second, so it is stated as the general
requirement with both instances named — and the acceptance case is a run of every
refusing document in the population against the cured build, not an argument.

- *A level slot of the wrong form.* `complexity.ccn: {threshold: 5, class: 5}`
  exits 3 with `Level "class" … takes a map of options, got int`, and the refusal
  rests on recognition alone, because `fromArray()` silently turns the scalar into
  an empty map. The push-down therefore writes only into a level key that is
  absent, `~`, or a map, and leaves every other value where it stands.
- *Two spellings of one band at the TOP level.* `coupling.cbo: {threshold: 30,
  warning: 10}` exits 3 today through the flat branch P4 deletes. Measured on two
  builds: with that branch gone the same document exits 2 and loses both keys in
  silence. P3 therefore carries this refusal at the seam, naming the top-level keys
  the author wrote, with today's message and exit code. The form is absent from
  both population files, which is why neither measurement saw it; P3's DoD adds it.

  **This one widens the refusal set in exactly one way, and B4 names it.** Today
  the refusal rides inside a branch the `enabled: false` early return jumps over:
  measured on the shipped binary, `{enabled: false, threshold: 30, warning: 10}`
  exits 2 while the same document without `enabled` exits 3. A seam that refuses
  unconditionally starts refusing the first. The alternative — reproducing "unless
  the rule is off in this layer" at the seam — would keep a document's validity
  depending on a key that has nothing to do with its contradiction, which is the
  shape this round removes elsewhere. So the widening is taken, not avoided, and
  it is a breaking change with an entry of its own.

K1 and K5 are the same class of defect in opposite directions: one invents a
refusal about a key nobody wrote, the other deletes a refusal about a key
somebody did.

## P0 — the instrument, before every other package

**Files:** `promise-effect/effect-magnitudes.tsv`,
`scripts/promise-effect/Stand.php`, `promise-effect/pair-kind-scope.tsv`, and the
stand's own tests.

The stand writes one canonical magnitude per numeric key, so the two keys of a
pair that contend for the same pointer write the same value and no leaf-wise
comparison can say which won. At those magnitudes `compose` goes green after the
cure on all eighteen rows of this round's own kind — the prediction would come
true for a reason unrelated to the cure. The second half: nineteen triples are
judged twice, under two kinds, so ten of axis B's defects are second copies.

Three requirements, which revision 3 ran together into one phrase:

1. **the two sides of a pair write different values.** Not expressible in
   `effect-magnitudes.tsv` as it stands: its two selectors (`form`, `option_leaf`)
   both answer "what SECOND value to write instead of the canonical one", not
   "what value to write for THIS side". A third selector is a new mechanism, and
   its price — a column in the file, a read in `Declarations`, a branch in
   `pairSide()`, a guard — must be costed the way `deeper-wins:` was.
2. **the choice of magnitude must not depend on the product under test.**
   `pairSide()` picks the canonical value only when the object differs from the
   omitted case *on the current build*, and falls back otherwise. So the document
   written is a function of the behaviour being measured, and the product changes
   between this round's runs. Whether the observational fallback survives is a
   decision P0 must take explicitly; if it does, the rows where inertness moves
   with the product are not comparable between runs and must be named.
3. **two forms admit no third value at all.** `bool` has two, one of which is the
   level default; the `scope` enum is `all`/`application`, and `all` is the
   default. These are the six cells that stay NOT OBSERVABLE after P0 — four
   `complexity.npath | *.enabled × enabled` and two `coupling.cbo |
   class.scope × scope`. A DoD stated as a property to reach is not reachable on
   them, and run #1 is judged without them rather than despite them.

`03-acceptance.md` carries the measurement and the numeric DoD.

Nothing else in this round may start before P0 lands, because every number the
other packages are judged by is produced by this instrument.

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
`scripts/promise-effect/Stand.php`, `tests/Unit/PromiseEffect/LedgerVocabularyTest.php`.

`Stand.php` is in the set because the key→side translation lives there in two
places: without it the live path reads the new value as "the second key won",
which is the defect `claude-07` named for `one-wins:`. A green
`LedgerVocabularyTest` over a live path that reads the value backwards is the
same false witness one layer up.

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
cannot express: which levels a top-level key reaches. The complexity family enters
through `unfold()`'s condition 4, which today makes its group a no-op because the
registry declares `LONE_THRESHOLD_SHAPE` with an empty graduated pair — that
condition is the line P3 changes for those three rules. `unfold()` gains the
cross-path step — for each top-level group with a declared reach, push the value
into every reached level whose own group this layer left unwritten, then remove
the top-level key. `enabled` and `scope` are declared and pushed the same way —
`scope` by key, since it is one key, and with its enum semantics unchanged. It
moves here from `CboOptions::fromArray()`, which composes it correctly by key but
layer-blind, so a lower layer's block beats a higher layer's `scope` today. P4
removes that composition in the same change; leaving both would apply it twice.

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

**What changes.** Both early branches go — but only because P3 has already taken
over what the flat branch was doing besides discarding blocks: the top-level
`ThresholdParser::parse()` call that refuses a same-layer spelling mix lives
inside it, and is the only top-level `parse()` call either coupling rule has. P4
may not land before that refusal is demonstrably carried at the seam. `fromArray()`
then reads every level from a document that no longer carries a top-level band;
the six tests that assert "the block is discarded" become tests that assert
composition, one per class. The `scope` composition inside `CboOptions::fromArray()`
is **removed** here, because P3 now performs it at the seam — with a cross-layer
acceptance case, since the point of moving it is the orientation `fromArray()`
cannot see: a lower layer's `class: {scope: …}` must no longer beat a higher
layer's top-level `scope`.

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

B1 through B4 get `Breaking` entries **with a migration recipe written from the
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
P0 ──▶ stand run #1 (unfixed product, distinguishable magnitudes)
        P1 ─┐
        P2 ─┼─ (independent, any order, parallel) ──▶ stand run #2 (axis B rises)
        P5 ─┘
                P3 ──▶ P4 ──▶ P6 ──▶ stand run #3, gate, composer check
```

Three stand runs, each attributable to one movement. A single run taken after two
of them proves nothing about either.

P5 describes the contract, not the implementation, so it does not wait for P3.
Its review, however, happens with the code: prose that survives review while the
code it describes is unwritten has been wrong before.

## Subagent note

No package may run `composer promise-effect`: the run takes ~200 s and writes a
shared artifact, so two at once overwrite each other. The stand belongs to the
orchestrator, between packages. A package needing a copy of the tree must build
it with `git ls-files` plus `rsync`: `git archive HEAD` silently drops `docs/`,
`scripts/`, `tests/` and `website/`, which are marked `export-ignore`.
