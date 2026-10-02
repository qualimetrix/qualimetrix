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
     * Another directive of the same rule covers the same subject, so removing
     * this one alone changes nothing whether or not it does something.
     * Produced by the threshold half only.
     */
    case Masked = 'masked';
}
