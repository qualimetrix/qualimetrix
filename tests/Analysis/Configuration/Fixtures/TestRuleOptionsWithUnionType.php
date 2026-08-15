<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Fixtures;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Test fixture for RuleOptions with union type parameter.
 */
final readonly class TestRuleOptionsWithUnionType implements RuleOptionsInterface
{
    public function __construct(
        public int|string|null $value,
    ) {}

    public static function fromArray(array $config): self
    {
        return new self(
            value: $config['value'] ?? null,
        );
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return null;
    }
}
