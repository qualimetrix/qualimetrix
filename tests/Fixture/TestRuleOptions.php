<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Fixture;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

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

    public static function fromArray(array $config): self
    {
        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            warningThreshold: (int) ($config['warningThreshold'] ?? 10),
            errorThreshold: (int) ($config['errorThreshold'] ?? 20),
            countNullsafe: (bool) ($config['countNullsafe'] ?? true),
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
}
