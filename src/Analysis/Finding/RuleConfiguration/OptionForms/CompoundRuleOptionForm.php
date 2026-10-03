<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionValueForm;

/** Matching and wording for a list, map, or union of option forms. */
final readonly class CompoundRuleOptionForm
{
    /** @param list<RuleOptionShape> $alternatives */
    public function __construct(
        public CompoundOptionKind $kind,
        public ?RuleOptionShape $element = null,
        public array $alternatives = [],
        private ?string $elementPlural = null,
        public ?RuleOptionShape $bareTextList = null,
    ) {}

    public function matches(mixed $value): bool
    {
        if ($this->kind === CompoundOptionKind::Union) {
            foreach ($this->alternatives as $alternative) {
                if ($alternative->matches($value)) {
                    return true;
                }
            }
            return false;
        }
        if (!\is_array($value) || array_is_list($value) !== ($this->kind === CompoundOptionKind::List)) {
            return false;
        }
        foreach ($value as $item) {
            if ($this->element !== null && !$this->element->matches($item)) {
                return false;
            }
        }
        return true;
    }

    public function describe(): string
    {
        if ($this->kind === CompoundOptionKind::Union) {
            return implode(' or ', array_map(static fn(RuleOptionShape $shape): string => $shape->describe(), $this->alternatives));
        }
        return ($this->kind === CompoundOptionKind::List ? 'a list of ' : 'a map of ') . ($this->elementPlural ?? 'values');
    }

    public function describeOutOfRange(mixed $written): ?string
    {
        if (!\is_int($written) && !\is_float($written)) {
            return null;
        }
        $ordinary = RuleOptionValueForm::describeWritten($written);
        foreach ($this->alternatives as $alternative) {
            $described = $alternative->describeWritten($written);
            if ($described !== $ordinary) {
                return $described;
            }
        }
        return null;
    }
}
