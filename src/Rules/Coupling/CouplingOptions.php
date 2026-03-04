<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Rule\HierarchicalRuleOptionsInterface;
use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Violation\Severity;
use InvalidArgumentException;

/**
 * Options for CouplingRule (hierarchical).
 *
 * Supports class and namespace levels for instability thresholds.
 */
final readonly class CouplingOptions implements HierarchicalRuleOptionsInterface
{
    public function __construct(
        public ClassCouplingOptions $class = new ClassCouplingOptions(),
        public NamespaceCouplingOptions $namespace = new NamespaceCouplingOptions(),
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // Handle legacy flat format: {maxInstabilityWarning, maxInstabilityError}
        if (isset($config['maxInstabilityWarning']) || isset($config['maxInstabilityError'])) {
            $options = new ClassCouplingOptions(
                enabled: (bool) ($config['enabled'] ?? true),
                maxInstabilityWarning: (float) ($config['maxInstabilityWarning'] ?? 0.8),
                maxInstabilityError: (float) ($config['maxInstabilityError'] ?? 0.95),
            );

            return new self(
                class: $options,
                namespace: new NamespaceCouplingOptions(enabled: false),
            );
        }

        // Handle hierarchical format: {class: {...}, namespace: {...}}
        $classConfig = isset($config['class']) && \is_array($config['class'])
            ? $config['class']
            : [];
        $namespaceConfig = isset($config['namespace']) && \is_array($config['namespace'])
            ? $config['namespace']
            : [];

        return new self(
            class: ClassCouplingOptions::fromArray($classConfig),
            namespace: NamespaceCouplingOptions::fromArray($namespaceConfig),
        );
    }

    public function isEnabled(): bool
    {
        return $this->class->isEnabled() || $this->namespace->isEnabled();
    }

    public function getSeverity(int|float $value): ?Severity
    {
        // For general rule-level checks, use class level thresholds
        return $this->class->getSeverity($value);
    }

    public function forLevel(RuleLevel $level): LevelOptionsInterface
    {
        return match ($level) {
            RuleLevel::Class_ => $this->class,
            RuleLevel::Namespace_ => $this->namespace,
            default => throw new InvalidArgumentException(
                \sprintf('Level %s is not supported by CouplingRule', $level->value),
            ),
        };
    }

    public function isLevelEnabled(RuleLevel $level): bool
    {
        return match ($level) {
            RuleLevel::Class_ => $this->class->isEnabled(),
            RuleLevel::Namespace_ => $this->namespace->isEnabled(),
            default => false,
        };
    }

    /**
     * @return list<RuleLevel>
     */
    public function getSupportedLevels(): array
    {
        return [RuleLevel::Class_, RuleLevel::Namespace_];
    }
}
