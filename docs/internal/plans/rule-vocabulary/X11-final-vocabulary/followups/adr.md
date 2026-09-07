# P2 follow-up — text for `FOLLOWUPS.md`, and two defects found in closed artifacts

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

## Two defects found in closed artifacts — reported, not fixed

Both are in `decision-table.tsv`, which this package was instructed not to edit.
Neither changes a decision; both are stale prose in the header or in reason
cells, left behind when the last three rows were decided.

1. **The header's self-check (f) prose miscounts the ten renames.** It says
   "four channels, their three cascaded producers (`architecture.coverage` has
   none of its own), and one metric key" — which is eight, not ten. The list it
   prints immediately below is correct and contains **five** channel codes
   (`architecture.coverage`, `complexity.cyclomatic`, `design.inheritance`,
   `duplication.code-duplication`, `maintainability.index`), **four** producer
   rule names and **one** metric key. The same stale arithmetic appears in the
   header's `Q1''` paragraph ("3 of the 7 renames are producers"), which was
   written while the file held seven renames. ADR 0048 §3 states five / four /
   one.

2. **39 reason cells still describe an owner channel as `PENDING`.** Eleven rule
   option keys (`min-tokens`, `vo-warning`, `woc-threshold`, …) and 28 CLI
   aliases (`cyclomatic-warning`, `dit-error`, `mi-min-statements`, …) carry a
   sentence of the form "Its owner … is a PENDING channel row, so this key's
   `<rule>:<option>` addressing moves with that fork". No row is pending; the
   header says so explicitly. The substantive claim in those cells — that the
   addressing moves with the rule half while the option word stays — is correct
   and is carried into ADR 0048's Consequences; only the word `PENDING` is stale.

A third, smaller one: the header's description of the `declared_shape` column
says `no-judged-key` is used "for the three channels that declare a magnitude
whose number is not a catalog metric". There are **four** such non-`health.*`
channels — `architecture.circular-dependency`, `architecture.unassigned-class`,
`design.god-class`, `duplication.code-duplication` — plus the six `health.*`
rows, which carry the same value.
