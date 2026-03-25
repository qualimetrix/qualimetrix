<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Complexity;

use AiMessDetector\Core\Rule\HierarchicalRuleOptionsInterface;
use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Rules\Support\ThresholdParser;
use InvalidArgumentException;

/**
 * Options for CognitiveComplexityRule (hierarchical).
 *
 * Supports method and class levels with separate thresholds.
 */
final readonly class CognitiveComplexityOptions implements HierarchicalRuleOptionsInterface
{
    public function __construct(
        public MethodCognitiveComplexityOptions $method = new MethodCognitiveComplexityOptions(),
        public ClassCognitiveComplexityOptions $class = new ClassCognitiveComplexityOptions(),
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // Explicit top-level enabled: false disables all levels
        if (\array_key_exists('enabled', $config) && $config['enabled'] === false) {
            return new self(
                method: new MethodCognitiveComplexityOptions(enabled: false),
                class: new ClassCognitiveComplexityOptions(enabled: false),
            );
        }

        // Handle legacy flat format: {enabled, warningThreshold, errorThreshold}
        // Also supports threshold shorthand at top level
        if (\array_key_exists('warningThreshold', $config) || \array_key_exists('errorThreshold', $config) || \array_key_exists('threshold', $config)) {
            $thresholds = ThresholdParser::parse($config, 'warning', 'error', 15, 30, legacyWarningKeys: ['warningThreshold'], legacyErrorKeys: ['errorThreshold']);

            return new self(
                method: new MethodCognitiveComplexityOptions(
                    enabled: (bool) ($config['enabled'] ?? true),
                    warning: (int) $thresholds['warning'],
                    error: (int) $thresholds['error'],
                ),
                class: new ClassCognitiveComplexityOptions(enabled: false),
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
            method: MethodCognitiveComplexityOptions::fromArray($methodConfig),
            class: ClassCognitiveComplexityOptions::fromArray($classConfig),
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
                \sprintf('Level %s is not supported by CognitiveComplexityRule', $level->value),
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
