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
composer promise-effect:controls   # the stand's own controls (<1 s)
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
measured from the inputs now on disk. The boundary is a statement, not a hedge
— a green grid check means the grid matches its declarations, never that it
matches the product's behaviour today. Only the expensive run says that.

Freshness is answered by `inputs.stamp.tsv`, written by the expensive run: the
sha256 of the ledger, every declaration table, the fixture and the stand's own
code. Editing the classifier therefore stales the grid, which is correct —
today's rule would no longer produce the published verdicts. The product is
deliberately **not** in the stamp: `src/` moves every day, and hashing it would
redden the aggregate on every commit while saying nothing about the verdicts.

## The controls

`composer promise-effect:controls` plants one breakage per verdict — all seven
of the form and both of the pair — plus one that edits the ledger rather than
an observation, plus one per population against the guard. Every case must
redden **its own** cell and no other: the run diffs the whole outcome map
against the unplanted baseline, so a blanket breakage fails as loudly as one
that does not bite.

The outcome compared is `VERDICT|defect`, never the label alone. The round
exists because a framed refusal of a promised form is a defect wearing an
innocent label, and a control blind to the defect column would be blind to
exactly the class being measured.

Nine cases recompute over the frozen raw observations instead of measuring
again — the snapshot holds every side the classifier reads — so the whole table
costs under a second. What they prove is the rule from observation to verdict.
They do **not** prove that the process probes measure the right thing; the
floor of `floor.tsv` is what holds that side.

Before any planting the run checks two cheap things and refuses rather than
reports: that every declared cell exists in the universe (exit 2, a stale
declaration is a different failure from a case that does not bite), and that
the frozen half still reproduces the published grid cell for cell (exit 3 — a
baseline that no longer is the grid would prove something about a document
nobody reads).

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

## The fifth set, and why it is printed rather than counted

`promise-effect:grid` prints `consumer \ declaration` — key literals a
`fromArray()` body reads that the class's own `acceptedOptionKeys()` does not
declare. The four sets of stage 01 cannot see such a key: it is absent from the
declaration and therefore from the denominator of axis A as well.

It is **not evidence**, and the print says so. 117 of the inventory's 455
form-deciding sites are recorded as deciding the form of *any* key rather than
a named one, so the set is computed over the remainder and stays silent about
the rest by construction. Resolving those sites is the first action of the cure
package (03 §P1).

Two kinds of inventory cell are resolved rather than guessed at, and a third is
refused: an ALL-CAPS token is looked up as a constant on `RuleOptionKey` and
then on the class, a comma cell is treated as spellings of **one** key (so
`enabled,ENABLED` is the key and the constant naming it, not two keys), and a
cell naming a local PHP variable is reported as an unresolved spelling instead
of being enrolled as a key nobody could type.
