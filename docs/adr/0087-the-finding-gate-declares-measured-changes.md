# 0087. The Finding Gate Declares Measured Changes

**Date:** 2026-09-27
**Status:** Accepted

## Context

A vocabulary map explains a renamed finding, but cannot explain a finding
introduced or withdrawn, a changed value, a report field added, or a newly
refused input. Treating these as whole-report diffs pairs unrelated neighbours
and either refuses legitimate changes or authorizes more than was measured.

Ranked positions also cannot authorize values. A removed finding can change
which record appears in a published slice, while duplicate identities make a
position insufficient to identify the occurrence whose value changed.

Presentation limits create a second gap: joining rankings to only visible
physical findings leaves hidden fields outside the comparison.

## Decision

The gate uses authored intentions with reasons and exact measured data.
Introduced and withdrawn records, values, schemas, case outcomes, surfaces
and structural input paths have separate forms. One derivation writes all
measured forms and residual diffs after validating the run; unexplained
changes refuse the write. An unused declaration is stale.

Physical finding authority is complete even when the public report truncates
its records. Additional captures must preserve the original population,
visible prefix, ranking, exit and semantic diagnostics. A missing provider
refuses where its data is needed rather than supplying an empty population.

JSON correspondence uses physical fields plus the virtual
`ranking.impactScore` and `ranking.coupling.class-rank` fields from a validated
complete ranking. Physical projections remain the authority for schema supply,
formatter consistency and edits of published bytes. Duplicate identities
pair only byte-equal records as a multiset; a changed duplicate is a withdrawn
and introduced instance.

Ranking has three independent checks. Values follow that same record pairing.
Order follows a longest common subsequence of unchanged paired labels visible
in either side's published slices, retaining equal occurrences. An order
intention measures each moved occurrence. Each published slice must be its
own complete ranking's prefix; incompatible limit intervals require an exact
`topIssues.limit` value change.

Baseline checking owns its JSON authority and complete ranking because an
accepted-level breach promotes severity and changes impact. Output-file and
parallel checks instead reproduce the ordinary JSON publication. Every
invocation has explicit stdout, stderr, exit, file and ranking provenance.

Refusal text and incompleteness are compared outcomes. Run diagnostics remain
byte-compared even when they are not finding records. Normalization comes from
five validated repeated passes and cannot remove semantic record fields.
Input translation names finite YAML and PHP positions; a touched unsupported
grammar refuses instead of receiving a best-effort text replacement.

Every failure class has a producer and every raise site and caller has an
observed self-test witness. Temporary permissions for unimplemented classes
are removed. Corpus controls additionally require observed failures at exact
scopes and reject changes elsewhere.

## Consequences

- Existing gate users migrate record, value and schema declarations to the
  intention/derived-table schemas in the [gate README](../../finding-gate/README.md).
  JSON record declarations include virtual ranking values. Baseline-source
  changes name both their source and baseline-check views where applicable.
- Ordering and slice size use `order/ranking/*` and `field/topIssues.limit`
  intentions instead of positional allowances. Ranking schema changes name
  the `json/ranking` view.
- The failure vocabulary includes `record-ambiguous`,
  `ranking-projection-mismatch` and `ranking-order-mismatch`. Wiring rejects
  the retired `pending` key.
- Complete support and repeated captures cost extra CLI invocations. This is
  the cost of comparing fields hidden by presentation caps rather than quietly
  reducing coverage.
- Correctness inside declared new values, changed-value positions, unpaired
  record positions and order outside both published slices needs independent
  product tests. Changing the population participating in ranking needs a new
  explicit form; the existing bijective join refuses it.
- Internal support formatting, unrelated aggregates and delivery behaviour
  outside captured surfaces are not independent cross-side publications.
  The README names these limits and their separate validation responsibilities.

## Rejected alternatives

- Whole-report diffs for record changes: a diff line does not preserve record
  identity, multiplicity or the independent projection on each format.
- Permissions attached to ranked positions: the position can change without
  identifying the value or duplicate occurrence it would authorize.
- Joining only visible physical records: truncation would retire hidden
  fields from the comparison while the report schema still claims them.
- Compatibility keys for temporary witness exemptions: an empty permission
  today could silently accept an unwitnessed class tomorrow.
