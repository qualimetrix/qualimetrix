<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\CompoundOptionKind;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\CompoundRuleOptionForm;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDefinition;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionSchemaProjection;

/** The declared form of a rule option value and its refusal wording. */
final readonly class RuleOptionShape
{
    public ?RuleOptionWordSet $words;

    private function __construct(private RuleOptionDefinition $definition)
    {
        $this->words = $definition->words;
    }

    public static function boolean(): self
    {
        return new self(new RuleOptionDefinition(plain: RuleOptionValueForm::Boolean));
    }

    public static function integer(): self
    {
        return new self(new RuleOptionDefinition(plain: RuleOptionValueForm::WholeNumber));
    }

    public static function number(): self
    {
        return new self(new RuleOptionDefinition(plain: RuleOptionValueForm::Number));
    }

    public static function signedNumber(): self
    {
        return new self(new RuleOptionDefinition(plain: RuleOptionValueForm::SignedNumber));
    }

    public static function text(): self
    {
        return new self(new RuleOptionDefinition(plain: RuleOptionValueForm::Text));
    }

    public static function nonEmptyText(): self
    {
        return new self(new RuleOptionDefinition(plain: RuleOptionValueForm::NonEmptyText));
    }

    public static function listOf(self $element): self
    {
        $plural = $element->definition->plain !== null && !$element->definition->nullable
            ? $element->definition->plain->describeMany() : $element->describe();
        return new self(new RuleOptionDefinition(compound: new CompoundRuleOptionForm(CompoundOptionKind::List, $element, elementPlural: $plural)));
    }

    public static function mapOf(self $value): self
    {
        $plural = $value->definition->plain !== null && !$value->definition->nullable
            ? $value->definition->plain->describeMany() : $value->describe();
        return new self(new RuleOptionDefinition(compound: new CompoundRuleOptionForm(CompoundOptionKind::Map, $value, elementPlural: $plural)));
    }

    public static function block(): self
    {
        return new self(new RuleOptionDefinition(plain: RuleOptionValueForm::Block));
    }

    public static function either(self ...$alternatives): self
    {
        if (\count($alternatives) < 2) {
            throw new LogicException('A union of forms needs at least two alternatives.');
        }
        $bareTextList = \count($alternatives) === 2
            && $alternatives[0]->definition->plain === RuleOptionValueForm::Text
            && $alternatives[1]->definition->compound?->kind === CompoundOptionKind::List
            && $alternatives[1]->definition->compound->element?->definition->plain === RuleOptionValueForm::Text
                ? $alternatives[1] : null;

        return new self(new RuleOptionDefinition(compound: new CompoundRuleOptionForm(
            CompoundOptionKind::Union,
            alternatives: array_values($alternatives),
            bareTextList: $bareTextList,
        )));
    }

    public static function words(RuleOptionWordSet $words): self
    {
        if ($words->words === []) {
            throw new LogicException('A closed set of words needs at least one word.');
        }
        foreach ($words->words as $word) {
            if (trim($word) === '') {
                throw new LogicException('A closed set of words cannot carry a blank word.');
            }
        }
        return new self(new RuleOptionDefinition(words: $words));
    }

    public function orNull(): self
    {
        return new self($this->definition->orNull());
    }

    public function atLeast(int|float $minimum): self
    {
        if (!\in_array($this->definition->plain, [RuleOptionValueForm::WholeNumber, RuleOptionValueForm::Number, RuleOptionValueForm::SignedNumber], true)) {
            throw new LogicException('A numeric floor requires a numeric rule option form.');
        }
        return new self($this->definition->atLeast($minimum));
    }

    /** @param Closure(ResolvedValueInterface, list<string>): void $judge */
    public function judgedInEachLayer(Closure $judge): self
    {
        return new self($this->definition->judgedInEachLayer($judge));
    }

    public function matches(mixed $value): bool
    {
        return $this->definition->matches($value);
    }

    public function asNodeSchema(?NodeSchema $block = null): NodeSchema
    {
        return (new RuleOptionSchemaProjection($this->definition))->project($block);
    }

    public function describe(): string
    {
        $described = match (true) {
            $this->definition->plain !== null => $this->definition->plain->describe(),
            $this->definition->words !== null => $this->definition->words->describe(),
            $this->definition->compound !== null => $this->definition->compound->describe(),
            default => throw new LogicException('Unknown rule option shape.'),
        };
        return $this->definition->nullable ? $described . ' or null' : $described;
    }

    public function describeWritten(mixed $written): string
    {
        return $this->words?->describeWritten($written)
            ?? $this->definition->plain?->describeOutOfRange($written)
            ?? $this->definition->compound?->describeOutOfRange($written)
            ?? RuleOptionValueForm::describeWritten($written);
    }
}
