<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleFamily;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;

/** Immutable public description of a registered rule. */
final readonly class RuleMetadata
{
    /**
     * The family this producer is listed under, derived from its name rather
     * than declared beside it — see {@see RuleFamily}. Handed to consumers
     * ready, so no listing splits a name of its own accord.
     */
    public string $family;

    /**
     * @param class-string<RuleOptionsInterface> $optionsClass
     * @param array<string, string> $aliases CLI alias => canonical option name
     */
    public function __construct(
        public string $name,
        public string $optionsClass,
        public string $description,
        public array $aliases,
        public bool $active,
    ) {
        $this->family = RuleFamily::of($name);
    }
}
