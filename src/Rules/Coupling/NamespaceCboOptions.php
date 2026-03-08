<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for namespace-level CBO (Coupling Between Objects) checks.
 *
 * CBO = Ca + Ce
 * - Low CBO (<=14): weakly coupled
 * - Medium CBO (15-20): acceptable
 * - High CBO (>20): tightly coupled
 */
final readonly class NamespaceCboOptions implements LevelOptionsInterface
{
    /**
     * @param list<string> $excludeNamespaces Namespaces to exclude from analysis (prefix matching)
     */
    public function __construct(
        public bool $enabled = true,
        public int $warning = 14,
        public int $error = 20,
        public int $minClassCount = 3,
        public array $excludeNamespaces = [],
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

        $excludeNamespaces = [];
        $excludeKey = $config['exclude_namespaces'] ?? $config['excludeNamespaces'] ?? null;
        if (\is_array($excludeKey)) {
            $excludeNamespaces = array_values($excludeKey);
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            warning: (int) ($config['warning'] ?? 14),
            error: (int) ($config['error'] ?? 20),
            minClassCount: (int) ($config['min_class_count'] ?? $config['minClassCount'] ?? 3),
            excludeNamespaces: $excludeNamespaces,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isNamespaceExcluded(string $namespace): bool
    {
        foreach ($this->excludeNamespaces as $prefix) {
            $prefix = rtrim($prefix, '\\');

            if ($namespace === $prefix || str_starts_with($namespace, $prefix . '\\')) {
                return true;
            }
        }

        return false;
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
