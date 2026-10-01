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
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleNameJudge;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Parses CLI options from Symfony Console InputInterface.
 */
final readonly class CliOptionsParser
{
    public function __construct(
        private RuleOptionsParser $ruleOptionsParser,
        private CliSelectorDecoder $selectorDecoder = new CliSelectorDecoder(),
    ) {}

    /**
     * @param ?list<array{optionName: string, text: string, ordinal: int}> $records
     *
     * @return list<CommandLinePathWrite>
     */
    public function pathWrites(InputInterface $input, ?array $records = null): array
    {
        $aliasForms = [];
        foreach ($this->ruleOptionsParser->getAliasNames() as $alias) {
            $target = $this->ruleOptionsParser->aliasTarget($alias);
            $surface = $target === null ? null : $this->ruleOptionsParser->surfaceFor($target['rule']);
            $address = $surface?->locate($target['option'] ?? '');
            if ($address !== null) {
                $aliasForms[$alias] = $surface->schemaAt($address)->scalarForms() !== [ScalarForm::Boolean];
            }
        }
        $records ??= AuthoredRuleOptionWrites::fromInput($input, $aliasForms);
        usort($records, static fn(array $a, array $b): int => $a['ordinal'] <=> $b['ordinal']);

        $judge = new RuleNameJudge($this->ruleOptionsParser->producerNames());
        $writes = [];
        foreach ($records as $record) {
            $optionName = $record['optionName'];
            if ($optionName === '--rule-opt') {
                $parsed = $this->ruleOptionsParser->parseAuthoredRuleOption($record['text']);
                $rule = $parsed['rule'];
                $option = $parsed['option'];
                $text = $parsed['text'];
            } else {
                $alias = substr($optionName, 2);
                $target = $this->ruleOptionsParser->aliasTarget($alias);
                if ($target === null || !$input->hasOption($alias)) {
                    throw ConfigurationRefusal::aboutCommandLineInput($optionName, 'Unknown rule option alias.');
                }
                $rule = $target['rule'];
                $option = $target['option'];
                $text = $record['text'];
                if (trim($text) === '') {
                    throw ConfigurationRefusal::aboutCommandLineInput(
                        $optionName,
                        \sprintf(
                            'Option %s (rule "%s", option "%s") was written with an empty value ("%s="). '
                            . 'Write a value after "=", or omit %s entirely to use its default.',
                            $optionName,
                            $rule,
                            $option,
                            $optionName,
                            $optionName,
                        ),
                    );
                }
            }

            $problem = $judge->judge($rule);
            if ($problem !== null) {
                throw ConfigurationRefusal::aboutCommandLineInput($optionName, $problem->summary);
            }
            $surface = $this->ruleOptionsParser->surfaceFor($rule)
                ?? throw new LogicException('An admitted producer must supply its option schema.');
            $address = $surface->locate($option);
            if ($address === null) {
                $framework = FrameworkOptionKeys::declared();
                $key = $framework->spellingOf(ConfigKeySpelling::normalize($option));
                if ($key !== null) {
                    $address = new RuleOptionAddress(null, $key);
                }
            }
            if ($address === null) {
                $parts = explode('.', $option, 2);
                $level = \count($parts) === 2 ? $surface->levelNamed($parts[0]) : null;
                $message = $level === null
                    ? RuleOptionRefusalWording::notAnOptionOfRule($option, $rule, $surface->writableAt(null))
                    : RuleOptionRefusalWording::notAnOptionAtLevel($parts[1], $rule, $level, $surface->writableAt($level));
                throw ConfigurationRefusal::aboutCommandLineInput($optionName, $message);
            }

            $path = ['rules', $rule];
            if ($address->level !== null) {
                $path[] = $address->level;
            }
            $path[] = $address->key;
            $selector = null;
            if ($address->level === null && $address->key === FrameworkOptionKeys::PATHS) {
                $selector = $this->selectorDecoder->pathPayload($text, $optionName);
            } elseif ($address->level === null && $address->key === FrameworkOptionKeys::NAMESPACES) {
                $selector = $this->selectorDecoder->namespacePayload($text, $optionName);
            } elseif ($address->level === null && ConfigKeySpelling::normalize($address->key) === 'includeNamespaces') {
                $selector = $this->selectorDecoder->namespacePayload($text, $optionName);
            }
            $writes[] = new CommandLinePathWrite($path, $text, $optionName, $surface->schemaAt($address), $selector);
        }

        return $writes;
    }

}
