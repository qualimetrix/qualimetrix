# P1 — the decision table, and the forks the owner has settled

> **STATE: written before the decisions, kept as it was.** This is the P1
> PLANNING document, as it stood when the forks below were still open. It is
> deliberately not updated to the answers: its value is that it shows what each
> question looked like *before* it had one — including passages that still read
> as open, such as "the nine rows P1 must rule on individually" and "Q4 — read
> the artifact first, then decide". Those rows have since been ruled on.
>
> **It is not the source of truth for any decision.** As of 2026-09-07 the
> table is closed: 314 rows, 304 `keep`, 10 `rename`, 0 `pending`. The decisions
> live in `decision-table.tsv` (the dated snapshot that carries them) and in
> `docs/adr/0048-the-final-published-name-vocabulary.md` (the accepted ADR).
> Where this document and either of those disagree, they are right and this one
> is simply older.

The ADR of П3 has one job: name the final vocabulary **whole**, so that after it
the question "is that all?" has no content. This package produces the table it
is written from.

## The artifact

`decision-table.tsv` in this folder. **The unit of a row is a name in a role,
not a name.** Eighteen channel codes are spelled identically to a metric key,
and the six `health.*` names are a key and a code at once; a single-decision row
cannot express "rename the channel, keep the key". A name in two roles is two
rows, joined by a shared `name` column.

Sets and their oracles:

| set                 | count                                              | oracle                                                                                                              |
| ------------------- | -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| channel codes       | 52 static + the configured `computed.*`/`health.*` | `ChannelIdentityInterface::channels()` — **not** `staticDeclarations()`, which excludes that family by construction |
| metric keys         | 82                                                 | `MetricName` constants                                                                                              |
| producer rule names | 51                                                 | `ChannelIdentityInterface::ruleNames()`                                                                             |
| rule option keys    | 27                                                 | the options each rule declares                                                                                      |
| CLI flag aliases    | 80                                                 | the console definition                                                                                              |

Columns:

| column                | filled from                                                                                                                 |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `name`, `set`, `role` | the five oracles above                                                                                                      |
| `current_form`        | `enumeration-channel-naming.tsv` / `enumeration-metric-key-naming.tsv`                                                      |
| `decision`            | `keep` / `rename` / `pending`                                                                                               |
| `proposed`            | the new spelling, or the current one when `keep`; empty only while `pending`                                                |
| `reason`              | why, in one clause — **required for `keep` exactly as for `rename`**; a `pending` row instead carries the fork's identifier |
| `sites`               | the occurrence total from `../enumeration-renames.tsv`                                                                      |
| `declarable_by`       | which gate map states this move, or `none` + why                                                                            |
| `migration_class`     | `command` / `refuse-loud` / `inert-detected` / `warn-and-default` / `silent`                                                |
| `frozen`              | `kind` / `bag` / `no`, from the derived columns as P0 leaves them — never a hand list                                       |

A `keep` row without a reason is the defect this package exists to prevent: it
is exactly how the last vocabulary pass left the question open. A `pending` row
is the one shape allowed to lack a `proposed`, and only until its fork is
answered; the DoD counts them and names them rather than tolerating blanks.

**Freshness.** `decision-table.tsv` is a **dated snapshot**, not a checked
artifact: it carries decisions, which no measurement can re-derive, and П4 makes
it historical by construction. Its header says so, and says which command
re-derives the *name* column so a reader can tell a stale row from a decided one.

## The forks, as the owner settled them

Recorded here because the ADR must reproduce the reasoning, not just the verdict.

### Q1 — do channel names read as subjects or as judgments? → **branch (a)**

The canon called this the largest question and unmeasured. It is now measured on
the cost side:

| current form                                | judges a metric | judges nothing |
| ------------------------------------------- | --------------- | -------------- |
| subject (`complexity.cyclomatic`)           | 17              | 1              |
| judgment (`code-smell.long-parameter-list`) | 5               | 22             |
| mechanism (`code-smell.eval`)               | 0               | 4              |
| ambiguous                                   | 0               | 3              |

The subject-form set is exactly the metric-named groups (`complexity.*`,
`coupling.*`, `cohesion.lcom`, `design.*`, `maintainability.index`, `size.*`),
so the correlation is structural.

**Decision: codify the bimodal rule.** A channel that judges a magnitude names
the magnitude; a channel that reports an occurrence names the occurrence.

The reason, which the ADR must carry: the ESLint analogy does not transfer.
`max-lines` must carry its judgment because ESLint publishes no metric layer to
name; this product publishes 82 keys, and since Х8 the relation "this channel
judges that metric" is **data** (`ChannelDeclaration::judging()`, printed by
`bin/qmx rules`). `complexity.cyclomatic` plus a declared judged-metric link
says more than `max-cyclomatic` does.

The counter-argument the ADR must state rather than hide: a subject-form name
alone does not tell a report's reader that a threshold was exceeded. Held to be
covered by severity, message text and the judged metric travelling with the
finding — a judgement, not a measurement.

Costs, both measured as `sites` sums over `../enumeration-renames.tsv`:

- **branch (a)** — 0 form-driven renames; the work is the nine rows below, whose
  sites total **907** if every one of them were renamed (the realistic figure is
  lower, and is what P1 determines).
- **branch (b)** — the 18 subject-form channels alone total **3705** sites,
  before any consumer's configuration, directives and dashboards.

The nine rows P1 must rule on individually, by reading each rule rather than by
classifying its name:

| row                                    | why it is here                                                                                                                                                                        | sites |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----- |
| `coupling.class-rank`                  | subject form, `judging()` declares nothing — but the rule **does** judge a magnitude against a project-size-scaled threshold, so the anomaly is in the declaration, not the behaviour | 188   |
| `code-smell.constructor-overinjection` | judgment form, judges `code-smell.parameter-count`                                                                                                                                    | 58    |
| `code-smell.long-parameter-list`       | judgment form, judges the same key                                                                                                                                                    | 156   |
| `code-smell.unreachable-code`          | judgment form, judges a key of its own name                                                                                                                                           | 74    |
| `code-smell.unused-private`            | judgment form, judges `…​.total`                                                                                                                                                      | 26    |
| `design.data-class`                    | judgment form, judges `design.woc`                                                                                                                                                    | 79    |
| `architecture.coverage`                | ambiguous: one word, neither subject nor judgment                                                                                                                                     | 133   |
| `code-smell.error-suppression`         | ambiguous: mechanism or judgment                                                                                                                                                      | 34    |
| `duplication.code-duplication`         | ambiguous, and tautological in spelling                                                                                                                                               | 159   |

That prior was half refuted by measurement, and the refutation is recorded here
rather than quietly dropped:

- **`duplication.code-duplication` and `architecture.coverage` are the weakest
  names — confirmed, and for mechanical reasons.** The first is a tautology (the
  leaf repeats the group), emits unconditionally, judges no catalog key, and
  publishes a block's line count. The second is a **configuration diagnostic**,
  not a coverage measure: no metric value, no threshold, severity from an
  `ignore/warn/error` mode, and its own class docblock says it "reports a
  mistake in the configuration rather than debt in the code" — while
  `design.type-coverage.*` in the same run prints a judged percentage. One word,
  two meanings.
- **The two `parameter-count` rows are NOT a clean many-to-one — refuted.**
  `LongParameterListRule` does not exclude `__construct`, so a fat non-VO
  constructor raises **both** findings on one declaration. Measured
  independently: an 8-parameter constructor prints
  `code-smell.constructor-overinjection` (threshold 8) and
  `code-smell.long-parameter-list` (threshold 6) together. They are not two
  situations of one metric; one is a subset of the other with a softer threshold
  pair, reported twice. Whether that is intended layering or noise is a question
  for the owner about behaviour, and the ADR must not settle it silently by
  choosing names.

### Q2 — the six `health.*` names → **keep the `health.<dimension>` form**

Three reasons, two of them measured: the form is settled by earlier ADRs and
re-opening it without cause is the worst kind of work; this repository's own
ratchet carries two of them (`health.cohesion` ×16, `health.typing` ×1 of the 20
channels in `qmx-baseline.json`), so the cost is not zero even in-tree; and
consumers address them in formulas, where the spelling has already spread.

What the ADR must say about them beyond the verdict — and what nothing in the
tree says today: **these are run-time-declared channels, absent from the static
registry by construction.** A future reader who builds a completeness check on
`staticDeclarations()` repeats this plan's own first mistake. That sentence is
the durable value of the row; `keep` is not.

P1 reads ADR 0032/0033/0036 first and cites whichever already settles the form.

### Q3 — `.avg` / `.sum` / `.p5` → **keep, and say so as a decision**

The gate stops before reading the first map row when the two trees' suffix lists
differ (`MetricVocabulary.php:91`), so this step cannot be run through the gate
at all; building a declaration whose unit is the strategy costs more than
`avg → average` returns. The ADR records `keep` **with that reason** — a settled
question, not a deferral. `02` treats it accordingly and does not list it among
what the ADR declines to decide.

The seven suffixes are `sum, avg, max, min, count, p95, p5`
(`AggregationStrategy`). Where the reconciliation stripped nine, two of them
(`median`, `stddev`) are not product suffixes; the artifact's header is corrected
rather than its conclusion, which no row depended on.

### Q4 — the 51 producer names → **read the artifact first, then decide**

`enumeration-gate-map-shapes.tsv` says `inputs.tsv` declares "option keys, flag
aliases, and names inside selectors" — which a `rules:` key and an `only_rules`
entry plausibly satisfy. So "keep because nothing can declare it" is probably a
false premise, and P1 confirms or refutes that one line in
`scripts/finding-gate/` with `file:line` rather than re-measuring from scratch.
What then remains substantive is the nine names that differ from their channel
code, which are ruled on individually.

### Q5 — rule option keys and CLI aliases → **name them, keep them, defer the fix**

27 option keys, several camelCase in a kebab-case product, plus 80 flag aliases.
The ADR states the spelling rule they will follow from now on and keeps every
current spelling. The reason is their migration class: a renamed option key
produces one stderr `[WARNING]` and **the run continues with the default**, so
the consumer's analysis silently changes result while their CI stays green.
Renaming into that behaviour is worse than leaving the spelling inconsistent.

"Make that refusal loud" is a separate decision about a mechanism, not about a
name; it goes to `followups/` with the measurement, and the ADR names it as
knowingly out of scope.

## Definition of Done

- `decision-table.tsv` exists with one row per name-and-role across the five
  sets; the set of `name`+`role` pairs equals the union of the five oracles,
  proved by a command quoted in the file's header.
- Every row carries a `reason`. A row may be `pending` only if it belongs to a
  fork this plan leaves open; the report lists every `pending` row by name, and
  the count is zero unless the report explains each one.
- The header carries "how this was produced" and "what this method does not
  see", and states that the file is a dated snapshot rather than a checked
  artifact.
- Q4's declarability is answered by reading `scripts/finding-gate/` with
  `file:line`.
- ADR 0032/0033/0036 are read and the one that settles `health.<dimension>` is
  cited in Q2's rows.
- No file outside this folder is touched. Nothing is renamed.
