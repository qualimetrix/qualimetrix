<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/**
 * Display text and documentation location for a channel, joined from the
 * facts its producing rule already owns.
 *
 * See `docs/internal/plans/sarif-channel-descriptions.md` ("Decision") for why
 * this join needs its own contract rather than a fourth view bolted onto
 * {@see ChannelUniverseInterface}: rule *instances* — the only place
 * `getDescription()` and `getCategory()` can be read from — do not exist when
 * the universe is assembled, so a composing service reads them at run time
 * instead. It consumes {@see ChannelIdentityInterface::producerOf()} for the
 * producing rule, that rule's own {@see RuleMetadata} for its description,
 * and — for the `computed.*` / `health.*` family, whose channels are
 * configured rather than declared — the configured
 * `ComputedMetricDefinition`'s own description in preference to the
 * producer's generic one.
 */
interface ChannelPresentationInterface
{
    /**
     * @return ChannelPresentation|null `null` when no channel carries this
     *                                  code, or when the resolved description
     *                                  is empty — an empty string is not
     *                                  display text, and treating it as "no
     *                                  presentation" keeps a mistakenly blank
     *                                  `ComputedMetricDefinition::$description`
     *                                  from reaching a consumer as if it were
     *                                  a legitimate answer.
     */
    public function presentationFor(string $code): ?ChannelPresentation;
}
