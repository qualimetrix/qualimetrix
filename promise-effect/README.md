# The promise-effect oracle

What a recognised configuration key actually **does**, against what its name
and its documentation **promised** it would do.

The decisions, the claim boundary and the denominators live in
[`docs/internal/plans/promise-effect/`](../docs/internal/plans/promise-effect/) —
`00-overview.md` for the scope, `01-promise.md` for the ledger schema,
`02-oracle.md` for this stand. This file is the operating manual for the
artifacts in this directory.

## Why the input-door stand does not answer this

`composer input-doors` measures a **referential** miss: the value pointed at
nothing, and the question is whether the product said so. Here the value points
at something, silence is often lawful, and the defect is in the **effect**. The
whole of `rules:` is one door out of eighteen over there.

What is borrowed is the discipline, not the logic: the `observable` column, the
normalization of `finding-gate/normalization.tsv` as the single authority (no
second list is started here), raw observations stored rather than verdicts, and
**not knowing yields the worse verdict**.

## What is here

| file                                                                   | what it is                                                                                                                   | written by         |
| ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- | ------------------ |
| `../docs/internal/plans/promise-effect/measurement/promise-ledger.tsv` | the promise — 1308 rows, frozen product of stage 01                                                                          | stage 01, a person |
| `forms.tsv`                                                            | the eight forms of a value and the literal each door is given                                                                | a person           |
| `axis-d-envelopes.tsv`                                                 | how a placeholder path outside `rules:` is actually written                                                                  | a person           |
| `axis-d-observables.tsv`                                               | where a hit is visible per root, what it shadows, and what a hit is                                                          | a person           |
| `cli-root-flags.tsv`                                                   | which CLI flag writes which root, and what it shadows                                                                        | a person           |
| `axis-a-hits.tsv`                                                      | the option leaves whose canonical magnitude names nothing in the fixture, and what a hit is                                  | a person           |
| `witness-envelopes.tsv`                                                | the seven producers whose subject does not exist under an empty document, and the smallest document that makes it exist      | a person           |
| `pair-kind-scope.tsv`                                                  | which source coordinates a pair row of each kind owes — the column `key-pairs.tsv` does not carry                            | a person           |
| `door-normalization.tsv`                                               | what each door hands the DECLARATION for each form — the dictionary the four sets are compared through                       | a person           |
| `floor.tsv`                                                            | the rows the stand is required to call defective                                                                             | a person           |
| `run-declaration.tsv`                                                  | the axes the grid spans, which of them own the exit code, and the commit the pre-cure shot is taken on                       | a person           |
| `composition-magnitudes.tsv`                                           | the two distinguishable values axis C writes with, and the framework patterns and witnesses its second point uses            | a person           |
| `effect-magnitudes.tsv`                                                | the counter-default value axes B and E fall back to where the canonical one is what the product already does without the key | a person           |
| `../docs/internal/plans/promise-effect/measurement/key-pairs.tsv`      | stage 01's frozen pair enumeration — read by axis C for a triple's graduated partner and by axis E for its whole population  | stage 01, a person |
| `fixtures/probe/**`                                                    | the small project every process run is taken against — twenty files, every one of them there to make some producer speak     | a person           |
| `../docs/internal/generated/promise-effect/verdicts.tsv`               | the verdict grid of the current tree                                                                                         | the stand          |
| `../docs/internal/generated/promise-effect/inputs.stamp.tsv`           | the sha256 of every input the grid was measured from                                                                         | the stand          |
| `../docs/internal/generated/promise-effect/observations-before/**`     | the frozen **raw** pre-cure observations                                                                                     | the stand          |

## The row key

`form|<door>|<path>|<form>` for axis A and D,
`pair|<rule>|<a>|<b>|<scope>|<kind>` for axis B,
`composition|<kind>|<subject>|<low>|<high>|<scope>|<point>` for axis C, and
`neighbourhood|<rule>|<null key>|<neighbour>` for axis E. The form is part of
the key: one ledger row carries eight questions, and folding them into one
verdict would hide seven of them.

The **point** is part of the axis-C key for the same reason. A
`composition-path` row is asked twice — once of the options object, once of
the registry predicates the three framework keys are read through — and the
two answers can differ, because `RuleOptionsFactory::create()` drains those
keys before `fromArray()` ever runs. On today's tree all four framework cells
say so in their own words: *at optionsObject the same two writes are
indistinguishable*.

The **kind** is part of the pair key for the same reason and was added after
the fact. Without it nineteen `same-source` pairs collapsed onto thirteen row
keys — the same two keys of the same rule are a ledger row twice, once as
`2-same-name-top-vs-level` and once as `4-cross-level` — so the grid held 6289
cells over 6270 distinct keys. Their verdicts agree today, which is exactly
what made the collision invisible: the floor and every key-addressed control
named one of two cells and nothing could say which, and a divergence between
them would have been decided by whichever row the run happened to write last.
Adding `path` would have split eighteen of the nineteen;
`coupling.cbo|class.scope|scope` carries `path=(top)|class` on both sides and
only the kind tells those apart. The kind is read out of the ledger note's
trailing `kind=…; path=…` chunk — `key-pairs.tsv` is stage 01's frozen product
and is not edited here — and a pair row without one is a `LedgerError`, never
a row quietly rejoining its namesake.

## Four observation points, one vote

`warning: true -> 1` happens inside `fromArray()`; before the factory the value
is still `true`. So the stand keeps four points — the **door**'s own output, the
**merged document**, the **options object**, and the **report** — but only the
deepest one available votes. The shallower ones answer a different question,
*where* the form was lost, and that answer travels in `decided_by` as
`lost at <point>`.

Judging the door in its own right would call every unpromised form a defect
there: carrying the value is the door's job, and the refusal that was promised
is owed one layer down.

A CLI door does not pass through the merged document at all — its value travels
beside the document as an override — so `mergedDocument` is not a location a CLI
form can be lost at, and the stand does not name it as one.

## The four probes

The triple `omitted / value / equivalent` is a question about the **form of a
value**, and it produces seven verdicts. A promise about **adjacency** is not
expressible by it: "a refusal was promised, the product composed" is neither
`INERT` nor `COLLAPSED`. So `kind=pair` rows get their own triple
`onlyA / onlyB / both` and their own two verdicts.

Two more questions need two more probes, for the same reason. "Whose value
survived" is not "what form may this value take": axis C writes one path from
up to three layers and compares `onlyLow / onlyHigh / both`. And "what does
`~` do BESIDE a neighbour" is asked by neither — axes A and D write a key
alone, axis B writes two keys and never `~` — so axis E writes
`omitted / neighbour / nullAlone / both` into one document.

Axes B and E write a key with the canonical magnitude of its declared shape,
and fall back to the alternate of `effect-magnitudes.tsv` where that magnitude
turns out to be what the product does with the key ABSENT. Both axes compare
against an omitted side, so a write equal to it answers their question
vacuously; the fallback is taken on the measurement, never on the spelling of
the key, and a key no declared value can tell from an omitted one reads
`NOT OBSERVABLE`.

| verdict          | condition                                                                                  |
| ---------------- | ------------------------------------------------------------------------------------------ |
| `OK`             | matched the promise **and** a reachability witness exists                                  |
| `INERT`          | `value == omitted` where the ledger promised an effect                                     |
| `COLLAPSED`      | `value` equals the canonical write of another form                                         |
| `REFUSES`        | exit 3 **and** the product's `Configuration error:` framing                                |
| `MALFORMED`      | a refusal without that framing, `exit 1`, a crash                                          |
| `NOT OBSERVABLE` | `omitted == equivalent`: this stand is not sensitive here                                  |
| `UNPROMISED`     | no ledger row: the grid grew and nobody said so                                            |
| `COEXISTENCE_OK` | `both` matched the ledger's `coexistence`                                                  |
| `MISCOMPOSED`    | `both` did something else                                                                  |
| `NOT OBSERVABLE` | a side alone leaves the object as an omitted key does, so no survival could be asked of it |

Axis C, where the ledger's sixth column names `low`, `high`, `refuse` or
`unpromised`:

| verdict                | condition                                                                    |
| ---------------------- | ---------------------------------------------------------------------------- |
| `COMPOSED_AS_PROMISED` | `both` is the promised side, or a promised refusal actually refused          |
| `MISLAYERED`           | `both` is the other side                                                     |
| `LOST_SIBLING`         | a slot only one side wrote, and no higher layer disputes, is back to default |
| `FRANKENSTEIN`         | `both` was accepted and equals **neither** side leaf for leaf                |
| `COMPOSITION_REFUSED`  | `both` was refused where no carrier promised a refusal                       |
| `NOT OBSERVABLE`       | `onlyLow == onlyHigh`, a side is unwritable, or the row has no plan          |

The order of those questions carries as much as the questions do, and it is
the reverse of the obvious one: sibling loss is asked **before** winner
equality. A middle layer that evicts the lowest layer's exclusive slot leaves
a result identical to the highest layer's, leaf for leaf, so a winner-first
reading calls it a correct composition and the triples measure nothing. The
one thing that keeps that from swallowing the ordinary case is a fact no
observation carries — whether a higher layer rewrote every key the lowest one
wrote. Where both sides dispute the same key, the higher value replacing the
lower one **is** the promise being kept, and its leaf effect is
indistinguishable from a slot vanishing; measured on the framework point, a
stand that only looked at leaves turned four kept promises into four defects.

`FRANKENSTEIN` is a name and not a nicety. A list or a map merges recursively,
so `[a, b]` from below and `[c]` from above produce a value equal to neither
side; a stand comparing whole texts has nowhere to put that but "the other
side won". Comparison is leaf by leaf, sorted, and control case `CP6` reddens
if it ever stops being.

Axis E, whose claim is narrow and is about the **neighbour**: writing `~` must
be worth exactly as much as leaving the key out.

| verdict                    | condition                                                                  |
| -------------------------- | -------------------------------------------------------------------------- |
| `PRESENCE_NEUTRAL`         | `both == neighbour`: the `~` changed nothing                               |
| `PRESENCE_SWITCHED_BRANCH` | `both != neighbour`: the `~` changed how its neighbour is read             |
| `PRESENCE_REFUSED`         | `both` was refused while the neighbour alone was accepted                  |
| `NOT OBSERVABLE`           | the neighbour has no effect to lose, or `~` alone already moves the object |

That last gate hands a row to axis A rather than judging it here: a `~` that is
already not an omitted key on its own is a defect axis A owns, and counting it
again here would publish one defect as two.

### The label and the defect are two columns, not one

The seven labels say what was **observed**. Whether the observation contradicts
the promise is a second question, and `verdicts.tsv` answers it in its own
`defect` column. They come apart in one direction that matters: a framed
refusal of a form the ledger **promised** — `exclude_health: [complexity]`
refused, `architecture: ~` refused where the carrier says `~` means "take the
default" — reads `REFUSES` and is a defect. Folding it into the label would
have needed an eighth verdict; counting defects by label would have lost
exactly the class this round measures. The exit code and the floor read the
column, never the label.

`COLLAPSED` has a second, weaker branch, and it is labelled rather than hidden:
a value accepted where the ledger required a refusal, whose result matches
**no** declared coercion target, is still a collapse — the stand simply cannot
say into what. `decided_by` reads `target unnamed`. That is the worse verdict,
deliberately.

### The hit, and why a canonical write is sometimes not one

A probe without a hit is not a probe. `suppress_paths: [7331]` names nothing in
the fixture, so it changes nothing, so `omitted == equivalent` and the row reads
`NOT OBSERVABLE` — for a reason that belongs to the stand. Every root whose
values are paths, namespaces or rule names therefore declares a real hit in
`axis-d-observables.tsv`; those are the control values of
`measurement/non-rules-roots.tsv`, already known to move this fixture. The form
the hit stands for is unchanged — only the magnitude is.

Axis A needs the same thing and declares it in `axis-a-hits.tsv`. The three
framework keys never reach `fromArray()`, so the stand observes them by asking
`isPathExcluded('src/Sub/Helper.php')` and `isNamespaceExcluded('Probe\Sub')`;
a canonical `[7331]` answers neither, and all 54 `suppress-paths` rows — 432
cells — read `NOT OBSERVABLE` for the stand's reason. The hit is keyed on the
option **leaf**, because those three keys repeat under every producer and an
enumeration of 162 paths would be four statements written 162 times.

The hit replaces the magnitude in the reference form's own cell as well as in
`equivalent`, and that is a correction: substituting it only into `equivalent`
left the cell writing a magnitude that names nothing, so the stand read
"a list of a path that is not here excludes nothing" — correct behaviour — as
the `INERT` defect. Axis D had exactly that shape on `suppress_paths|list` and
`suppress_namespaces|list`, and both now read `OK`.

What the hit does **not** fix, said here rather than left to be discovered: the
other seven forms of a hit-carrying row still write 7331. `suppress-paths: 7331`
reading `INERT` cannot be split by this stand into "the int form was accepted
and dropped" and "the int form was coerced into a path that names nothing".

### `equivalent`, and where it comes from

`equivalent` is the canonical write of a **promised** form, spelled with a
different but ledger-equivalent key spelling (`snake = camel = kebab`, carrier
C3). The respelling is produced as text inside the stand and never asked of the
product: asking the product which spellings it treats alike would make the
equivalence a measurement of itself.

The magnitude every canonical write uses is `7331`. A canonical write that
happened to equal a rule's own default would make the sensitivity probe blind,
and every threshold default in this tree is far below it.

Where the ledger promises **no** form at all — every `unpromised` row — there is
nothing to be equivalent to. The stand then searches `int`, `bool`,
`string-nonnumber`, `list`, `map` in that order for one the door accepts and
that moves the observation, and uses it as the reference. Without the search
those rows would all read `NOT OBSERVABLE` by construction, which is a claim
about the stand dressed up as a claim about the product. The search is a
weaker basis than a declared equivalence, and it is confined to rows where a
declared one does not exist.

## The reachability witness is a producer name, not a class

`OK` requires evidence that the producer behind the path actually runs.
Keying that on the options class would be wrong three times over:
`TypeCoverageOptions` serves three rules, `ComputedMetricRuleOptions` eight, and
six `health.*` producers have no options class at all — while the hole the round
measured is rule-shaped. So the witness is **one process run per producer name**,
with only that producer enabled.

A producer that says nothing on this fixture is **not** evidence of a dead
producer. It is evidence that this stand cannot witness it, which is why the
verdict it blocks is `NOT OBSERVABLE` and not a defect.

**All 54 are witnessed.** They were not always: on the first shot 7 were, and
the other 47 said nothing because the fixture held five files and nothing in
them tripped a threshold. 47 of the 54 now speak against the fixture alone.
The remaining seven report on a **configuration section** — declared layers, a
defined computed metric, an `exclude:` entry that matched nothing — and have
nothing to report on when handed an empty document. Calling those unwitnessed
would state a property of the producer where the truth is a property of the
document, so each declares the smallest document that makes its subject exist,
in `witness-envelopes.tsv`. The document travels into the frozen raw
observations beside the outcome and reaches no probe but the witness.

One envelope has to enable a second rule beside the probed one:
`annotation.directive` only counts a directive against an **enabled** rule, and
the fixture's directive addresses `complexity.ccn`. That would make "the run
said something" true of the other rule's findings too, so that envelope
declares a `channel` and the witness counts only findings carrying it.

## The cache is not in the trusted chain

Both early measurements of the round ran with `--no-cache`, and that flag **does
not hold** in this tree — measured in the round, carried into `DEFERRED`. An
oracle that trusts a broken switch is not an oracle, whether or not the defect
was reported. So the flag is never used here. Every process probe:

1. names its cache directory **explicitly**, unique to the probe, so a run
   cannot quietly fall back to the default one;
2. deletes that directory before the run;
3. is checked afterwards — the named directory is absent or was created by this
   run, and the run is single: the probe's run directory is freshly
   materialized, so no file it wrote could have been read by it;
4. **fails**, rather than reports, when the default `.qmx-cache` appears: that
   is the product ignoring the directory it was given.

Point 3 on its own would be weaker than "the cache was not read" — it says
nothing about a different directory. Points 1 and 4 close that. The exit code is
taken from the process, never through a pipe.

Three units own the cache directory themselves (`cache`, `cache.dir`,
`cache.enabled`). The protocol cannot inject a directory into a probe whose
subject *is* that directory, so those probes get a fresh unique run directory
and no injection, and the appearance of the default directory is **recorded**
instead of failing. That exemption is named in `axis-d-envelopes.tsv`.

## The refusal framing is proved, not assumed

In process the stand sees an exception, not a frame, and `REFUSES` is defined
by the frame. So every run first takes two process controls: a value the product
refuses with `Configuration error:` and a value it refuses without one. If
either changes shape the stand exits 3 rather than reporting. Measured on
`6a833ab8`: `ConfigurationRefusal` is framed, a bare `InvalidArgumentException`
from a rule-option type check is **not** — exit 3 with no frame, which is the
`MALFORMED` class.

**The unframed half is mortal, and it has already died once.** It stood on
`--layer-violation-severity=true`, a rule-option alias, until the first cure
package framed that refusal — after which the stand exited 3 instead of
reporting, which is the control working. Its replacement is `--workers=-5`:
`ParallelConfigurationResolver` refuses a negative worker count with a bare
`InvalidArgumentException`, the console catches it as its **fallback** refusal,
and the run ends 3 with no frame. The subject was chosen to outlive the same
fate rather than to be merely different — it is a root flag judged by an
infrastructure resolver, so no rule-option key, spelling or registry, which is
the whole material of axis C, can reach it. When it is framed too, the stand
will say so the same way, and
`php scripts/enumerate-refusal-fallback.php` (130 bare throws today) is where
the next subject is found.

The probe withdraws this stand's own `--workers=0` invariant for that run: a
probe handing the product two values for one flag measures the parser, not the
resolver.

## Exit 2 is a value, not a refusal

In this product exit 2 means "findings at or above the gate". Reading it as an
unframed refusal made `fail_on|null` a `MALFORMED` defect on both doors — the
stand reporting its own reading of an exit code as a product defect.

The rule now lives in exactly one place, `Observation::ofMeasured()`, which both
the frozen half and every fresh measurement are read through: exit 2 is an
**accepted** observation whose comparable text is the exit code alone. The body
is dropped because the raw snapshot holds only a 400-byte head of the report,
and that head carries a timestamp — comparing bodies would make two runs of one
configuration differ for a reason that has nothing to do with the door.

Nothing is lost where it matters: exit 2 is reachable only where this stand
withdraws its own `--fail-on=none`, which is the two `fail_on` rows, and their
declared observable **is** the exit code. Anywhere else the row would lose
sensitivity and read `NOT OBSERVABLE`, which is the worse verdict, deliberately.

What the two cells say now, on the frozen half: `yaml|fail_on|null` is `OK`
(`~` behaves as an omitted key, both runs ending above the gate), and
`cli-root|fail_on|null` is the `INERT` defect — an empty `--fail-on=` accepted
and doing nothing where the ledger promised a refusal. That defect was
previously wearing the stand's own `MALFORMED` label.

## The worker decision, and what the observation used to carry

`parallel.workers` is observed in the debug log, and the whole JSON record used
to travel into the observation. Two of its fields move on their own:
`timestamp` is second-granular, and `projectRoot` is the probe's run directory —
which is keyed on the document the probe writes. So **every** logfile probe
produced a text unique by construction, `~` differed from an omitted key for a
reason that was the stand's, and `parallel.workers|null` read `COLLAPSED`
against a product that defaults correctly. The extraction now keeps the message
and the worker fields and nothing else, matched on the decoded record rather
than on the raw line, and it is kept as readable text: the defect lived inside
an md5 for a whole round.

**This one could not be applied to both halves, and the consequence is a
property of the snapshot.** The frozen half stores the digest, md5 is not
invertible, and no re-judging can reach what it ate. So the pre-cure half keeps
`yaml|parallel.workers|null = COLLAPSED` — one overstated defect — and its two
`int` cells read `OK` for the wrong reason. Sixteen cells of the frozen half,
the two `parallel.workers` rows, are the stand's; a before/after delta on them
says nothing about the product.

## What the oracle does not prove

- **That the ledger is right.** The stand judges the tree against the ledger. A
  ledger written to a known floor passes that floor and stays tautological
  outside it (`01-promise.md`).
- **Completeness over placeholder paths.** Each `<name>`, `<layer>` and `[*]` has
  exactly one hand-written instantiation, and the claim is about that one.
- **Anything about the `inline` door.** It is deferred whole, and out of the
  denominator: four doors in this round, not five.
- **Anything about `cross-source` adjacency.** Those 108 rows are pairs of two
  DIFFERENT keys written from two sources; axis C answers the other question —
  one path, several writers — and does not cover them.
- **The five SLOT pairs of axis C.** Of the twelve real writer pairs, seven are
  path pairs and five put `@qmx-threshold` on the upper side, which writes an
  already-built options slot rather than a configuration path: another
  mechanism, another oracle, not measured here.
- **What any SHIPPED preset actually writes.** Axis C writes its own preset
  files. The denominator claims the stage can carry any path, not that
  `strict`, `legacy` or `ci` writes one, and no cell here reads their contents.
- **Triples beyond the four forms.** The scenario count is `C(N+2,3) x 30` by
  formula; the grid exercises one triple per form T1-T4, with `L3` writing
  STRICTLY one slot of the pair. A triple whose `L3` writes both slots has
  nothing to lose and is green by construction, so it is outside the sample on
  purpose.
- **Whether a neighbour is right, on axis E.** The claim is only that writing
  `~` beside it changes nothing; a neighbour that was already wrong stays
  wrong, and a `~` whose own value already misbehaves is handed back to axis A
  rather than counted twice.
- **That `NOT OBSERVABLE` means the product is silent.** It means this fixture
  and this observable cannot tell a written value from an omitted one. Its share
  is reported as a number, not a footnote, because a growing share devalues the
  denominator. On the frozen pre-cure half it stands at **1297 of 6289 cells,
  20.6%** — axis A 1254 of 5280 (23.8%), axis B none by construction, axis D 43
  of 544 (7.9%) — and every one of them is accounted for below.
- **That a FALLING `NOT OBSERVABLE` share means the stand got less blind.** On
  the cured tree the share reads **286 of 6289, 4.5%**, and almost none of that
  is the stand seeing better. A refusal is judged BEFORE the sensitivity gate,
  so a product that starts refusing a written value moves the cell out of
  `NOT OBSERVABLE` without the stand becoming any more sensitive on that row.
  Measured, cell by cell: of the 1012 cells that left, **1003 sit on rows whose
  gate is still closed today** and became `REFUSES`; 9 are on rows whose gate
  genuinely opened, 8 of them one row (`code-smell.error-suppression`
  `.allowed-functions`) where the canonical `list` write is itself now refused,
  so `omitted != equivalent` — which is a gate opened by a refusal, not by a
  measured effect, and only 3 of those 8 cells are decided after the gate at
  all. The honest row-level statement is the one to quote: the sensitivity gate
  is closed on **166 rows before and 165 after, and 165 of them are the same
  rows**. Both shares above are the X18 pair, over its 6289 cells. The live
  grid is no longer that size — axis C and the neighbourhood coordinate added
  891 cells to it — and the number to quote for today is the one the run
  prints.
- **Element forms inside a list.** The row's axis is the form of the container.
- **Anything about a form its door cannot spell, or a key the canonical
  magnitude does not name.** Those cells read `NOT OBSERVABLE` by declaration,
  in `observability-limits.tsv`, and the reason travels with each one. The
  limit is the stand admitting it never asked; it is not evidence that the
  product is right there, and a later round that gives those forms a spelling
  a door can carry would have to measure them afresh.
- **That the `map` form is written in a way every consumer can fail on.** The
  canonical write is `{a: 7331}`, whose value is a number, and a string
  consumer filters a non-string out before it can break on it. The fourth
  package found by hand a real failure the stand was blind to for exactly this
  reason. The write is frozen input and cannot be changed without breaking the
  before/after pair, so it stays named: the 120 frozen axis-D defects
  **understate** the count by at least two, and that is a property of the
  snapshot, not of the product.
- **The two `parallel.workers` rows of the frozen half.** See the section on the
  worker decision above: sixteen cells there are the stand's own contamination,
  fixed for every future measurement and unreachable in the frozen one.
- **The 96 `computed_metrics` cells, across the pair.** Review found the axis-D
  envelope writing the metric name `probe_metric`, which
  `ComputedMetricDefinition::NAME_TEMPLATE` rejects before any leaf can show an
  effect: every probe on those twelve rows measured the NAME error. Two
  repairs, and they are different in kind. The classifier now refuses any cell
  whose value-side refusal is byte-identical to the omitted-side one — the
  refusal is the ENVELOPE's, and that rule holds on both halves and on every
  envelope, not only the caught one: **117 cells of the frozen half** read it,
  and the frozen defect count falls from **1793 to 1763**, because 30 of them
  had been counted as defects of the product. The envelope itself is also
  fixed, to `computed.probe-metric`, and that is a change of INPUT: those 96
  cells no longer measure the same write on the two halves. The price is
  bounded — **66 of the 96 already read `NOT OBSERVABLE`** on the frozen half,
  so what stops comparing is **30 cells, 4 of them defects**. Without the input
  fix those rows could only ever read `NOT OBSERVABLE`, which measures nothing.
  One of the 30 is a floor row, `computed_metrics.<name>.enabled|null`, and it
  is the reason `floor.tsv` now has a THIRD disposition: it left the floor
  because the probe that produced it was wrong, not because the product was
  shown repaired, and those two must not read alike. Measured through the
  stand's own process probe: under the old name all three sides — omitted,
  equivalent and `~` — were the SAME framed refusal of the name, so the
  pre-cure defect stood on a question never put about the leaf; under the
  corrected name the product accepts `~`, but `enabled: true` is the default,
  so the canonical write equals an omitted key and the sensitivity gate is
  blind. The row therefore says nothing about the product in either direction,
  which is exactly what a `withdrawn` column claims and a `cure` column would
  have falsified.

## Where the remaining `NOT OBSERVABLE` share comes from

Counted on the **frozen half**, which is where the number means what it says.
The share was **2205 of 6289, 35.1%** when the stand was first built. Two of
the three causes were the stand's, and both are fixed; the third is measured
and left, and the reason is that fixing it changes what the `bool` cell of 155
rows means and needs controls of its own.

| cause                                                                                                                                                                                | cells then | cells now |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------: | --------: |
| no reachability witness — 47 of 54 producers said nothing on a five-file fixture                                                                                                     | 476        | 0         |
| the `suppress-paths` probe named nothing in the fixture (axis A)                                                                                                                     | 432        | 0         |
| the canonical `bool` write is the default — `enabled: true` where `enabled` already defaults to true                                                                                 | 1202       | 1202      |
| `coupling.cbo.scope`, `.class.scope`, `circular-dependency.direct-as-error`, `error-suppression.allowed-functions`                                                                   | 50         | 50        |
| the ledger promises nothing about `~` here                                                                                                                                           | 2          | 2         |
| a valueless CLI flag carries no spelling for this form (axis D)                                                                                                                      | 14         | 14        |
| axis D, `omitted == equivalent` (14 rows; 2 `architecture.max_expanded_layers`, 3 `cache.enabled`, 1 `computed_metrics.<name>`, 7 `exclude`, 16 on the two CLI `--suppress-*` doors) | 29         | 29        |

**The bool-default blindness is the whole of what is left that could be
removed, and it is a stand defect, not a product one.** 130 `*.enabled` rows
and 25 `exclude*` rows promise `bool`; the canonical write is `true`; the
product's own default is `true`; so the canonical write is indistinguishable
from an omitted key and the sensitivity gate blinds all eight cells of the row,
including the ones that would otherwise read `INERT` or `COLLAPSED`. The same
hazard is already named for the magnitude 7331 in this file and was simply not
carried over to `bool`.

The cure of the round did NOT remove this blindness, and the live grid must not
be read as though it had. The 162 bool-blind rows carry 1283
`NOT OBSERVABLE` cells on the frozen half; on the cured tree 272 of them still
read `NOT OBSERVABLE` and 1011 read `REFUSES` — the same rows, the same
blindness, now standing behind a refusal that is judged first. 161 of the 162
rows still fail the sensitivity gate today.

The cure is a **counter-default write**: the row's `bool` cell and its
`equivalent` both write the value the product is not already at, with `1 -> 0`
as the comparand. It is not applied here for two reasons said plainly rather
than deferred: flipping only `equivalent` would make `value(bool) = true` read
`INERT` on 155 rows, which is the stand calling correct behaviour a defect; and
flipping both changes what the `bool` cell of 155 rows asks, which owes a
control of its own and would destroy the attribution this table exists to give
("how much did enriching the fixture remove?").

## Running it

```bash
composer promise-effect            # write the verdict grid and the input stamp (~200 s)
composer promise-effect:check      # 0 fresh, 1 drift or a red outcome on a blocking axis (~200 s)
composer promise-effect:before     # re-judge the frozen raw observations (<1 s)
composer promise-effect:grid       # declarations, grid span, populations, fifth set (<1 s)
composer promise-effect:grid:check # the same, exit 1 on drift — this one is in `check:artifacts`
composer promise-effect:controls   # the stand's own controls (~5 s: two of them take process runs)
composer promise-effect:stability  # measure twice, demand the same text
php scripts/promise-effect.php --freeze-before --reason='…'   # retake the pre-cure shot
php scripts/promise-effect.php --axis=A          # narrow a run; never evidence on its own
php scripts/promise-effect.php --axis=B --stability   # the cheap way to exercise the repeat
```

**A narrowed run still WRITES the grid**, with only the axes it measured in it.
That is a trap rather than a feature: the next `grid:check` — and every control
that reads the published grid — then judges a partial file. Follow any
`--axis=` run with a full one before trusting anything that reads
`verdicts.tsv`.

Which axes exist, which of them own the exit code, and which commit the
pre-cure shot belongs on are all read from `run-declaration.tsv`; the script
holds its own generator map against that list in **both** directions, so an
axis nobody produces and a generator nobody declares are each a refusal rather
than an axis that silently stops being measured. Today A and D are blocking.
B is measured and not cured — no owner holds a mandate over pair semantics —
and C and E are the subject the current round is measuring in order to cure
later, so their defects travel with a number and do not redden the run.

`promise-effect:before` re-judges the frozen raw observations with **today's**
classifier, so both halves of the before/after pair are judged by one rule.
Retaking the shot needs `--freeze-before` on the commit `run-declaration.tsv`
names **and** a `--reason=`;
the reason is written into `shot.txt` beside the commit and the date, because
the file is what the next reader of the frozen half has in front of them.
Editing the classifier is not a reason to retake, because it requires nothing
to be re-measured.

The shot has been retaken once, on **2026-09-11**, from
`6a833ab8fcd5004e964e32a59bfeb9978c39739c` — the same commit, the same product
code. The reason is the one `shot.txt` records: **the fixture changed and the
product did not.** 47 of the 54 producers had no reachability witness because
the five-file fixture tripped nothing, and the `suppress-*` probes named
nothing in it. The pre-cure half therefore still measures the pre-cure product;
it measures it against a fixture that can see the product speak.

The expensive stand lives **outside** `composer check`, beside
`composer input-doors`: it costs process runs — measured at about 200 s over
614 of them, against a `check:artifacts` threshold of roughly 40 s. It was
150 s before the fixture grew from five files to twenty, and that is what the
other 47 reachability witnesses cost.

What does go into `check:artifacts` is `composer promise-effect:grid:check`,
which takes no probe at all. It answers three narrower questions in under a
second: does the grid still span exactly the cells the ledger owes, does it
still carry every member of every population the code knows about, and was it
measured from the inputs now on disk. The four sets and the fifth set are
printed by the same command and judge nothing — they compare two declarations
and take no measurement, so a disagreement there is a finding to read, not an
exit code. The boundary is a statement, not a hedge
— a green grid check means the grid matches its declarations, never that it
matches the product's behaviour today. Only the expensive run says that.

Freshness is answered by `inputs.stamp.tsv`, written by the expensive run: the
sha256 of the ledger, every declaration table, the fixture and the stand's own
code. Editing the classifier therefore stales the grid, which is correct —
today's rule would no longer produce the published verdicts. The product is
deliberately **not** in the stamp: `src/` moves every day, and hashing it would
redden the aggregate on every commit while saying nothing about the verdicts.

## The observability limit: where the question was never put

A verdict is about the product only if the probe asked the product's own
question. 473 cells of the last measurement did not, and they had been counted
as product defects for two rounds. Two mechanisms, both measured:

**A CLI door has no syntax for three of the eight forms.** In a document the
quotes around `"7331"` are the language's own syntax and produce the string
7331; in `argv` they are six literal characters. Measured:

```
--rule-opt=complexity.ccn:callable.warning=7331    -> exit 0
--rule-opt=complexity.ccn:callable.warning="7331"  -> exit 3
```

So `int` and `string-number` are ONE spelling on a CLI door — which the
registry says in its own words, by promising both names of one write — and the
stand was distinguishing them by adding quotes, which measures a third thing.
`list` and `map` have no CLI spelling either: the brackets arrive as
characters. 174 cells of axis A read `REFUSES`-with-a-defect for this reason
alone, and 19 more read `COLLAPSED`.

**A generic magnitude answers about the domain, not about the form.**
`suppress-paths: 7331` excludes nothing because 7331 is not a file, and
`suppress_namespace_channels: {a: 7331}` is refused because `a` is not a
channel selector — not because a map is the wrong form there. This is the
discipline `axis-a-hits.tsv` already carries for the reference form, never
extended to the other seven. 216 `INERT` and 64 `REFUSES` defects are this.

`null` is deliberately **not** declared a limit on CLI doors: `--flag=` with
nothing after it is a write a user makes and the registry answers for it per
door, so the empty value is a distinct spelling, not an inexpressible form.

### Why a declaration and not a re-spelling

Re-spelling the probe is a change of INPUT. The frozen half is nailed to
`6a833ab8` and can never be re-measured, so the 238 cells under argument would
stop comparing across the pair — which is the one thing the before/after
acceptance rests on. A rule read by the CLASSIFIER applies to both halves by
construction, and the share moves in both together.

### The guard that keeps it from being a silencer

A limit covering a cell whose unrestricted verdict is `OK` is **refused**, on
both halves: `OK` means the effect was distinguishable and the producer
reachable, and a door that cannot express a form cannot have carried a lawful
effect through it. The stand computes both verdicts for every covered cell and
prints how many of them the limit would have deleted. It stands at **0**.

That rule alone only protects what is GREEN, and a table that can only eat a
DEFECT is the more dangerous half. Review found it doing exactly that, so three
more rules now stand beside it, all read by the classifier and therefore
applied to both halves:

- **No unpublished crash.** A `MALFORMED` cell — a crash, or a refusal without
  the product's framing — may not be covered unless the same `(door, path)`
  publishes `MALFORMED` on a form no limit covers. Being handed a value proves
  the write arrived and was mishandled, which no statement about the value's
  domain excuses. On the frozen half **246 covered cells are `MALFORMED`**, and
  every one of them is published on an uncovered form of its own row; the
  exception is what keeps the count honest rather than duplicated, and it is
  the loss named at the end of this section.
- **No coerced value under a value kind.** `generic-write-names-nothing` and
  `refusal-about-the-inner-key` both claim the answer is about the VALUE. A
  `COLLAPSED` cell is the product coercing the value, which is the form being
  acted on. Silence and a refusal are both left to those kinds deliberately:
  which of the two a key answers with is the product's choice of words, and
  this round's own cure moved several keys from one to the other.
- **The door kinds are read off the product.** `door-cannot-express … list` is
  false for a flag the check command declares `VALUE_IS_ARRAY`, because such a
  door writes a list by REPEATING the flag; and
  `stand-writes-one-where-the-door-repeats` is false for every other flag. The
  basis is `InputDefinition::getOption(…)->isArray()`, never a sentence in the
  table. A flag the definition does not know is a THIRD state, not a `false`,
  and is reported as a ledger row naming a door the command does not define.
  The reading is asked per door — `cli-root` answers for 14 flags and
  `cli-alias` for 80, and a door nothing was read about reddens even when the
  other door looks healthy — and the reading as a whole must distinguish, or a
  basis read off an empty definition would agree with anything.

**What the guard found the first time it ran, and what it did not.** The basis
rule refused **seven declarations**: the array-valued root flags (`--exclude`,
`--disable-rule`, `--only-rule`, `--suppress-path`, `--suppress-namespace`,
`--exclude-health` and the positional `paths`), whose row claimed a CLI door
has no list syntax at all when in truth it is the STAND that writes one flag
where the door repeats it. Those seven are now
`stand-writes-one-where-the-door-repeats`, no cell changed verdict, and what
was wrong was the statement — a statement nothing could contradict is the
failure this round exists to remove.

The **ten level-slot rows** were moved to `refusal-about-the-inner-key` **by
hand**, from the wording of their own reason, and no rule that ships here
reddens on them. They were caught by an intermediate rule — "each kind is held
to the verdict its reason predicts" — which was then **withdrawn**, because it
is false as a general rule: the cure of this very round moved several keys from
silence to a framed refusal, so a row whose kind predicted `INERT` on the
frozen half would have predicted wrongly on the cured one and reddened a
successful repair. What survives of it is the narrow, defensible half: a value
kind may not cover `COLLAPSED`. So of the two mistakes review found in this
table, one is now machine-checked and one is not — the second rests on reading
each row's reason, and nothing in the stand would notice a kind chosen wrongly
between the two value kinds.

### What the guard still cannot express

Under the two door kinds, `REFUSES` and `COLLAPSED` cannot be told apart from a
refusal of the form itself: the stand wrote characters the door took literally,
and the product's answer is about those characters. On the frozen half that is
**588 `REFUSES` and 30 `COLLAPSED` cells**. And the crash exception above is a
loss of granularity by design: a limit may cover a crash on one form while the
row publishes that crash on another, so **which form** crashes is not
guaranteed to be on the report, only **that the row does**.

### What it cost, in both halves at once

On the frozen half the limit covers **1507 cells**. `NOT OBSERVABLE` rises from
**1297 of 6289 (20.6%) to 2549 of 6289 (40.5%)**, and the defect count falls
from **2632 to 1793**. That is not the stand getting blinder — it is the same
blindness, named where it was previously being reported as the product's
behaviour. On the cured grid the same table covers 1513 cells, 473 of which
were counted as defects.

Held against the round's own reading of the remainder: of the 454 axis-A
defects called stand properties, **all 454 are**, and none is a product defect
in disguise — with one correction of attribution. The 64 `map` refusals are
54 `suppress-namespace-channels` and 10 level-slot rows (`complexity.ccn`
`.callable|map` and its siblings), where `{a: 7331}` names no option OF THAT
LEVEL; the family is the same, the count is not.

## The floor is two claims, not one

`floor.tsv` is a statement about the CLASSIFIER reading a known pre-cure tree:
`01-promise.md` says the snapshot **before**, read through the registry, must
call defective position 64, position 66 and the 21 rows of
`non-rules-roots.tsv`. It is judged where it means that — on the frozen half,
by `composer promise-effect:before`, which exits 1 when a row stops being
recognised.

Applied to the LIVE grid the same list said the wrong thing, and it said it for
a whole round: **a successful cure removes a defect**, so every row the round
repaired became a floor miss and the first cured measurement exited 1 with
21 of them. A floor that goes red when the work succeeds is not a floor.

The live grid is held to a different and stronger claim, declared per row in
the `cure` column:

- a row with no `cure` must **still** be a defect — the floor's original job;
- a row with a `cure` must **no longer** be one — the round's own claim about
  what it repaired, checked rather than asserted.

Both directions redden, and the second is why this is worth more than the floor
it replaces. A row that quietly stops being defective with nothing claiming it
was repaired used to hide inside a red floor; a row declared cured that is
still defective is a false claim about the round, which is worse. Today the
grid reads **2 rows still defective, 21 cured as declared**, and the cured ones
are printed by name with the commit whose own subject covers them.

## The controls

`composer promise-effect:controls` runs thirty-nine cases in five groups: one
planting per verdict — all seven of the form and both of the pair — plus one
that edits the ledger rather than an observation, plus one that plants an exit
code back into the shape the stand used to read it as; nine **judgement** cases
over the eight verdicts of axes C and E; twelve probe cases; three cross-check
cases over the two sides of the four sets; and one per population against the
guard. Every planting case must redden **its own** cell and no other: the run
diffs the whole outcome map against the unplanted baseline, so a blanket
breakage fails as loudly as one that does not bite.

**The cheap half was collected and never read, for a whole round.** The
coverage arithmetic — a case addressing a cell the run does not carry, a
verdict nothing plants, a population nothing guards — filled a list that was
then thrown away, and the exit 2 this file documents could not happen. That was
true of this stand from the round that wrote it, and it is the class this
programme exists to measure: a guard that cannot redden. The list is consulted
now, before a single planting runs.

A duplicate case id is refused beside it. `--only=` addresses a case by id, so
two cases sharing one are run together while the operator believes they have
narrowed to a single probe — which is exactly what happened twice while the
axis-C cases were being written, and is why the guard exists rather than being
argued for.

The outcome compared is `VERDICT|defect`, never the label alone. The round
exists because a framed refusal of a promised form is a defect wearing an
innocent label, and a control blind to the defect column would be blind to
exactly the class being measured.

Ten cases recompute over the frozen raw observations instead of measuring
again — the snapshot holds every side the classifier reads — so that half of
the table costs under a second. What they prove is the rule from observation to
verdict.

Two further groups exist because that rule is not the whole stand:

- **stand controls** (`B1`, `B2`, `N1`–`N5`, `S1`, `F1`, `F2`) address what the
  verdict cases cannot reach. `N1`–`N5` are the five directions of the
  observability limit: a covered cell that gets judged anyway reddens (`N1`); a
  limit planted on the YAML door over a cell whose effect the stand observes
  reddens (`N2`); a limit over a crash the row publishes nowhere reddens, and
  the same crash stops being a conflict once an uncovered form of that row does
  publish it (`N3` — both halves, or the case would pass against a guard that
  simply refused every crash); a value-domain limit over a coerced value
  reddens (`N4`); and both door kinds are refused against the door definition
  in either direction (`N5`). `S1` is the same shape for the P1 file set: a
  hand-typed path in one of its constants that no file stands at is named, and
  the real constants name none — that guard was blind on two paths for a whole
  round, and `array_intersect` over a string no file carries can never
  intersect anything. `F1` and `F2` are the two halves of the floor. `B1` and `B2`
  address what happens *before* an observation is stored, which the frozen half
  is blind to by construction. `B1` takes the
  real framing pair — the only way to say "the fixture still answers unframed
  today" — and then plants an outcome into the pure judgement, so the control
  can go red without the product being broken. `B2` addresses the worker
  extraction directly, because the frozen half holds an md5 of the contaminated
  line and nothing recomputed over it can reach what that digest ate. It asserts
  three things, and the third is the one that matters: that the observation
  still *carries the worker number*, or an extraction returning a constant would
  pass the other two and observe nothing;
- **judgement controls** (`CP1`–`CP6`, `NB1`–`NB3`) plant into the axis-C and
  axis-E rules themselves. They cannot be planted the way the verdict cases
  are: the frozen half predates both axes, so there is no stored side to edit —
  and two of them are not about an observation at all but about the
  **comparison**. Each carries a fixture read twice, a baseline and a planting
  that differ in exactly one observation, and must move from one declared
  verdict to another; a case whose planting changed nothing would otherwise
  pass by agreeing with itself. `CP4` and `CP2` are the two substitutions
  `03-grid.md` asks for by name — whole texts instead of leaves renames
  `FRANKENSTEIN` into `MISLAYERED`, and winner equality asked before sibling
  loss renames `LOST_SIBLING` into `COMPOSED_AS_PROMISED`. `CP4` only
  *demonstrates* the first, because the renaming branch is reachable only
  through the control; `CP6` is what actually bites the run, through a fixture
  whose leaves match the promised side in a different order — it reddens the
  moment the production comparison stops being leaf-based. `PL1` sits in the
  stand group and covers the one decision no fixture can reach: whether the
  plan says a higher layer rewrote every key the lowest one wrote, true for a
  two-writer path and false for a triple, refused in both directions;
- **cross-check controls** (`C1`, `C2`, `C3`) plant into each side of the four
  sets, and `C3` is an absolute assertion rather than a differential one: a
  comparison that ignored the door normalization would produce the same
  before/after difference under any planting while being wrong everywhere, and
  no planting can see that. The declaration side is planted **in memory**, not
  into the copied tree: a copy resolves PSR-4 back through `vendor/` into the
  original `src/`, a false green this repository has already been bitten by, so
  the bound is stated — `C2` proves the comparison sees a changed declaration,
  not that it would see one changed in `src/`.

Before any planting the run checks that every declared cell exists in the
universe (exit 2 — a stale declaration is a different failure from a case that
does not bite).

A second refusal used to stand beside it — the frozen half had to reproduce the
published grid cell for cell — and it is **retired**, for the same reason the
floor moved halves. It held only because both documents were measured on
`6a833ab8`; the round has since cured the product, so the published grid is the
cured tree and the frozen half is the pre-cure one, and they differ by
thousands of cells because that is what a cure looks like. Suspending it while
the input stamp is stale, which is what the first version of this package did,
only postpones the refusal to the next measurement. What the demand protected —
"the cases plant into the document a reader sees" — is carried by the
stale-declaration refusal and by the floor case `F1`; what is lost is named in
the script: nothing notices if the frozen half stops matching a published
rendering of ITSELF, because no such rendering is published.

## The population guard

Four populations, all counted from code rather than from prose: producer names,
`RuleOptionsInterface` implementations, the positions of `config-paths.tsv` and
the `same-source` pairs of `key-pairs.tsv`. The guard reddens on a member the
grid does not carry.

Producer names and options classes have two enumerators and the guard takes
their **union**: the container is authoritative — it is what the product runs —
and a source scan is the one a control can plant into, since no configurator
registers a file dropped into a copied tree. A disagreement therefore enlarges
the population rather than shrinking it.

Two registries are deliberately **not** consulted, because the round by axis C
is entitled to delete them: `RuleThresholdKeyGroupRegistry` and
`RuleOptionsRegistry`. The consequence is named rather than hidden — a missing
or wrong entry in the group registry is invisible to this guard, and finding it
is that round's work.

`key-pairs.tsv` carries no `source_scope` column although 02 §5 says the
mapping is fixed by one, and the artifact is stage 01's frozen product. The
mapping is therefore **declared** in `promise-effect/pair-kind-scope.tsv` — one
row per pair kind, naming the coordinates a row of that kind owes — and
`promise-effect:grid` holds the ledger to it row by row: for every
`key-pairs.tsv` row the ledger carries one pair row at each declared scope, and
no pair row exists that no `key-pairs.tsv` row accounts for. A kind the table
does not classify is a refusal, not a default.

Before this the coordinate was reconstructed inside the guard from the identity
465 + 108 = 573. That arithmetic is true of more than one interpretation: a
different reading of which kinds are two-coordinate would have moved the
guard's population while the sum still added up. The declaration is checkable
where the sum was not.

## The four sets: the registry against the declaration

`promise-effect:grid` prints them, and they are the round's central evidence.
Two authors were kept apart to make them evidence at all: the registry was
written by a package forbidden to read the declaration, because two authors who
consult each other agree by construction. The comparison consults both, after
both were written.

Since the first cure package the declaration carries a **form**
(`RuleOptionShape`), not just a key, and the registry carries `promised_forms`
in the eight names of 02 §5. Bridging the two vocabularies is
`promise-effect/door-normalization.tsv` — a declared table, not code — which
says what value each door hands the declaration for each form. The shape is
then asked about that value in its own words, through `matches()`.

**The formulation, and it is not the naive one:**

> the declaration, composed with its door's declared normalization, must accept
> exactly the `promised_forms` of **that door's** registry row.

The naive comparison — "the declared form against `promised_forms`" — reports a
disagreement on `string-number` for every CLI row and on `null` for every YAML
row of the same key, 205 paths at once, and both halves would be the
comparison's fault. `rules.design.dit.warning` is the worked example and the
`C3` control: one declaration, `integer()->orNull()`, serves three doors;
YAML `warning: "5"` is a string and is refused, while `5` on a CLI door is the
only way to type a number at all and is folded back into one before the
declaration ever sees it.

Two more rules the walk forces, each stated because a comparison that skipped
it would argue with a declaration the product does not consult:

- **A level slot is not judged by a shape.** `RuleOptionKeyRecognition` branches
  on the slot before asking the parent's declaration, accepts `null` outright,
  refuses a non-array, and hands the keys inside to the slot's own declaration —
  which the depth-2 registry rows are about. So a slot row's declaration is "a
  block of that level's options, or null".
- **The container, not the element.** A `listOf(nonEmptyText())` accepts the
  `list` FORM even though it refuses the canonical list of the magnitude 7331.
  The row's axis is the container, as this file says two sections above, so the
  container is offered filled with each scalar of the same door and one
  accepted filling is enough.

The sets, and the split that carries the hazard:

| set                                          | what it means                                                                                   |
| -------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `ledger ∩ declaration`                       | a form both name                                                                                |
| `declaration \ ledger`, **WIDER**            | the declaration accepts a form nothing promised — a false green that moved into the declaration |
| `declaration \ ledger`, **WIDER, unopposed** | the same, on a row whose `promised_forms` is empty: the registry never made a claim there       |
| `declaration \ ledger`, **DEEPER**           | a declared key the registry's denominator never reached — a number, not an argument             |
| `ledger \ declaration`                       | promised and not accepted, listed row by row                                                    |

Three kinds of row stand outside the comparison rather than being folded into
agreement, because folding them would let a key with no declared form count as
declared: a **framework key** whose form no declaration states (the two
namespace keys — `suppress-paths` is compared, against
`RuleOptionKeyRecognition`'s own shape, which is where the round's boundary puts
the declaration side of the three), a key **answered by the class itself**, and
a path **no registered producer owns**.

`status` is printed as three counters and never netted out: a `DECIDED` row
compares this round's decision with itself, so agreement there is not
independent evidence, and which rows carry independent weight is the reader's
question rather than this printer's. Only axis-A form rows have a declaration
side at all, so the counters cover those and no others — the roots outside
`rules:` are read by resolvers that declare no key set, and inventing one for
them would be the comparison agreeing with itself.

**A WIDER line is not by itself a product defect, and must not be read as one.**
It says the declared FORM admits a value the registry does not promise. What the
product does with that value afterwards is a different question, and the two
come apart in both directions — measured, not assumed:
`--rule-opt=maintainability.mi:threshold=7331.9` is accepted and analysed, so
there the declaration and the behaviour agree with each other and disagree with
the registry; `--layer-violation-severity=` is refused, framed, by a
value-domain check that runs after the form was admitted. The grid is what
measures behaviour. These sets measure two declarations against each other, and
that is their whole claim.

## The fifth set

`consumer \ declaration` — key literals a reading body reads that the
declaration behind it does not declare. The four sets cannot see such a key: it
is absent from the declaration and therefore from the denominator of axis A as
well.

It used to be printed and **not** counted, for two reasons that are both gone.
117 of the inventory's 455 form-deciding sites recorded the form of *any* key
rather than a named one; those are now dispositioned one row each in
`measurement/form-deciding-sites-resolution.tsv`, and the set reads that file —
17 rows name keys against an `acceptedOptionKeys()` declaration, 5 name
framework keys, 95 cannot produce a member at all and say why. And the set was
narrower than its own definition in two independent places: a
`method !== 'fromArray'` filter and a match against SHORT class names. Of 22
comparable rows the stand reached 11, with three readers-on-behalf-of-a-rule
(`LcomCollectionConfigurationResolver`, `RuleOptionsFactory`,
`ChannelExclusionKeyValidator`) falling out as "unresolvable" even with the
first filter widened. A site is now resolved through its FILE PATH, and every
reading body counts, not only a factory's: `unresolvable` went 44 → 0 and the
compared sites 107 → 185.

Two things a reader of the inventory will meet and should not be caught by:

- the filters `in_factory=yes` and `method=fromArray` select **different**
  elevens whose intersection is empty. The line this printer used to carry,
  "inside fromArray()", counted the second and never the factory; it is replaced
  by a count in a unit the set uses — sites whose class carries a declaration;
- the declaration side is a **union**: `acceptedOptionKeys()` plus the framework
  keys of `RuleOptionKeyRecognition`. No options class declares `suppress-paths`
  and none ever will, so comparing those against `acceptedOptionKeys()` alone
  would manufacture members that name nothing wrong. That is a decision about
  what the product promises, taken by the round, not a fact read off the code.

The set holds **six** members, before the widening and after it: `warning` and
`error` read at the top level of `ComplexityOptions`,
`CognitiveComplexityOptions` and `NpathComplexityOptions`. They are `DEFERRED`
rather than cured, and the price is stated: the flat branch lives in two
incompatible semantics, and the ban on mixing `threshold` with `warning`/`error`
is a dispute between carriers that this round does not settle. Declaring those
keys would settle it by accident.

Two kinds of inventory cell are resolved rather than guessed at, and a third is
refused: an ALL-CAPS token is looked up as a constant on `RuleOptionKey` and
then on the class, a comma cell is treated as spellings of **one** key (so
`enabled,ENABLED` is the key and the constant naming it, not two keys), and a
cell naming a local PHP variable is reported as an unresolved spelling instead
of being enrolled as a key nobody could type.
