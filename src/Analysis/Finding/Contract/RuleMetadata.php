<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;

/** Immutable public description of a registered rule. */
final readonly class RuleMetadata
{
    /**
     * @param class-string<RuleOptionsInterface> $optionsClass
     * @param array<string, string> $aliases CLI alias => canonical option name
     */
    public function __construct(
        public string $name,
        public string $optionsClass,
        public RuleCategory $category,
        public string $description,
        public array $aliases,
        public bool $active,
    ) {}
}
