<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Size;

use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for namespace-level size checks.
 *
 * Checks the number of classes in a namespace.
 * Thresholds based on package cohesion principles:
 * - <= 15 classes: good namespace size, focused responsibility
 * - 15-25 classes: warning, namespace may be doing too much
 * - > 25 classes: error, namespace should be split into subnamespaces
 */
final readonly class NamespaceLevelOptions implements LevelOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 15,
        public int $error = 25,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            warning: (int) ($config['warning'] ?? $config['warningThreshold'] ?? 15),
            error: (int) ($config['error'] ?? $config['errorThreshold'] ?? 25),
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
