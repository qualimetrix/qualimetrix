<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleLevel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\ShorthandOptionKeysInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for CognitiveComplexityRule (hierarchical).
 *
 * Supports method and class levels with separate thresholds.
 */
final readonly class CognitiveComplexityOptions implements HierarchicalRuleOptionsInterface, ShorthandOptionKeysInterface
{
    public function __construct(
        public MethodCognitiveComplexityOptions $callable = new MethodCognitiveComplexityOptions(),
        public ClassCognitiveComplexityOptions $class = new ClassCognitiveComplexityOptions(),
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // Explicit top-level enabled: false disables all levels
        if (\array_key_exists(RuleOptionKey::ENABLED, $config) && $config[RuleOptionKey::ENABLED] === false) {
            return new self(
                callable: new MethodCognitiveComplexityOptions(enabled: false),
                class: new ClassCognitiveComplexityOptions(enabled: false),
            );
        }

        // Handle legacy flat format: {enabled, warningThreshold, errorThreshold}
        // Also supports threshold shorthand at top level
        if (\array_key_exists('warningThreshold', $config) || \array_key_exists('errorThreshold', $config) || \array_key_exists('threshold', $config)) {
            $thresholds = ThresholdParser::parse($config, RuleOptionKey::WARNING, RuleOptionKey::ERROR, 15, 30, legacyKeys: ['warning' => ['warningThreshold'], 'error' => ['errorThreshold']]);

            return new self(
                callable: new MethodCognitiveComplexityOptions(
                    enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
                    warning: (int) $thresholds['warning'],
                    error: (int) $thresholds['error'],
                ),
                class: new ClassCognitiveComplexityOptions(enabled: false),
            );
        }

        // Handle hierarchical format: {callable: {...}, class: {...}}
        $callableConfig = isset($config['callable']) && \is_array($config['callable'])
            ? $config['callable']
            : [];
        $classConfig = isset($config['class']) && \is_array($config['class'])
            ? $config['class']
            : [];

        return new self(
            callable: MethodCognitiveComplexityOptions::fromArray($callableConfig),
            class: ClassCognitiveComplexityOptions::fromArray($classConfig),
        );
    }

    /**
     * @return list<string>
     */
    public static function getShorthandOptionKeys(): array
    {
        return ['threshold'];
    }

    public function isEnabled(): bool
    {
        return $this->callable->isEnabled() || $this->class->isEnabled();
    }

    public function getSeverity(int|float $value): ?Severity
    {
        // For general rule-level checks, use callable level thresholds
        return $this->callable->getSeverity($value);
    }

    public function forLevel(RuleLevel $level): LevelOptionsInterface
    {
        return match ($level) {
            RuleLevel::Callable => $this->callable,
            RuleLevel::Class_ => $this->class,
            default => throw new InvalidArgumentException(
                \sprintf('Level %s is not supported by CognitiveComplexityRule', $level->value),
            ),
        };
    }

    public function isLevelEnabled(RuleLevel $level): bool
    {
        return match ($level) {
            RuleLevel::Callable => $this->callable->isEnabled(),
            RuleLevel::Class_ => $this->class->isEnabled(),
            default => false,
        };
    }

    /**
     * @return list<RuleLevel>
     */
    public function getSupportedLevels(): array
    {
        return [RuleLevel::Callable, RuleLevel::Class_];
    }
}
