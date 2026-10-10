<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

use LogicException;

use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for the code duplication rule.
 */
final readonly class CodeDuplicationOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $min_lines = 5,
        public int $min_tokens = 70,
        public int $error = 50,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $error = $config->integer('error', 50);
        $minLines = $config->integer('min-lines', 5);
        $minTokens = $config->integer('min-tokens', 70);
        if ($error < 1 || $minLines < 1 || $minTokens < 1) {
            throw new LogicException('Duplication thresholds and minimum sizes must be positive integers.');
        }

        return new self(
            enabled: $config->boolean('enabled', true),
            min_lines: $minLines,
            min_tokens: $minTokens,
            error: $error,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): Severity
    {
        return $value >= $this->error ? Severity::Error : Severity::Warning;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'error' => RuleOptionShape::integer()->atLeast(1)->orNull(),
            'min-lines' => RuleOptionShape::integer()->atLeast(1)->orNull(),
            'min-tokens' => RuleOptionShape::integer()->atLeast(1)->orNull(),
        ]);
    }
}
