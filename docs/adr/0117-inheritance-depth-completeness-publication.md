# 0117. Inheritance Depth Completeness Publication

**Date:** 2026-10-11
**Status:** Accepted

## Context

[ADR 0107](0107-inheritance-chain-outcomes.md) distinguishes exact inheritance
depth, a numeric lower bound and a cycle without finite depth. Raw class metrics
retain this distinction through `design.dit` and `design.dit-unresolved`, but
successful check JSON did not expose it. The enabled DIT rule's warning could
not make an incomplete measurement visible to a quiet structured consumer or
to a run with the rule disabled. A report without findings could consequently
look complete while its collected inheritance evidence remained unresolved.

Rule abstentions account for selected populations that were not judged.
Computed-value outcomes account for authored formulas with absent inputs or
results. Neither represents the completeness of a collected base measurement:
a numeric lower bound can produce a finding, and an unresolved measurement
exists independently of whether its rule is selected.

## Decision

**Reporting captures collected inheritance completeness once per report.**
`InheritanceDepthCompleteness` reads exact class declaration metric bags. Only
`design.dit-unresolved: 1` participates: a present numeric `design.dit` produces
`lower-bound`, and an absent numeric depth produces `absent-depth`. Exact depths
and declarations without collected unresolved evidence produce no group. The
snapshot describes the collected run, independently of finding selection, rule
activation, thresholds, severity, debt and project-scope metadata.

Each nonempty group has `status`, `count` and `examples`. Counts cover distinct
canonical exact declaration subjects. Examples are sorted unique subjects,
bounded to five per group; physical files and declaration ordinals remain in
the identities. An absent-depth outcome describes missing finite measurement;
it does not manufacture a depth of zero or claim a particular unresolved cause.

**Publish through each format's existing document-diagnostic surface.** JSON
and HTML payloads always include `inheritanceDepthOutcomes`, including `[]`.
HTML shows a visible banner. SARIF adds a `note` invocation notification with
descriptor `QMX-INHERITANCE-DEPTH-INCOMPLETE` and the same groups in its
properties. Text, summary and health use the shared bounded explanation even
when the finding or health report is empty. Quiet structured publication does
not depend on logger output. Checkstyle and GitLab retain ordinary findings
only; there is no synthetic finding for a measurement outcome.

This supplements ADR 0107's rule-owned caveat with independent report metadata.
The rule warning and numeric floor finding wording remain separate behaviour.
No rule abstention, authored computed-value outcome, policy severity, debt or
exit-code change is introduced. Raw metric keys and ancestry resolution remain
Design-owned; Reporting owns their bounded publication projection.

## Consequences

Consumers can distinguish a clean finding list from complete inheritance
measurement without activating a rule or parsing stderr. Strict JSON/HTML
schemas must admit the new field; SARIF consumers must distinguish invocation
notes from rule results. Consumers must not count these groups as findings or
use `absent-depth` as numeric zero. The bounded examples are explanatory, not
an exhaustive declaration export; raw metrics retain detailed evidence.

The snapshot reflects the repository supplied when constructing `Report`;
native report construction and copying must retain that measurement input.
No generic measurement-outcome registry or new cross-capability contract is
introduced. Migration is recorded in the [Changelog](../../CHANGELOG.md).
