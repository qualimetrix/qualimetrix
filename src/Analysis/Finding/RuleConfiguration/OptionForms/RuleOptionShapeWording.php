<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionValueForm;

/** Names a declared form and a value written against it. */
final readonly class RuleOptionShapeWording
{
    public function describe(RuleOptionShape $shape): string
    {
        $described = match ($shape->kind) {
            RuleOptionShape::PLAIN => $this->plain($shape),
            RuleOptionShape::WORDS => $this->word($shape),
            RuleOptionShape::LIST => 'a list of ' . $this->elementPlural($shape),
            RuleOptionShape::MAP => 'a map of ' . $this->elementPlural($shape),
            RuleOptionShape::UNION => implode(' or ', array_map($this->describe(...), $shape->alternatives)),
            default => throw new LogicException('Unknown rule option shape.'),
        };

        return $shape->nullable ? $described . ' or null' : $described;
    }

    public function describeWritten(RuleOptionShape $shape, mixed $written): string
    {
        return $this->declaredWrittenForm($shape, $written)
            ?? $this->numericUnionWrittenForm($shape, $written)
            ?? RuleOptionValueForm::describeWritten($written);
    }

    private function plain(RuleOptionShape $shape): string
    {
        return $shape->plain?->describe() ?? throw new LogicException('Missing plain rule option form.');
    }

    private function word(RuleOptionShape $shape): string
    {
        return $shape->words?->describe() ?? throw new LogicException('Missing rule option word set.');
    }

    private function declaredWrittenForm(RuleOptionShape $shape, mixed $written): ?string
    {
        if ($shape->words !== null) {
            $word = $shape->words->describeWritten($written);
            if ($word !== null) {
                return $word;
            }
        }
        if ($shape->plain !== null) {
            $outOfRange = $shape->plain->describeOutOfRange($written);
            if ($outOfRange !== null) {
                return $outOfRange;
            }
        }

        return null;
    }

    private function numericUnionWrittenForm(RuleOptionShape $shape, mixed $written): ?string
    {
        if ($shape->kind !== RuleOptionShape::UNION || (!\is_int($written) && !\is_float($written))) {
            return null;
        }

        $ordinary = RuleOptionValueForm::describeWritten($written);
        foreach ($shape->alternatives as $alternative) {
            $described = $this->describeWritten($alternative, $written);
            if ($described !== $ordinary) {
                return $described;
            }
        }

        return null;
    }

    private function elementPlural(RuleOptionShape $shape): string
    {
        $element = $shape->element ?? throw new LogicException('A container needs an element form.');

        return $element->plain !== null && !$element->nullable
            ? $element->plain->describeMany()
            : $this->describe($element);
    }
}
