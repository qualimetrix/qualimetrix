<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleNameJudge;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDocumentForms;

/** The rule option entries and the two independent rule selection lists. */
final readonly class RulesSection implements DocumentSectionSchemaInterface
{
    public function __construct(private RuleExecutionInterface $execution, private string $root)
    {
        if (!\in_array($root, ['rules', 'only_rules', 'disabled_rules'], true)) {
            throw new LogicException(\sprintf('Unsupported rule configuration root "%s".', $root));
        }
    }

    public function declaration(): SectionDeclaration
    {
        if ($this->root !== 'rules') {
            $element = NodeSchema::scalar(ScalarForm::String)->nonEmpty();
            return new SectionDeclaration($this->root, $this->root === 'disabled_rules'
                ? NodeSchema::set($element)
                : NodeSchema::list($element)->announcingEmptyOverride('An empty only_rules applies no rule filter: every enabled rule runs.'));
        }
        $entries = [];
        foreach ($this->execution->allRules() as $producer) {
            $entries[$producer->name] = (new RuleOptionDocumentForms())->schema(RuleOptionSurface::of($producer->optionsClass));
        }
        $judge = new RuleNameJudge(array_keys($entries));
        return new SectionDeclaration('rules', NodeSchema::namedMapOf(
            static fn(string $name): ?NodeSchema => $entries[$name] ?? null,
            NameVocabulary::predicate($judge->judge(...)),
        ));
    }
}
