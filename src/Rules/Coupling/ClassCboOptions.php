<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Rules\Support\ThresholdParser;

/**
 * Options for class-level CBO (Coupling Between Objects) checks.
 *
 * CBO = |Ca ∪ Ce|
 * - Low CBO (<14): weakly coupled, easy to test
 * - Medium CBO (14-19): acceptable (warning)
 * - High CBO (>=20): tightly coupled, hard to isolate (error)
 */
final readonly class ClassCboOptions implements LevelOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 14,
        public int $error = 20,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // If config is empty, use defaults (all enabled)
        if ($config === []) {
            return new self();
        }

        $thresholds = ThresholdParser::parse($config, 'warning', 'error', 14, 20);

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
        $cbo = (int) $value;

        if ($cbo >= $this->error) {
            return Severity::Error;
        }

        if ($cbo >= $this->warning) {
            return Severity::Warning;
        }

        return null;
    }
}
