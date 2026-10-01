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
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusalWording;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleNameJudge;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser;

/** Admits one CLI rule-option address against its declared producer surface. */
final readonly class CliRuleOptionAddressing
{
    private RuleNameJudge $ruleNames;

    public function __construct(private RuleOptionsParser $parser, private CliSelectorDecoder $selectors)
    {
        $this->ruleNames = new RuleNameJudge($parser->producerNames());
    }

    /** @return array<string, bool> alias => accepts text */
    public function aliasForms(): array
    {
        $forms = [];
        foreach ($this->parser->getAliasNames() as $alias) {
            $target = $this->parser->aliasTarget($alias);
            $surface = $target === null ? null : $this->parser->surfaceFor($target['rule']);
            $acceptsText = $surface === null ? null : self::acceptsText($surface, $target['option']);
            if ($acceptsText !== null) {
                $forms[$alias] = $acceptsText;
            }
        }
        return $forms;
    }

    public static function acceptsText(RuleOptionSurface $surface, string $option): ?bool
    {
        $address = $surface->locate($option);
        return $address === null ? null : $surface->schemaAt($address)->scalar->forms !== [ScalarForm::Boolean];
    }

    public function pathWrite(string $rule, string $option, string $text, string $optionName): CommandLinePathWrite
    {
        $problem = $this->ruleNames->judge($rule);
        if ($problem !== null) {
            throw ConfigurationRefusal::aboutCommandLineInput($optionName, $problem->summary);
        }
        $surface = $this->parser->surfaceFor($rule)
            ?? throw new LogicException('An admitted producer must supply its option schema.');
        $address = $surface->locate($option)
            ?? throw ConfigurationRefusal::aboutCommandLineInput($optionName, self::unknownOption($surface, $rule, $option));

        $path = ['rules', $rule];
        if ($address->level !== null) {
            $path[] = $address->level;
        }
        $path[] = $address->key;

        return new CommandLinePathWrite($path, $text, $optionName, $surface->schemaAt($address), $this->selectorPayload($address, $text, $optionName));
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
        return $level === null
            ? RuleOptionRefusalWording::notAnOptionOfRule($option, $rule, $surface->writableAt(null))
            : RuleOptionRefusalWording::notAnOptionAtLevel($parts[1], $rule, $level, $surface->writableAt($level));
    }
}
