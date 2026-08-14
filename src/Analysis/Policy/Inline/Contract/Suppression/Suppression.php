<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Suppression;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleMatcher;
use Qualimetrix\Core\Symbol\MetricSubject;

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
        public ?MetricSubject $subject = null,
        public ?ControlScope $controlScope = null,
    ) {
        $isSymbolControl = $type === SuppressionType::Symbol;
        if ($isSymbolControl !== ($subject !== null) || $isSymbolControl !== ($controlScope !== null)) {
            throw new InvalidArgumentException('Symbol suppressions require a subject and control scope; physical suppressions require neither');
        }
    }

    /**
     * Checks if suppression matches given violation code.
     *
     * Supports:
     * - Wildcard '*' to suppress all rules
     * - Prefix matching: 'complexity' suppresses 'complexity.cyclomatic.callable'
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
