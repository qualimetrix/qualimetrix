<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Instability;

use Qualimetrix\Analysis\Evidence\Coupling\Cbo\CboOptions;

use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalOptionsQueries;

use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionRefusal;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Options for InstabilityRule (hierarchical).
 *
 * Supports class and namespace levels for instability thresholds.
 */
final readonly class InstabilityOptions implements HierarchicalRuleOptionsInterface
{
    use HierarchicalOptionsQueries;

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
            $namespace = new NamespaceInstabilityOptions(enabled: false, maxWarning: $namespace->maxWarning, maxError: $namespace->maxError, minTypeCount: $namespace->minTypeCount, minAfferent: $namespace->minAfferent);
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

    /** @return non-empty-array<string, LevelOptionsInterface> */
    protected function levelOptions(): array
    {
        return ['class' => $this->class, 'namespace' => $this->namespace];
    }

}
