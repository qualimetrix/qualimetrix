# ADR 0110: Complete offender ranking and catalog thresholds

## Status

Accepted

## Context

Global health summaries and class drill-downs built offender records
independently. They could disagree about finding ownership, dimension keys and
the population available to presentation filters. Truncating the global list
before selecting a namespace or class hid valid candidates. The `count` option
name described a default order that actually followed health scores.

Reason thresholds and console colors also used literals independently of the
resolved definition catalog. Namespace records combined subtree measurements
with a field named as an own class count.

## Decision

ComputedMetrics builds one complete offender snapshot for each report. One
ranking service owns namespace and exact class records. Namespace eligibility
requires at least one own class, interface, trait or enum; a container with only
child declarations is excluded. Namespace record values describe its subtree,
while class record values describe that exact declaration. The global namespace
is an isolated leaf: its finding count includes only its own population.

Reporting retains both complete lists. Class drill-down selects those captured
records without measuring or ranking again. Namespace selectors form a union
and the internal class selector retains every matching exact declaration.
JSON and summary `--class` still require one exact declaration for their
separate class health heading; duplicate logical names refuse with exit 5.
Use a namespace selection to display all their exact offender records. Each formatter
selects the complete population, applies the shared comparator and only then
limits its output. Score order is ascending health; density order is descending
findings per 100 LOC. Ties use logical spelling and then exact subject identity.
The HTML viewer orders its local child population by score, display name and
exact node identity; it does not promise the global JSON ranking.

The project/offender snapshot captures resolved health definitions once per
report. The same definitions supply project score metadata, offender reasons
and overall warning/error thresholds. Selected class/subtree score headings
retain their separate `HealthScoreDrillDown` resolver.
Offender records carry their exact subject and overall threshold pair. Their
logical symbol and source file derive from that subject, so callers cannot
supply contradictory identities. Console
colors consume that pair. Missing required built-in thresholds refuse rather
than inventing defaults. A later report captures its current catalog anew.

`healthScores` contains the five available component dimensions; overall is
published only as `healthOverall` and never becomes a reason dimension. The
namespace class count is named `size.class-count.sum`. Summary advice names the
true remaining population and the `top` value needed to show it. HTML names its
local available population before limiting it to ten visible entries.

## Consequences and migration

- Replace `rank-by=count` with `rank-by=score`, or omit it. The accepted values
  are `score` and `density`; `count` refuses with exit 3.
- Read the composite from `healthOverall`, not `healthScores.overall`.
- Read namespace subtree class counts from `size.class-count.sum`, replacing
  `size.class-count`. Namespace eligibility includes type-only namespaces.
- Construct offender records with their exact `MetricSubject` and resolved
  overall threshold pair. Replace independent symbol/file constructor inputs
  with the subject; both the constructor and `fromEvidence()` take subject,
  overall score, label, reason, evidence and thresholds. Preserve the complete captured lists when copying
  reports; apply display limits after selecting candidates.
- Reasons, labels and console colors follow the effective catalog thresholds.
  No compatibility alias or literal threshold fallback is retained.

Base formulas, calibration and project benchmark expectations are unchanged.
