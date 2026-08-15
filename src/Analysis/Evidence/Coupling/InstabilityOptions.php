<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleLevel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\ShorthandOptionKeysInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for InstabilityRule (hierarchical).
 *
 * Supports class and namespace levels for instability thresholds.
 */
final readonly class InstabilityOptions implements HierarchicalRuleOptionsInterface, ShorthandOptionKeysInterface
{
    public function __construct(
        public ClassInstabilityOptions $class = new ClassInstabilityOptions(),
        public NamespaceInstabilityOptions $namespace = new NamespaceInstabilityOptions(),
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // Explicit top-level enabled: false disables all levels
        if (\array_key_exists(RuleOptionKey::ENABLED, $config) && $config[RuleOptionKey::ENABLED] === false) {
            return new self(
                class: new ClassInstabilityOptions(enabled: false),
                namespace: new NamespaceInstabilityOptions(enabled: false),
            );
        }

        // Flat shorthand at the rule's own top level: a bare `threshold` (or
        // bare `max_warning`/`max_error`) applies UNIFORMLY to both the class
        // and namespace dimensions, instead of the nested `class:`/
        // `namespace:` sub-configs below. Mirrors CboOptions's own top-level
        // branch — see its docblock for why both levels stay enabled with
        // the same threshold rather than one being disabled. Intentionally
        // NOT declared via getShorthandOptionKeys() beyond `threshold`
        // itself, matching CboOptions.
        $hasFlatMaxWarning = \array_key_exists('max_warning', $config) || \array_key_exists('maxWarning', $config);
        $hasFlatMaxError = \array_key_exists('max_error', $config) || \array_key_exists('maxError', $config);

        if (\array_key_exists(RuleOptionKey::THRESHOLD, $config) || $hasFlatMaxWarning || $hasFlatMaxError) {
            $thresholds = ThresholdParser::parse(
                $config,
                'max_warning',
                'max_error',
                0.8,
                0.95,
                legacyKeys: ['warning' => ['maxWarning'], 'error' => ['maxError']],
            );
            $levelConfig = [
                RuleOptionKey::ENABLED => (bool) ($config[RuleOptionKey::ENABLED] ?? true),
                'max_warning' => $thresholds['warning'],
                'max_error' => $thresholds['error'],
            ];

            return new self(
                class: ClassInstabilityOptions::fromArray($levelConfig),
                namespace: NamespaceInstabilityOptions::fromArray($levelConfig),
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
            class: ClassInstabilityOptions::fromArray($classConfig),
            namespace: NamespaceInstabilityOptions::fromArray($namespaceConfig),
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
        return $this->class->isEnabled() || $this->namespace->isEnabled();
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return $this->class->getSeverity($value);
    }

    public function forLevel(RuleLevel $level): LevelOptionsInterface
    {
        return match ($level) {
            RuleLevel::Class_ => $this->class,
            RuleLevel::Namespace_ => $this->namespace,
            default => throw new InvalidArgumentException(
                \sprintf('Level %s is not supported by InstabilityRule', $level->value),
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
