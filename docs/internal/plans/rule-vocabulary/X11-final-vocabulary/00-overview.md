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

- **Stage 1 (this plan, `01`-`02`)** — the decision table, the forks put to the
  owner, and the ADR written from the approved table. Nothing renames.
- **Stage 2 (planned after the ADR is accepted)** — П4 and П5. Its packages are
  written against the ADR's table, not against a guess.

## What the enumeration established, before any decision

Six artifacts in this folder. The plan references them; it does not restate them.

| artifact                                 | what it settles                                                                            |
| ---------------------------------------- | ------------------------------------------------------------------------------------------ |
| `enumeration-universe-reconciliation.md` | what the whole vocabulary **is**, from two independent witnesses                           |
| `enumeration-channel-naming.tsv`         | 52 channels × naming properties                                                            |
| `enumeration-metric-key-naming.tsv`      | 82 metric keys × naming properties                                                         |
| `enumeration-runtime-witness.tsv`        | the same universe seen only through the product's printed output                           |
| `enumeration-migration-surfaces.tsv`     | 17 places a consumer's written name reaches the product, and what each does after a rename |
| `enumeration-gate-map-shapes.tsv`        | what each of the five gate maps can and cannot declare                                     |

Four results carry the plan:

1. **The universe is not "52 + 82".** Both witnesses agree on the 52 declared
   channels exactly, and on 77 of the 82 keys (five need a fixture the corpus
   lacks). Beyond them the product ships **six `HealthDimension` names**
   (`health.complexity|cohesion|coupling|typing|maintainability|overall`) that
   are in neither oracle and that are **a metric key and a channel code at the
   same time**, plus an open-ended family of consumer-defined `computed.*`.
   An ADR naming only 52 + 82 would leave six product names unruled.
2. **The rule-name vocabulary is a fourth set.** 51 producer names, of which
   nine do not equal their channel code. A consumer writes these in `rules:`
   keys, `only_rules`, `--rule-opt` and `@qmx-threshold`. The ADR must rule on
   them as a set, or state that they are derived and why.
3. **The naming form is already bimodal, and the mode tracks a product
   distinction** rather than an accident: of the 18 channels whose second
   segment names a subject, 17 judge a metric magnitude; of the 27 that name a
   judgment, 22 judge nothing and report an occurrence. Six rows break the
   pattern and three are genuinely ambiguous. This narrows the largest open
   question but does not answer it — see the forks in `01`.
4. **"Migration is one command" is false as a promise.** Of 17 surfaces exactly
   one is migrated by the shipped command. The rest split three ways: loud
   refusal (configuration, exit 3 — verified independently), detection but not
   migration (`@qmx-*` directives become `annotation.unresolved-directive`), and
   silence (SARIF `ruleId`, GitLab `check_name`/`fingerprint`, Checkstyle
   `source`). П5's `Breaking` entry states all three.

## Two structural gate limits the decision must respect

- **An aggregation-suffix rename cannot be declared at all.** Re-verified
  against today's code, not inherited from the 2026-08-26 note:
  `MetricVocabulary::assertSuffixesAgreeWith()` (`MetricVocabulary.php:91`)
  stops the gate before a single row is read. Deciding to change `.avg`/`.sum`/
  `.p5` means building that shape first — a step of its own, not a row.
- **A producer-name rename has no map of its own.** `channels.tsv` declares a
  channel key; `inputs.tsv` declares option keys and names inside selectors.
  Whether a rule-name move is declarable, and by which map, is question Q4 in
  `01` and is answered by measurement before the ADR rules on the 51.

Conversely, one limit that was assumed and is **not** real: no map validates a
name against the channel registry or against `MetricName` membership, so the six
`health.*` names **are** declarable on either map. Keeping them must be a
product decision, not a gate excuse.

## Order

```
P1  decision table: one row per name across all four vocabularies   (01)
    forks put to the owner in plain text, with cost per branch
P2  the ADR, written from the approved table only                   (02)
--- stage boundary: ADR accepted ---
П4  every rename in one step, each declared in finding-gate/maps/, gate GREEN
П5  release: Breaking with a consumer-side migration, and the map shipped with it
```

## Constraints that hold across every package

- **The freeze is the one thing a rename can break irreversibly.** Six channels
  carry a `private const string OCCURRENCE_KIND` equal to today's spelling **on
  purpose**; twelve more rules pin a `SMELL_TYPE`/`PATTERN_TYPE` that keys the
  occurrence the same way. These are different mechanisms and are named apart.
  A sweep must skip them **by the derived `frozen_kind`/`frozen_pin` columns of
  `../enumeration-renames.tsv`**, never by a hand-kept list. After П4 a frozen
  constant reads differently from its channel's `NAME`: that is the freeze
  working, and `OccurrenceKindFreezeGuardTest` is designed not to redden on it.
  Anyone "fixing" that divergence ends the freeze.
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
- **Validation runs on a quiet tree.** `composer check` in full, plus
  `composer gate` and `composer gate:controls` with no agent running: their
  "working tree changed" guard reddens the exit code otherwise.
- Packages never edit `FOLLOWUPS.md` or `AUDIT.md`. Their texts go to
  `followups/<package>.md` in this folder.
