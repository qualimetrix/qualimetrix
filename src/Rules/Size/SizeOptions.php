<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Size;

use AiMessDetector\Core\Rule\HierarchicalRuleOptionsInterface;
use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Violation\Severity;
use InvalidArgumentException;

/**
 * Options for SizeRule (hierarchical).
 *
 * Supports class and namespace levels with separate thresholds.
 */
final readonly class SizeOptions implements HierarchicalRuleOptionsInterface
{
    public function __construct(
        public ClassSizeOptions $class = new ClassSizeOptions(),
        public NamespaceLevelOptions $namespace = new NamespaceLevelOptions(),
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // Handle legacy flat format for backward compatibility
        if (isset($config['warningThreshold']) || isset($config['errorThreshold'])) {
            return new self(
                class: new ClassSizeOptions(enabled: false),
                namespace: new NamespaceLevelOptions(
                    enabled: (bool) ($config['enabled'] ?? true),
                    warning: (int) ($config['warningThreshold'] ?? 15),
                    error: (int) ($config['errorThreshold'] ?? 25),
                ),
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
            class: ClassSizeOptions::fromArray($classConfig),
            namespace: NamespaceLevelOptions::fromArray($namespaceConfig),
        );
    }

    public function isEnabled(): bool
    {
        return $this->class->isEnabled() || $this->namespace->isEnabled();
    }

    public function getSeverity(int|float $value): ?Severity
    {
        // For general rule-level checks, use namespace level thresholds (primary)
        return $this->namespace->getSeverity($value);
    }

    public function forLevel(RuleLevel $level): LevelOptionsInterface
    {
        return match ($level) {
            RuleLevel::Class_ => $this->class,
            RuleLevel::Namespace_ => $this->namespace,
            default => throw new InvalidArgumentException(
                \sprintf('Level %s is not supported by SizeRule', $level->value),
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
