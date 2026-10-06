<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SchemaWordSet;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\TextRequirement;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionValueForm;

/** Projects an option declaration into the document reader's node language. */
final readonly class RuleOptionSchemaProjection
{
    public function project(RuleOptionShape $shape, ?NodeSchema $block = null): NodeSchema
    {
        $schema = match ($shape->kind) {
            RuleOptionShape::PLAIN => $this->plain($shape, $block),
            RuleOptionShape::WORDS => $this->word($shape),
            RuleOptionShape::LIST => NodeSchema::list($this->project($shape->element ?? throw new LogicException('A list needs an element form.'))),
            RuleOptionShape::MAP => NodeSchema::namedMap(
                $this->project($shape->element ?? throw new LogicException('A map needs a value form.')),
                NameVocabulary::predicate(static fn(string $name) => null),
            ),
            RuleOptionShape::UNION => $this->union($shape),
            default => throw new LogicException('Unknown rule option shape.'),
        };

        return $shape->layerJudge === null ? $schema : $schema->judgedInEachLayer($shape->layerJudge);
    }

    private function plain(RuleOptionShape $shape, ?NodeSchema $block): NodeSchema
    {
        return match ($shape->plain ?? throw new LogicException('Missing plain rule option form.')) {
            RuleOptionValueForm::Boolean => NodeSchema::scalar(ScalarForm::Boolean),
            RuleOptionValueForm::WholeNumber => NodeSchema::scalar(ScalarForm::Integer)->atLeast(max(0, $shape->minimum ?? 0)),
            RuleOptionValueForm::Number => NodeSchema::scalar(ScalarForm::Number)->atLeast(max(0, $shape->minimum ?? 0)),
            RuleOptionValueForm::SignedNumber => $shape->minimum === null
                ? NodeSchema::scalar(ScalarForm::Number)
                : NodeSchema::scalar(ScalarForm::Number)->atLeast($shape->minimum),
            RuleOptionValueForm::Text => NodeSchema::scalar(ScalarForm::String),
            RuleOptionValueForm::NonEmptyText => NodeSchema::scalar(ScalarForm::String)->nonEmpty(),
            RuleOptionValueForm::Block => $block ?? throw new LogicException('A block needs its declared child schema.'),
        };
    }

    private function word(RuleOptionShape $shape): NodeSchema
    {
        $words = $shape->words ?? throw new LogicException('A word set needs at least one word.');
        if ($words->words === []) {
            throw new LogicException('A word set needs at least one word.');
        }

        $schemaWords = $words->foldsCase()
            ? SchemaWordSet::foldingCase(...$words->words)
            : SchemaWordSet::of(...$words->words);

        return NodeSchema::scalar(ScalarForm::String)->words($schemaWords);
    }

    private function union(RuleOptionShape $shape): NodeSchema
    {
        $alternatives = $shape->alternatives;
        if (\count($alternatives) === 2
            && $alternatives[0]->kind === RuleOptionShape::PLAIN
            && $alternatives[0]->plain === RuleOptionValueForm::Text
            && $alternatives[1]->kind === RuleOptionShape::LIST
            && $alternatives[1]->element?->kind === RuleOptionShape::PLAIN
            && $alternatives[1]->element->plain === RuleOptionValueForm::Text) {
            return $this->project($alternatives[1])->admittingBareElement();
        }

        $forms = [];
        $minimum = null;
        foreach ($alternatives as $alternative) {
            $schema = $this->project($alternative);
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
