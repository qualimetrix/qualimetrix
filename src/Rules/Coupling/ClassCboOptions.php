<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for class-level CBO (Coupling Between Objects) checks.
 *
 * CBO = Ca + Ce
 * - Low CBO (<=14): weakly coupled, easy to test
 * - Medium CBO (15-20): acceptable
 * - High CBO (>20): tightly coupled, hard to isolate
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
        // If config is empty, level is disabled
        if ($config === []) {
            return new self(enabled: false);
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            warning: (int) ($config['warning'] ?? 14),
            error: (int) ($config['error'] ?? 20),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        $cbo = (int) $value;

        if ($cbo > $this->error) {
            return Severity::Error;
        }

        if ($cbo > $this->warning) {
            return Severity::Warning;
        }

        return null;
    }
}
