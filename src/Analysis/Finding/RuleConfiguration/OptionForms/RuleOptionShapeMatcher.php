<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;

/** Evaluates one declared option form, including nested containers. */
final readonly class RuleOptionShapeMatcher
{
    public function matches(RuleOptionShape $shape, mixed $value): bool
    {
        if ($value === null) {
            return $shape->nullable;
        }
        if (!$this->meetsMinimum($shape, $value)) {
            return false;
        }

        return match ($shape->kind) {
            RuleOptionShape::PLAIN => $this->matchesPlain($shape, $value),
            RuleOptionShape::WORDS => $this->matchesWord($shape, $value),
            RuleOptionShape::LIST, RuleOptionShape::MAP => $this->container($shape, $value),
            RuleOptionShape::UNION => $this->union($shape, $value),
            default => throw new LogicException('Unknown rule option shape.'),
        };
    }

    private function meetsMinimum(RuleOptionShape $shape, mixed $value): bool
    {
        return $shape->minimum === null
            || (!\is_int($value) && !\is_float($value))
            || $value >= $shape->minimum;
    }

    private function matchesPlain(RuleOptionShape $shape, mixed $value): bool
    {
        return $shape->plain?->accepts($value) ?? throw new LogicException('Missing plain rule option form.');
    }

    private function matchesWord(RuleOptionShape $shape, mixed $value): bool
    {
        return $shape->words?->contains($value) ?? throw new LogicException('Missing rule option word set.');
    }

    private function container(RuleOptionShape $shape, mixed $value): bool
    {
        if (!\is_array($value) || array_is_list($value) !== ($shape->kind === RuleOptionShape::LIST)) {
            return false;
        }

        $element = $shape->element ?? throw new LogicException('A container needs an element form.');
        foreach ($value as $item) {
            if (!$this->matches($element, $item)) {
                return false;
            }
        }

        return true;
    }

    private function union(RuleOptionShape $shape, mixed $value): bool
    {
        foreach ($shape->alternatives as $alternative) {
            if ($this->matches($alternative, $value)) {
                return true;
            }
        }

        return false;
    }
}
