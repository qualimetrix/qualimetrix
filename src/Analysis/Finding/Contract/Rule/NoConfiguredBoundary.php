<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/**
 * Why a class that accepts a threshold override still cannot name one warning
 * boundary.
 *
 * **There is one case, and the shape of the interface is why.** "This rule has
 * no boundary at all" is not said here: it is said by not implementing
 * {@see ThresholdAwareOptionsInterface} — which is what every occurrence
 * detector does, judging against a hardcoded "more than zero" that no
 * configuration moves. Inside the interface the only remaining reason is having
 * several boundaries and no way to know which was applied.
 *
 * A second case is added when a second reason is found, not before. The first
 * draft of this enum carried a `NotAThresholdDecision` case for
 * `GodClassOptions` and `DataClassOptions`, on the belief that a rule deciding
 * inside its own `analyze()` holds no boundary. Both turned out to hold one on
 * the very axis their channel reports — `minCriteria` and `wocThreshold` — and
 * the case was left with no members.
 */
enum NoConfiguredBoundary
{
    /**
     * The object holds more than one boundary and nothing in the question says
     * which one was applied. `LongParameterListOptions` is the standing case:
     * the rule picks `voWarning` over `warning` from a property of the subject
     * — whether the callable is a value object's constructor — and a caller
     * asking about the channel cannot know which the finding was judged
     * against. A wrong number is worse than a missing one.
     */
    case MoreThanOneBoundary;
}
