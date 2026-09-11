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

| file                                                                   | what it is                                                                                                               | written by         |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ | ------------------ |
| `../docs/internal/plans/promise-effect/measurement/promise-ledger.tsv` | the promise — 1308 rows, frozen product of stage 01                                                                      | stage 01, a person |
| `forms.tsv`                                                            | the eight forms of a value and the literal each door is given                                                            | a person           |
| `axis-d-envelopes.tsv`                                                 | how a placeholder path outside `rules:` is actually written                                                              | a person           |
| `axis-d-observables.tsv`                                               | where a hit is visible per root, what it shadows, and what a hit is                                                      | a person           |
| `cli-root-flags.tsv`                                                   | which CLI flag writes which root, and what it shadows                                                                    | a person           |
| `axis-a-hits.tsv`                                                      | the option leaves whose canonical magnitude names nothing in the fixture, and what a hit is                              | a person           |
| `witness-envelopes.tsv`                                                | the seven producers whose subject does not exist under an empty document, and the smallest document that makes it exist  | a person           |
| `pair-kind-scope.tsv`                                                  | which source coordinates a pair row of each kind owes — the column `key-pairs.tsv` does not carry                        | a person           |
| `door-normalization.tsv`                                               | what each door hands the DECLARATION for each form — the dictionary the four sets are compared through                   | a person           |
| `floor.tsv`                                                            | the rows the stand is required to call defective                                                                         | a person           |
| `fixtures/probe/**`                                                    | the small project every process run is taken against — twenty files, every one of them there to make some producer speak | a person           |
| `../docs/internal/generated/promise-effect/verdicts.tsv`               | the verdict grid of the current tree                                                                                     | the stand          |
| `../docs/internal/generated/promise-effect/inputs.stamp.tsv`           | the sha256 of every input the grid was measured from                                                                     | the stand          |
| `../docs/internal/generated/promise-effect/observations-before/**`     | the frozen **raw** pre-cure observations                                                                                 | the stand          |

## The row key

`form|<door>|<path>|<form>` for axis A and D,
`pair|<rule>|<a>|<b>|<scope>|<kind>` for axis B. The form is part of the key:
one ledger row carries eight questions, and folding them into one verdict
would hide seven of them.

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

## The two probes

The triple `omitted / value / equivalent` is a question about the **form of a
value**, and it produces seven verdicts. A promise about **adjacency** is not
expressible by it: "a refusal was promised, the product composed" is neither
`INERT` nor `COLLAPSED`. So `kind=pair` rows get their own triple
`onlyA / onlyB / both` and their own two verdicts.

| verdict          | condition                                                   |
| ---------------- | ----------------------------------------------------------- |
| `OK`             | matched the promise **and** a reachability witness exists   |
| `INERT`          | `value == omitted` where the ledger promised an effect      |
| `COLLAPSED`      | `value` equals the canonical write of another form          |
| `REFUSES`        | exit 3 **and** the product's `Configuration error:` framing |
| `MALFORMED`      | a refusal without that framing, `exit 1`, a crash           |
| `NOT OBSERVABLE` | `omitted == equivalent`: this stand is not sensitive here   |
| `UNPROMISED`     | no ledger row: the grid grew and nobody said so             |
| `COEXISTENCE_OK` | `both` matched the ledger's `coexistence`                   |
| `MISCOMPOSED`    | `both` did something else                                   |

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
- **Anything about `cross-source` adjacency.** The 108 rows of that coordinate
  are deferred with axis C.
- **That `NOT OBSERVABLE` means the product is silent.** It means this fixture
  and this observable cannot tell a written value from an omitted one. Its share
  is reported as a number, not a footnote, because a growing share devalues the
  denominator. It stands at **1297 of 6289 cells, 20.6%** — axis A 1254 of 5280
  (23.8%), axis B none by construction, axis D 43 of 544 (7.9%) — and every one
  of them is accounted for below.
- **Element forms inside a list.** The row's axis is the form of the container.
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

## Where the remaining `NOT OBSERVABLE` share comes from

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
composer promise-effect:check      # 0 fresh, 1 drift or a red outcome on A or D (~200 s)
composer promise-effect:before     # re-judge the frozen raw observations (<1 s)
composer promise-effect:grid       # declarations, grid span, populations, fifth set (<1 s)
composer promise-effect:grid:check # the same, exit 1 on drift — this one is in `check:artifacts`
composer promise-effect:controls   # the stand's own controls (~4 s: two of them take process runs)
composer promise-effect:stability  # measure twice, demand the same text
php scripts/promise-effect.php --freeze-before --reason='…'   # retake the pre-cure shot (only on 6a833ab8)
php scripts/promise-effect.php --axis=A          # narrow a run; never evidence on its own
php scripts/promise-effect.php --axis=B --stability   # the cheap way to exercise the repeat
```

Axis B is measured and **not** cured in this round, so `MISCOMPOSED` travels
with a number and does not redden the run. Axes A and D own the exit code.

`promise-effect:before` re-judges the frozen raw observations with **today's**
classifier, so both halves of the before/after pair are judged by one rule.
Retaking the shot needs `--freeze-before` on `6a833ab8` **and** a `--reason=`;
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

## The controls

`composer promise-effect:controls` runs twenty cases in four groups: one
planting per verdict — all seven of the form and both of the pair — plus one
that edits the ledger rather than an observation, plus one that plants an exit
code back into the shape the stand used to read it as; two probe cases; three
cross-check cases over the two sides of the four sets; and one per population
against the guard. Every planting case must redden **its own** cell and no
other: the run diffs the whole outcome map against the unplanted baseline, so a
blanket breakage fails as loudly as one that does not bite.

The outcome compared is `VERDICT|defect`, never the label alone. The round
exists because a framed refusal of a promised form is a defect wearing an
innocent label, and a control blind to the defect column would be blind to
exactly the class being measured.

Ten cases recompute over the frozen raw observations instead of measuring
again — the snapshot holds every side the classifier reads — so that half of
the table costs under a second. What they prove is the rule from observation to
verdict.

Two further groups exist because that rule is not the whole stand:

- **probe controls** (`B1`, `B2`) address what happens *before* an observation
  is stored, which the frozen half is blind to by construction. `B1` takes the
  real framing pair — the only way to say "the fixture still answers unframed
  today" — and then plants an outcome into the pure judgement, so the control
  can go red without the product being broken. `B2` addresses the worker
  extraction directly, because the frozen half holds an md5 of the contaminated
  line and nothing recomputed over it can reach what that digest ate. It asserts
  three things, and the third is the one that matters: that the observation
  still *carries the worker number*, or an extraction returning a constant would
  pass the other two and observe nothing;
- **cross-check controls** (`C1`, `C2`, `C3`) plant into each side of the four
  sets, and `C3` is an absolute assertion rather than a differential one: a
  comparison that ignored the door normalization would produce the same
  before/after difference under any planting while being wrong everywhere, and
  no planting can see that. The declaration side is planted **in memory**, not
  into the copied tree: a copy resolves PSR-4 back through `vendor/` into the
  original `src/`, a false green this repository has already been bitten by, so
  the bound is stated — `C2` proves the comparison sees a changed declaration,
  not that it would see one changed in `src/`.

Before any planting the run checks two cheap things: that every declared cell
exists in the universe (exit 2 — a stale declaration is a different failure from
a case that does not bite), and that the frozen half still reproduces the
published grid cell for cell (exit 3 — a baseline that no longer is the grid
would prove something about a document nobody reads).

**The second of those is suspended while the input stamp is stale**, and the
reason is not convenience. The stamp covers the stand's own verdict-producing
code, so editing the classifier stales the grid by construction, and the
published verdicts were then produced by a rule that no longer exists. Refusing
there would make the controls unusable during exactly the work they guard — a
classifier cannot be fixed while its own controls demand the pre-fix grid. The
run says so in as many words and keeps going; the refusal returns the moment
`composer promise-effect` re-measures, which is the only thing that clears the
stamp. While it is suspended the controls prove the rule from observation to
verdict and say nothing about the published grid.

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
