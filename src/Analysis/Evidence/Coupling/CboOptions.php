<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Rule\AdditionalOptionKeysInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleLevel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\ShorthandOptionKeysInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for CboRule (hierarchical).
 *
 * Supports class and namespace levels for CBO thresholds.
 */
final readonly class CboOptions implements HierarchicalRuleOptionsInterface, ShorthandOptionKeysInterface, AdditionalOptionKeysInterface
{
    public function __construct(
        public ClassCboOptions $class = new ClassCboOptions(),
        public NamespaceCboOptions $namespace = new NamespaceCboOptions(),
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // Explicit top-level enabled: false disables all levels
        if (\array_key_exists(RuleOptionKey::ENABLED, $config) && $config[RuleOptionKey::ENABLED] === false) {
            return new self(
                class: new ClassCboOptions(enabled: false),
                namespace: new NamespaceCboOptions(enabled: false),
            );
        }

        // Flat shorthand at the rule's own top level: a bare `threshold` (or
        // bare `warning`/`error`) applies UNIFORMLY to both the class and
        // namespace dimensions, instead of the nested `class:`/`namespace:`
        // sub-configs below. This mirrors the legacy-flat branch pattern used
        // by ComplexityOptions/CognitiveComplexityOptions/NpathComplexityOptions
        // (bare top-level `threshold` short-circuits the nested form
        // entirely), but — unlike those — applies to BOTH levels rather than
        // disabling one: CBO's class/namespace defaults already match
        // (14/20), and there is no historical single-level format to stay
        // compatible with here, so there is no reason to silence a level.
        // A bare top-level `warning`/`error` is accepted for the same reason
        // ComplexityOptions accepts `warningThreshold`/`errorThreshold` in its
        // own legacy-flat branch: it lets ThresholdParser detect a genuine
        // same-layer "threshold mixed with warning/error" conflict, and lets
        // a higher-priority layer switch mode against a lower layer's flat
        // form at this same nesting level. It is intentionally NOT declared
        // via getShorthandOptionKeys() (only `threshold` is advertised),
        // matching how ComplexityOptions leaves its own legacy aliases
        // unadvertised.
        if (
            \array_key_exists(RuleOptionKey::THRESHOLD, $config)
            || \array_key_exists(RuleOptionKey::WARNING, $config)
            || \array_key_exists(RuleOptionKey::ERROR, $config)
        ) {
            $thresholds = ThresholdParser::parse($config, RuleOptionKey::WARNING, RuleOptionKey::ERROR, 14, 20);
            $levelConfig = [
                RuleOptionKey::ENABLED => (bool) ($config[RuleOptionKey::ENABLED] ?? true),
                RuleOptionKey::WARNING => $thresholds['warning'],
                RuleOptionKey::ERROR => $thresholds['error'],
            ];

            $classLevelConfig = $levelConfig;
            if (isset($config['scope'])) {
                $classLevelConfig['scope'] = $config['scope'];
            }

            return new self(
                class: ClassCboOptions::fromArray($classLevelConfig),
                namespace: NamespaceCboOptions::fromArray($levelConfig),
            );
        }

        // Handle hierarchical format: {class: {...}, namespace: {...}}
        $classConfig = isset($config['class']) && \is_array($config['class'])
            ? $config['class']
            : [];
        $namespaceConfig = isset($config['namespace']) && \is_array($config['namespace'])
            ? $config['namespace']
            : [];

        // Allow scope to be set at top level and propagate to class config
        if (isset($config['scope']) && !isset($classConfig['scope'])) {
            $classConfig['scope'] = $config['scope'];
        }

        return new self(
            class: ClassCboOptions::fromArray($classConfig),
            namespace: NamespaceCboOptions::fromArray($namespaceConfig),
        );
    }

    /**
     * @return list<string>
     */
    public static function getShorthandOptionKeys(): array
    {
        return ['threshold'];
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalOptionKeys(): array
    {
        return ['scope'];
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
                \sprintf('Level %s is not supported by CboRule', $level->value),
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
