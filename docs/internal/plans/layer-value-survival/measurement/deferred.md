# What this round deferred, and the number that made it a deferral

Each entry names what was measured, where the table is, and what the method does
not see. The point is that the round which picks one up begins with the table
rather than with reconnaissance — the pattern ADR 0055 used to hand this round
its own subject.

## Axis F — container form × CONTENT form

**Not deferred for cost. Deferred because its population is wrong as first
built, and building the right one is a measurement design task.**

`axis-f-positions.tsv` enumerates the configuration positions where the product
reads a LIST whose elements it then interprets (24) and a MAP whose KEYS it then
interprets (10). `axis-f-known-rows.tsv` holds the 24 rows of the remainder table
this axis would have to cover, and a first population built as position × 8
canonical forms (272 cells) does contain all 24.

**The measurement that refuses that population.** `Yaml::parse()` over the eight
canonical forms in KEY position:

| form as written | what reaches the product        |
| --------------- | ------------------------------- |
| `~`             | parser refuses                  |
| `true`          | parser refuses                  |
| `7331.9`        | parser refuses                  |
| `[7331]`        | parser refuses                  |
| `{a: 7331}`     | parser refuses                  |
| `7331`          | integer key `7331`              |
| `"7331"`        | **the same** integer key `7331` |
| `abc`           | string key `abc`                |

Three outcomes, not eight, and two forms collapse into one before the product is
reached. A probe writing eight would record five false refusals and one false
collapse per position — it would measure Symfony's parser, not this product. The
map side of the population has to be built from forms the parser DISTINGUISHES,
and the CLI door has to be measured separately because its syntax path differs.

**The stand cost, measured separately.** Ten guards react to a new axis. Three
fail loudly and immediately (`assertAxesHaveGenerators`, `Ledger::load()`'s closed
`kind` list, the SPAN check through `Stand::expectedKeys()` — in two places).
Two stay **silent forever** unless hand-extended: `promise-effect:controls`'
coverage arithmetic is two hand-written verdict arrays, and `Stand::before()` has
a closed three-branch structure that would print an empty `axis F (before)`
section and exit 0. Silence is worse than red, and closing it is part of the
axis, not a follow-up.

Blind spot of the position enumeration: `RuleOptionShape::mapOf` has zero use
sites, so the map positions were found by hand through `ConfigSchema` roots and
their validators rather than by one mechanism; `ConfigDataNormalizer` was not read
line by line; tests were not swept.

## Axis B — 82 cells, 4 mechanisms

`axis-b-mechanisms.tsv`. 81 of the 82 cells are one design pattern copy-pasted
into five sibling options classes: a top-level branch (`enabled: false`, or a flat
shorthand) returns early and discards any nested `class:`/`callable:`/`namespace:`
block written beside it. The fourth mechanism is a single cell and is flagged by
its own enumerator as probably a fixture artifact — `severity` alone is already
refused for every magnitude the stand tries — and is cheap to settle with one
direct probe before it is treated as a product defect at all.

**The premise this corrects.** Axis B was recorded as blocked on the rule layer
having no single notion of "channel boundary", citing
`docs/internal/plans/channel-identity-substrate.md`. That document is about
finding identity — `(ruleName, violationCode)` across suppression, threshold,
selector and exclusion consumers — and contains no mention of the axis, of pairs,
or of coexistence. X17's recorded reason was that no owner held a mandate over
pair semantics: a governance gap. Nothing technical blocks the fix.

**Confirmed independently, after the round's code was written.** The external
reviewer of the implementation raised exactly this as its one HIGH finding: a
lower layer's flat `threshold` sends the options class into its flat branch, and
a `class:`/`callable:` block written by a HIGHER layer is discarded. Measured to
be neither introduced nor worsened here — the axis-B arithmetic is unchanged for
it, 20 + 25 + 36 before and after — but it is the sharpest statement of why the
question has to be decided whole: the layer order is honoured by the merge and
then thrown away by the branch the options class takes afterwards.

**One of the four mechanisms turned out not to be a product defect at all.** M4,
the single `architecture.layer-violation` cell, was flagged by its own enumerator
as probably an artifact of the magnitude the stand writes rather than a
composition bug, and left unverified. It disappeared during this round without
anyone aiming at it: declaring that key's closed word set gave the stand a
magnitude it could write, and the cell went green. 82 = 20 + 25 + 36 + 1 before,
81 = 20 + 25 + 36 after — the arithmetic is the confirmation.

**Why it is still deferred.** What a top-level shorthand MEANS beside the nested
block it is shorthand for is one undecided question — the same question as floor
row 96 and as the three `LONE_THRESHOLD` paths this round deliberately leaves
alone. It is decided once, for the family, by a round that owns it; bolting it
onto the round that changes the merge path underneath it would decide it twice.

Blind spot: single enumerator, no independent second witness; the fourth
mechanism is unverified by a product probe.

## The ten STAND rows, and the retake they need

`stand-ten.tsv`. Five rows are one defect (the only observation point of axis D
does not show the chosen cache directory or a value-less flag, so every form
yields one observation); four are the envelope writing a layer named `Domain`,
which the product's name regex rejects before the leaf is reached; one is a
canonical magnitude (`abc`) that is not a formula and needs a declared hit.

**A correction to the framing this round was handed.** The envelope defect was
described as six top-level bases. Six lines do carry the un-lowered literal, but
only `patterns` and `suffix` produce a STAND defect from it — for `attributes`,
`implements`, `extends` and `match` the ledger promises only `list`, so refusing
a scalar string is correct product behaviour and was never in the remainder table.
The minimal fix is two lines and clears four cells.

**Why deferred.** Every one of these is an INPUT edit, so each costs a re-take of
the frozen half, and a re-take must precede any cure in the same round or the
witness is destroyed. The cure this round exists for needs no re-take once the
floor can judge a post-snapshot cure, so paying for one buys stand hygiene at the
price of the comparability the cure's proof rests on.

## Bool blindness on axis A

The canonical `bool` write is `true`, which equals the product default for every
`*.enabled` key, so the probe cannot tell a written key from an omitted one.
Cured on axis B by choosing the magnitude against the observed default through
`promise-effect/effect-magnitudes.tsv`; not cured on axis A because the magnitude
there is the frozen `forms.tsv`, and the same trick means a new declaration file
plus roughly 60-110 lines of control cases.

**The number, corrected.** This was handed to the round as 1202 cells. That is a
retired snapshot's figure, taken on a 6289-cell grid before a series of refusal-
framing cures; `promise-effect/README.md` warns three times against quoting it.
Today's comparable figure is **155 rows / 226 cells**.

Blind spot: 226 counts what reads `NOT OBSERVABLE` today, not what would be blind
if the refusal framing did not take priority — that second quantity needs its own
run and was not taken.

## The cross-layer `~` cell, cured but unprobed

ADR 0056 recorded it as a cell belonging to neither axis: the composition probe
writes magnitudes into layers and never `~`, and the adjacency coordinate writes
`~` only inside one document. This round CURED it — measured before and after,
`measurement/observations.md` §4 and §5 — but no axis watches it, so a future
change could undo the cure without a cell going red.

It cannot be given a ledger row here: ledger coverage is checked in both
directions, and a row nothing produces reddens the span guard. It belongs with
the wider composition sample ADR 0056 already listed — `~` across layers, path
pairs on a list and a map, `#[CliAlias]` in the `L3` position, triples by the
stage-03 selection rule — with one difference now: the probe that gets built
there will be confirming a repair rather than finding a defect.

## `survives()` infers "this layer wrote here" from a difference, not from a fact

The check compares each leaf against the value the omitted probe produced: a
layer counts as having written a slot when its value differs from that. A layer
writing a value that HAPPENS to equal the compiled default is therefore
invisible to it, and the leaf would be judged against the wrong layer.

Not reachable today, and the reason is a declaration rather than a check:
`promise-effect/composition-magnitudes.tsv` picks 4211/4212/8623/8624 and says
they are "far above every threshold default in this tree". Nothing verifies that
sentence. A rule whose default rose above a magnitude, or a magnitude chosen
carelessly by a later round, would reopen it silently.

Closing it properly needs a real "this layer wrote this slot" signal in the
observation rather than an inference from the text, which reaches into the probe
and the in-process runner. Raised by the review of this round's implementation
and left open with its reason, rather than patched with a wider inference that
would be wrong in a different way.

## Promise values with no branch to award them

`promised_survival = survives` sat in the ledger for four rows with no branch in
the classifier able to award it. It was invisible while those rows were caught
earlier by `LOST_SIBLING`, and became a defect the moment the product stopped
losing the slot. Nothing in the stand enumerates the promise values the ledger
carries against the branches that award them, so other values may be in the same
state. The enumeration is cheap — both sets are closed and both are in the
repository — and it is not taken here only because this round found the instance
rather than the class.

## The gate cannot see what this round changed

`composer gate -- --reference=1210b037` ran GREEN, and the honest reading is not
"the change is safe" but "the corpus never exercises it".

Read off the corpus rather than assumed: no case passes `--preset`, so no case
has three layers; the one case that combines a config file with `--rule-opt`
(`applied-threshold`) writes BOTH halves of every band from the command line
(`warning=2` and `error=2`), so no half is ever left for a lower layer to
supply. The cured path — a higher layer rewriting one half of a band — is not
in the corpus at all.

**What that costs:** a regression of this round's cure passes the gate GREEN.
The safety net that exists to prove a change altered no finding is blind to the
one thing this change altered.

**Closed by the `layered-threshold` corpus case**, added straight after the round
merged. Its fixture is configured across all three layers — a preset writing the
graduated pair, a config file replacing both halves with `threshold: 5`, and a
`--rule-opt` rewriting `warning` alone — and its `Tangled` subject reports
`error` at the shorthand's 5 only while the half the command line did not
rewrite still carries the middle layer's value. Measured against the pre-cure
product at `1210b037`: **24 surfaces go red**, the text surface showing
`error ... threshold of 5` against `warning ... threshold of 2` and the exit code
moving 2 against 0.

One correction to what this entry first said. It called for the difference to be
declared in `finding-gate/declared-delta.tsv`, which would have been right only
had the case landed inside the round. Added after the cure merged, the case
compares a cured product against a cured reference and there is no difference to
declare; what it buys is forward protection, and the 24 red surfaces above are
the proof that the protection is real rather than a case that merely runs.

## Two docblocks that promise what their code does not do

Found while measuring, fixed only where this round's own stages touch them.

- `scripts/promise-effect.php`, floor block: claims "a cure that names a commit
  the snapshot does not contain is the lie this catches". `Floor::cureMisses()`
  has no such check. **Repaired by stage 1**, which also explains why a commit
  check cannot be written.
- `promise-effect/README.md`: says `inputs.stamp.tsv` hashes "every declaration
  table"; `Stamp::FILES` deliberately omits `pair-kind-scope.tsv` and
  `door-normalization.tsv`. The real criterion is "produces a cell of
  `verdicts.tsv`". **Not repaired here** — it belongs with the round that adds an
  axis, because that round has to know which of its own tables the stamp should
  and should not carry.

## The alternative cure this round did not take

Moving the threshold-group declaration onto each options class's own
`acceptedOptionKeys()` / `RuleOptionKeySet` and deleting
`RuleThresholdKeyGroupRegistry` outright. Reachability was checked rather than
assumed: `RuleNameReader::read()` plus the static
`RuleDefinitionInterface::getOptionsClass()` give a rule-name → options-class map
with no rule instance and no dependency cycle; `RuleOptionsFactory::create()`
already holds the class at its merge site; `FindingConfigurationResolver` is a
registered DI service and could take the lookup at its own. It is the better
long-term shape. It was not taken because it moves roughly 22 options classes and
a static resolver's shape in the same change as the behavioural cure four floor
rows are waiting on.
