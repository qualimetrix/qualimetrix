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
 * Options for NpathComplexityRule (hierarchical).
 *
 * Supports callable and class levels with separate thresholds.
 */
final readonly class NpathComplexityOptions implements HierarchicalRuleOptionsInterface
{
    public function __construct(
        public MethodNpathComplexityOptions $callable = new MethodNpathComplexityOptions(),
        public ClassNpathComplexityOptions $class = new ClassNpathComplexityOptions(),
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $callable = MethodNpathComplexityOptions::fromResolved($config->atLevel('callable'));
        $class = ClassNpathComplexityOptions::fromResolved($config->atLevel('class'));
        if (!$config->boolean('enabled', true)) {
            $callable = new MethodNpathComplexityOptions(enabled: false, warning: $callable->warning, error: $callable->error);
            $class = new ClassNpathComplexityOptions(enabled: false, maxWarning: $class->maxWarning, maxError: $class->maxError);
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
                \sprintf('Level %s is not supported by NpathComplexityRule', $level->value),
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
            SymbolLevel::Callable->value => MethodNpathComplexityOptions::class,
            SymbolLevel::Class_->value => ClassNpathComplexityOptions::class,
        ];
    }
}
