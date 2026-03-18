<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for namespace-level CBO (Coupling Between Objects) checks.
 *
 * CBO = Ca + Ce
 * - Low CBO (<14): weakly coupled
 * - Medium CBO (14-19): acceptable (warning)
 * - High CBO (>=20): tightly coupled (error)
 */
final readonly class NamespaceCboOptions implements LevelOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 14,
        public int $error = 20,
        public int $minClassCount = 3,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self();
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            warning: (int) ($config['warning'] ?? 14),
            error: (int) ($config['error'] ?? 20),
            minClassCount: (int) ($config['min_class_count'] ?? $config['minClassCount'] ?? 3),
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
