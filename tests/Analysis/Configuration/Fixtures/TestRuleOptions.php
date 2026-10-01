<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Fixtures;

use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Test fixture for RuleOptionsInterface.
 */
final readonly class TestRuleOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warningThreshold = 10,
        public int $errorThreshold = 20,
        public bool $countNullsafe = true,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        return new self(
            enabled: $config->boolean('enabled', true),
            warningThreshold: $config->integer('warning-threshold', 10),
            errorThreshold: $config->integer('error-threshold', 20),
            countNullsafe: $config->boolean('count-nullsafe', true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        if ($value >= $this->errorThreshold) {
            return Severity::Error;
        }

        if ($value >= $this->warningThreshold) {
            return Severity::Warning;
        }

        return null;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'count-nullsafe' => RuleOptionShape::boolean()->orNull(),
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'error-threshold' => RuleOptionShape::integer()->orNull(),
            'warning-threshold' => RuleOptionShape::integer()->orNull(),
        ]);
    }
}
