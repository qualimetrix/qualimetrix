# 0047. Suppression Is Not Exclusion

**Date:** 2026-09-05
**Status:** Accepted

## Context

`exclude_paths`, `exclude_namespaces` and `exclude_namespace_channels` sat
under one word, `exclude`, alongside root `exclude`, `exclude_health`,
`architecture.<layer>.exclude`, and the per-rule `exclude_readonly` /
`exclude_promoted_only` / `exclude_data_classes` / `exclude_tests` /
`exclude_exceptions` / `exclude_methods` family. `enumeration-exclusion-vocabulary.tsv`
(20 rows) counted every surface carrying that meaning and found six distinct
mechanisms, not the two the record that opened this step
(`docs/internal/plans/rule-vocabulary/FOLLOWUPS.md`, entry 366) expected.

The measurement cut the six a different way than the record assumed. Four
mechanisms earn the word honestly: a candidate is removed *before* a finding
would ever be produced from it — the file is never read (root `exclude`), the
dimension never enters the health formula (`exclude_health`), the class never
joins the layer (`architecture.<layer>.exclude`), or the entity is outside
what the rule measures in the first place (the `exclude_readonly` family).
Two do not: `exclude_paths` / `exclude_namespaces` / `exclude_namespace_channels`
run *after* a rule has already produced a finding, globally on the report
projection or per-rule inside execution output, and throw it away. The
record's premise — "two of three exclusion mechanisms are really
suppression" — was wrong about which two, and about the count: the
population is six, not three.

**What the record did not know when it was written.** The distinction it
asked for already exists in code and is already published.
`Qualimetrix\Reporting\FindingProjection\SuppressionMechanism` is a closed
seven-value enum: the five `FindingFilterStage` cases plus the two halves of
the per-rule exclusion ledger. Its own docblock states that collapsing the
global pair (`PathExclusion` / `NamespaceExclusion`) into the per-rule pair
(`RuleNamespaceExclusion` / `RulePathExclusion`) "would discard a distinction
the product already measures separately," because the two run at different
points in the pipeline — once, globally, inside `FindingProjector`, versus
per rule, inside rule execution itself, before a finding ever reaches the
projector. `--format=suppressed` publishes all seven values as a single
closed vocabulary. A plan draft that proposed treating global and per-rule
suppression as "one behaviour at two levels" would have re-opened a
distinction the codebase had already closed and tested.

## Decision

**Suppression and exclusion are two words for two different times in the
pipeline, and the vocabulary is renamed to say so.** A mechanism that keeps a
finding from ever being produced keeps the word `exclude`. A mechanism that
lets a rule produce a finding and then throws it away is renamed to
`suppress`:

| was                                                                                                           | becomes                                                                                                                          |
| ------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `exclude_paths` / `excludePaths` (root and per-rule)                                                          | `suppress_paths`                                                                                                                 |
| `exclude_namespaces` / `excludeNamespaces`                                                                    | `suppress_namespaces`                                                                                                            |
| `exclude_namespace_channels` / `excludeNamespaceChannels`                                                     | `suppress_namespace_channels`                                                                                                    |
| `--exclude-path`, `--exclude-namespace`                                                                       | `--suppress-path`, `--suppress-namespace`                                                                                        |
| `SuppressionMechanism::PathExclusion` / `NamespaceExclusion` / `RulePathExclusion` / `RuleNamespaceExclusion` | `PathSuppression` / `NamespaceSuppression` / `RulePathSuppression` / `RuleNamespaceSuppression`, values suffixed `…-suppression` |

`ignore_*` was rejected as the replacement word: `@qmx-ignore` is already one
of the seven mechanisms, and reusing its verb for a different mechanism would
recreate exactly the ambiguity this step removes. `suppress` was already the
product's own word for the outcome everywhere it spoke about it out loud —
`--show-suppressed`, `--format=suppressed`, `SuppressionMechanism` itself —
so the rename brings the configuration keys into a vocabulary the product
already had, rather than inventing one.

**Nesting level (global vs. per-rule) stays a scope of application, not a
second mechanism.** `SuppressionMechanism` already separates the two by case
(`PathExclusion`/`NamespaceExclusion` vs. `RulePathExclusion`/`RuleNamespaceExclusion`)
and both halves are published by `--format=suppressed`. This step does not
merge them, and does not introduce a third axis: a root-level key and the
same key under a `rules:` block remain two distinct, already-distinguished
things that happen to share a name because they act at different scopes of
the same finding-production pipeline.

**A retired key must fail loudly, not warn quietly.** An unrecognized key
inside a rule's options previously produced a warning, not a refusal
(`RuleOptionsFactory.php`). Left alone, that path would have made the
migration silent: a configuration written against the old key would keep
running, its suppressions would quietly stop applying, and findings the
project did not expect would appear in the report with no error to explain
why. Each of the five retired spellings therefore gets a named refusal at
both the root and the per-rule level, and the refusal message names both
forks a reader might have meant: `suppress_*` for suppressing findings a rule
still produces, and the root `exclude` for keeping a file out of the analysis
entirely — the confusion this same distinction produced misread three times
over during the X8 rule-vocabulary work that led here.

**`graph:export --exclude-namespace` is out of scope and stays named as it
is.** It narrows which namespaces appear in an exported dependency graph; no
rule runs and no finding is ever produced or suppressed. Renaming it would
apply this step's vocabulary to a mechanism it does not describe.

**The price of that is accepted, not overlooked: one flag spelling means two
things in one binary.** `--exclude-namespace` runs on `graph:export` and is
refused on `check`. The alternative is renaming a live, correct flag so that a
retired one can be refused symmetrically, which costs a working surface to buy
consistency in an error message. Two further asymmetries are accepted with it:
`check --exclude-path=x --help` prints help rather than refusing, because the
application substitutes the help command before `check` ever runs; and a
retired flag is recognized only where Symfony's binding rejects it, so a
*value* that happens to be spelled like one is a value.

## Consequences

- `qmx-baseline.json` is unaffected: no channel code and no subject changes,
  only configuration key and CLI flag spellings. An existing baseline applies
  unchanged after this step.
- `docs/internal/generated/suppression/composition.tsv` is regenerated in the
  same step that renames the keys, so the tracked snapshot never names a
  retired spelling.
- The four honest `exclude_*` mechanisms — root `exclude`, `exclude_health`,
  `architecture.<layer>.exclude`, and the `exclude_readonly` family — are
  unchanged in name and in count; this step is a rename of two mechanisms out
  of six, not a redesign of the other four.
- A future rename of the *channel* vocabulary (rule/channel codes such as
  `complexity.cyclomatic`) is a separate, larger, and deliberately deferred
  step — see `docs/internal/plans/rule-vocabulary/X8-one-string-two-jobs/04-final-naming-step.md`.
  It is not entangled with this one: this step touches configuration keys and
  CLI flags, not channel codes or baseline subjects.
