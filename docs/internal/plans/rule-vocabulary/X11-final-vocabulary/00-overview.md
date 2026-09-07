# Х11 — the final naming vocabulary (П3), and the rename it authorises (П4-П5)

The canon of this step is `../X8-one-string-two-jobs/04-final-naming-step.md`.
Its П1 and П2 are closed and released (Х10, v0.25.0): the occurrence
discriminator is frozen away from the channel code in six families, and
`bin/qmx baseline:rename-channels` ships. What remains is П3 (decide the whole
vocabulary, one ADR), П4 (perform every rename in one step) and П5 (release it).

## Why this plan is two stages and not one

П4's packages are a function of П3's answer: which names move decides which
files a package owns, which map rows are declared, and how many gate cycles the
step costs. A plan that writes П4 packages before the ADR exists argues with
itself the moment the owner picks a name. So:

- **Stage 1 (this plan)** — P0 closes an unguarded hazard that no naming
  decision depends on; P1 builds the decision table; P2 writes the ADR from the
  approved table. Nothing renames.
- **Stage 2 (planned after the ADR is accepted)** — П4 and П5, written against
  the ADR's table rather than against a guess.

## What the enumeration established, before any decision

Six artifacts in this folder. The plan references them; it does not restate
them. Two carry corrections made after review and are read with their headers.

| artifact                                 | what it settles                                                                             |
| ---------------------------------------- | ------------------------------------------------------------------------------------------- |
| `enumeration-universe-reconciliation.md` | what the whole vocabulary **is**, from two independent witnesses                            |
| `enumeration-channel-naming.tsv`         | 52 statically declared channels × naming properties                                         |
| `enumeration-metric-key-naming.tsv`      | 82 metric keys × naming properties                                                          |
| `enumeration-runtime-witness.tsv`        | the same universe seen only through the product's printed output                            |
| `enumeration-migration-surfaces.tsv`     | the places a consumer's written name reaches the product, and what each does after a rename |
| `enumeration-gate-map-shapes.tsv`        | what each of the five gate maps can and cannot declare                                      |

Five results carry the plan.

### 1. The channel oracle in the enumeration is the narrow one

`ChannelDeclarationRegistryInterface::staticDeclarations()` returns 52, and its
own docblock says it "excludes the run-time `computed.*` / `health.*` family by
construction" — it exists for a fixture drift guard, not as the universe. The
live universe is `ChannelIdentityInterface::channels()`
(`src/Infrastructure/Rule/ChannelUniverse.php:135`): the static set **plus**
every configured computed-metric definition.

So the six `health.*` names the runtime witness found are not names the product
ships undeclared — they are the run-time half of the universe, exactly where the
narrow oracle's docblock predicts. What the two witnesses did establish stands
and is the reason P1 changes oracle: **a decision table built on
`staticDeclarations()` would omit six product-shipped names**, each of which is
a metric key and a channel code at the same time. The rest of that family is
open by construction — a consumer's own `computed_metrics:` entries.

### 2. There are five consumer-facing vocabularies, not four

| set                 | count                                              | oracle                                  |
| ------------------- | -------------------------------------------------- | --------------------------------------- |
| channel codes       | 52 static + the configured `computed.*`/`health.*` | `ChannelIdentityInterface::channels()`  |
| metric keys         | 82                                                 | `MetricName` constants                  |
| producer rule names | 51                                                 | `ChannelIdentityInterface::ruleNames()` |
| rule option keys    | 27                                                 | the options each rule declares          |
| CLI flag aliases    | 80                                                 | the console definition                  |

The last two were missing from the first draft of this plan. A consumer writes
them in `--rule-opt rule:option=value`, in `rules: {<rule>: {...}}` and in
`@qmx-threshold rule key=value`, and several are camelCase in an otherwise
kebab-case product (`maxCycleSize`, `lcomThreshold`, `classLocThreshold`) —
precisely the kind of question the ADR exists to close.

### 3. The naming form is already bimodal, and the mode tracks a product
distinction

Of the 18 channels whose second segment names a subject, 17 judge a metric
magnitude; of the 27 that name a judgment, 22 judge nothing and report an
occurrence. The subject-form set is exactly the metric-named groups, so the
correlation follows the group and is not an artifact of one reader's
classification. Six rows break the pattern and three are genuinely ambiguous.
This narrows the largest open question; it does not answer it. See Q1 in `01`.

### 4. "Migration is one command" is false as a promise, and there are four
classes, not three

Exactly one surface is migrated by the shipped command: the baseline file's
`channel` field. The rest, all measured:

| class                | what a consumer sees                                                                   | examples                                                                                        |
| -------------------- | -------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| **refuse-loud**      | exit 3, with a "did you mean" naming the new spelling                                  | `rules:` keys, `only_rules`, `--disable-rule`, selector keys, `m["..."]` formulas               |
| **inert-detected**   | the run continues; the stale name becomes an `annotation.unresolved-directive` finding | `@qmx-ignore`, `@qmx-threshold`, `@qmx-ignore-file` in the consumer's own sources               |
| **warn-and-default** | one `[WARNING]` line on stderr, and **the run continues with the default value**       | rule option keys and CLI aliases                                                                |
| **silent**           | nothing anywhere                                                                       | SARIF `ruleId`, GitLab `check_name` and `fingerprint`, Checkstyle `source`, JSON/metrics fields |

`warn-and-default` is the worst of the four and was missing from the first
draft: a renamed option key does not fail the run, it silently restores a
default threshold, so the consumer's analysis changes result while their CI
stays green. Measured directly (`RuleOptionsFactory` logs and continues).

### 5. Three structural gate limits, not two

- **An aggregation-suffix rename cannot be declared at all.** Re-verified
  against today's code: `MetricVocabulary::assertSuffixesAgreeWith()`
  (`MetricVocabulary.php:91`) stops the gate before a single row is read.
  (`RenameMaps::assertPlainMetricKey()` does **not** contribute: its pattern
  admits a dot. One guard, not two.)
- **A producer-name rename has no map of its own** — but `inputs.tsv` declares
  "option keys, flag aliases, and names inside selectors" by its own
  description, which a `rules:` key and an `only_rules` entry plausibly satisfy.
  P1 settles this by reading, not by assuming a gap.
- **`case.json` channel claims are hand-written and checked against the
  candidate tree only.** Sixteen cases claim channels; after П4 each claim
  naming a renamed channel must be rewritten by hand in the same commit, and no
  map declares that. The refusal is loud (`CASE_CLAIM_MISMATCH`), so this is a
  cost, not a hazard — but it is the one place П4 edits the corpus with the same
  step that measures through it.

Conversely, one limit that was assumed and is **not** real: no map validates a
name against the channel registry or against `MetricName` membership, so the six
`health.*` names **are** declarable on either map. Keeping them must be a
product decision, not a gate excuse.

## The hazard P0 closes, and why it comes first

X10 froze six families' occurrence discriminator into a `private const string
OCCURRENCE_KIND`. Twelve **more** rules key the same occurrence through a
`SMELL_TYPE`/`PATTERN_TYPE` constant — and the derived `frozen_kind`/`frozen_pin`
columns of `../enumeration-renames.tsv` report **zero** on all twelve, because
they count only the `OCCURRENCE_KIND` shape.

Measured, not reasoned. A coordinated rename of `SMELL_TYPE = 'eval'` across all
of its sites (rule constant, visitor literal, collector list, four test files)
leaves the **entire 8174-test suite green, exit 0**, while the occurrence hash
moves from `cb4db382fbd60b86` to `044e26abaa4cfd73` — silently re-binding every
accepted baseline entry on `code-smell.eval` at every consumer. A *partial*
sweep reddens 8 tests; the complete one, which is what П4 performs, reddens
nothing.

This is the single irreversible way to spoil П4, it is independent of every
naming decision, and until it is closed all downstream work runs over it. Hence
P0 before P1.

## Order

```
P0  extend the derived freeze columns to the twelve bag-keyed families, and
    guard them — control: the eval sweep above must redden
P1  decision table: one row per name-and-role across five vocabularies   (01)
    forks put to the owner in plain text, with cost per branch
P2  the ADR, written from the approved table only                        (02)
--- stage boundary: ADR accepted ---
П4  every rename in one step, each declared in finding-gate/maps/, gate GREEN
П5  release: Breaking with a consumer-side migration, and the map shipped with it
```

## Constraints that hold across every package

- **Never reconcile a frozen constant with its channel's `NAME`.** After П4 they
  read differently on purpose. A guard that compares them would redden by design
  and be "fixed" by ending the freeze — the trap X10 caught before it fired.
  Guards pin literals.
- **The gate's reference goes stale with product behaviour.** П4 moves findings
  by construction, so the working mode is: declare the map rows, get GREEN
  against the commit the step starts from, land it, and let the next step take
  that commit as its reference.
- **`check:artifacts` cascades.** `enumeration-renames.tsv` goes stale on any
  literal movement; `enumeration-threshold-directives.tsv` on directive **text**
  edits (`--write` fixes it); the suppression snapshot on suppressed findings
  moving. Every new `.md` under `docs/internal/plans/` needs its line in
  `documentationDisposition()` in the **same commit** that creates it — the
  generator sees untracked files, so `architecture:check` reddens at once.
  `.tsv` files are outside that glob and need no line.
- **Validation runs on a quiet tree.** `composer check` in full, plus
  `composer gate` and `composer gate:controls` with no agent running: their
  "working tree changed" guard reddens the exit code otherwise.
- **A new test under `tests/Analysis/Policy/Baseline` needs
  `P6_C_BASELINE_PATHS_SHA256` recomputed** — a review decision, not a
  regeneration.
- Packages never edit `FOLLOWUPS.md` or `AUDIT.md`. Their texts go to
  `followups/<package>.md` in this folder.
