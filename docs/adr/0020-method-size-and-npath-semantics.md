# 0020. Method Size and NPath Semantics

**Date:** 2026-08-08
**Status:** Accepted

## Context

Maintainability Index consumed `methodLoc` from the Halstead collector. That
value mixed concerns: Halstead owned method size, counted visited source lines
rather than semantic statements, and fell back to physical span. Formatting and
AST traversal details could therefore change MI independently of program
structure.

NPath expression handling had a related ownership problem. Branch-producing
expressions were recognized only in selected wrapper positions, so nested
ternaries, boolean operators, nullsafe access, loop slots, `echo`, switch case
conditions, and match arms could disappear from the result. A simple `match`
could even report fewer paths than the equivalent `switch`.

## Decision

Method size is a Size-domain metric named `methodStatementCount`. A dedicated
collector counts executable and control-flow statements recursively, excludes
nested callable bodies from their enclosing callable, and treats an arrow
function body as one synthetic statement. Maintainability Index consumes this
metric directly and clamps only the logarithm input for an empty method.

The public migration is:

| Old surface                               | New surface                                                      |
| ----------------------------------------- | ---------------------------------------------------------------- |
| Metric `methodLoc` / `halstead.methodLoc` | Size metric `methodStatementCount` / `size.methodStatementCount` |
| Option `minLoc`                           | `minStatements`                                                  |
| YAML / `--rule-opt` key `min_loc`         | `min_statements`                                                 |
| CLI alias `--mi-min-loc`                  | `--mi-min-statements`                                            |

No compatibility aliases are retained. Existing MI values, aggregates, health
scores, thresholds, and baselines may shift and must be reviewed.

NPath expression semantics live in `NpathExpressionCalculator`. Transparent AST
wrappers recursively carry contributions from expression-bearing children;
closures, arrow functions, and anonymous-class bodies form separate scopes.
Nullsafe access contributes its conditional short-circuit path. Every `match`
arm contributes at least one path, with subject, arm conditions, and arm bodies
included. Expression-bearing slots in `for`, `foreach`, `switch`, and `echo` are
included. Arithmetic saturates at the documented cap.

## Consequences

- Halstead no longer owns an unrelated size metric.
- MI is formatting-independent but values differ from earlier releases.
- NPath values for wrapper-heavy, match, nullsafe, loop, switch, and echo code
  can increase; thresholds and baselines may need recalibration.
- NPath rules still use the same external metric name and threshold surface; the
  breaking change is semantic rather than syntactic.

## Amendment: null-coalescing value selection

PHP's `??` and `??=` select a value like a ternary, rather than expressing a
Boolean conjunction or disjunction. Their ordinary expression contribution
is therefore 2, the same as the existing ternary recurrence, instead of 1
(which statement `max(1, ordinary)` could absorb entirely). Boolean operators
retain +1. Nullsafe `?->` contributions remain separate: a callable statement
uses `max(1, ordinary) + nullsafe`, with one nullsafe path per hop.

This is a PHP extension to Nejmeh (1988), which predates these operators.
A chain `$x ?? $y ?? 5` reports 4, like the existing nested ternary recurrence,
even though both have three distinguishable outcomes. A special chain rule
would make equivalent value-selection forms disagree and is not introduced.

The old `complexity.npath` contribution for `??` and `??=` was 0 or +1 at a
statement boundary; the new ordinary contribution is +2 per operator.
`return $x ?? 5` changes from 1 to 2, and two chained coalesces from 2 to 4.
Consumers must re-run analysis and review NPath thresholds and accepted
baseline magnitudes. No metric key, option or default threshold changes.

The Maintainability documentation also names the existing variants explicitly:
Halstead bugs use the later `B = V / 3000` estimate rather than the original
`E^(2/3) / 3000`; MI uses the Visual Studio three-term normalized variant.
These clarifications do not change either calculation.
