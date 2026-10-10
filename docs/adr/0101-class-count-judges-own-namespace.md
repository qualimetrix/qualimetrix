# ADR 0101: Class Count Judges the Own Namespace

## Status

Accepted. This decision partially supersedes the class-count example in
[ADR 0046](0046-a-channel-declares-the-metric-it-judges.md).

## Context

The class-count rule judged `size.class-count.sum` only at leaf namespaces.
It skipped parents even when they declared enough classes of their own to
breach the threshold. The subtree metric remained published, but its name
did not make the parent's own class count subject to the rule.

## Decision

`size.class-count` judges each namespace's own `size.class-count` value,
including parent namespaces. The channel declares that exact judged metric.
The collected and published `size.class-count.sum` subtree aggregate remains
available to other consumers; it is not a rule input. Defaults remain warning
at 15 and error at 25, with a finding at equality.

## Consequences

A parent with 30 own classes and one child class is judged at 30; the child is
judged at one. A parent with no own classes and many descendant classes is not
flagged by this rule merely because of the subtree total. Existing baselines
or report consumers of this channel may observe changed findings, while metric
consumers of the subtree aggregate retain the same value.
