<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Fixture;

use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\Severity;

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
