# Stage 2 — the cure, as packages

Base: `main` at `c755fe00`. Every package below names its own file set. The sets
of packages that may run AT THE SAME TIME do not overlap; `scripts/promise-effect/Stand.php`
is written by both P0 and P2 and therefore belongs to one owner across two strictly
sequential packages — P2 may not start until P0 has landed. P1 and P5 are
independent of each other and of the code; P3 must land before P4; P6 closes the
accounting and can only run after P4.

Revision 4. Rounds 1 and 2 were plan review: K5 is new, K1 says which path's form
is checked, the floor edit moved into its own package, K5 is generalised from one
instance to the requirement with the top-level spelling mix as its second, `scope`
is pushed down rather than excluded, and P3 names the `LONE_THRESHOLD` gate it has
to open for the complexity family. Round 3 is not review but measurement: P0's
premises were taken on the tree before any code
(`measurement/p0-premises.md`), seven of them did not hold, and P0's section is
rewritten whole rather than patched — including its file set, which omitted the
file its own price named, and its proof, which could not be given without
circularity. No other package's section has been re-measured yet; each is
re-measured as it starts.

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

**K1b. Pushing an enum needs a check the pushing mechanism does not have.**
`unfold()`'s condition 2 guards the value's form through `RuleOptionValueForm` —
boolean, whole number, number, text, block — and a closed set of words is not one
of them; it lives in `RuleOptionShape::oneOf()`. So a typo in a top-level `scope`
would be pushed into `class` and refused there, printing `… at level "class"`, a
level the author never wrote. Measured: today the same typo prints `Option "scope"
of rule "coupling.cbo" must be one of …` at the top level. The push must consult
the target's declared shape, not only its form, and leave a non-member where the
author wrote it. Price, named the way P0's was: one more predicate on the gate,
reading the shape the registry entry already points at, plus a case per enum key —
today exactly one.

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
  the author wrote, with today's message and exit code **for a document whose only
  defect is the mix**. That qualifier is measured, not cautious: refusals are
  ordered, and the mix is last today (step 6) and first after the move (step 2), so
  a document carrying both a mix and an unknown key prints the unknown key today
  and would print the mix afterwards. Both exit 3, and the population contains only
  single-defect documents, which is why no measurement of this round saw it. The form is absent from
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

Revision 4, rewritten rather than amended after the premises were measured on the
tree (`measurement/p0-premises.md`, run #0 at `1bda6f95`). Nine of the round's
numbers held cell for cell; seven premises did not, and three of those bear on
what P0 is: `axis-b-rows.tsv` has no generator, `PairRow::key()` carries `kind`
deliberately so the duplicate cannot be deduplicated in the ledger, and no axis-B
cell reads NOT OBSERVABLE today — identical sides are reported **green**, not
blind.

**Files:** `promise-effect/effect-magnitudes.tsv`,
`scripts/promise-effect/Declarations.php`, `scripts/promise-effect/Stand.php`,
`scripts/promise-effect.php`, `scripts/promise-effect/tests/`,
`scripts/promise-effect-axis-b-rows.php` (new, the derive mode below),
`docs/internal/plans/shorthand-scope/measurement/`, and the regenerated
`docs/internal/generated/promise-effect/`.

Revision 3 named `pair-kind-scope.tsv` and omitted `Declarations.php`, which is
where `effect-magnitudes.tsv` is parsed, and `promise-effect.php`, which is the
only place a count is reported. The first is dropped — document identity is not
what that table is about — and the other two are added. Nothing may run beside
P0, so widening its set costs no parallelism.

### The defect, confirmed in the form it was described

Measured on the frozen observations of
`pair|coupling.cbo|class:|threshold|same-source|3-shorthand-vs-level-block`:

| side                              | `class`    | `namespace` |
| --------------------------------- | ---------- | ----------- |
| omitted                           | 14, 20     | 14, 20      |
| onlyA (the `class:` block)        | 7331, 7331 | 14, 20      |
| onlyB (the top-level `threshold`) | 7331, 7331 | 7331, 7331  |
| both                              | 7331, 7331 | 7331, 7331  |

`both` is `onlyB` byte for byte. Both sides write the canonical 7331 into the
contested pointer, so `Classifier::pair()`'s `compose` branch finds each side's
moved leaves intact and returns `COEXISTENCE_OK, "both effects present"`.

### Distinguishable sides, declared

`effect-magnitudes.tsv` gains a third selector kind, `side_b`: the literal the
**second** key of a pair is written with. Side A keeps the canonical magnitude, so
its candidate list and its fallback semantics do not move; side B tries the
declared per-side literal first and keeps the existing candidates behind it.

The selector is declared per form and only for forms that admit a third value.
`bool` has two and one of them is a level default; the `scope` enum has two and
one of them is the default. Those get no `side_b` row, `writeForShape()` finds no
match in the per-side set and answers null, and side B degrades to today's
candidates with no special case in the code. That is how the six permanently
unobservable cells stay unobservable **by the shape of their values** rather than
by an exclusion list.

**One magnitude, many spellings — the rule `forms.tsv` already follows and a
per-form table invites breaking.** The canonical write is 7331 as an int, `7331.9`
as a float, `"7331"` as text, `[7331]` and `{a: 7331}` in the two containers: one
number wearing five spellings, so a value surviving a merge is attributable
whatever form the shape picked. The alternate does the same with 9137. `side_b` is
therefore **5519** in all five, and `pqr` for `string-nonnumber`, whose canonical
`abc` and alternate `xyz` are not numbers at all.

This is not tidiness. `writeForShape()` walks `forms.tsv` in file order — `null`,
`bool`, `int`, `float`, `string-number`, `string-nonnumber`, `list`, `map` — and
returns the first form the shape accepts, so a text-shaped key is written
`"7331"` and never reaches `abc`. Declaring 5519 for `int` but 4877 for
`string-number` would put two different magnitudes on one side depending on a
shape decision nobody reads, and the per-form guard below would not see it,
because each row would be distinct from its own form's canonical.

`Declarations` carries the same guard this file already carries twice: a `side_b`
literal equal to that form's canonical write, or to its alternate, is a
`LedgerError`, because a side that cannot be told from the other side reports a
typo here as a property of the product. The guard is per form and catches a typo,
not a magnitude split across spellings — that one is held by the one-magnitude
rule above and by DoD property 1, which reads the two sides' **observations**
rather than their declarations.

**Axis E must not move.** `effectWritesFor()` is shared with the neighbourhood
probe (`Stand.php:926, 944`). The per-side set is therefore threaded as an
optional argument that defaults to today's behaviour, and only `pairSide()` and
`pairCandidates()` pass it. A cell moving on axes A, C, D or E is a falsification
of the round.

### The observational fallback stays, and that is a decision

`pairSide()` writes the canonical magnitude and falls back to a declared alternate
only when the canonical does not move the object **on the build under test**, so
the document written is a function of the behaviour being measured. Revision 3
left the decision open. It is taken here: **the fallback stays.**

Measured. Inside the five cured rules the fallback fires on 71 of 506 sides and on
exactly one leaf — `enabled` — choosing between `true` and `false`, both already
declared. The product-dependence is real and visible in one pair of rows:
`complexity.npath` is written `false` at `callable.enabled` (level default `true`)
and `true` at `class.enabled` (level default `false`). Those four rows are about
different documents on run #1 and run #3, and `03-acceptance.md` already excludes
them from row-wise comparison.

Three reasons the alternatives lose:

- Removing the fallback and declaring the leaf instead puts a second source of
  truth beside the product's own defaults, and it goes stale **silently** when a
  default changes — the stand's own header names observation as the reason this
  file exists.
- A declared `false` at `npath|class.enabled` is inert there by construction
  (the level defaults to `false`), so the declaration would blind the very row B3
  is about. B3 is observed directly in P4 through
  `bin/qmx directives --format=json`, not through these rows.
- The fallback is what keeps the new per-side literals safe: a `side_b` value that
  turned out to be a product default would otherwise make its side inert and move
  a cell **out** of defect, which is the one direction P0 must not produce. With
  the fallback, such a side falls through to the next candidate instead.

### What P0 proves, and why the planned proof cannot be given

Revision 3's proof was "a stand run on the unfixed product whose axis-B number
matches the regenerated table's count". That is not obtainable without circularity.
`today_verdict` at the new magnitudes cannot be predicted from the observations
taken at the old ones: what `both` contains at the contested pointer is *which key
won*, and that is the thing under measurement. A table that predicted it would be
a model of the product, built by the same round that is changing the product.

P0's proof is therefore a property, checkable cell by cell against run #0 and
needing no predicted verdict:

1. **Distinguishability, stated as the property and not as a count.** Revision 4
   first wrote this as "every cell except the six named above", which is two
   different claims run together: the six are cells that stay unobservable
   **after the cure under P2's assigned values**, and what this property is about
   is which sides can be told apart **today**. Measured, those are not the same
   set and not the same size.

   The property is therefore derived rather than carried: **side B is written
   with the per-side magnitude wherever its key's declared shape admits a third
   value, and the exceptions are exactly the keys whose shape is `bool` or a
   closed set of words.** Run #1 over the whole of axis B: 415 cells took the
   per-side magnitude, and the 50 that did not carry a side-B leaf of `enabled`,
   `exclude-readonly`, `exclude-promoted-only`, `exclude-tests`,
   `exclude-data-classes`, `exclude-exceptions`, `flag-promoted-properties`
   (`bool`), or `scope`, `severity`, `mode`, `unused-directive-severity`,
   `unreachable-layer-severity` (a closed set). No leaf outside those two
   categories appears. Inside the cure's radius the exceptions are 23 cells — 18
   on `enabled` and 5 on `scope` — which is how many cells the two valueless
   forms reach, not a list anyone maintains.
2. **Monotonicity.** Axis B's defect count may rise or stay, never fall, and every
   newly red cell is one whose two sides were indistinguishable at a contested
   leaf on run #0. This follows from `Classifier::effectSurvives()`: at a leaf both
   sides move to the same literal, `both` carries that literal whichever key won,
   so both sides read as surviving — the branch is green regardless of the truth.
   Giving the sides different literals can only reveal a loss, never hide one.
   The one way it could fall is a `side_b` literal that is itself a product
   default, which would make its side inert and move the cell to NOT OBSERVABLE;
   `pairSide()`'s fallback is what forecloses it, by dropping such a side back to
   the canonical candidate. **This is an argument over the code, and run #1 is
   where it is checked** — the report states both, and a cell that goes from
   MISCOMPOSED to anything else falsifies P0.
3. **Axes A, C, D and E unchanged**, cell for cell.

### The duplicate, counted and reported

Nineteen `same-source` triples `(rule, key_a, key_b)` are judged under two kinds;
ten of them are defects on both, so **ten of axis B's eighty-one are second
copies of a document already counted**.

They are not deduplicated. `PairRow::key()` puts `kind` in the key deliberately —
its docblock records that removing it collapses nineteen rows onto thirteen and
that a divergence between two kinds "would have been silently averaged". A row's
promise belongs to its kind, and two kinds may promise different things about one
document.

**So requirement 1's "counted once" is not delivered, and the substitute is named
rather than passed off as it.** The defect count stays keyed by cell, because a
cell is a promise and two kinds may promise different things about one document.
What P0 adds is the count nothing keeps: the run reports axis B's **documents**
beside its cells, a document being `rule|key_a|key_b|source_scope`. No verdict, no
key and no exit code moves; the number that was silently a row count stops being
readable as a document count. A test over the ledger names the nineteen triples as
a literal list, so a triple that quietly gains or loses a kind is refused rather
than absorbed — the list is written out rather than derived, or the test rewrites
itself.

One consequence is carried rather than left implicit: `03-acceptance.md` reads 81
cells as 71 documents wherever it counts documents. `axis-b-rows.tsv` is **not**
re-keyed — measured, its 465 rows are 465 cells over 446 distinct documents, so
each of the nineteen occupies two rows there exactly as it occupies two cells in
the grid, and the two artefacts stay addressable by the same key.

### `axis-b-rows.tsv`, and what "re-derive" can honestly mean

The table has no generator (465 rows, no script in the tree names it), and four of
its twelve columns are predictions. P0 does not build a classifier simulator to
produce them — an oracle widened on faith is the failure this programme already
has a name for. The promise is narrowed instead:

- `today_verdict` and `today_coexistence` are **written from run #1 and the
  ledger** by a derive mode that is a write and not a check;
- `affected` and `assigned` are decisions and are carried forward, unchanged, as
  P2's input;
- `post_cure_under_today_value`, `post_cure_under_assigned` and
  `assigned_on_todays_product` stay predictions, marked as unverified, and run #3
  is what checks them.

The pre-P0 table is kept as the witness of what P0 changed.

### DoD

- Every `side_b` row's literal is refused by `Declarations` when it equals that
  form's canonical write or its alternate; a control proves each refusal fires.
- Properties 1-3 above hold on run #1, each reported with its number. Measured:
  415 of 465 sides took the per-side magnitude and every exception is a `bool` or
  a closed word set; axis B goes 81 to 111 with 354 green cells unmoved, 81 red
  cells unmoved and 30 green becoming red, none in the other direction; and all
  6753 cells outside axis B are identical to run #0.
- Axis B's document count is reported — 446 documents over 465 cells, 93
  defective over 111 — and the nineteen multi-kind triples are named by a test.
- `measurement/axis-b-rows.tsv` regenerated in its two measured columns by
  `scripts/promise-effect-axis-b-rows.php`, with the pre-P0 copy kept beside it as
  `measurement/axis-b-rows-before-p0.tsv`.
- `composer check` green as an aggregate.

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

**Files:** `promise-effect/promise-ledger.tsv`,
`docs/internal/plans/shorthand-scope/measurement/axis-b-mechanisms.tsv`,
`scripts/promise-effect/Ledger.php`, `scripts/promise-effect/Classifier.php`,
`scripts/promise-effect/Stand.php`, `scripts/promise-effect/tests/LedgerVocabularyTest.php`.

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
