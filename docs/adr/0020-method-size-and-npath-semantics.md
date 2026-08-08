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
