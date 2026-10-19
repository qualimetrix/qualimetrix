<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity\Cyclomatic;

use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalOptionsQueries;

use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionRefusal;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Options for ComplexityRule (hierarchical).
 *
 * Supports callable and class levels with separate thresholds.
 */
final readonly class ComplexityOptions implements HierarchicalRuleOptionsInterface
{
    use HierarchicalOptionsQueries;

    public function __construct(
        public MethodComplexityOptions $callable = new MethodComplexityOptions(),
        public ClassComplexityOptions $class = new ClassComplexityOptions(),
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        try {
            $callable = MethodComplexityOptions::fromResolved($config->atLevel('callable'));
        } catch (RuleOptionRefusal $refusal) {
            throw $refusal->under('callable');
        }
        try {
            $class = ClassComplexityOptions::fromResolved($config->atLevel('class'));
        } catch (RuleOptionRefusal $refusal) {
            throw $refusal->under('class');
        }
        if (!$config->boolean('enabled', true)) {
            $callable = new MethodComplexityOptions(enabled: false, warning: $callable->warning, error: $callable->error);
            $class = new ClassComplexityOptions(enabled: false, maxWarning: $class->maxWarning, maxError: $class->maxError);
        }
        return new self(callable: $callable, class: $class);
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'threshold' => RuleOptionShape::integer()->orNull(),
        ])->withLevelSlots(self::levelOptionsClasses())->spreadingInto('threshold', ['callable.threshold']);
    }

    /**
     * @return array<string, class-string<LevelOptionsInterface>>
     */
    public static function levelOptionsClasses(): array
    {
        return [
            SymbolLevel::Callable->value => MethodComplexityOptions::class,
            SymbolLevel::Class_->value => ClassComplexityOptions::class,
        ];
    }
    /** @return non-empty-array<string, LevelOptionsInterface> */
    protected function levelOptions(): array
    {
        return ['callable' => $this->callable, 'class' => $this->class];
    }

}
