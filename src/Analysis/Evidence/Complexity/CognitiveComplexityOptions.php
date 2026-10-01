<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use InvalidArgumentException;

use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Options for CognitiveComplexityRule (hierarchical).
 *
 * Supports callable and class levels with separate thresholds.
 */
final readonly class CognitiveComplexityOptions implements HierarchicalRuleOptionsInterface
{
    public function __construct(
        public MethodCognitiveComplexityOptions $callable = new MethodCognitiveComplexityOptions(),
        public ClassCognitiveComplexityOptions $class = new ClassCognitiveComplexityOptions(),
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $callable = MethodCognitiveComplexityOptions::fromResolved($config->atLevel('callable'));
        $class = ClassCognitiveComplexityOptions::fromResolved($config->atLevel('class'));
        if (!$config->boolean('enabled', true)) {
            $callable = new MethodCognitiveComplexityOptions(enabled: false, warning: $callable->warning, error: $callable->error);
            $class = new ClassCognitiveComplexityOptions(enabled: false, maxWarning: $class->maxWarning, maxError: $class->maxError);
        }
        return new self(callable: $callable, class: $class);
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
                \sprintf('Level %s is not supported by CognitiveComplexityRule', $level->value),
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
            SymbolLevel::Callable->value => MethodCognitiveComplexityOptions::class,
            SymbolLevel::Class_->value => ClassCognitiveComplexityOptions::class,
        ];
    }
}
