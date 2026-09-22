<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ValueError;

/**
 * Builds FormatterContext from CLI input and formatter defaults.
 */
final class FormatterContextFactory
{
    private const int DEFAULT_DETAIL_LIMIT = 200;

    /**
     * Takes the registry rather than reading the one formatter passed to create():
     * the set of real keys is the union over every formatter, and a key another
     * format reads is a real key typed at the wrong run, not a typo.
     */
    public function __construct(
        private readonly FormatterRegistryInterface $formatterRegistry,
        private readonly CliSelectorDecoder $selectorDecoder = new CliSelectorDecoder(),
    ) {}

    public function create(
        InputInterface $input,
        OutputInterface $output,
        FormatterInterface $formatter,
        AbsolutePath $projectRoot,
        bool $scopedReporting = false,
        ?NamespacePattern $namespacePattern = null,
    ): FormatterContext {
        // Resolve group-by: explicit CLI option or formatter default
        /** @var string|null $groupByValue */
        $groupByValue = $input->getOption('group-by');
        $isGroupByExplicit = $groupByValue !== null;
        try {
            $groupBy = $isGroupByExplicit
                ? GroupBy::from($groupByValue)
                : $formatter->getDefaultGroupBy();
        } catch (ValueError) {
            $valid = implode(', ', array_column(GroupBy::cases(), 'value'));
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--group-by',
                \sprintf('Invalid --group-by value "%s". Valid values: %s', $groupByValue, $valid),
            );
        }

        // Parse --format-opt key=value pairs
        /** @var list<string> $formatOpts */
        $formatOpts = $input->getOption('format-opt');
        $options = [];
        foreach ($formatOpts as $opt) {
            $eqPos = strpos($opt, '=');
            if ($eqPos === false) {
                throw ConfigurationRefusal::aboutCommandLineInput(
                    '--format-opt',
                    \sprintf('Invalid --format-opt value "%s": expected format key=value', $opt),
                );
            }
            $options[substr($opt, 0, $eqPos)] = substr($opt, $eqPos + 1);
        }

        // Handle --all flag: alias for --format-opt=violations=all --detail=all
        $allFlag = (bool) $input->getOption('all');
        if ($allFlag) {
            $existingFindings = $options['violations'] ?? '';
            if ($existingFindings !== '' && $existingFindings !== 'all') {
                throw ConfigurationRefusal::aboutCommandLineInput(
                    '--all',
                    'Conflicting options: --all cannot be combined with --format-opt=violations=N. '
                    . 'Use either --all (show everything) or --format-opt=violations=N (explicit limit)',
                );
            }
            $options['violations'] = 'all';
        }

        // After --all, not before: the key this factory writes itself is held to
        // the same declaration as one the user typed, so a formatter dropping
        // `violations` cannot leave --all writing into a void.
        $this->refuseUnknownFormatOptionKeys($options);

        // Parse --namespace and --class (mutually exclusive)
        /** @var string|null $namespaceFilter */
        $namespaceFilter = $input->getOption('namespace');
        /** @var string|null $classFilter */
        $classFilter = $input->getOption('class');

        if ($namespaceFilter !== null && $classFilter !== null) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--namespace/--class',
                'Options --namespace and --class are mutually exclusive',
            );
        }

        $detectedWidth = (new \Symfony\Component\Console\Terminal())->getWidth();
        $terminalWidth = $detectedWidth !== 0 ? $detectedWidth : 80;
        $namespacePattern ??= $namespaceFilter !== null
            ? $this->selectorDecoder->decodeNamespace($namespaceFilter, '--namespace')
            : null;
        $detailLimit = $this->parseDetailOption($input, $namespaceFilter, $classFilter);
        $topIssuesLimit = $this->parseTopOption($input);

        // --all implies unlimited detail
        if ($allFlag) {
            $detailLimit = 0;
        }

        return new FormatterContext(
            useColor: $output->isDecorated(),
            groupBy: $groupBy,
            options: $options,
            basePath: $projectRoot->value(),
            scopedReporting: $scopedReporting,
            namespace: $namespacePattern,
            class: $classFilter,
            terminalWidth: $terminalWidth,
            detailLimit: $detailLimit,
            isGroupByExplicit: $isGroupByExplicit,
            topIssuesLimit: $topIssuesLimit,
        );
    }

    /**
     * Binds the report selector before an analysis starts.
     */
    public function namespacePattern(InputInterface $input): ?NamespacePattern
    {
        /** @var string|null $namespaceFilter */
        $namespaceFilter = $input->getOption('namespace');
        /** @var string|null $classFilter */
        $classFilter = $input->getOption('class');

        if ($namespaceFilter !== null && $classFilter !== null) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--namespace/--class',
                'Options --namespace and --class are mutually exclusive',
            );
        }

        return $namespaceFilter !== null
            ? $this->selectorDecoder->decodeNamespace($namespaceFilter, '--namespace')
            : null;
    }

    /**
     * Refuses keys no registered formatter reads.
     *
     * A key belonging to another formatter passes: scripts run one option set
     * through several formats, and only a key nobody reads is a miss.
     *
     * @param array<string, string> $options
     */
    private function refuseUnknownFormatOptionKeys(array $options): void
    {
        $known = $this->formatterRegistry->declaredFormatOptionKeys();
        $unknown = array_values(array_diff(array_keys($options), $known));

        if ($unknown === []) {
            return;
        }

        throw ConfigurationRefusal::aboutCommandLineInput(
            '--format-opt',
            \sprintf(
                'Unknown --format-opt %s %s. No formatter reads %s. Known keys: %s.',
                \count($unknown) === 1 ? 'key' : 'keys',
                implode(', ', array_map(static fn(string $key): string => \sprintf('"%s"', $key), $unknown)),
                \count($unknown) === 1 ? 'it' : 'them',
                $known !== [] ? implode(', ', $known) : 'none',
            ),
        );
    }

    /**
     * Parses --detail option into a detail limit.
     *
     * Returns: null = off, 0 = all, N = limit.
     * --detail (no value) = 200, --detail=all = 0, --detail=N = N.
     * --namespace/--class implicitly enables detail with default limit.
     */
    private function parseDetailOption(InputInterface $input, ?string $namespaceFilter, ?string $classFilter): ?int
    {
        $detailValue = $input->getOption('detail');

        // VALUE_OPTIONAL: false = not passed, null = passed without value, string = passed with value
        if ($detailValue === false) {
            // Not passed -- but namespace/class filters imply detail
            if ($namespaceFilter !== null || $classFilter !== null) {
                return self::DEFAULT_DETAIL_LIMIT;
            }

            return null;
        }

        if ($detailValue === null) {
            // --detail without value
            return self::DEFAULT_DETAIL_LIMIT;
        }

        /** @var string $detailValue */
        if ($detailValue === 'all' || $detailValue === '0') {
            return 0;
        }

        $parsed = filter_var($detailValue, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed !== false ? $parsed : self::DEFAULT_DETAIL_LIMIT;
    }

    /**
     * Parses --top option into a top issues limit.
     *
     * Returns default 10 when not set. Returns 0 to disable.
     */
    private function parseTopOption(InputInterface $input): int
    {
        /** @var string|null $topValue */
        $topValue = $input->getOption('top');

        if ($topValue === null) {
            return FormatterContext::DEFAULT_TOP_ISSUES_LIMIT;
        }

        $parsed = filter_var($topValue, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $parsed !== false ? $parsed : FormatterContext::DEFAULT_TOP_ISSUES_LIMIT;
    }
}
