# 0108. Health Score Applicability and Evaluation

**Date:** 2026-10-09
**Status:** Accepted

## Context

A measured zero and an input that does not exist used to enter health formulas
through the same numeric fallback. Cohesion invented TCC for small classes,
overall health supplied a neutral 75 for missing dimensions, and typing could
report 100 when there was nothing to type. These plausible values hid the
population the score actually described.

Formula errors and authored missing values also shared a logger warning. A
quiet run or a NullLogger could discard the only explanation, while invalid
applicable builtin input could leave a successful partial report. Later report
assembly could then replace an absent class score with a project score.

## Decision

**Applicability belongs to the selected builtin formula.** Each stored builtin
formula level carries an explicit Always, AnyPresent or PositiveSum policy.
Validation examines the raw subject values before a numeric lookup can filter
them. Every supplied non-null policy operand must be a finite number;
denominator operands must also be nonnegative. Zero remains present. PositiveSum
requires an actual positive total. A namespace coupling formula asks exactly
its five current inputs; a function-only namespace with none of them is
inapplicable, while measured Ce=0 and distance=1 retain their computed score 75.

Writing a formula makes that selected level authored, even when its text is
copied verbatim from a builtin. Metadata-only overrides retain the builtin
policy. Namespace-to-project inheritance follows the selected stored formula;
an independent formula at another level retains its own policy and source.

**Evaluation has distinct outcomes.** A pure subject evaluator consumes one
definition, reporting level and raw value map, and returns Value,
NotApplicable, MissingKeys, NoValue or Failure. Runtime orchestration retains
one immutable definitions-and-writers snapshot. Genuine measured-level input
refusals remain in preflight; branch-dependent per-subject absence is judged
only for the branch actually entered. Applicable builtin absence and evaluation
failure use the effective formula source in the existing configuration refusal
and exit 3. Builtin inapplicability is quiet.

Authored MissingKeys and NoValue outcomes publish no scalar. They form an
immutable summary grouped by metric and level, with separate reason counts,
the union of missing keys and at most three deterministic exact subject samples.
Exact declaration identities distinguish duplicate names. Run and Reporting
carry this subject-owned summary beside the existing measured result. Synthetic
construction defaults to an empty summary; copies and merges preserve it.
Directive audit shares computed preparation and failure handling, but keeps its
own verdict document rather than publishing the normal check summary.

**Nullable arithmetic has one supported weighted mean.** `weighted_mean` takes
ordered alternating nullable values and finite positive weights, validates
weights even beside null, skips only null and preserves measured zero. It
divides by the participating weight sum in native term order. With no value it
returns null. The exact enclosing `clamp(weighted_mean(...), min, max)` preserves
that null; ordinary clamp and strict arithmetic on null fail. Other functions
retain native PHP semantics. Health exclusions remove complete canonical terms,
preserving their original weights and order without rounded normalization.

Cohesion combines only the available TCC and LCOM halves, including measured
LCOM for stateless classes. Overall uses only available dimensions and invents
no neutral value. Typing uses the actual summed raw typed and total counters;
the six raw counters preserve zero, while a zero typeable total withholds
percentage metrics and typing health. A positive total with no typed declaration
remains a measured zero. These changes do not revise the underlying academic
cohesion metrics or the existing purity adjustment.

**Publication follows the effective formula and exact subject.** Coverage and
decomposition describe inputs used by the selected formula, not a builtin with
the same name. An authored constant 80 cannot borrow builtin input evidence.
Missing decomposition values remain null, distinct from numeric zero. Coverage
distinguishes measured, not-measured and not-applicable, retaining an explanation
or the measured/eligible share. Enabled builtin project dimensions can remain
visible without a score; disabled dimensions are omitted. An absent selected
class or namespace score never falls back to the project score.

PHP prepares project decomposition and coverage for the HTML payload. The
viewer renders those values without another formula evaluator. Cohesion
contributors follow participating TCC, or otherwise measured LCOM with its
lower-is-better direction. Other contributor ranking and eligibility policies
remain separate decisions.

Every successful normal check JSON report includes `computedMetricOutcomes`,
including an empty array. Text, summary and health publish bounded absence
lines without logger output, including when there are no health scores.
Successful check with resolved format exactly `json` and stdout destination
writes the complete raw document at normal and quiet verbosity; silent emits
no bytes. File publication and other formats retain their existing routes.

## Consequences and migration

Consumers must accept missing numeric health scores and nullable decomposition
values, preserve zero, and inspect coverage state before treating a score as a
statement about all eligible symbols. Remove project fallback for a missing
selected subject. JSON readers should consume `computedMetricOutcomes` separately
from findings and configuration diagnostics; prose readers can rely on the same
bounded summary without enabling a logger.

Custom formulas may intentionally leave a value absent or use a meaningful
`??` fallback. To combine available values, use ordered `weighted_mean` terms
and its exact nullable clamp form. Overall formulas that use health exclusions
must have the supported canonical shape; unsupported shapes refuse with their
source. Recheck health limits and accepted baseline findings against the changed
scores rather than reproducing invented defaults.

The evaluator's former void return becomes an immutable evaluation summary and
its runtime definition input becomes the configured subject owner. Internal
callers must preserve the summary through normal result and report construction.
The resolver's list query remains a projection of the same sourced resolution,
so existing native list consumers do not require a second resolution algorithm.
No public mutable catalog, metric-removal operation or generic lifecycle port
is added. Consumer-visible changes are recorded in the
[Changelog](../../CHANGELOG.md).
