<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity\Npath;

use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalOptionsQueries;

use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionRefusal;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Options for NpathComplexityRule (hierarchical).
 *
 * Supports callable and class levels with separate thresholds.
 */
final readonly class NpathComplexityOptions implements HierarchicalRuleOptionsInterface
{
    use HierarchicalOptionsQueries;

    public function __construct(
        public MethodNpathComplexityOptions $callable = new MethodNpathComplexityOptions(),
        public ClassNpathComplexityOptions $class = new ClassNpathComplexityOptions(),
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        try {
            $callable = MethodNpathComplexityOptions::fromResolved($config->atLevel('callable'));
        } catch (RuleOptionRefusal $refusal) {
            throw $refusal->under('callable');
        }
        try {
            $class = ClassNpathComplexityOptions::fromResolved($config->atLevel('class'));
        } catch (RuleOptionRefusal $refusal) {
            throw $refusal->under('class');
        }
        if (!$config->boolean('enabled', true)) {
            $callable = new MethodNpathComplexityOptions(enabled: false, warning: $callable->warning, error: $callable->error);
            $class = new ClassNpathComplexityOptions(enabled: false, maxWarning: $class->maxWarning, maxError: $class->maxError);
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
            SymbolLevel::Callable->value => MethodNpathComplexityOptions::class,
            SymbolLevel::Class_->value => ClassNpathComplexityOptions::class,
        ];
    }
    /** @return non-empty-array<string, LevelOptionsInterface> */
    protected function levelOptions(): array
    {
        return ['callable' => $this->callable, 'class' => $this->class];
    }

}
