# 38. An options class names its own warning boundary

Date: 2026-08-30

## Status

Accepted

## Context

`baseline:explain` prints, next to a baseline entry, the warning boundary the
channel is configured with. The number lives on a rule's options object, and
`BaselineConfiguredThresholds` used to find it by **guessing the property
name**: a hardcoded list of three (`warning`, `maxWarning`, `minWarning`), a
name derived from the channel's suffix, and a count of `…Warning` properties
used to detect that a class holds more than one boundary.

The guess answered a different question than the one being asked. "How is this
property spelled" is not "what does this object decide with", and the two parted
company in a measurable way: `coupling.distance` spells its boundary
`maxDistanceWarning`, the name was not on the list, and `explain` printed "not
resolvable" for a channel whose boundary is configured and working. The reader's
own docblock had grown to sixty lines explaining why the remaining answers
happened to be right.

The failure mode is silent and open-ended. A fourth spelling is repaired only by
someone remembering to extend a list inside a console command, and the class
that introduces it gets no signal at all.

## Decision

**A class that holds a warning boundary says which one it is.**
`ThresholdAwareOptionsInterface` gains

```php
public function warningBoundary(): int|float|NoConfiguredBoundary;
```

and the reader asks instead of guessing. `NoConfiguredBoundary` is an enum, not
a bare `null`, because "no boundary" and "several boundaries" are different
statements that a `null` collapses: the enum carries one case,
`MoreThanOneBoundary`, for `LongParameterListOptions`, which judges a value
object's constructor against `voWarning` and everything else against `warning`
and cannot know which applied. "No boundary at all" needs no case: it is said by
not implementing the interface, which is what every occurrence detector does.

**The obligation sits on `ThresholdAwareOptionsInterface`, not on
`RuleOptionsInterface`.** This was measured rather than assumed: over the 49
channel-level pairs the reader walks, no row that resolves to a number belongs
to a class outside that interface, and no class outside it holds a
warning-named member at all. Putting the method there reaches every holder of a
boundary in 27 classes instead of 55, leaves the occurrence detectors without a
constant-returning method, and gives the five hierarchical parents no obligation
to answer a question they have no answer to — their levels hold the numbers.

**The method has no channel or level argument.** The level is already chosen by
`forLevel()`. The channel decides nothing: three classes serve more than one
channel (`CodeSmellOptions` seven, `SecurityPatternOptions` three,
`TypeCoverageOptions` three) and none holds two different boundaries. A future
multi-axis class answers `MoreThanOneBoundary` — an honest refusal — rather than
silently returning one of two numbers.

**The method reports what the object holds now, not what `qmx.yaml` said.** On
the copy `withOverride()` returns, it reports the overridden number.
`baseline:explain` gets the configured value because it asks an object no
override has touched, which is a property of the caller. The name says
`warningBoundary`, not `configuredWarningBoundary`, for exactly that reason.

### `getSeverity()` is the authority only where the rule delegates to it

The boundary a class names is not always the number `getSeverity()` compares
against. `GodClassRule` reports `$matchedCount` and warns from
`matchedCount >= minCriteria`; `DataClassRule` reports WOC and emits while
`woc <= wocThreshold`. Both decide inside `analyze()` and leave `getSeverity()`
a stub that reads none of their thresholds.

**This cost a wrong answer before it was written down.** The first
implementation had both classes declaring that they hold no boundary, on the
reasoning that a rule weighing several metrics inline has none. The reasoning
was checked against `getSeverity()`, which for a stub reports no boundary
whatever the class holds — so absence of evidence was read as evidence of
absence, and `baseline:explain` kept printing "not resolvable" for two channels
whose boundary is configurable and documented in the rules' own docblocks.

For those two the only witness is `withOverride()`, which writes the warning
half into exactly the member they name. The set of such classes is pinned by a
test in both directions rather than derived, because a third one arrives with a
boundary no automatic witness can confirm.

`ThresholdOverrideSupportReader` already settled that implementing
`ThresholdAwareOptionsInterface` does not by itself tell a user their
`@qmx-threshold` will mean what they expect. This decision does not reopen that:
membership gives the method a home, and the method — not the interface — says
whether a number exists.

## Consequences

- `baseline:explain` now resolves three channels it used to refuse:
  `coupling.distance`, `design.god-class` and `design.data-class`. Every other
  line of the map is unchanged, which is asserted against the whole map rather
  than claimed.
- A boundary is a number, and the direction it worsens in stays where it already
  lives — the channel's declaration. `design.data-class`, like type coverage and
  maintainability, reports at or **below** its boundary.
- 27 options classes gain a three-line method; the reader loses its reflection,
  its name list and forty lines of docblock justifying the guess.
- A class that acquires a configured boundary without joining the interface is
  caught by a test, not by review attention: an object that reports no boundary
  must have no public numeric member where `getSeverity()` changes its answer.
- The declaration is checked against behaviour, not against another list of
  names: the declared number must be where severity starts, with exactly one
  side of it silent — which also distinguishes the warning boundary from the
  error one.
- Breaking for anyone implementing `ThresholdAwareOptionsInterface` outside this
  repository. By policy there is nobody; the migration is one method returning
  either the class's warning member or the enum case that says why not.
