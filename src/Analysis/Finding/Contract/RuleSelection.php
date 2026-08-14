<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/** Ordered rule selectors resolved for one invocation. */
final readonly class RuleSelection
{
    /**
     * @param list<string> $only
     * @param list<string> $disabled
     */
    public function __construct(
        public array $only = [],
        public array $disabled = [],
    ) {}
}
