<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Suppression;

use Qualimetrix\Core\Rule\RuleMatcher;

/**
 * Represents a suppression tag from docblock.
 *
 * Example: `@qmx-ignore complexity Reason why it's ignored` it's ignored
 */
final readonly class Suppression
{
    public function __construct(
        public string $rule,
        public ?string $reason,
        public int $line,
        public SuppressionType $type,
        public ?int $endLine = null,
    ) {}

    /**
     * Checks if suppression matches given violation code.
     *
     * Supports:
     * - Wildcard '*' to suppress all rules
     * - Prefix matching: 'complexity' suppresses 'complexity.cyclomatic.method'
     * - Exact matching: 'complexity.cyclomatic' suppresses 'complexity.cyclomatic'
     */
    public function matches(string $violationCode): bool
    {
        if ($this->rule === '*') {
            return true;
        }

        return RuleMatcher::matches($this->rule, $violationCode);
    }
}
