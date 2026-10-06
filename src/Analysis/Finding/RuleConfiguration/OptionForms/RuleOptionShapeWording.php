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
            RuleOptionShape::PLAIN => $shape->plain?->describe() ?? throw new LogicException('Missing plain rule option form.'),
            RuleOptionShape::WORDS => $shape->words?->describe() ?? throw new LogicException('Missing rule option word set.'),
            RuleOptionShape::LIST => 'a list of ' . $this->elementPlural($shape),
            RuleOptionShape::MAP => 'a map of ' . $this->elementPlural($shape),
            RuleOptionShape::UNION => implode(' or ', array_map($this->describe(...), $shape->alternatives)),
            default => throw new LogicException('Unknown rule option shape.'),
        };

        return $shape->nullable ? $described . ' or null' : $described;
    }

    public function describeWritten(RuleOptionShape $shape, mixed $written): string
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
        if ($shape->kind === RuleOptionShape::UNION && (\is_int($written) || \is_float($written))) {
            $ordinary = RuleOptionValueForm::describeWritten($written);
            foreach ($shape->alternatives as $alternative) {
                $described = $this->describeWritten($alternative, $written);
                if ($described !== $ordinary) {
                    return $described;
                }
            }
        }

        return RuleOptionValueForm::describeWritten($written);
    }

    private function elementPlural(RuleOptionShape $shape): string
    {
        $element = $shape->element ?? throw new LogicException('A container needs an element form.');

        return $element->plain !== null && !$element->nullable
            ? $element->plain->describeMany()
            : $this->describe($element);
    }
}
