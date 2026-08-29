# 0035. A Metric Key Names Its Family, in Kebab

**Date:** 2026-08-28
**Status:** Accepted
**Refines:** [ADR 0033](0033-display-family-is-derived-from-the-producer-name.md),
which made a producer's family the first segment of its name. This ADR gives the
metric vocabulary the same shape, and records why the two vocabularies are
allowed to arrive at identical strings.

## Context

Metric keys and rule names were two vocabularies with nothing in common but the
values they described. A rule was `complexity.cognitive`; the metric it read was
`cognitive`. A rule was `size.class-count`; the metric was `classCount`. Ten
keys carried a dot (`typeCoverage.paramTotal`, `halstead.volume`), sixty-one
carried none, three carried an underscore (`ce_packages`, `rfc_own`,
`pureMethodCount_cohesion`), and eleven more were published from collector-owned
constants outside `MetricName` altogether.

Two costs were measured rather than argued.

A key with no family cannot say who owns it: `loc`, `mi`, `noc` and `woc` are
published side by side and nothing in the name says which capability produces or
interprets them. And the product's own convention already split the two
spellings — `docs/internal/CLI_CONVENTIONS.md` puts YAML keys in snake and every
identifier a user reads in output into kebab.

## Decision

A metric key is `family.metric`, in lower-case kebab, where the family is the
subject the metric belongs to and not the collector that happens to produce it.
`MethodCountCollector` lives under `Size` and publishes seven `design.*` facts
about a class's shape; the family follows the meaning, and the layout defect is
recorded in `AUDIT.md` rather than encoded in a name.

Three things follow, and all three are decisions rather than consequences.

**Words do not change, only spelling and family.** `ccn` becomes
`complexity.ccn`, not `complexity.cyclomatic`. The step is about grammar; a
change of word is a change of vocabulary with its own radius, and the direction
of that change is not obvious — the cheaper fix for `ccn`/`cyclomatic` is
renaming the channel, not the metric. Three such pairs remain and are recorded
in `FOLLOWUPS.md` with both costs.

**A metric and the rule checking it may be the same string, and often are.**
`size.method-count` is a key and a producer; so are `cohesion.lcom`,
`design.noc`, `complexity.wmc` and about twenty more. That is the point: one
thing, one name. The exact set is generated from the rename table rather than
listed here, because a list in prose is a number that stops being true.

**The aggregation suffix is part of the key's spelling, not of its identity.**
`complexity.ccn.avg` is the same key seen through a strategy from a closed list,
and anything that decomposes such a name must recognise the suffix from that
list rather than by cutting at a dot.

## Consequences

Every published metric key changed, in `--format=metrics`, `--format=json` and
the HTML report. Consumers keying on the old names read nothing; the mapping is
in `CHANGELOG.md` and in the gate's `finding-gate/maps/metric-keys.tsv`.

`RuleIdentifierLiteralGuardTest` loses coverage exactly on the names in the
overlap: a literal that is both a key and a code no longer proves a channel
reference. The cost is stated in the test and in `FOLLOWUPS.md`; nothing textual
can recover it, because both readings are the same string in the same file.

The gate's key map applies only to the surfaces that publish keys. Half the
metric vocabulary is an ordinary English word — `cognitive`, `distance`,
`instability`, `abstractness` — and on a prose surface a whole-name rule is no
protection: the reference's "Maximum method cognitive complexity is 29" came
back rewritten. A key that later reaches a surface outside that list is not
translated and the run goes red, which is the direction this has to fail in.
