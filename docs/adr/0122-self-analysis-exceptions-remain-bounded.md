# 0122. Self-Analysis Exceptions Remain Bounded

**Date:** 2026-10-15
**Status:** Accepted

## Context

The repository judges its own code with the same rules it publishes. Its v14
baseline records accepted residual magnitudes and occurrences as ceilings;
it is not a list of accepted warnings. Some warnings and errors predate the
current work. A passing selfcheck therefore does not, by itself, distinguish
an improved design from a finding hidden indefinitely. Nor does an exception
remain useful merely because it is still present in configuration.

Self-analysis combines several different signals. Incoming popularity of a
public contract can be intentional, whereas outgoing coupling, complexity,
cohesion and size describe code quality. Instability with very few incoming
edges can overstate a leaf's architectural risk. One global response to all of
these would either conceal debt or reject legitimate design.

## Decision

Judge each finding by what it measures, at its exact subject and channel:

1. Fix a real quality issue in its owner. If the remaining design is cohesive
   but the metric models it poorly, keep the quality signal visible under a
   finite ceiling: a reasoned `@qmx-threshold` for a class or method, or an
   exact baseline entry for a namespace or project. A change to an accepted
   magnitude requires a measured, reviewed update of that entry. Never
   regenerate the entire baseline to accept incidental changes, raise a global
   rule threshold for local debt, or move a bounded quality signal to an
   unbounded `suppress_*` entry.
2. Exclude an intentionally popular contract hub only at the exact subject and
   channel where incoming readership explains the finding. The exemption can cover a
   namespace whose own declarations all belong to its `Contract` subject. It
   does not cover outgoing breadth of an implementation merely because that
   implementation sits in a `Contract` directory. The shared configuration
   refusal carrier is one such incoming hub: splitting it by refusal source
   would fragment one exit and provenance contract. Raw PageRank and the
   uniform-relative `coupling.class-rank-share` are separate metrics under
   [ADR 0109](0109-rule-populations-and-coupling-units.md). The exact
   popularity exclusion is justified by the carrier's role, but its continued
   activity is established only by observed suppressed findings. Changes to
   the measured graph can change the share; readers alone do not keep the
   exception live. This is a narrow population decision, not a general
   exemption for Configuration.
3. Allow a category exclusion only for structural inapplicability, such as a
   visitor population for a rule that cannot meaningfully judge visitors.
   Record the current affected classes and hidden findings when choosing its
   scope. A directory label alone is not sufficient evidence; Console, for
   example, contains both composition adapters and ordinary value objects.

An exact `coupling.cbo` exception has a directional cost. CBO judges the union
of incoming and outgoing dependencies, even though its finding describes Ca
and Ce separately. For a popular shared type such as `ConfigSchema` or
`CommandLineSpelling`, excluding the whole channel also hides future outgoing
growth on that subject from the CBO verdict. Exact scope limits which subject
is hidden, not which edge direction is hidden. A separately judged outgoing
signal could restore that visibility; it is a candidate, not a current guard.

Remove an exception when it no longer hides a finding. Check both suppression
composition (`neverMatched`) and inline directive audit (`inert`); do not retain
dead entries as placeholders for a future regression. A category entry remains
valid while it actually suppresses at least one applicable finding. If an
entry's activity changes because unrelated subjects alter its measurement,
reconsider the metric model or use an exact finite ceiling instead of preserving
the drifting suppression.

Class instability exposes a population limit: raw incoming edge count includes
composition roots and same-subject collaborators, which need not be independent
dependents in the Stable Dependencies Principle. Existing small-population
ceilings acknowledge that limit; they do not claim the principle has been
satisfied. In particular, the remaining traversal context and finding
projection each compose already separated owners. A further subject split has
not been demonstrated, so their accepted instability stays under exact finite
ceilings. The next outgoing edge or a lost incoming edge must be visible.
Shared option vocabularies are another lever: expanding one option-shape
contract can raise outgoing breadth of several option classes at once. Review
that shared change and its affected ceilings together, rather than treating
each class as unrelated debt. A future population predicate may distinguish
independent external dependents from composition and same-subject edges, but
must be specified and tested before it replaces these local decisions.

## Consequences

For a temporary cross-subject concentration awaiting a real subject split,
record each affected finding and its owner. A warning magnitude can remain in
the baseline; a class error can use a narrow inline threshold. A namespace
quality error also needs an exact finite baseline ceiling: an exact namespace
suppression would hide subsequent growth. The handoff is complete only when
the exact keys, current magnitudes and replacement decisions are checked;
merely naming future work does not turn a missing ceiling into a guard.
These ceilings limit measured debt already present; they do not authorize a
new error or a higher accepted magnitude without its own decision.

Each self-analysis decision records the signal, category, current and accepted
magnitudes, and reason in the change report. Source reasons describe the
durable design cause rather than embedding measurements that will drift.
Selfcheck still requires the exact architecture manifest check before the qmx
ratchet. These practices preserve a visible upper bound on accepted quality
debt while letting measured improvements tighten it.
