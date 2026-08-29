<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\ShorthandOptionKeysInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Options for ComplexityRule (hierarchical).
 *
 * Supports callable and class levels with separate thresholds.
 */
final readonly class ComplexityOptions implements HierarchicalRuleOptionsInterface, ShorthandOptionKeysInterface
{
    public function __construct(
        public MethodComplexityOptions $callable = new MethodComplexityOptions(),
        public ClassComplexityOptions $class = new ClassComplexityOptions(),
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // Explicit top-level enabled: false disables all levels
        if (\array_key_exists(RuleOptionKey::ENABLED, $config) && $config[RuleOptionKey::ENABLED] === false) {
            return new self(
                callable: new MethodComplexityOptions(enabled: false),
                class: new ClassComplexityOptions(enabled: false),
            );
        }

        // Handle legacy flat format: {enabled, warningThreshold, errorThreshold}
        // Also supports threshold shorthand at top level
        if (\array_key_exists('warningThreshold', $config) || \array_key_exists('errorThreshold', $config) || \array_key_exists('threshold', $config)) {
            $thresholds = ThresholdParser::parse($config, RuleOptionKey::WARNING, RuleOptionKey::ERROR, 10, 20, legacyKeys: ['warning' => ['warningThreshold'], 'error' => ['errorThreshold']]);

            return new self(
                callable: new MethodComplexityOptions(
                    enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
                    warning: (int) $thresholds['warning'],
                    error: (int) $thresholds['error'],
                ),
                class: new ClassComplexityOptions(enabled: false),
            );
        }

        // Handle hierarchical format: {callable: {...}, class: {...}}
        $callableKey = SymbolLevel::Callable->value;
        $classKey = SymbolLevel::Class_->value;
        $callableConfig = isset($config[$callableKey]) && \is_array($config[$callableKey])
            ? $config[$callableKey]
            : [];
        $classConfig = isset($config[$classKey]) && \is_array($config[$classKey])
            ? $config[$classKey]
            : [];

        return new self(
            callable: MethodComplexityOptions::fromArray($callableConfig),
            class: ClassComplexityOptions::fromArray($classConfig),
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

    public function forLevel(SymbolLevel $level): LevelOptionsInterface
    {
        return match ($level) {
            SymbolLevel::Callable => $this->callable,
            SymbolLevel::Class_ => $this->class,
            default => throw new InvalidArgumentException(
                \sprintf('Level %s is not supported by ComplexityRule', $level->value),
            ),
        };
    }

    public function isLevelEnabled(SymbolLevel $level): bool
    {
        return match ($level) {
            SymbolLevel::Callable => $this->callable->isEnabled(),
            SymbolLevel::Class_ => $this->class->isEnabled(),
            default => false,
        };
    }

    /**
     * @return list<SymbolLevel>
     */
    public function getSupportedLevels(): array
    {
        return [SymbolLevel::Callable, SymbolLevel::Class_];
    }
}
