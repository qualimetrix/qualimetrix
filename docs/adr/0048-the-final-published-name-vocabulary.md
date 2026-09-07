# 0048. The Final Published Name Vocabulary

**Date:** 2026-09-07
**Status:** Accepted

## Context

The product publishes names a consumer writes down and stores: channel codes in
a baseline and in `@qmx-ignore`, metric keys in a computed-metric formula and in
a dashboard column, producer rule names in `only_rules:` and `--disable-rule`,
option keys in `--rule-opt` and in `rules:`, flag aliases on a CI command line.
Successive passes fixed the *grammar* of some of them — ADR 0035 made every
metric key `family.metric` in kebab, ADR 0028 moved one rule between families,
ADR 0032 gave the six health dimensions producer names, ADR 0047 retired three
`exclude_*` spellings — and each pass ended with a list of names nobody had
ruled on. The recurring cost is not any single wrong word. It is that after
every pass the question *"is that all?"* still had content, so the next rename
had to be planned as if the vocabulary were unknown, and consumers were asked to
migrate more than once.

This ADR ends that. It rules on **every name the product publishes, in every
role it publishes it**, in one document, so that the rename step that follows is
the last one this vocabulary needs and can be released as a single breaking
change.

It rules on names. It does not describe how the rename is performed; that is the
following stage's plan. It renames nothing by itself.

### The enumeration this ADR is written from

Six artifacts hold the enumeration and its measurements; this ADR cites them by
path and does not restate them:

| artifact                                                                        | what it holds                                                                      |
| ------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `docs/internal/plans/rule-vocabulary/X11-final-vocabulary/decision-table.tsv`   | **the decision of record**: 314 rows, one per name-in-a-role, each with its reason |
| `.../decision-table-population.tsv`                                             | the population and the oracle script that produced it                              |
| `.../enumeration-universe-reconciliation.md`                                    | what the universe is, reconciled between a declaration and an observation witness  |
| `.../enumeration-channel-naming.tsv`, `.../enumeration-metric-key-naming.tsv`   | naming properties per channel and per metric key                                   |
| `.../enumeration-runtime-witness.tsv`                                           | the same universe seen only through printed output                                 |
| `.../enumeration-migration-surfaces.tsv`, `.../enumeration-gate-map-shapes.tsv` | what a consumer's written name reaches, and what a rename costs to state           |
| `.../followups/naming-questions-answered.md`                                    | the readings, with `file:line`, behind the individual verdicts below               |

The decision table is a **dated snapshot of decisions**, not a checked artifact:
nothing regenerates a `reason`, and the rename step makes its `name` column
historical. Where this ADR and the table disagree in future, this ADR is the
decision and the table is the working paper it was written from.

## Decision

### 1. The universe is five closed sets and one named open one

| set                     | size | oracle                                                         |
| ----------------------- | ---: | -------------------------------------------------------------- |
| channel codes           | 58   | `ChannelIdentityInterface::channels()`                         |
| metric keys             | 82   | `MetricName` constants                                         |
| producer rule names     | 51   | `ChannelIdentityInterface::ruleNames()`                        |
| rule option keys        | 43   | the keys `RuleOptionsFactory` accepts for a rule               |
| CLI flag aliases        | 80   | the flags generated from `#[CliAlias]`                         |
| **user computed names** | open | a consumer's own `computed_metrics:` — **not enumerable here** |

**The channel oracle is `channels()`, and this matters more than it looks.**
`ChannelDeclarationRegistryInterface::staticDeclarations()` returns 52 and says
in its own docblock that it "excludes the run-time `computed.*` / `health.*`
family by construction": it exists to compare against a tracked fixture, not to
enumerate the universe. A completeness check built on it silently omits six
product-shipped names — `health.complexity`, `health.cohesion`,
`health.coupling`, `health.typing`, `health.maintainability`, `health.overall` —
each of which is *simultaneously a channel code and a metric key*. This ADR's
own plan made that mistake first and caught it by measuring the product's
printed output against its declarations
(`enumeration-universe-reconciliation.md`). The next reader who builds a
completeness check must build it on `channels()`, after a configuration document
has been resolved and the computed-metric catalog configured; before that,
`channels()` itself answers only the 52.

Six of the 82+58 names carry a **key half no oracle emits**: `MetricName`
declares no `health` constant, yet `--format=metrics` prints
`"health.complexity"` and the other five as metric-key JSON keys, and
`ComputedMetricDefaults` addresses them in formulas. They are accounted for by
this ADR and are invisible to the residue check below, which is therefore a
lower bound rather than an exact identity.

**The open set is ruled on by shape, never by name.** A consumer's
`computed_metrics:` entries become both channel codes and metric keys that this
repository never sees. The product imposes on them, and continues to impose,
exactly the ADR 0035 grammar — `family.metric`, lower-case kebab — enforced by
the name validator; and one shared producer, `computed`, per ADR 0032, because a
user metric's name is not known when the build-time validator runs. No other
constraint is added and none is removed.

**What is deliberately outside the universe**, so that "is that all?" has no
content for a reader who knows these strings exist:

- **Nested option keys.** `max_warning` / `max_error`, and the `warning` /
  `error` inside a per-level map, are not rows: `RuleOptionsFactory`'s unknown-key
  check validates **top-level keys only**, so these are not merely
  warn-and-default, they are unchecked. They follow the spelling rule of §6
  going forward and are not renamed.
- **The static `check` options.** `--format`, `--preset`, `--baseline`,
  `--workers` and the other hand-written flags of `CheckCommandDefinition` are a
  CLI surface, governed by `docs/internal/CLI_CONVENTIONS.md`, not by the rule
  vocabulary. The 80 aliases in the table are the flags generated from
  `#[CliAlias]`.
- **Value vocabularies.** `mode: strict`, `--fail-on=warning`, the `severity:`
  words, the `scope:` values and the `format:suppressed` report values are
  enumerable published strings, but they are values, not names of a rule, a
  channel or a metric.
- **The internal data-bag family** — see §7.

### 2. The naming rule, stated so it decides a name that does not exist yet

A channel is named in these clauses, in order. The first clause that applies
decides. The two family clauses come first, and deliberately: a computed-metric
channel is declared at run time from a formula and has no catalog key **by
construction**, so reading its declaration can never tell it apart from clause 5
— the family of the producer emitting it settles the name before any declaration
is read.

1. **It is one of the six built-in health dimensions.** `health.<dimension>`,
   per §5.
2. **It is a user-defined computed metric.** Shape only, per §1: the ADR 0035
   grammar and nothing else.

Otherwise, read **the channel's own declaration**:

3. **It declares `judging()` with exactly one judged base key, and no sibling
   channel judges that key.** The channel code *is* that key, spelled
   identically. An aggregate-shaped suffix on the judged key (`.sum`, `.total`)
   is stripped for this comparison, and its presence is not a spelling
   divergence: `size.class-count` judging `size.class-count.sum` and
   `code-smell.unused-private` judging `code-smell.unused-private.total` are
   conforming.
4. **Two channels judge the same key.** Neither may take it, because a code is
   unique. Each names its own judgment instead. This is the
   `code-smell.constructor-overinjection` / `code-smell.long-parameter-list`
   pair, both judging `code-smell.parameter-count`.
5. **It declares a magnitude but no single catalog key** — a conjunction of two
   thresholds, a tally of matched criteria, a cycle's class count, a block's line
   count. There is no magnitude to name; the channel names the verdict it reaches
   or the fact it reports. `design.data-class`, `design.god-class`,
   `architecture.circular-dependency`, `architecture.unassigned-class`.
6. **It declares an occurrence.** The channel names the fact the reader is told
   about — the mechanism, the annotation, the defect. Inside the architecture
   diagnostics the established shape is `<adjective>-<noun>` naming what is
   wrong: `unassigned-class`, `unreachable-layer`, `empty-template`,
   `potential-shadow`, `pending-layer-matched`.

Two things this rule is *not*. It is not a preference for subjects over
judgments: the product publishes 82 metric keys and, since ADR 0046, the
relation "this channel judges that metric" is data on the declaration, printed
by `bin/qmx rules`. The ESLint convention, where `max-lines` must carry its
judgment because there is no metric layer to name, does not transfer to a
product that has one. And it is not free: a subject-form name alone does not
tell a report's reader that a threshold was exceeded. That is held to be carried
by the severity, the message text and the judged metric that travel with the
finding — a judgement, accepted openly, not a measurement.

The rule was measured before it was adopted, over the 22 channels whose
declaration carries a judged key: **14** already spell their judged metric
exactly, **2** differ only by an aggregate-shaped suffix (clause 1), **3** are
the channels clauses 2 and 3 do not reach, and exactly **3** differ in spelling
over one magnitude. Those three are the renames of §3.

**One channel is a deliberate exception at the declaration, not at the name.**
`coupling.class-rank` judges a project-size-scaled threshold in its body and
prints it, but declares `occurrence` on purpose, because ADR 0017 point 5 holds
that a project-normalised rank can change meaning while the channel does not, so
a baseline entry bound to it would over-accept. Its name is the metric it
judges, so it conforms under both readings; the divergence is a settled design
decision, and the rule above reads the declaration, so the case cannot recur by
accident.

### 3. The ten renames

Ten rows of the decision table: five channel codes, the four producer rule names
that are the same declared literal as their channel, and one metric key. Full
reasons and `file:line` sit in the `reason` column of `decision-table.tsv`.

| old spelling                   | new spelling                | role(s)                                 |
| ------------------------------ | --------------------------- | --------------------------------------- |
| `complexity.cyclomatic`        | `complexity.ccn`            | channel code **and** producer rule name |
| `maintainability.index`        | `maintainability.mi`        | channel code **and** producer rule name |
| `design.inheritance`           | `design.dit`                | channel code **and** producer rule name |
| `duplication.code-duplication` | `duplication.clone`         | channel code **and** producer rule name |
| `architecture.coverage`        | `architecture.coverage-gap` | channel code only                       |
| `design.type-coverage.pct`     | `design.type-coverage.all`  | metric key                              |

**The first three are clause 1 applied.** Each channel judges one key and spells
it differently: `complexity.ccn`, `maintainability.mi`, `design.dit`. The
direction is **channel to metric**, never the reverse, for a reason about cost
rather than taste: a metric key is what a consumer writes inside
`m["…"]`, whose failure mode is a refused run, and what a stored
dashboard column is keyed on. Precedent agrees — the abbreviation is already the
*channel* spelling in `cohesion.lcom`, `complexity.wmc`, `coupling.cbo` and
`design.noc`. `design.dit` additionally repairs a split inside one family:
`design.noc` judges the other inheritance magnitude and is already named after
the measure, so one group spelled one magnitude by its measure and the other by
its subject. These three close the `ccn`/`cyclomatic`, `mi`/`index` and
`dit`/`inheritance` word pairs that
`docs/internal/plans/rule-vocabulary/FOLLOWUPS.md:119-136` recorded on
2026-08-28 as "acceptable now, worth closing"; the corresponding **metric keys
are kept**.

**`duplication.code-duplication` → `duplication.clone`.** It is the only
tautology among the 52 static codes — the leaf repeats its own group, so it
carries no information — and neither reading it invites is what the rule does:
it publishes no duplication ratio (its number is one block's line count, with no
catalog metric behind it) and its unit is one duplicated block with its copies
listed. `clone` is the standard term of the subject area, and this vocabulary is
already technical in the same way (`ccn`, `lcom`, `dit`, `wmc`). The rejected
alternative was `duplication.repeated-block`: readable without domain knowledge,
but longer, and it still describes the unit instead of naming it. The accepted
risk is stated rather than hidden: PHP spells object copying `clone`, so a
reader may pause once, and the `duplication.` segment resolves it immediately.

**`architecture.coverage` → `architecture.coverage-gap`.** In this product
"coverage" means a judged proportion — `design.type-coverage.param` prints
`Parameter type coverage is 0.0% (minimum: 50.0%)` in the same run. This channel
publishes no metric value, no threshold and no ratio; it takes its severity from
an `ignore`/`warn`/`error` configuration mode, and its own source says it
"reports a mistake in the configuration rather than debt in the code". A reader
who transfers the meaning of `coverage` from one to the other is wrong about all
three. The new leaf names the defect, as every sibling diagnostic in the group
does, and keeps the recognisable word so the channel stays findable. It has **no
producer of its own spelling** — it is emitted by
`architecture.layer-violation`, which is kept — so it is a channel-code move
alone.

**`design.type-coverage.pct` → `design.type-coverage.all`.** The leaf named a
*unit* where its three siblings name an *area*. Re-verified for this decision:
`.param`, `.return` and `.property` are each already a percentage over their own
area, and `.pct` is the same unit computed over the three areas combined. So
`pct` said the one thing all four keys share and hid the one thing that
distinguishes this one. `all` names that. It collides with nothing: no
`MetricName` constant ends in `.all`, and `all` is not one of the seven
`AggregationStrategy` suffixes. The rejected alternative was `overall`, on the
`health.overall` precedent; it reads as a health-score word rather than an
extent-of-measurement word. It is the **only metric-key rename of the step, and
the only move that reaches a consumer's formula** — where a stale key is refused
rather than defaulted; on the report surfaces it publishes into (JSON, metrics,
HTML, a stored dashboard column) it is as silent as the five channel moves. Its
cost is stated rather than discovered: `health.typing`'s shipped default formula
reads
`m["design.type-coverage.pct"]` (`ComputedMetricDefaults.php:85`), so the
product's own defaults move with it, and every consumer formula and metric hint
keyed on the old spelling must move too.

**The producer/channel cascade.** A producer rule name spelled identically to
its channel code *by one declared constant* — the rule keys its channel
declarations on `self::NAME` and the emitted finding carries that same constant
as its code — has no decision of its own: it follows its channel. That is why
four of the ten renames are producers. They are the other half of four moves,
not four more moves. Splitting a pair would be a deliberate new
producer-≠-channel divergence, and this ADR does not take one.

### 4. Why the other 304 names stay

By group rule, with every exception named.

**Channel codes (53 kept).** Each was tested against §2 individually, using the
declared shape plus a reading of the rule body where the two disagree — not by
classifying the name's grammar.

- The 25 kept occurrence channels name the fact reported and conform to clause 4.
- The 17 magnitude channels whose judged key they already spell exactly, plus
  the 2 that differ only by an aggregate-shaped suffix, conform to clause 1.
- The 3 kept magnitude channels with no catalog key conform to clause 3, and the
  6 `health.*` to clause 5.
- Named exceptions, kept with the reason stated rather than the anomaly hidden:
  - `coupling.class-rank` — deliberate occurrence declaration, §2.
  - `code-smell.constructor-overinjection`, `code-smell.long-parameter-list` —
    clause 2. See §7 for the behaviour question they raise.
  - `design.data-class` (a conjunction of `woc` and `wmc` plus five exclusions)
    and `design.god-class` (a tally of matched criteria) — clause 3. Naming one
    by a magnitude and not the other would split a pair that behaves alike.
  - `architecture.circular-dependency` and `architecture.unassigned-class` —
    clause 3; both declare a magnitude with no catalog key, chosen so a count
    can be ratcheted down.
  - `code-smell.unreachable-code` — the channel and its judged key are already
    one string; its default warning threshold of 1 makes the threshold a
    presence test, so it behaves as the occurrence its name describes. What
    stays open is whether a presence test should declare a magnitude — a
    question about the declaration, not the name.
  - `code-smell.unused-private` — fires on any nonzero total with a fixed
    severity, and every finding of a class carries the class-wide total; the
    name describes the per-member fact the reader is told.
  - `code-smell.error-suppression` — the residual ambiguity is in English only
    ("error suppression" reads as a verdict as readily as it reads as PHP's own
    name for the `@` operator), and the behaviour resolves it. An English
    ambiguity the behaviour resolves does not buy a rename.
  - `size.method-count` — conforming under clause 1; what is wrong with it is a
    behaviour-and-documentation question, §7.

**Metric keys (81 kept).** The ADR 0035 grammar holds for every one:
`family.metric` in lower-case kebab, where the family is the subject rather than
the producing collector. Named exceptions:

- `complexity.ccn`, `maintainability.mi`, `design.dit` — kept **because** their
  channels move to them (§3).
- `size.method-count` and its `.public` / `.protected` / `.private` siblings —
  kept, with the overstatement recorded: they exclude getters and setters, §7.

**Producer rule names (47 kept).** 36 are spelled identically to their one
channel code and carry that channel's kept verdict; 8 more are the unchanged
half of a channel/producer pair. Three are named individually:

- `annotation.directive` — its four channels are all published under their own
  identity, none spelled like it. `directive` names the entity the rule
  examines, which is what all four findings are about.
- `architecture.layer-violation` — producer of six channels, five under their
  own names. It states the one judgment it is primarily about and matches its
  own channel of that spelling; the diagnostics keep their own names, which is
  what makes the producer/channel split legible.
- `computed` — the only single-segment name in any set, and settled by ADR 0032:
  every user-defined computed metric keeps one shared producer, because its name
  is not known when the build-time validator runs. ADR 0033 then counts it among
  the producers whose display family is the first segment of the name — here the
  whole name.

**Rule option keys (43) and CLI flag aliases (80) — all kept**, see §6.

### 5. The names that do two jobs with one string

**This is deliberate identity, not a collision to be removed.**

Today 18 channel codes are spelled identically to a metric key; after the three
clause-1 renames of §3 there are **21**. That is the naming rule doing its job:
clause 1 *produces* the identity. ADR 0035 already permits a metric and the rule
checking it to be the same string on purpose and records the two costs of it — a
literal guard that can no longer tell them apart, and a key map that must not
touch prose. Those costs stand; nothing here adds a third.

**The six `health.*` names are a metric key and a channel code at once, and stay
that way.** The form `health.<dimension>` is settled twice and is not reopened:
ADR 0001 introduced the six spellings with the feature, and ADR 0032 made each
one a producer name "named exactly as its one channel is", on a build-time
constraint rather than a taste. Three further facts belong on the record because
nothing in the tree states them:

- They are **run-time-declared** channels, absent from `staticDeclarations()` by
  construction. §1 says why that matters.
- The `health.` prefix is load-bearing beyond the name: ADR 0033 derives a
  producer's display family — the group heading and the `--group` value in
  `bin/qmx rules` — from the first dot-separated segment, so renaming the prefix
  moves those by construction.
- Their cost is not zero even in-tree: this repository's own ratchet carries
  `health.cohesion` and `health.typing` entries, and consumers address all six
  in formulas, where the spelling has already spread.

### 6. Keeps that are decisions, not deferrals

**Aggregation suffixes — `sum`, `avg`, `max`, `min`, `count`, `p95`, `p5` —
keep.** Not "not yet": there is no shape in which such a step could be stated.
The finding gate reads the strategy list from both trees and refuses to run when
they disagree, because forward translation expands a metric-key row over the
strategies of the tree it is applied to; a step that renames a strategy moves the
published spelling of every aggregated metric at once and stops the comparison
before its first row is read. Building a declaration whose unit is the strategy
costs more than `avg → average` returns. The suffixes are not rows of any set,
so nothing in this ADR goes stale if that judgement is revisited later.

**`health.<dimension>` — keep**, §5.

**All 43 rule option keys and all 80 CLI flag aliases — keep, including the 27
declared in camelCase and the 5 in snake_case** (`maxCycleSize`,
`lcomThreshold`, `classLocThreshold`, `min_tokens`, `min_lines`, …), in a
product whose configuration is otherwise kebab. The reason is the failure mode,
measured: a renamed option key produces **one stderr `[WARNING]` and the run
continues with the default value**. A consumer's analysis silently changes
result while their CI stays green. That is worse than any of the four other
migration classes, and worse than an inconsistent spelling. Every deviating
spelling is named in its own row of the decision table rather than hidden behind
the verdict.

**The spelling rule from now on**, which the kept names do not all satisfy and
every new name must: a rule option key is written in lower-case kebab, and a CLI
alias is `<rule-word>-<option-word>` in lower-case kebab. `ConfigKeySpelling`
already folds a declared camelCase or snake_case key to that canonical form, so
the rule describes the spelling a consumer writes; what stays inconsistent is
the *declared* spelling in the Options class, and it stays because renaming it
would move the accepted key with it.

### 7. Knowingly out of scope, each with its measurement

None of these is deferred silently; each is a question this ADR is the wrong
instrument for.

- **Making the option-key refusal loud.** `warn-and-default` is the reason 123
  names are kept. Changing it to a refusal is a decision about a *mechanism*, and
  it would make the kept spellings renameable — but it is not made here, because
  it changes what a consumer's existing configuration does, independently of any
  name. Recorded with its measurement in the plan's follow-ups.
- **The internal camelCase/snake_case data-bag family.** `codeSmell.{$type}`,
  `security.{$type}`, `identicalSubExpression.{$type}` and two standalone
  literals are written with `MetricBag::withEntry()` and read with `entries()`,
  which address the bag's `data` side — a namespace distinct from the map every
  published metric key comes from. Measured twice: nothing in `src/Reporting/`
  reads that side at all, and across seven runs and four formats no key
  containing an uppercase letter or an underscore was ever printed. They are
  **not published**, so they are not part of this vocabulary. They are also
  entangled with X10's freeze — `$type` is the frozen occurrence discriminator,
  while the `codeSmell.` prefix is not — so tidying the convention is not a
  cosmetic edit and must not be done as part of a rename.
- **Two questions about behaviour that no choice of name answers.** Both were
  measured, and this ADR deliberately declines to settle either by picking a
  word:
  - **The parameter-count double report.** `LongParameterListRule` does not
    exclude `__construct`, so one 8-parameter non-VO constructor raises
    `code-smell.constructor-overinjection` (default threshold 8) *and*
    `code-smell.long-parameter-list` (default 6) on the same declaration, at the
    same line. They are not two disjoint situations of one metric: one is a
    subset of the other with a looser threshold pair, reported twice. Whether
    that is intended layering or noise is the owner's question about behaviour.
    Renaming either channel would encode an answer that has not been given.
  - **`size.method-count` counts fewer methods than its name and the website
    promise.** Measured: a class with `getA`, `setA`, `doWork` yields
    `size.method-count = 1` against `size.method-count.total = 3`, and
    `size.method-count.public = 1` against three public methods — getters and
    setters are excluded. `src/Analysis/Evidence/Size/README.md:219` documents
    the exclusion correctly; `website/docs/rules/size.md:20` tells a user it
    "Counts the number of methods in a class", which is wrong. The sibling
    family inverts the convention — `size.property-count` is bare *and*
    inclusive — so bare-versus-`.total` means opposite things inside one group.
    Either the page is corrected or the definition is; a spelling expresses
    neither.

### 8. The completeness claim, and the command that checks it

**Claim.** The union of the five oracles, taken as `name` + `role` pairs, minus
the names this ADR accounts for, is empty.

The names this ADR accounts for are the 314 rows of `decision-table.tsv`, plus
the six `health.*` metric-key halves of §1 that no oracle emits, plus the open
`computed_metrics:` family ruled by shape. Run, from a checkout with
dependencies installed:

```bash
# 1. Write the oracle script quoted verbatim at
#    docs/internal/plans/rule-vocabulary/X11-final-vocabulary/decision-table-population.tsv:49-125
#    to oracles.php at the repository root, then boot it from an EMPTY working
#    directory so it sees the shipped defaults and not this repository's qmx.yaml.
WORKDIR="$(mktemp -d)"; QMX_CWD="$WORKDIR" OUT=/tmp/raw.json php oracles.php
#    -> channels=58 rules=51 metrics=82 aliases=80 optionKeys=43 checkOptions=80

# 2. The union of the five oracles, as name+role pairs.
python3 -c "
import json,re
d=json.load(open('/tmp/raw.json')); out=[]
for n in d['channels']: out.append((n,'channel-code'))
for n in d['metrics']: out.append((n,'metric-key'))
for n in d['rules']: out.append((n,'producer-rule-name'))
kebab=lambda s: re.sub(r'(?<!^)(?=[A-Z])','-',s.replace('_','-')).lower()
for n in d['optionKeys']: out.append((kebab(n),'rule-option-key'))
for n in d['aliases']: out.append((n,'cli-flag-alias'))
print('\n'.join(sorted(a+'\t'+b for a,b in set(out))))
" > /tmp/universe.txt

# 3. The names this ADR accounts for.
cd docs/internal/plans/rule-vocabulary/X11-final-vocabulary
grep -v '^#' decision-table.tsv | tail -n +2 | awk -F'\t' '{print $1"\t"$3}' \
  | sort > /tmp/accounted.txt

# 4. The residue. Both must print nothing.
comm -23 /tmp/universe.txt /tmp/accounted.txt    # published but unaccounted
comm -13 /tmp/universe.txt /tmp/accounted.txt    # accounted but not published

# 5. oracles.php is scratch, not a tracked artifact.
rm -f oracles.php
```

Run on `2026-09-07` against this branch: both empty, 314 against 314.

**Two properties of the check a reader must know.** It is *one-sided by six*: the
`health.*` metric-key halves are accounted for and emitted by no oracle, so the
first `comm` is a lower bound on completeness, never an over-count. And after the
rename step it must be run against the **`proposed`** column (field 7) instead of
`name`, because `name` will then hold spellings the product no longer publishes.
That column was checked for collisions now: the 314 `proposed` + `role` pairs are
distinct, and none of the six new spellings already exists in its role.

## Consequences

- **The rename is now a plannable step with a fixed scope.** Ten moves, four of
  them one channel-and-producer pair each, one channel alone, one metric key. The
  step that follows plans packages against this list rather than against a guess,
  ships as one breaking release, and is the last rename this vocabulary needs.
- **The same rename reaches a consumer through three different failure modes**,
  and the release note must state all three rather than only the command that
  migrates a baseline. (The fourth class, `warn-and-default`, is the one §6
  refuses to rename into, so no move here lands in it.) A stale spelling in a stored SARIF `ruleId`, Checkstyle
  `source`, GitLab `check_name` or dashboard column is **silent** — no
  product-side detection covers any of them, and the five channel-code moves and
  the metric-key move all publish into them. A stale producer name in `rules:`,
  `only_rules` or `--disable-rule`, and a stale metric key inside an `m["…"]`
  formula, are **refused loudly**. A stale name in an inline `@qmx-ignore` or
  `@qmx-threshold` is **inert but detected**: it becomes an
  `annotation.unresolved-directive` finding, and the suppression it was
  performing is lost, so the finding it was hiding comes back.
- **The `bin/qmx baseline:rename-channels` command migrates exactly one
  surface** — a baseline file's `channel` field. It is not a general migration.
- **Identity between a channel code and a metric key becomes the norm, not an
  accident**: 21 pairs after the step, produced by the naming rule itself. ADR
  0035's two recorded costs of that identity apply to the three new pairs.
- **The freeze survives the rename, on purpose.** `duplication.clone` moves its
  channel code while `CodeDuplicationRule`'s `OCCURRENCE_KIND` stays pinned to
  the old spelling, and the twelve `SMELL_TYPE` / `PATTERN_TYPE` discriminators —
  `error_suppression` among them — must not be tidied to follow any renamed
  channel. Reconciling a frozen discriminator with its channel's name silently
  re-binds every accepted baseline entry at every consumer. This is a standing
  invariant, not a step-local caution: a guard that compares the two would redden
  by design and be "fixed" by ending the freeze.
- **Two surfaces deliberately diverge after the step, and that is not a defect
  to repair later.** `complexity.ccn`'s CLI aliases stay `cyclomatic-warning` /
  `cyclomatic-error`, because §6 does not rename into a warn-and-default surface;
  and an option's addressing moves with its rule half while the option word
  stays, so `duplication.code-duplication:min-tokens` becomes
  `duplication.clone:min-tokens`.
- **`docs/internal/plans/rule-vocabulary/FOLLOWUPS.md:119-136` is closed by this
  ADR** — the three word pairs are decided, in favour of the metric word, by
  moving the channel. The aggregation-suffix entry at `:13` is converted from an
  open gate limitation into a settled `keep` with that limitation as its reason.
- **Anything not decided here is decided by §2, not by precedent.** A channel
  added after this ADR has one correct spelling, obtained by reading its own
  declaration. If a future channel makes the rule produce two defensible answers,
  that is an ADR-level decision, not a judgement call at the call site.
