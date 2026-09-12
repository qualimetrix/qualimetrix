<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Options for CboRule (hierarchical).
 *
 * Supports class and namespace levels for CBO thresholds.
 */
final readonly class CboOptions implements HierarchicalRuleOptionsInterface
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
        // A bare top-level `warning`/`error` opens this branch on its own,
        // because the condition below reads them and not only the body. That
        // is what makes them real options here and declared as such, and it
        // is where the complexity wrappers differ: their same-named top-level
        // keys are read only inside a branch some other key opens, so writing
        // one alone does nothing — and they are refused rather than declared.
        // What opens the branch is a written VALUE, not a written key: `~`
        // leaves the key's own value to the default and selects nothing, so a
        // `class:`/`namespace:` block beside it is still read.
        if (
            isset($config[RuleOptionKey::THRESHOLD])
            || isset($config[RuleOptionKey::WARNING])
            || isset($config[RuleOptionKey::ERROR])
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
        $classKey = SymbolLevel::Class_->value;
        $namespaceKey = SymbolLevel::Namespace_->value;
        $classConfig = isset($config[$classKey]) && \is_array($config[$classKey])
            ? $config[$classKey]
            : [];
        $namespaceConfig = isset($config[$namespaceKey]) && \is_array($config[$namespaceKey])
            ? $config[$namespaceKey]
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
     * `warning` and `error` are declared because they work alone here — see
     * the flat-shorthand branch in `fromArray()`, whose condition reads them.
     * The same two spellings at the top level of a complexity rule are refused
     * instead, for the opposite reason.
     */
    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'error' => RuleOptionShape::integer()->orNull(),
            'scope' => RuleOptionShape::oneOf('all', 'application')->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
            'warning' => RuleOptionShape::integer()->orNull(),
        ])->withLevelSlots(self::levelOptionsClasses());
    }

    /**
     * @return array<string, class-string<LevelOptionsInterface>>
     */
    public static function levelOptionsClasses(): array
    {
        return [
            SymbolLevel::Class_->value => ClassCboOptions::class,
            SymbolLevel::Namespace_->value => NamespaceCboOptions::class,
        ];
    }

    public function isEnabled(): bool
    {
        return $this->class->isEnabled() || $this->namespace->isEnabled();
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return $this->class->getSeverity($value);
    }

    public function forLevel(SymbolLevel $level): LevelOptionsInterface
    {
        return match ($level) {
            SymbolLevel::Class_ => $this->class,
            SymbolLevel::Namespace_ => $this->namespace,
            default => throw new InvalidArgumentException(
                \sprintf('Level %s is not supported by CboRule', $level->value),
            ),
        };
    }

    public function isLevelEnabled(SymbolLevel $level): bool
    {
        return match ($level) {
            SymbolLevel::Class_ => $this->class->isEnabled(),
            SymbolLevel::Namespace_ => $this->namespace->isEnabled(),
            default => false,
        };
    }

    /**
     * @return list<SymbolLevel>
     */
    public function getSupportedLevels(): array
    {
        return [SymbolLevel::Class_, SymbolLevel::Namespace_];
    }
}
