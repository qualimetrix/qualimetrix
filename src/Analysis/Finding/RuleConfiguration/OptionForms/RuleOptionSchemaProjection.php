<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SchemaWordSet;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\TextRequirement;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionValueForm;

/** Projects an option declaration into the document reader's node language. */
final readonly class RuleOptionSchemaProjection
{
    public function __construct(private RuleOptionDefinition $definition) {}

    public function project(?NodeSchema $block = null): NodeSchema
    {
        $schema = match (true) {
            $this->definition->plain !== null => $this->plain($this->definition->plain, $block),
            $this->definition->words !== null => $this->word(),
            $this->definition->compound !== null => $this->compound($this->definition->compound),
            default => throw new LogicException('Unknown rule option shape.'),
        };
        return $this->definition->layerJudge === null ? $schema : $schema->judgedInEachLayer($this->definition->layerJudge);
    }

    private function plain(RuleOptionValueForm $form, ?NodeSchema $block): NodeSchema
    {
        return match ($form) {
            RuleOptionValueForm::Boolean => NodeSchema::scalar(ScalarForm::Boolean),
            RuleOptionValueForm::WholeNumber => $this->nonNegativeNumber(ScalarForm::Integer),
            RuleOptionValueForm::Number => $this->nonNegativeNumber(ScalarForm::Number),
            RuleOptionValueForm::SignedNumber => $this->signedNumber(),
            RuleOptionValueForm::Text => NodeSchema::scalar(ScalarForm::String),
            RuleOptionValueForm::NonEmptyText => NodeSchema::scalar(ScalarForm::String)->nonEmpty(),
            RuleOptionValueForm::Block => $block ?? throw new LogicException('A block needs its declared child schema.'),
        };
    }

    private function nonNegativeNumber(ScalarForm $form): NodeSchema
    {
        return NodeSchema::scalar($form)->atLeast(max(0, $this->definition->minimum ?? 0));
    }

    private function signedNumber(): NodeSchema
    {
        $schema = NodeSchema::scalar(ScalarForm::Number);
        return $this->definition->minimum === null ? $schema : $schema->atLeast($this->definition->minimum);
    }

    private function word(): NodeSchema
    {
        $words = $this->definition->words;
        if ($words === null || $words->words === []) {
            throw new LogicException('A word set needs at least one word.');
        }
        $schemaWords = $words->foldsCase() ? SchemaWordSet::foldingCase(...$words->words) : SchemaWordSet::of(...$words->words);
        return NodeSchema::scalar(ScalarForm::String)->words($schemaWords);
    }

    private function compound(CompoundRuleOptionForm $form): NodeSchema
    {
        return match ($form->kind) {
            CompoundOptionKind::List => NodeSchema::list($form->element?->asNodeSchema() ?? throw new LogicException('A list needs an element form.')),
            CompoundOptionKind::Map => NodeSchema::namedMap($form->element?->asNodeSchema() ?? throw new LogicException('A map needs a value form.'), NameVocabulary::predicate(static fn(string $name) => null)),
            CompoundOptionKind::Union => $this->union($form),
        };
    }

    private function union(CompoundRuleOptionForm $form): NodeSchema
    {
        if ($form->bareTextList !== null) {
            return $form->bareTextList->asNodeSchema()->admittingBareElement();
        }
        $forms = [];
        $minimum = null;
        foreach ($form->alternatives as $alternative) {
            $schema = $alternative->asNodeSchema();
            if ($schema->scalar->forms === [] || $schema->scalar->words !== null || $schema->scalar->textRequirement === TextRequirement::NonBlank) {
                throw new LogicException('The declared union has no document form.');
            }
            $forms = [...$forms, ...$schema->scalar->forms];
            $minimum ??= $schema->scalar->minimum;
        }
        $schema = NodeSchema::scalar(...$forms);
        return $minimum === null ? $schema : $schema->atLeast($minimum);
    }
}
