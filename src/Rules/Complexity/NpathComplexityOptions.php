<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Complexity;

use InvalidArgumentException;
use Qualimetrix\Core\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Core\Rule\LevelOptionsInterface;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Rule\RuleOptionKey;
use Qualimetrix\Core\Rule\ShorthandOptionKeysInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Support\ThresholdParser;

/**
 * Options for NpathComplexityRule (hierarchical).
 *
 * Supports method and class levels with separate thresholds.
 */
final readonly class NpathComplexityOptions implements HierarchicalRuleOptionsInterface, ShorthandOptionKeysInterface
{
    public function __construct(
        public MethodNpathComplexityOptions $callable = new MethodNpathComplexityOptions(),
        public ClassNpathComplexityOptions $class = new ClassNpathComplexityOptions(),
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // Explicit top-level enabled: false disables all levels
        if (\array_key_exists(RuleOptionKey::ENABLED, $config) && $config[RuleOptionKey::ENABLED] === false) {
            return new self(
                callable: new MethodNpathComplexityOptions(enabled: false),
                class: new ClassNpathComplexityOptions(enabled: false),
            );
        }

        // Handle legacy flat format: {enabled, warningThreshold, errorThreshold}
        // Also supports threshold shorthand at top level
        if (\array_key_exists('warningThreshold', $config) || \array_key_exists('errorThreshold', $config) || \array_key_exists('threshold', $config)) {
            $thresholds = ThresholdParser::parse($config, RuleOptionKey::WARNING, RuleOptionKey::ERROR, 200, 1000, legacyKeys: ['warning' => ['warningThreshold'], 'error' => ['errorThreshold']]);

            return new self(
                callable: new MethodNpathComplexityOptions(
                    enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
                    warning: (int) $thresholds['warning'],
                    error: (int) $thresholds['error'],
                ),
                class: new ClassNpathComplexityOptions(enabled: false),
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
            callable: MethodNpathComplexityOptions::fromArray($callableConfig),
            class: ClassNpathComplexityOptions::fromArray($classConfig),
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
                \sprintf('Level %s is not supported by NpathComplexityRule', $level->value),
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
