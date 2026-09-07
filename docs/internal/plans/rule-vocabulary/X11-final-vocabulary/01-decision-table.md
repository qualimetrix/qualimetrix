# P1 — the decision table, and the forks that must be settled before the ADR

The ADR of П3 has one job: name the final vocabulary **whole**, so that after it
the question "is that all?" has no content. This package produces the table it
is written from. It decides nothing on its own about the largest forks — those
go to the owner in plain text, with a measured cost per branch.

## The artifact

`decision-table.tsv` in this folder. One row per name in the vocabulary, across
all four sets:

| set                     | count | oracle                                           |
| ----------------------- | ----- | ------------------------------------------------ |
| channel codes           | 52    | the channel registry on the production container |
| metric keys             | 82    | `MetricName` constants                           |
| `HealthDimension` names | 6     | the enum; each is a key **and** a code           |
| producer rule names     | 51    | `RuleExecutionInterface::allRules()`             |

191 rows, minus the overlaps the table itself states (a producer name equal to
its channel code is one row carrying both roles, not two).

Columns:

| column                 | filled from                                                                                        |
| ---------------------- | -------------------------------------------------------------------------------------------------- |
| `name`, `set`, `roles` | the four oracles above                                                                             |
| `current_form`         | `enumeration-channel-naming.tsv` / `enumeration-metric-key-naming.tsv`                             |
| `decision`             | `keep` / `rename` — the decision this package proposes                                             |
| `proposed`             | the new spelling, or the current one when `keep`                                                   |
| `reason`               | why, in one clause; **required for `keep` as much as for `rename`**                                |
| `sites`                | the occurrence total from `../enumeration-renames.tsv`                                             |
| `declarable_by`        | which gate map states this move, or `none` + why                                                   |
| `migration_class`      | `command` / `refuse-loud` / `inert-detected` / `silent`, from `enumeration-migration-surfaces.tsv` |
| `frozen`               | `kind` / `pin` / `no`, from the derived columns — never a hand list                                |

A `keep` row without a reason is the defect this package exists to prevent: it
is exactly how the last vocabulary pass left the question open.

## The forks, and why the plan does not settle them

Each is stated to the owner in plain text with the cost of each branch. The ADR
records the answer; this package does not choose.

### Q1 — do channel names read as subjects or as judgments?

The canon calls this the largest question and says it is not measured. It is now
partly measured, and the measurement **narrows the cost, not the choice**:

| current form                                | judges a metric | judges nothing |
| ------------------------------------------- | --------------- | -------------- |
| subject (`complexity.cyclomatic`)           | 17              | 1              |
| judgment (`code-smell.long-parameter-list`) | 5               | 22             |
| mechanism (`code-smell.eval`)               | 0               | 4              |
| ambiguous                                   | 0               | 3              |

The subject-form set is exactly the metric-named groups (`complexity.*`,
`coupling.*`, `cohesion.lcom`, `design.*`, `maintainability.index`, `size.*`),
so the correlation is structural — it follows the group, and is not an artifact
of one reader's classification.

- **Branch (a) — codify the bimodal rule.** A channel that judges a magnitude
  names the magnitude; a channel that reports an occurrence names the
  occurrence. **0 form-driven renames**; the work is ruling on the 6 rows that
  break the pattern and the 3 ambiguous ones.
- **Branch (b) — one form for all, ESLint's `max-lines` shape.** ~18 channels
  change spelling. Cost is the `sites` sum of those rows plus every consumer's
  configuration and directives; the ADR would also have to say what happens to
  the 18 codes that today coincide with a metric key.

The six pattern-breakers, named so the owner can rule on them either way:
`coupling.class-rank` (subject form, judges nothing);
`code-smell.constructor-overinjection` and `code-smell.long-parameter-list`
(judgment form, both judging `code-smell.parameter-count` — the legitimate
many-to-one); `code-smell.unreachable-code`; `code-smell.unused-private`;
`design.data-class` (judges `design.woc`). Ambiguous:
`architecture.coverage`, `code-smell.error-suppression`,
`duplication.code-duplication`.

### Q2 — the six `health.*` names

They are a metric key and a channel code at once, they are outside both oracles,
and this repository's own ratchet carries two of them (`health.cohesion` ×16,
`health.typing` ×1 of 20 channels in `qmx-baseline.json`) — so a rename here is
one of the few that our own dogfood migration actually exercises. They are
declarable on either map: the gate does not check membership. Branches: keep the
`health.<dimension>` form as already-decided (check ADR 0032/0033/0036 first —
do not relitigate a settled form), or fold them into the ruling of Q1.

### Q3 — `.avg` / `.sum` / `.p5`

**Not a row-shaped question.** The gate stops before reading any map row when
the two trees' suffix lists differ (`MetricVocabulary.php:91`), so this step
cannot be run through the gate at all. Branches: decide `keep` with that reason
recorded, or accept a separate preceding step that builds a declaration whose
unit is the strategy. The plan recommends `keep`; the ADR must say so explicitly
rather than omitting the question.

### Q4 — the 51 producer names

Nine differ from their channel code. A consumer writes them in four places.
**This package must first measure which map declares a producer rename** —
`channels.tsv` declares a channel key and `inputs.tsv` declares names inside
selectors and option keys; whether either covers a `rules:` key is a fact, not a
guess. If none does, that is a third structural gate gap and the honest branch
is `keep` for all 51, recorded with that reason.

## Definition of Done

- `decision-table.tsv` exists with one row per name in all four sets; every row
  carries a `reason`, including every `keep`.
- The set of names in the table equals the union of the four oracles, proved by
  a command quoted in the file's header.
- The header carries "how this was produced" and "what this method does not
  see", per the same rule the other artifacts in this folder follow.
- Q4's declarability is answered by reading `scripts/finding-gate/`, with
  `file:line`, not asserted.
- The four forks are put to the owner in plain text with cost per branch, and
  the table's `decision` column for the affected rows stays unfilled until the
  answer arrives.
- No file outside this folder is touched. Nothing is renamed.
