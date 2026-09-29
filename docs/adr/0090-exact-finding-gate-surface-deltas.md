# 0090. Exact Finding Gate Surface Deltas

**Date:** 2026-09-29
**Status:** Accepted

## Context

Semantic declarations explain record, value, schema and outcome changes, but
some legitimate changes have no correspondence those forms can express.
An ordinary structural delta cannot authorize an unpaired record population.
Its 200 changed-line limit also refuses non-record publications even though
their full bytes are measured.

Baseline projection has a separate constraint: not every finding group is
written by the product's baseline generator. Requiring an entry for every
withdrawn group invents a projection the product does not promise.

## Decision

Each compared tree supplies the baseline eligibility partition by invoking its
own existing generator over the complete raw source groups and captured
configuration. Rename maps are applied afterwards. Eligibility comes from
product behaviour rather than a channel allow-list maintained by the gate.
Eligible groups still require exact baseline correspondence.

Ordinary deltas keep their record restrictions and 200 changed-line limit on
record-bearing publications. Non-record publications retain full byte precision
without that size limit. The existing diff computation budget remains.

A separate exact case/surface intention is the last resort after semantic
forms. An isolated trial decides whether those forms explain the publication;
a fully explained surface makes the exact intention stale. Otherwise the
whole normalized surface is measured before semantic erasure, and its records
are judged through this exact measurement rather than cross-side pairing.

The measurement includes length-framed visible bytes and the complete
comparative record authority. For finding projections this is an
occurrence-preserving physical multiset with its joined ranking values,
including hidden records. JSON numeric tokens are preserved. Same-side source,
projection, completeness and validity checks remain mandatory. Support metadata
and order outside published slices remain outside the cross-side promise.
An additional undeclared byte or authority change fails the measured delta.

This supersedes ADR 0087's rejection of whole-report diffs when the explicit
whole-surface route supplies the complete authority described here. It does
not replace semantic declarations or their narrower explanations.

## Consequences

- Consumers author `declared-exact-surfaces.tsv` with the exact case, surface,
  measured file and reason, then use the existing declaration derivation.
- Ordinary and exact delta intentions cannot overlap. Consumed measurements
  remain specific to their reference and must be retired with it.
- Exact deltas preserve precision but explain changes more coarsely than
  semantic forms. Reviewers inspect the entire framed measurement.
- Baseline capture incurs one product eligibility probe for each required
  source publication. Missing or invalid probe data refuses the comparison.
- The exact route catches an undeclared cross-side change in a residual no
  semantic form can represent; product regression tests alone cannot compare
  the external corpus against an arbitrary Git reference. It reuses complete
  captures and adds an in-process semantic trial and bounded diff computation.
  A stale intention, missing authority, exceeded diff budget or changed product
  probe constructor can refuse a legitimate change until its data is refreshed.
- Existing negative controls exercise the new route through their report
  contract and rename plants; no additional control family is introduced.
