<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Reporting\Formatter\FormatOptionValue;
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
        $explicitGroupBy = $this->explicitGroupBy($input);
        $isGroupByExplicit = $explicitGroupBy !== null;
        $groupBy = $explicitGroupBy ?? $formatter->getDefaultGroupBy();

        $options = $this->formatOptions($input);
        $allFlag = (bool) $input->getOption('all');

        // Parse --namespace and --class (mutually exclusive)
        [$namespaceFilter, $classFilter] = $this->drillDownFilters($input);

        $detectedWidth = (new \Symfony\Component\Console\Terminal())->getWidth();
        $terminalWidth = $detectedWidth !== 0 ? $detectedWidth : 80;
        $namespacePattern ??= $this->decodeNamespaceFilter($namespaceFilter);
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
     * Binds every presentation option that can be judged without the analysis,
     * and returns the bound namespace selector.
     *
     * {@see self::create()} runs only after the analysis, so a value refused
     * there costs a whole run first. Everything read here is independent of
     * the analysed code and of the output format a configuration file may
     * still select, so it is refused before the run starts; `create()` parses
     * the same values through the same methods and cannot disagree.
     */
    public function bindBeforeAnalysis(InputInterface $input): ?NamespacePattern
    {
        [$namespaceFilter, $classFilter] = $this->drillDownFilters($input);
        $this->explicitGroupBy($input);
        $this->formatOptions($input);
        $this->parseDetailOption($input, $namespaceFilter, $classFilter);
        $this->parseTopOption($input);

        return $this->decodeNamespaceFilter($namespaceFilter);
    }

    private function explicitGroupBy(InputInterface $input): ?GroupBy
    {
        /** @var string|null $groupByValue */
        $groupByValue = $input->getOption('group-by');
        if ($groupByValue === null) {
            return null;
        }

        try {
            return GroupBy::from($groupByValue);
        } catch (ValueError) {
            $valid = implode(', ', array_column(GroupBy::cases(), 'value'));
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--group-by',
                \sprintf('Invalid --group-by value "%s". Valid values: %s', $groupByValue, $valid),
            );
        }
    }

    /**
     * @return array{?string, ?string} `--namespace` and `--class`, which are mutually exclusive
     */
    private function drillDownFilters(InputInterface $input): array
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

        return [$namespaceFilter, $classFilter];
    }

    /**
     * `--format-opt` pairs after `--all` has written its own, each key known to
     * some formatter and each value parsing under that key's grammar.
     *
     * @return array<string, string>
     */
    private function formatOptions(InputInterface $input): array
    {
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
        if ((bool) $input->getOption('all')) {
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

        foreach ($options as $key => $value) {
            $expected = FormatOptionValue::problem($key, $value);
            if ($expected !== null) {
                throw ConfigurationRefusal::aboutCommandLineInput(
                    '--format-opt',
                    \sprintf('Invalid --format-opt value "%s=%s": expected %s.', $key, $value, $expected),
                );
            }
        }

        return $options;
    }

    private function decodeNamespaceFilter(?string $namespaceFilter): ?NamespacePattern
    {
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
     * --detail (no value) = 200, --detail=all = 0, --detail=N = N; anything else is refused.
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

        // `true` is the same flag written through an array input, which
        // spells a value-less option that way rather than as null.
        if ($detailValue === null || $detailValue === true) {
            return self::DEFAULT_DETAIL_LIMIT;
        }

        /** @var string $detailValue */
        if ($detailValue === 'all') {
            return 0;
        }

        return self::wholeNumber($detailValue) ?? throw ConfigurationRefusal::aboutCommandLineInput(
            '--detail',
            \sprintf('Invalid --detail value "%s". Expected a whole number (0 for no cap) or "all".', $detailValue),
        );
    }

    /**
     * Parses --top option into a top issues limit.
     *
     * Returns default 10 when not set. Returns 0 to disable. Anything else is refused.
     */
    private function parseTopOption(InputInterface $input): int
    {
        /** @var string|null $topValue */
        $topValue = $input->getOption('top');

        if ($topValue === null) {
            return FormatterContext::DEFAULT_TOP_ISSUES_LIMIT;
        }

        return self::wholeNumber($topValue) ?? throw ConfigurationRefusal::aboutCommandLineInput(
            '--top',
            \sprintf('Invalid --top value "%s". Expected a whole number (0 hides the section).', $topValue),
        );
    }

    private static function wholeNumber(string $raw): ?int
    {
        if (preg_match('/^(0|[1-9][0-9]*)$/', $raw) !== 1) {
            return null;
        }

        $value = filter_var($raw, \FILTER_VALIDATE_INT);

        return $value === false ? null : $value;
    }
}
