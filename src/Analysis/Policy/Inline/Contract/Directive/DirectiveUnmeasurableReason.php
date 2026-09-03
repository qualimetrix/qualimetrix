<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

/**
 * Why a directive has no verdict.
 *
 * These are the paths {@see \Qualimetrix\Analysis\Policy\Inline\Directive\Audit\DirectiveUsage::unmeasurableReason()} refuses to
 * account for, named rather than silently dropped. Reporting any of them as
 * `inert` would tell an author to remove an annotation on the strength of a
 * question that was never asked.
 */
enum DirectiveUnmeasurableReason: string
{
    /**
     * The producer of the addressed channel did not report. Both ways of
     * switching a rule off count, because the author made the same decision
     * either way: `disabled_rules` / `--disable-rule` stop it from running,
     * and `rules: { X: false }` lets it run and return nothing.
     */
    case ProducerDisabled = 'producer-disabled';

    /**
     * The directive was already refused elsewhere — an unaddressable
     * `channel:level` pair, a selector that expands to no channel, a channel
     * no producer owns, or a target reaching the one channel no directive may
     * address at all. `annotation.unresolved-directive` answers all four, and
     * answering again would judge one mistake twice.
     */
    case AlreadyRefused = 'already-refused';

    /**
     * The directive carries no rule filter. It says "whatever is here", so
     * there is no channel whose producer could be consulted, and calling it
     * inert would report a file's cleanliness as a defect.
     */
    case AddressesEveryChannel = 'addresses-every-channel';

    /**
     * Another directive of the same rule covers the same subject, so removing
     * this one alone changes nothing whether or not it does something.
     * Produced by the threshold half only.
     */
    case Masked = 'masked';
}
