<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Fixture;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

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
