<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use InvalidArgumentException;

use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusal;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
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

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        try {
            $class = ClassCboOptions::fromResolved($config->atLevel('class'));
        } catch (RuleOptionRefusal $refusal) {
            throw $refusal->under('class');
        }
        try {
            $namespace = NamespaceCboOptions::fromResolved($config->atLevel('namespace'));
        } catch (RuleOptionRefusal $refusal) {
            throw $refusal->under('namespace');
        }
        if (!$config->boolean('enabled', true)) {
            $class = new ClassCboOptions(enabled: false, warning: $class->warning, error: $class->error, scope: $class->scope);
            $namespace = new NamespaceCboOptions(enabled: false, warning: $namespace->warning, error: $namespace->error, minClassCount: $namespace->minClassCount);
        }
        return new self(class: $class, namespace: $namespace);
    }

    /** Root warning/error shorthands spread into both declared level bands. */
    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'error' => RuleOptionShape::integer()->orNull(),
            'scope' => RuleOptionShape::oneOf('all', 'application')->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
            'warning' => RuleOptionShape::integer()->orNull(),
        ])->withLevelSlots(self::levelOptionsClasses())->spreadingInto('threshold', ['class.threshold', 'namespace.threshold'])->spreadingInto('warning', ['class.warning', 'namespace.warning'])->spreadingInto('error', ['class.error', 'namespace.error'])->spreadingInto('scope', ['class.scope']);
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
