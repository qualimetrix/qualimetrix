# 0109. Rule Populations and Coupling Units

**Date:** 2026-10-10
**Status:** Accepted

## Context

An empty finding list previously could mean healthy subjects, missing metric
publication, an excluded native population, or evidence that could not be
judged. Reporting findings alone could not distinguish those cases. Rebuilding
healthy counts from emitted findings would lose both healthy subjects and
pre-comparison exclusions, and would disagree with direct rule execution.

ClassRank used an academic probability beside graph-size-scaled rule limits and
ranking fallbacks in another scale. A probability depends on the graph's vertex
count; the old rule limits did not name a stable unit. Distance mixed subtree
metric aggregates with an own-namespace population floor, allowing descendants
to stand in for the namespace being judged.

## Decision

**Eligibility belongs to the channel declaration.** Each reporting coordinate
carries its ordered native gates and exact population unit. Seven closed
predicate families express required metric presence, metric thresholds, active
flag exclusions, known kinds, native name matching, rule-computed thresholds
and context facts. An enabled invocation reuses its full declaration; direct,
unselected and selected calls evaluate the same pure predicates. Selection
controls accounting, not eligibility. The first failing gate owns the absence.
Numeric zero stays present; missing-as-zero behavior exists only where that
native condition declares it. Required invalid operands refuse rather than
becoming healthy or unjudged values.

**Population accounting is compact and per call.** Each selected rule execution
or hosted measurement owns one partition. Healthy members increment judged
counts; failed members increment a group with a reason, unit and at most five
sorted distinct canonical examples. Healthy identities, metric bags and other
transient inputs are not retained by the frozen result. Exact declaration
identities distinguish duplicate names, while each native producer owns its
roster's uniqueness. This replaces global healthy-identity union semantics:
independent calls add counts even when they judge the same canonical identity.
Adopting the same frozen partition again is idempotent. Repeated freezing of
one session preserves its partition identity.

Run results, late filtering results and reports preserve immutable populations
through copies and merges. Hosted audits capture the actual publication used
by the invocation. Their produced findings remain separate from selected
accounting, including counterfactual inline audits. Resolved computed metric
definitions share one successful document resolution without retaining the
document; computed evaluation outcomes retain their independent summary.

**Academic PageRank and uniform-relative share are separate metrics.**
`coupling.class-rank` remains raw PageRank probability with total probability
one on a nonempty measured logical graph. `coupling.class-rank-share` is raw
probability multiplied by N, the number of analysed logical PageRank vertices,
and therefore measures multiples of uniform probability. N contains graph
vertices with a logical subject in the metric repository; unmeasured external
vertices do not enter it. Named PHP classes, interfaces, traits and enums,
including isolates, participate in this measured population. Duplicate declarations do not
inflate it. An empty graph publishes no per-vertex value; a singleton has raw
probability and share one. This changes no PageRank formula.

The ClassRank rule joins measured declarations with exact graph PHP-kind facts,
judges classes including abstract classes, and requires positive afferent
coupling. Other PHP kinds retain graph evidence without entering this rule's
class population. Missing or conflicting exact kind facts are invariants, not
invented healthy classes. Rule defaults are fixed shares 5 and 10; equality
crosses the boundary. Display uses the shortest common precision from two to
six decimals that preserves the visible distinction, and labels remaining
rounded collisions. Severity and findings retain unrounded values.

Prioritization reads share through direct, declaration, file, namespace and
median paths, with no raw-probability fallback. Its native missing-data median
fallback remains in the same units as measured inputs. A changed analysed graph
changes N and PageRank: share supports current-graph judgement, not a claim that
scores from different graph scopes are directly comparable. ClassRank findings
retain occurrence ceilings rather than graph-relative numeric baseline limits.

**Distance judges one namespace's own evidence.** Its D, A, I, Ca and Ce inputs
are own publications; subtree aggregates remain available as evidence for other
consumers. The own population floor counts classes, traits, interfaces and
implementing enums, excluding bare enums. Positive own Ca+Ce is required; an
uncoupled namespace is outside this judgement. The option is `min_type_count`.
CBO and Instability retain their existing population scopes and options. CBO
advice displays the shortest namespace suffix distinguishing selected dependency
identities without collapsing same-basename targets.

**Reporting describes unjudged populations separately.** Successful check JSON
contains `abstentions`, including an empty array, with producer, channel, level,
gate, reason, unit, count and bounded examples. Normal text, summary and health
provide a compact indication; verbose output adds bounded explanations,
including on empty finding reports. SARIF uses
an invocation note, GitHub a notice, and HTML a banner and payload. These are
not findings, severities, baseline identities or suppression counts. They do
not change the policy exit code. Computed metric outcomes, input-file coverage
and score coverage remain independent. Quiet JSON and report files preserve
their complete data; successful silent stdout remains empty.

## Consequences and migration

Consumers must preserve immutable population values in native result and report
copies, and use the captured publication for hosted audit result operations.
Count only comparable units; an invocation with unknown evidence is not a
fabricated roster of declaration failures. Empty unjudged groups establish no
claim about disabled or unselected populations.

Migrate ClassRank limits to uniform-relative share and review accepted findings
against the actual graph. Replace `RankedIssue::classRank` with `classRankShare`
and JSON top-issue `coupling.class-rank` with `coupling.class-rank-share`.
Metric exports keep both raw probability and share. Replace Distance's retired
`min_class_count`, `min-class-count` and `minClassCount` spellings with
`min_type_count`; retired spellings refuse without aliases. Review own-namespace
Distance findings rather than importing a descendant aggregate as its value.

JSON consumers must accept `abstentions` and keep it separate from violations
and `computedMetricOutcomes`. Audit callers must consume findings and
population together. The [Changelog](../../CHANGELOG.md) records the breaking
surfaces; no compatibility shim, generic lifecycle port or inferred global
population registry is introduced.
