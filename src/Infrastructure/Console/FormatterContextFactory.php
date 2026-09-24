<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Reporting\DrillDown\OutOfScopeFindings;
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

    private readonly FormatOptionPairs $formatOptionPairs;

    public function __construct(
        FormatterRegistryInterface $formatterRegistry,
        private readonly CliSelectorDecoder $selectorDecoder = new CliSelectorDecoder(),
    ) {
        $this->formatOptionPairs = new FormatOptionPairs($formatterRegistry);
    }

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

        // Parse --namespace and --class (mutually exclusive)
        [$namespaceFilter, $classFilter] = $this->drillDownFilters($input);
        $this->refuseSelectionUnder($formatter->getName(), $namespaceFilter, $classFilter);

        $detectedWidth = (new \Symfony\Component\Console\Terminal())->getWidth();
        $terminalWidth = $detectedWidth !== 0 ? $detectedWidth : 80;
        $namespacePattern ??= $this->decodeNamespaceFilter($namespaceFilter);
        $detailLimit = $this->detailLimit($input, $namespaceFilter, $classFilter);
        $topIssuesLimit = $this->parseTopOption($input);

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
        $this->detailLimit($input, $namespaceFilter, $classFilter);
        $this->parseTopOption($input);

        return $this->decodeNamespaceFilter($namespaceFilter);
    }

    /**
     * Refuses, before the analysis runs, a `--namespace` or `--class`
     * selection under a format with no place to say the report is a partial
     * view ({@see OutOfScopeFindings::FORMATS_WITHOUT_A_PLACE}).
     */
    public function bindFormatBeforeAnalysis(InputInterface $input, string $format): void
    {
        [$namespaceFilter, $classFilter] = $this->drillDownFilters($input);
        $this->refuseSelectionUnder($format, $namespaceFilter, $classFilter);
    }

    private function refuseSelectionUnder(string $format, ?string $namespaceFilter, ?string $classFilter): void
    {
        $selector = $namespaceFilter !== null ? '--namespace' : ($classFilter !== null ? '--class' : null);
        if ($selector === null || !\in_array($format, OutOfScopeFindings::FORMATS_WITHOUT_A_PLACE, true)) {
            return;
        }

        throw ConfigurationRefusal::aboutCommandLineInput($selector, \sprintf(
            'Format "%s" has no place to say the report is a partial view: its consumer reads every entry as a finding. '
            . 'Drop %s, or use a format that says what the selection left out, such as json, sarif or github.',
            $format,
            $selector,
        ));
    }

    private function explicitGroupBy(InputInterface $input): ?GroupBy
    {
        $groupByValue = CommandLineSpelling::option($input, 'group-by');
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
        $namespaceFilter = CommandLineSpelling::option($input, 'namespace');
        $classFilter = CommandLineSpelling::option($input, 'class');

        if ($namespaceFilter !== null && $classFilter !== null) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--namespace/--class',
                'Options --namespace and --class are mutually exclusive',
            );
        }

        return [$namespaceFilter, $classFilter];
    }

    /** @return array<string, string> */
    private function formatOptions(InputInterface $input): array
    {
        $written = CommandLineSpelling::options($input, 'format-opt');

        return (bool) $input->getOption('all')
            ? $this->formatOptionPairs->resolveUnderAllFlag($written)
            : $this->formatOptionPairs->resolve($written);
    }

    private function decodeNamespaceFilter(?string $namespaceFilter): ?NamespacePattern
    {
        return $namespaceFilter !== null
            ? $this->selectorDecoder->decodeNamespace($namespaceFilter, '--namespace')
            : null;
    }

    /**
     * The detail limit, after `--all` has written its own: `--all` is an alias
     * for `--detail=all`, so a cap written beside it — `--detail` alone is a
     * cap of 200 — is refused rather than silently lifted, the way
     * {@see FormatOptionPairs::resolveUnderAllFlag()} refuses `violations=N`.
     */
    private function detailLimit(InputInterface $input, ?string $namespaceFilter, ?string $classFilter): ?int
    {
        $detailLimit = $this->parseDetailOption($input, $namespaceFilter, $classFilter);
        if (!(bool) $input->getOption('all')) {
            return $detailLimit;
        }

        if ($input->getOption('detail') !== false && $detailLimit !== 0) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--all',
                'Conflicting options: --all cannot be combined with --detail or --detail=N, which cap the list --all shows whole. '
                . 'Use either --all (show everything) or --detail[=N] (a capped list).',
            );
        }

        return 0;
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

        $detailValue = CommandLineSpelling::of($detailValue, '--detail');
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
        $topValue = CommandLineSpelling::option($input, 'top');

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
