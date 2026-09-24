# 0081. A Channel Not Named After Its Producer Describes Itself

**Date:** 2026-09-23
**Status:** Accepted

## Context

A channel's display text — published, for example, as a SARIF rule's
`shortDescription` and `fullDescription` — was resolved from its producer:
`ChannelPresentationView` joined the channel to the producing rule and
published that rule's `getDescription()`.

That is the right text for the channel named after its producer and the wrong
text for every other. Of the 59 static channels, 14 are not named after their
producer, and all 14 were published with a description of something else:

- the seven `architecture.*` diagnostics besides `layer-violation` and
  `unassigned-class` read "Detects dependencies between layers that are not
  explicitly allowed by the architecture policy" — for an exclude clause that
  removed nothing, an assignment in doubt, an empty template;
- the four `annotation.*` channels shared one family sentence about inline
  directives, and the three `suppression.unmatched-*` channels one about
  suppression values.

Nothing refused this, and a new diagnostic channel would have joined the list
silently: the join had no way to know a channel was not the producer's own.

## Decision

**The description belongs to the channel declaration, and registry assembly
decides which declarations must carry one.**

`ChannelDeclaration` gains an optional `description`, set by the wither
`describedAs()`, which refuses blank text. The factories keep their signatures;
the configuration-error stamp carries the text across.

`ChannelDeclarationCompilerPass` applies one rule to both producer kinds:

- a channel whose name differs from its producer's must declare a description;
- the channel named after its producer must not — its producer's
  `getDescription()` describes it, and a second text beside it would leave no
  rule about which one is published.

Either violation refuses the container build. "Named after its producer" is
decided by the name, not by how many channels the producer has:
`annotation.unused-directive` is the only channel its rule declares and still
not the producer's own name (`annotation.directive`).

`ChannelPresentationView` reads the declared description through the channel
declaration registry and falls back to the producer's description only for a
channel with none. A configured `computed.*`/`health.*` channel is synthesised
at run time without a description, gets the family text there, and
`ComputedMetricChannelPresentation` still replaces it with the definition's own.

## Alternatives considered

- **A description map on the presentation view, built by the compiler pass.**
  It would be a second place that knows which channels exist, beside the
  declarations themselves, and the refusal would have to compare the two.
- **A description on every channel, primary ones included.** It duplicates each
  rule's `getDescription()` 45 times, and `qmx rules` would keep reading the
  rule's copy, so the two could drift apart unnoticed.
- **Falling back to the producer's text when a channel declares none.** That is
  the state this record replaces: the fallback is exactly what published the
  wrong text, and it would stay silent for the next channel.

## Consequences

- Every non-primary channel carries a sentence describing its own finding; the
  SARIF `rules[]` entry for it changes accordingly. Rule-level output (`qmx
  rules`) is unchanged, because rule descriptions are unchanged.
- A producer that adds a channel under another name cannot build the container
  until it states what the channel reports.
- `ChannelDeclarationFixtureDriftTest` compares structural facts; display text
  is held by `ChannelDescriptionTest`, which sweeps the real universe and
  requires every non-primary channel's text to differ from its producer's and
  from every other channel's.
