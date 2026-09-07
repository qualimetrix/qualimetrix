# P2 follow-up — text for `FOLLOWUPS.md`, and what was reported against the decision table

Written by the package that produced `docs/adr/0048-the-final-published-name-vocabulary.md`.
This package does not edit `FOLLOWUPS.md` or `AUDIT.md`; the orchestrator merges
the blocks below. Nothing under `src/`, `tests/`, `finding-gate/` or the
enumeration artifacts was modified.

---

## For `FOLLOWUPS.md` — entries this ADR closes

### `Words, not spellings: ccn / cyclomatic, mi / index, dit / inheritance` (FOLLOWUPS.md:119-136) — CLOSED

Decided by ADR 0048 §3, in favour of the **metric** word, by moving the
**channel**: `complexity.cyclomatic → complexity.ccn`,
`maintainability.index → maintainability.mi`,
`design.inheritance → design.dit`, each with the producer name of the same
spelling cascading with it. The three metric keys are kept.

The entry asked for the radius of both directions to be measured before one was
picked, and it was: a metric-key rename is refused loudly inside a consumer's
`m["…"]` formula and is what a stored dashboard column is keyed on, while the
abbreviation was already the *channel* spelling in `cohesion.lcom`,
`complexity.wmc`, `coupling.cbo` and `design.noc`. The entry's own correction —
that the pairs are not three, because `code-smell.constructor-overinjection` and
`code-smell.unused-private` also differ from what they judge — is answered by
the naming rule rather than by a fourth rename: the first is one of two channels
judging one key (neither may take it), the second differs only by an
aggregate-shaped suffix, which the rule does not treat as a divergence.

### `A step that renames an aggregation strategy has no shape to declare it` (FOLLOWUPS.md:13-25) — RESTATED as a settled decision

The mechanism is unchanged and the entry's reading of it stands. What changes is
its status: ADR 0048 §6 keeps `sum`, `avg`, `max`, `min`, `count`, `p95`, `p5`
**as a decision**, with the gate's refusal as the reason, not as a deferral
awaiting a declaration whose unit is the strategy. The suffixes are rows of no
set in the vocabulary, so nothing in the ADR goes stale if a later step builds
that declaration. The entry should stay as a description of the gate limitation
and stop being read as an open naming question.

---

## For `FOLLOWUPS.md` — deliberately deferred, each with its measurement

### The option-key refusal is `warn-and-default`, and that is why 123 names are frozen

**Measured** (`00-overview.md` §4, from `RuleOptionsFactory`): a renamed rule
option key produces **one stderr `[WARNING]` and the run continues with the
default value**. The consumer's analysis silently changes result while their CI
stays green. That is the worst of the four migration classes, and it is the sole
reason ADR 0048 §6 keeps all 43 option keys and all 80 CLI aliases — including
the 27 declared in camelCase and the 5 in snake_case — even where the spelling
deviates from the product's kebab convention.

- **Cost of leaving it:** the 123 spellings are effectively unrenameable, so the
  deviations named row-by-row in `decision-table.tsv` are permanent until this
  mechanism changes.
- **What would close it:** making an unknown top-level option key a refusal
  rather than a warning. That is a decision about a mechanism and it changes what
  a consumer's existing configuration does, independently of any name — so ADR
  0048 names it out of scope rather than settling it by choosing words.
- **Note the sharper half:** the *nested* keys (`max_warning` / `max_error`, and
  `warning` / `error` inside a per-level map) are validated **not at all** —
  `RuleOptionsFactory`'s unknown-key check is top-level only. They are worse than
  warn-and-default, not better.

### The parameter-count double report

**Measured** (`followups/naming-questions-answered.md` Q3 rows 2-3): one
8-parameter non-VO constructor raises `code-smell.constructor-overinjection`
(default threshold 8) *and* `code-smell.long-parameter-list` (default 6) on the
same declaration, at the same line, because `LongParameterListRule` does not
exclude `__construct` (`LongParameterListRule.php:115-117`). They are not two
disjoint situations of one metric: one is a subset of the other with a looser
threshold pair, reported twice.

- **Cost:** every fat constructor in a consumer's codebase costs two findings,
  two baseline entries and two suppressions.
- **Why the ADR does not settle it:** renaming either channel would encode an
  answer the owner has not given, at the price of a silent-class rename. Both
  names are individually accurate. This is a question about behaviour — intended
  layering, or duplicate reporting.

### `size.method-count` counts fewer methods than the website promises

**Measured on this branch:** a class with `getA`, `setA`, `doWork` yields
`size.method-count = 1` against `size.method-count.total = 3`, and
`size.method-count.public = 1` against three public methods. Getters and setters
are excluded (`MethodCountVisitor.php:264`, `MethodCountMetrics.php:52-54`).
`src/Analysis/Evidence/Size/README.md:219` documents the exclusion correctly;
`website/docs/rules/size.md:20` says the rule "Counts the number of methods in a
class", which is wrong. The sibling family inverts the convention —
`size.property-count` is bare **and** inclusive — so bare-versus-`.total` means
opposite things inside one group.

- **Cost:** a user reading the page mis-sizes every threshold they set on this
  rule, and the inconsistency inside `size.*` is invisible from the outside.
- **Why the ADR does not settle it:** the name conforms to the naming rule, and
  no spelling expresses "either correct the page or correct the definition".
  Whichever is chosen, the fix is a documentation or a behaviour change.

### The internal data-bag spelling convention is entangled with the X10 freeze

`codeSmell.{$type}`, `security.{$type}`, `identicalSubExpression.{$type}` and two
standalone literals use a camelCase family with a snake_case leaf. **Measured
twice** (`enumeration-universe-reconciliation.md`): nothing in `src/Reporting/`
reads the bag's `data` side at all, and across seven runs and four formats no key
containing an uppercase letter or an underscore was ever printed. They are not
published, so ADR 0048 excludes them from the vocabulary.

- **Cost of leaving them:** a third spelling convention lives inside the product
  and reads, to a newcomer, like a published one.
- **Why they must not be tidied during the rename:** `$type` is exactly the
  string X10 froze as the occurrence discriminator, while the `codeSmell.` prefix
  is not. Tidying the convention moves an occurrence hash and silently re-binds
  every accepted baseline entry at every consumer.

---

## What was reported against `decision-table.tsv`, and where it now stands

The three items below were reported by the package that wrote ADR 0048, which
was instructed not to edit `decision-table.tsv`. Re-checked on 2026-09-07:
**all three are repaired in the table.** The first two landed in `eef70210`; the
third's per-cell repair was still an uncommitted working-tree change when this
was written, so re-check it against the tree rather than against the commit.
They are kept here because the third one repaired badly first, and how it did is
worth more than the defect was.

1. **The header's self-check (f) miscounted the ten renames — repaired.** It
   said "four channels, their three cascaded producers, and one metric key",
   which is eight. The header now reads "five channels, the four cascaded
   producers of" (`decision-table.tsv:329`), and the stale `Q1''` arithmetic
   that said "3 of the 7 renames are producers" now reads "4 of the 10 renames
   are producers" (`:148`). Agrees with ADR 0048 §3: five channel codes, four
   producer rule names, one metric key.

2. **The `declared_shape` column description undercounted `no-judged-key` —
   repaired.** It said "the three channels that declare a magnitude whose number
   is not a catalog metric"; there are four non-`health.*` such channels
   (`architecture.circular-dependency`, `architecture.unassigned-class`,
   `design.god-class`, `duplication.code-duplication`) plus the six `health.*`
   rows carrying the same value. `:187` now reads "the four channels that
   declare".

   Note for the reader who joins this description to ADR 0048 §4: the two do not
   partition the same way, and the gap is not the compound pair. §4 counts **2**
   kept channels under clause `no-magnitude` (`architecture.circular-dependency`,
   `architecture.unassigned-class`) against the column's **4** non-`health.*`
   `no-judged-key` rows. The other two are `design.god-class`, which ADR 0048 §2
   decides by its new `compound` clause instead, and
   `duplication.code-duplication`, which is renamed and so is not among the kept.
   `design.data-class` is *not* in either of those four: its `declared_shape` is
   `magnitude:lower:judges=design.woc`, and it reaches `compound` precisely
   because the clause reads the rule body rather than that declaration. The
   column measures the declaration; the clause decides the name. They are allowed
   to differ.

3. **39 `reason` cells described an owner channel as `PENDING` — repaired, but
   the first repair was a sweep, and the sweep asserted falsehoods.** Eleven
   rule option keys and 28 CLI aliases carried "Its owner … is a PENDING channel
   row, so this key's `<rule>:<option>` addressing moves with that fork". No row
   was pending. The substantive claim was correct and is carried into ADR 0048's
   Consequences; only the word was stale.

   The first repair replaced the sentence uniformly: every one of the 39 cells
   came out saying the owner "is a channel row renamed by this step". But the 39
   cells did not share one truth — some owners are renamed by the step and some
   are kept. Measured against the channel-code rows, by diffing that commit's
   table against the repaired one: 14 of the 39 owners are renamed, so **25 of
   the 39 cells asserted a falsehood** (9 of them about `design.data-class`, 7
   about `code-smell.long-parameter-list`, the rest spread over five more kept
   channels). A stale cell had become a false one. That has
   since been repaired per-cell, and re-verified here rather than spot-checked:
   for all 123 option-key and alias rows, the owner channel named in the cell
   was joined to that channel's own `decision` row, and "IS renamed by this
   step" appears in exactly the cells whose owner is `rename` — **0 mismatches,
   0 remaining `PENDING`**. Fifteen rows name no channel at all; those are the
   shared options (`enabled`, `error`, `warning`, `threshold`, `suppress_*`, the
   per-level map introducers) that genuinely have no single owner.

   **The lesson, which outlives the defect: a sweep-replace inside the decision
   artifact is a defect generator, not a repair.** Every cell in `reason` is an
   individual claim with an individual truth value; the shape of the sentence is
   shared and the content is not. A uniform edit over a column of claims
   converts a *stale* cell into a *false* one, and a false cell reads as
   decided. The table is a snapshot of 314 individual decisions and must be
   edited one row at a time, with the joined check above as the acceptance test.
