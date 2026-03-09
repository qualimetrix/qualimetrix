<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Complexity;

use AiMessDetector\Core\Rule\HierarchicalRuleOptionsInterface;
use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Violation\Severity;
use InvalidArgumentException;

/**
 * Options for NpathComplexityRule (hierarchical).
 *
 * Supports method and class levels with separate thresholds.
 */
final readonly class NpathComplexityOptions implements HierarchicalRuleOptionsInterface
{
    public function __construct(
        public MethodNpathComplexityOptions $method = new MethodNpathComplexityOptions(),
        public ClassNpathComplexityOptions $class = new ClassNpathComplexityOptions(),
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // Explicit top-level enabled: false disables all levels
        if (\array_key_exists('enabled', $config) && $config['enabled'] === false) {
            return new self(
                method: new MethodNpathComplexityOptions(enabled: false),
                class: new ClassNpathComplexityOptions(enabled: false),
            );
        }

        // Handle legacy flat format: {enabled, warningThreshold, errorThreshold}
        if (isset($config['warningThreshold']) || isset($config['errorThreshold'])) {
            return new self(
                method: new MethodNpathComplexityOptions(
                    enabled: (bool) ($config['enabled'] ?? true),
                    warning: (int) ($config['warningThreshold'] ?? 200),
                    error: (int) ($config['errorThreshold'] ?? 1000),
                ),
                class: new ClassNpathComplexityOptions(enabled: false),
            );
        }

        // Handle hierarchical format: {method: {...}, class: {...}}
        $methodConfig = isset($config['method']) && \is_array($config['method'])
            ? $config['method']
            : [];
        $classConfig = isset($config['class']) && \is_array($config['class'])
            ? $config['class']
            : [];

        return new self(
            method: MethodNpathComplexityOptions::fromArray($methodConfig),
            class: ClassNpathComplexityOptions::fromArray($classConfig),
        );
    }

    public function isEnabled(): bool
    {
        return $this->method->isEnabled() || $this->class->isEnabled();
    }

    public function getSeverity(int|float $value): ?Severity
    {
        // For general rule-level checks, use method level thresholds
        return $this->method->getSeverity($value);
    }

    public function forLevel(RuleLevel $level): LevelOptionsInterface
    {
        return match ($level) {
            RuleLevel::Method => $this->method,
            RuleLevel::Class_ => $this->class,
            default => throw new InvalidArgumentException(
                \sprintf('Level %s is not supported by NpathComplexityRule', $level->value),
            ),
        };
    }

    public function isLevelEnabled(RuleLevel $level): bool
    {
        return match ($level) {
            RuleLevel::Method => $this->method->isEnabled(),
            RuleLevel::Class_ => $this->class->isEnabled(),
            default => false,
        };
    }

    /**
     * @return list<RuleLevel>
     */
    public function getSupportedLevels(): array
    {
        return [RuleLevel::Method, RuleLevel::Class_];
    }
}
