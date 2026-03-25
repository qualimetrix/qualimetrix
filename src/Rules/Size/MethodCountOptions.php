<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Size;

use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Support\ThresholdParser;

/**
 * Options for MethodCountRule.
 *
 * Checks the number of methods in a class.
 * Thresholds based on common industry standards:
 * - <= 10-15 methods: good class size
 * - 20-30 methods: warning, class may be doing too much
 * - > 30 methods: error, class should be split
 */
final readonly class MethodCountOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 20,
        public int $error = 30,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self(enabled: false);
        }

        $thresholds = ThresholdParser::parse($config, 'warning', 'error', 20, 30);

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            warning: (int) $thresholds['warning'],
            error: (int) $thresholds['error'],
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        if ($value >= $this->error) {
            return Severity::Error;
        }

        if ($value >= $this->warning) {
            return Severity::Warning;
        }

        return null;
    }
}
