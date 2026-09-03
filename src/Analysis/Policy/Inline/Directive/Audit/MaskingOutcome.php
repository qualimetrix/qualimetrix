<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive\Audit;

use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveUnmeasurableReason;

/**
 * What the sweep decided about one authored group, before it is reported.
 *
 * A judgement about a group is not the group, which is why it is a type of its
 * own rather than four more keys beside the group's own. It is internal to the
 * sweep: {@see \Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict}
 * is what leaves, and it carries the site rather than the group plus the one
 * fact — boundary observability — that is read off the run rather than decided
 * here. `$maskedBy` is the hiding directive itself rather than its site, for
 * the same reason: turning a directive into a site is the report's step, not
 * this one's.
 */
final readonly class MaskingOutcome
{
    public function __construct(
        public AuthoredDirectiveGroup $group,
        public DirectiveEffect $effect,
        public ?DirectiveUnmeasurableReason $reason,
        public ?AuthoredDirectiveGroup $maskedBy,
    ) {}
}
