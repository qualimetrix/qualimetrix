# The published name universe, reconciled between two witnesses

The ADR of П3 must name the **whole** vocabulary. This file states what the whole
is, by comparing two independently produced enumerations, and names the residue
that neither the declared count "52 channels, 82 metric keys" nor a single
witness would have shown.

## How this was produced

- **Witness A (declaration)** — `docs/internal/plans/rule-vocabulary/enumeration-renames.tsv`,
  regenerated and checked fresh by `composer enumeration:renames:check`. Oracles:
  `ChannelDeclarationRegistryInterface::staticDeclarations()` on the production
  container (52 channel rows) and `ReflectionClass(MetricName::class)->getConstants()`
  (82 metric-key rows).
- **Witness B (observation)** — `enumeration-runtime-witness.tsv` in this folder,
  produced without reading witness A: the channel and metric-key strings the
  product actually printed across seven runs (`src/`, `src/ --preset=strict`,
  `finding-gate/`, `tests/`, each `finding-gate/cases/*/`, plus three runs that
  force a threshold so an otherwise silent channel fires). Commands are in that
  file's header.
- **Reconciliation** — `comm` over the two sorted sets; aggregation suffixes
  stripped from witness B before comparing base keys. Commands are reproducible
  from this file's text.

## Result: the declared sets are confirmed, and they are not the whole vocabulary

**Every one of the 52 declared channels was also observed.** No channel is
declared and unpublishable, and no run printed a channel that the registry does
not declare — except the residue below. On metric keys, 77 of the 82 declared
were observed; the five that were not need a fixture this corpus does not carry
(`security.hardcoded-credentials`, `security.sensitive-parameter`, and the three
`code-smell.unused-private.{constant,method,property}` sub-keys). Absence from
observation is a property of the corpus, not of the declaration.

**The residue is the finding.** Seven names were published that neither declared
set contains:

| name                                                                                                                   | why it is outside both sets                                                                                                                                                                                                            |
| ---------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `health.complexity`, `health.cohesion`, `health.coupling`, `health.typing`, `health.maintainability`, `health.overall` | shipped by the product, but declared in neither oracle: they are cases of the `HealthDimension` enum (`src/Analysis/Evidence/ComputedMetrics/HealthDimension.php:16-21`), instantiated as computed metrics by `ComputedMetricDefaults` |
| `computed.branch-load`                                                                                                 | the gate corpus's user-defined computed metric — an instance of the open-ended user family, not a product name                                                                                                                         |

The six `health.*` names do **two jobs with one string**: they are metric keys
(a formula addresses them — `ComputedMetricDefaults.php:123` reads
`m["health.complexity"]`) and they are channel codes (a finding is published on
`health.cohesion`, and this repository's own sources carry
`@qmx-ignore health.cohesion` directives). A rename of one of them therefore
moves both a metric key and a channel code at once.

## What the ADR's universe must therefore be

```
52  declared channel codes            (closed, oracle: the channel registry)
82  declared metric keys              (closed, oracle: MetricName)
 6  HealthDimension names             (closed, oracle: the enum; each is both)
 n  user-defined computed metrics     (OPEN by construction — the consumer's own
                                       qmx.yaml; cannot be enumerated here)
```

An ADR that names only 52 + 82 leaves the question "is that all?" with content,
which is exactly what П3 exists to remove.

## What this method does not see

- Observation cannot prove a name is unpublishable; it can only fail to print it.
  The five unobserved metric keys above are unobserved, not absent.
- The user family is unbounded on purpose: a consumer's `computed_metrics:`
  entries produce channel codes and metric keys this repository never sees. The
  ADR can rule on the *shape* the product imposes on those names, never on the
  names.
- Aggregation suffixes were stripped mechanically by a fixed list
  (`avg|sum|max|min|p5|p95|median|count|stddev`) taken from what witness B
  printed; a suffix no run produced would have been read as part of a base key.
- Neither witness covers a name assembled at runtime from parts; witness A's
  header names that blind spot for text counting, and witness B inherits it in
  the opposite direction (it sees the assembled result, not the parts).

## A third spelling convention exists, and it is not part of the published universe

The metric-key enumeration in this folder reports keys assembled by
interpolation — `security.{$type}`, `codeSmell.{$type}`,
`identicalSubExpression.{$type}` — producing strings such as
`codeSmell.boolean_argument`: camelCase family, snake_case leaf, neither of which
the 82 kebab-case keys use. Its header frames these as published keys outside
`MetricName`. **Measurement narrows that claim: they are real, and they are not
published.**

- They are written with `MetricBag::withEntry()` and read with
  `MetricBag::entries()`, which address the bag's `data` side
  (`src/Analysis/Evidence/Measurement/Contract/MetricBag.php:73,115`) — a
  namespace distinct from `metrics`, the map that `with()` fills and that every
  published metric key comes from.
- Nothing in `src/Reporting/` reads that side at all: a grep for `entries(` and
  `MetricData` across the whole of Reporting returns nothing. Its only consumers
  are the rules that turn the entries into a finding's evidence.
- Witness B agrees independently: across seven runs and the JSON, metrics, SARIF
  and GitLab formats, **no** key containing an uppercase letter or an underscore
  was ever printed.

The same reading reclassifies the two standalone literals the enumeration found
(`npath-complexity.factors`, `cognitive-complexity.increments`): both are
`withEntry()` calls, so both live on the same unpublished side.

So the published universe stands as reconciled above. What this adds is a
different fact, and one the ADR should still state rather than discover later: a
third, internal spelling convention lives inside the product, and one of its
values — `codeSmell.{$type}`, where `$type` is the rule's `SMELL_TYPE` — is the
very string X10 froze as the occurrence discriminator for nine code-smell rules.
Tidying that convention is therefore entangled with the freeze and is not a
cosmetic edit.

## Two corrections to the gate-shape reading, and one stale docblock they expose

Read directly in `scripts/finding-gate/`, because П3 must not rule "keep this
name" for a reason that turns out to be a misreading:

1. **`RenameMaps::assertPlainMetricKey()` (`RenameMaps.php:995`) does not refuse a
   suffixed spelling.** Its pattern `^[A-Za-z0-9][A-Za-z0-9_.-]*$` admits a dot,
   so `complexity.ccn.avg` passes it. The refusal of an aggregation-suffix step is
   therefore **single-mechanism**, not double: `MetricVocabulary::assertSuffixesAgreeWith()`
   (`MetricVocabulary.php:91`) stops the gate before any row is consulted, because
   a step that changes the suffix list makes the two trees' lists differ. The
   conclusion the enumeration reached stands — there is no shape for that step —
   but it rests on one guard.
2. **No map validates a name against the product's channel registry.** Nothing in
   `RenameMaps` reads `staticDeclarations()`, and `metric-keys.tsv` uses
   `MetricVocabulary::baseKeys` only to detect suffix collisions
   (`RenameMaps.php:1237`), never as a membership test. So a rename of a
   `health.*` name — outside both oracles — **is declarable**, on either map. If
   the ADR keeps those six names, it must be for a product reason, not because the
   gate cannot state the move.

The same reading exposes a stale claim worth carrying forward: the docblock of
`assertNoSuffixOverlap()` (`RenameMaps.php:1216-1219`) still says `MetricName`'s
constants are "71 of the 82 published keys, and the other eleven are
collector-owned literals". Ш5e3 promoted those eleven — `MetricName` declares 82
today, and FOLLOWUPS (Ш5e3-0) promised to re-measure and retire that caveat once
it had. The caveat should not simply be deleted, because this reconciliation shows
the population is still partial, for a different reason: the six `HealthDimension`
keys are published and are not `MetricName` constants.
