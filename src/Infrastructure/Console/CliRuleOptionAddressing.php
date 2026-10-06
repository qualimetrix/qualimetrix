<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\CommandLinePathWrite;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionAddress;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionDocumentFormsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusalWording;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleNameJudge;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser;

/** Admits one CLI rule-option address against its declared producer surface. */
final readonly class CliRuleOptionAddressing
{
    private RuleNameJudge $ruleNames;

    public function __construct(
        private RuleOptionsParser $parser,
        private RuleOptionDocumentFormsInterface $documentForms,
        private CliSelectorDecoder $selectors,
    ) {
        $this->ruleNames = new RuleNameJudge($parser->producerNames());
    }

    /** @return array<string, bool> alias => accepts text */
    public function aliasForms(): array
    {
        $forms = [];
        foreach ($this->parser->getAliasNames() as $alias) {
            $target = $this->parser->aliasTarget($alias);
            $surface = $target === null ? null : $this->parser->surfaceFor($target['rule']);
            $acceptsText = $surface === null ? null : self::acceptsText($surface, $target['option'], $this->documentForms);
            if ($acceptsText !== null) {
                $forms[$alias] = $acceptsText;
            }
        }
        return $forms;
    }

    public static function acceptsText(RuleOptionSurface $surface, string $option, RuleOptionDocumentFormsInterface $documentForms): ?bool
    {
        $address = $surface->locate($option);
        return $address === null ? null : $documentForms->schemaAt($surface, $address)->scalar->forms !== [ScalarForm::Boolean];
    }

    public function pathWrite(string $rule, string $option, string $text, string $optionName, string $authoredExpression): CommandLinePathWrite
    {
        $problem = $this->ruleNames->judge($rule);
        if ($problem !== null) {
            throw ConfigurationRefusal::aboutCommandLineInput($optionName, $problem->summary . ' Written: ' . $authoredExpression . '.');
        }
        $surface = $this->parser->surfaceFor($rule)
            ?? throw new LogicException('An admitted producer must supply its option schema.');
        $address = $surface->locate($option)
            ?? throw ConfigurationRefusal::aboutCommandLineInput($optionName, self::unknownOption($surface, $rule, $option) . ' Written: ' . $authoredExpression . '.');

        $path = ['rules', $rule];
        if ($address->level !== null) {
            $path[] = $address->level;
        }
        $path[] = $address->key;

        try {
            $selectorPayload = $this->selectorPayload($address, $text, $optionName);
        } catch (ConfigurationRefusal $refusal) {
            throw ConfigurationRefusal::aboutCommandLineInput($optionName, $refusal->summary() . ' Written: ' . $authoredExpression . '.', $refusal);
        }
        return new CommandLinePathWrite($path, $text, $optionName, $authoredExpression, $this->documentForms->schemaAt($surface, $address), $selectorPayload);
    }

    /** @return ?list<array<string, string>> */
    private function selectorPayload(RuleOptionAddress $address, string $text, string $optionName): ?array
    {
        if ($address->level === null && $address->key === FrameworkOptionKeys::PATHS) {
            return $this->selectors->pathPayload($text, $optionName);
        }
        if ($address->level === null && ($address->key === FrameworkOptionKeys::NAMESPACES
            || ConfigKeySpelling::normalize($address->key) === 'includeNamespaces')) {
            return $this->selectors->namespacePayload($text, $optionName);
        }
        return null;
    }

    private static function unknownOption(RuleOptionSurface $surface, string $rule, string $option): string
    {
        $parts = explode('.', $option, 2);
        $level = \count($parts) === 2 ? $surface->levelNamed($parts[0]) : null;
        if ($level === null && \count($parts) === 2) {
            $suggestion = self::similarLevel($surface, $parts[0]);
            if ($suggestion !== null) {
                return \sprintf('Level "%s" of rule "%s" is not a declared spelling. Write "%s".', $parts[0], $rule, $suggestion);
            }
        }
        $wording = $level === null
            ? RuleOptionRefusalWording::notAnOptionOfRule($option, $rule, $surface->writableAt(null))
            : RuleOptionRefusalWording::notAnOptionAtLevel($parts[1], $rule, $level, $surface->writableAt($level));
        $written = $level === null ? $option : $parts[1];
        $suggestion = self::similarOption($surface, $level, $written);

        return $suggestion === null ? $wording : $wording . \sprintf(' Write "%s".', $suggestion);
    }

    private static function similarLevel(RuleOptionSurface $surface, string $written): ?string
    {
        foreach ($surface->levels() as $declared) {
            if (ConfigKeySpelling::sameWords($written, $declared)) {
                return $declared;
            }
        }

        return null;
    }

    private static function similarOption(RuleOptionSurface $surface, ?string $level, string $written): ?string
    {
        foreach ($surface->writableAt($level) as $canonical) {
            if (ConfigKeySpelling::sameWords($written, $canonical)) {
                return $canonical;
            }
        }

        return null;
    }
}
