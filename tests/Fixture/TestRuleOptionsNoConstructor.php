<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Fixture;

use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\Severity;

/**
 * Test fixture for RuleOptions without constructor.
 */
final readonly class TestRuleOptionsNoConstructor implements RuleOptionsInterface
{
    public static function fromArray(array $config): self
    {
        return new self();
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
