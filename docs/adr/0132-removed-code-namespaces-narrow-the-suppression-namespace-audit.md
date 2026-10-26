# 0132. Removed Code's Namespaces Narrow the Suppression Namespace Audit

**Date:** 2026-10-26
**Status:** Accepted

Supplements [ADR 0093](0093-measured-run-scope-and-project-tree-queries.md):
its rule that the project-scope audit "opens no source" now has one bounded
exception, the `namespace` statements of removed code. The two measured
questions, the other doors and the report states keep their ADR 0093 meaning.

## Context

An authored `exclude` that removes any PHP file, or any file skipped as
`@generated`, made `judgesNamespaceClaims()` false. Every namespace-absence
channel was then withheld: the removed file could have declared any namespace,
so absence of one could not be proved. The reason is sound; its reach was not.
One excluded `Kernel.php` switched off the audit of every `suppress_namespaces`
value in the run, so a stale `exact:App\Does\Not\Exist` went unreported while
`projectScope.state` still read `covered`. The only trace was the
`unjudgedChannels` array and a summary line.

Which namespaces the removed code declares is cheap to read without analysing it.
For two of the eight withheld channels, `suppression.unmatched-namespace` and the
namespace values of `suppression.unmatched-rule-ledger`, absence of a literal
selector follows exactly from that set. The other six need more than namespaces:
class FQNs, method names, references, attributes, ancestors.

## Decision

1. **Only literal namespace suppression selectors narrow.** An `exact` or
   `subtree` namespace value, global or under `rules.<name>`, is judged when the
   removed code cannot have declared a namespace it binds. `judgesNamespaceClaims()`
   is unchanged and stays strict for its other readers; a new Finding question,
   `ProjectScopeJudgement::judgesNamespaceLiteral()`, answers for these values.
2. **`regex` selectors are unchanged.** Case semantics of a regex over a removed
   namespace are ambiguous, so a stale regex stays withheld under removal.
3. **Only two doors narrow:** `Exclude` with `php-file` evidence, and
   `Generated`. Omitted PHP paths, an unknown universe, an unlistable excluded
   directory, a missing PSR-4 map and unmeasured scope withhold as before.
4. **Run measures, Finding judges.** Discovery reads the removed code once and
   carries one aggregate, Finding's `RemovedCodeNamespaces` (`none`, `known`,
   `unknown`), beside the selector verdicts into `ProjectScopeMeasurement`. A
   file that cannot be read, a directory that cannot be listed, or an exhausted
   budget makes the aggregate `unknown`, which withholds every literal as before.
   `ExcludeSelectorVerdict` is not widened.
5. **The scan is a token scan.** `RemovedNamespaceScan` reads a whole file and
   tokenizes it: block and semicolon namespaces, several per file, the global
   block, `namespace\foo()` not counted, comments and strings ignored. No AST, no
   rules. Comparison is ASCII case-folded like the namespace index, and the
   ancestors of a declared namespace count as declared (as in `NamespaceTree`), so
   a file in `App\Foo\Bar` also withholds `exact:App\Foo`. Global code declares
   the empty namespace.
6. **Descent is complete.** The early exit at the first PHP file of a removed
   entry is gone, and so is the second short circuit that skipped further entries
   of a selector that already had `php-file` evidence: the aggregate needs every
   removed entry. An unlistable child makes the aggregate `unknown` even after a
   PHP file was found. `@generated` files get a separate full read, because the
   generated-file filter reads only a 2,048-byte header.
7. **A read budget bounds the cost.** One `RemovedNamespaceBudget` per walk allows
   2,000 file reads, shared by excluded and generated files. Past it the aggregate
   is `unknown` and the descent returns to stopping at the first PHP file, so
   `check .` with `exclude: [vendor]` does not read a dependency tree.
8. **Publication follows judgement.** `ReportProjectScope::measured()` omits
   `suppression.unmatched-namespace` from `unjudgedChannels` when at least one of
   its values was judged. The directive audit computes no values and still names
   the channel. The other six namespace-claim channels (`architecture.*`,
   `cohesion.unmatched-exclude-method`, `coupling.unmatched-framework-namespace`)
   stay all-or-nothing.
9. **`check` says what remained.** When exclusion or generated filtering is the
   only reason namespace suppression values stayed unjudged, `check` writes a
   stderr warning naming the exclusion, the count of unjudged values and their
   channels; `-q` silences it. `directives` and `baseline:*` print none. The
   `exclude` reason stays in `projectScope.reasons`.

Rejected:

- **Lazy scan on demand.** It needs file IO inside a Finding contract type and
  breaks "Run measures, Finding judges".
- **Scanning FQNs, methods and references to narrow the other six channels.**
  That is a second analyser without an AST.
- **Making the scan unbudgeted.** A project excluding `vendor` would read every
  file of it; see the measurement.

## Consequences

- A stale literal namespace selector is reported although unrelated files are
  excluded; a selector the removed code could have declared stays withheld and
  is named.
- Regex selectors and six channels keep the old all-or-nothing behaviour; the
  website names this as a limitation. The warning is a `check` surface only.
- `unjudgedChannels` no longer proves that every value of an absent channel was
  judged; that was already true of value channels (ADR 0093) and now holds for
  `suppression.unmatched-namespace`. `unjudgedValues` stays the complete list.
- Project scope reads removed source, bounded by the budget. Reading is
  best-effort: every failure degrades to the previous withholding, never to a
  judgement.

Measured cost, median of 7 runs of `ProjectFiles::discover` on synthetic
projects:

| Project                                                          | Before     | After      |
| ---------------------------------------------------------------- | ---------- | ---------- |
| 6,500 files, 3,000 excluded and 3,000 `@generated` (over budget) | 361–383 ms | 629–649 ms |
| 2,000 files, 1,500 excluded (under budget)                       | 39–50 ms   | 253–303 ms |

A read costs roughly 130–170 µs, so the budget bounds the addition at about
2,000 reads (+250–300 ms). Without it the 6,500-file case cost about three times
its discovery time.
