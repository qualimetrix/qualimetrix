<?php

declare(strict_types=1);

namespace AiMessDetector\Baseline\Suppression;

/**
 * Represents a suppression tag from docblock.
 *
 * Example: @aimd-ignore complexity Reason why it's ignored
 */
final readonly class Suppression
{
    public function __construct(
        public string $rule,
        public ?string $reason,
        public int $line,
    ) {}

    /**
     * Checks if suppression matches given rule.
     * Supports wildcard '*' to suppress all rules.
     */
    public function matches(string $rule): bool
    {
        return $this->rule === $rule || $this->rule === '*';
    }
}
