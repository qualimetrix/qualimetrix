<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
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

    public function __construct(
        private readonly ConfigurationProviderInterface $configurationProvider,
    ) {}

    public function create(
        InputInterface $input,
        OutputInterface $output,
        FormatterInterface $formatter,
        bool $scopedReporting = false,
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
            throw new InvalidArgumentException(\sprintf(
                'Invalid --group-by value "%s". Valid values: %s',
                $groupByValue,
                $valid,
            ));
        }

        // Parse --format-opt key=value pairs
        /** @var list<string> $formatOpts */
        $formatOpts = $input->getOption('format-opt');
        $options = [];
        foreach ($formatOpts as $opt) {
            $eqPos = strpos($opt, '=');
            if ($eqPos === false) {
                throw new InvalidArgumentException(\sprintf(
                    'Invalid --format-opt value "%s": expected format key=value',
                    $opt,
                ));
            }
            $options[substr($opt, 0, $eqPos)] = substr($opt, $eqPos + 1);
        }

        // Handle --all flag: alias for --format-opt=violations=all --detail=all
        $allFlag = (bool) $input->getOption('all');
        if ($allFlag) {
            $existingViolations = $options['violations'] ?? '';
            // Conflict: --all with explicit numeric violations limit
            if ($existingViolations !== '' && $existingViolations !== 'all') {
                throw new InvalidArgumentException(
                    'Conflicting options: --all cannot be combined with --format-opt=violations=N. '
                    . 'Use either --all (show everything) or --format-opt=violations=N (explicit limit)',
                );
            }
            $options['violations'] = 'all';
        }

        // Parse --namespace and --class (mutually exclusive)
        /** @var string|null $namespaceFilter */
        $namespaceFilter = $input->getOption('namespace');
        /** @var string|null $classFilter */
        $classFilter = $input->getOption('class');

        if ($namespaceFilter !== null && $classFilter !== null) {
            throw new InvalidArgumentException('Options --namespace and --class are mutually exclusive');
        }

        $terminalWidth = (new \Symfony\Component\Console\Terminal())->getWidth() ?: 80;
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
            basePath: $this->configurationProvider->getConfiguration()->projectRoot,
            scopedReporting: $scopedReporting,
            namespace: $namespaceFilter,
            class: $classFilter,
            terminalWidth: $terminalWidth,
            detailLimit: $detailLimit,
            isGroupByExplicit: $isGroupByExplicit,
            topIssuesLimit: $topIssuesLimit,
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
