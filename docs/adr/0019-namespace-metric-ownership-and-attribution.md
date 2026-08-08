# 0019. Namespace Metric Ownership and Attribution

**Date:** 2026-08-08
**Status:** Accepted

## Context

File-scoped collectors historically contributed their whole-file bag to one
derived namespace. That model loses attribution when a PHP file contains more
than one bracketed namespace block. It also makes a tempting but incorrect fix:
splitting a physical file bag into namespace bags and then summing those bags at
project level can double-count pre-namespace code or count the same file more
than once.

Git report scoping had the same single-namespace assumption, so a changed file
could fail to retain violations for its later namespace declarations.

## Decision

Physical ownership and namespace attribution are separate contracts.

Collectors that can attribute source contributions implement
`NamespaceMetricProviderInterface` and return `NamespaceWithMetrics` values for
each namespace source block. Namespace aggregation prefers these explicit
contributions. LOC/LLOC/CLOC use the inclusive source span of each namespace AST
node; structural counts are attributed to the block that owns each declaration,
including zero-valued contributions for empty blocks.

The physical file `MetricBag` remains the only source for project aggregation.
Code before the first namespace stays file-owned, and a file without namespace
declarations contributes its full span to the global namespace. This preserves
the invariant that project totals describe each physical file exactly once.

Git scope indexing records every namespace declaration in each changed file,
not only the first one.

## Consequences

- Namespace metrics are correct for multi-namespace files without inflating
  project totals.
- Namespace attribution can no longer be inferred safely from a file-level bag;
  collectors with source ownership data must expose it explicitly.
- Parallel wire results carry namespace contributions alongside method and class
  contributions.
- New file-scoped metrics must state whether they are physically owned only or
  can be attributed to namespace source blocks.
