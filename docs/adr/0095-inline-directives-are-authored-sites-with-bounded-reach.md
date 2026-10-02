# 0095. Inline Directives Are Authored Sites with Bounded Reach

Date: 2026-10-02

## Status

Accepted

## Context

A source directive makes a claim at a particular place in the author's code.
Parser attachment, a declaration's metric identity and a report's publication
filters answer different questions. Treating them as one coordinate caused
midline prose to become a control, comments around a closure to bind to a
nested callable, member controls to reach unrelated findings, and identical
comments on one line to collapse.

The directive audit also treated a refused request as an absence of measurement,
and Reporting tried to rediscover which annotation had removed a finding.
These separate interpretations could disagree with actual suppression.

## Decision

Inline owns admission, declaration binding, reach, refusal classification and
the first actually applied suppression site.

1. Admission is line-oriented. A tag starts a physical comment line after
   decoration. Documentation uses equal paired backtick delimiters or closed
   fences; malformed quoting does not silently excuse authored requests.
2. An authored site retains its physical position independently of the
   declaration bindings it creates. A next-line tag's reported coordinate and
   its target line are separate.
3. Binding identifies the declaration; reach bounds findings inside it.
   Member suppressions use inclusive source-line ranges in the containing
   class/callable, while methods and hooks retain whole-callable reach.
   Promoted parameters participate as parameters and properties. Threshold
   bindings remain whole-only and do not inherit member containment.
4. Direct anonymous callable values bind without searching arbitrary wrapper
   expressions. Docblocks throughout a declaration header belong to that
   declaration; source interpretation does not mutate the cached AST.
5. Refused requests produce one site with nonempty refusal details.
   The validator and audit consume one classifier. Refusal publication follows
   declared channel roles using an internal addressed producer, which is not
   part of the public JSON.
6. Inline records the first suppression that actually applied. Reporting
   consumes this result through its projection contract instead of maintaining
   a second placement/matching algorithm.

Blanket suppressions receive an Effective/Inert judgement; they do not bypass
banned channels. Unused directives default to Warning so a dead exception is
visible at the ordinary warning boundary; an explicit Info setting remains
available.

## Consequences

Source authors must move midline tags to comment-line starts, close quoted
examples, place nested closure controls before the callable, and put member
exceptions on the member they intend to silence. Consumers must handle
Refused and the required refusal list. Incomplete analysis remains stronger
than any directive verdict in the exit ladder.

Line granularity deliberately does not distinguish parameters on one line.
Threshold overrides retain no physical position and can coalesce when the same
rule is written on one line. The suppressed report still labels an annotation
by file and line, so distinct same-line sites share that label.

The contracts stay with Inline; neutral symbol identity remains in Core,
Finding owns threshold requests and channel admission, and CLI publication
remains in Infrastructure. Regression tests exercise these product boundaries;
no additional permanent control of those tests is introduced.

## References

- [Inline policy](../../src/Analysis/Policy/Inline/README.md)
- [Consumer migration](../../CHANGELOG.md)
- [Source syntax](../../website/docs/usage/baseline.md#inline-suppression)
