<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

/**
 * A rejected threshold directive: what to say, and which of the two
 * configuration-error channels says it.
 *
 * The flag rather than a channel name because the channel constants belong to
 * the emitting rule — this type says "the rule exists but cannot be retuned"
 * versus "the name is not a rule at all", and the rule turns that into a
 * channel.
 */
final readonly class DirectiveRejection
{
    public function __construct(
        public bool $ruleExistsButCannotBeRetuned,
        public string $message,
    ) {}
}
