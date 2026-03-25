<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Support\ThresholdParser;

/**
 * Options for LongParameterListRule.
 *
 * Checks the number of parameters in a method/function.
 * Thresholds based on common industry standards:
 * - <= 3 parameters: good
 * - 4+ parameters: warning, consider introducing a parameter object
 * - 6+ parameters: error, definitely needs refactoring
 */
final readonly class LongParameterListOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 4,
        public int $error = 6,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self(enabled: false);
        }

        $thresholds = ThresholdParser::parse($config, 'warning', 'error', 4, 6);

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
