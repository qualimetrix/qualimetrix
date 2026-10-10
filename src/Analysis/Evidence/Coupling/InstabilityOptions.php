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
 * Options for InstabilityRule (hierarchical).
 *
 * Supports class and namespace levels for instability thresholds.
 */
final readonly class InstabilityOptions implements HierarchicalRuleOptionsInterface
{
    public function __construct(
        public ClassInstabilityOptions $class = new ClassInstabilityOptions(),
        public NamespaceInstabilityOptions $namespace = new NamespaceInstabilityOptions(),
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        try {
            $class = ClassInstabilityOptions::fromResolved($config->atLevel('class'));
        } catch (RuleOptionRefusal $refusal) {
            throw $refusal->under('class');
        }
        try {
            $namespace = NamespaceInstabilityOptions::fromResolved($config->atLevel('namespace'));
        } catch (RuleOptionRefusal $refusal) {
            throw $refusal->under('namespace');
        }
        if (!$config->boolean('enabled', true)) {
            $class = new ClassInstabilityOptions(enabled: false, maxWarning: $class->maxWarning, maxError: $class->maxError, minAfferent: $class->minAfferent);
            $namespace = new NamespaceInstabilityOptions(enabled: false, maxWarning: $namespace->maxWarning, maxError: $namespace->maxError, minClassCount: $namespace->minClassCount, minAfferent: $namespace->minAfferent);
        }
        return new self(class: $class, namespace: $namespace);
    }

    /**
     * `max-warning`/`max-error` are declared here for the same reason
     * `CboOptions::acceptedOptionKeys()` declares `warning`/`error`: they sit
     * in the legacy-flat branch's condition, not only its body, so a bare
     * `max-warning`/`max-error` works alone.
     */
    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'max-error' => RuleOptionShape::number()->orNull(),
            'max-warning' => RuleOptionShape::number()->orNull(),
            'threshold' => RuleOptionShape::number()->orNull(),
        ])->withLevelSlots(self::levelOptionsClasses())->spreadingInto('threshold', ['class.threshold', 'namespace.threshold'])->spreadingInto('max-warning', ['class.max-warning', 'namespace.max-warning'])->spreadingInto('max-error', ['class.max-error', 'namespace.max-error']);
    }

    /**
     * @return array<string, class-string<LevelOptionsInterface>>
     */
    public static function levelOptionsClasses(): array
    {
        return [
            SymbolLevel::Class_->value => ClassInstabilityOptions::class,
            SymbolLevel::Namespace_->value => NamespaceInstabilityOptions::class,
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
                \sprintf('Level %s is not supported by InstabilityRule', $level->value),
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
