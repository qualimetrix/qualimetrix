<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Pipeline\CommandLinePathWrite;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionDocumentFormsInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Parses CLI options from Symfony Console InputInterface.
 */
final readonly class CliOptionsParser
{
    public function __construct(
        private RuleOptionDocumentFormsInterface $documentForms,
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
        $addressing = new CliRuleOptionAddressing($this->ruleOptionsParser, $this->documentForms, $this->selectorDecoder);
        $records ??= AuthoredRuleOptionWrites::fromInput($input, $addressing->aliasForms());
        usort($records, static fn(array $a, array $b): int => $a['ordinal'] <=> $b['ordinal']);

        $writes = [];
        foreach ($records as $record) {
            $writes[] = $this->writeRecord($input, $record, $addressing);
        }

        return $writes;
    }

    /** @param array{optionName: string, text: string, ordinal: int} $record */
    private function writeRecord(InputInterface $input, array $record, CliRuleOptionAddressing $addressing): CommandLinePathWrite
    {
        $optionName = $record['optionName'];
        if ($optionName === '--rule-opt') {
            $parsed = $this->ruleOptionsParser->parseAuthoredRuleOption($record['text']);
            return $addressing->pathWrite($parsed['rule'], $parsed['option'], $parsed['text'], $optionName, '--rule-opt=' . $record['text']);
        }

        $alias = substr($optionName, 2);
        $target = $this->ruleOptionsParser->aliasTarget($alias);
        if ($target === null || !$input->hasOption($alias)) {
            throw ConfigurationRefusal::aboutCommandLineInput($optionName, 'Unknown rule option alias.');
        }
        if (trim($record['text']) === '') {
            throw ConfigurationRefusal::aboutCommandLineInput(
                $optionName,
                \sprintf(
                    'Option %s (rule "%s", option "%s") was written with an empty value ("%s="). '
                    . 'Write a value after "=", or omit %s entirely to use its default.',
                    $optionName,
                    $target['rule'],
                    $target['option'],
                    $optionName,
                    $optionName,
                ),
            );
        }

        return $addressing->pathWrite($target['rule'], $target['option'], $record['text'], $optionName, $optionName . '=' . $record['text']);
    }
}
