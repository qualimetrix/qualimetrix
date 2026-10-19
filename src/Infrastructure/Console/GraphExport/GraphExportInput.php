<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\GraphExport;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalysisResult;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\Invocation\CliSelectorDecoder;
use Qualimetrix\Infrastructure\Console\Invocation\CommandLineSpelling;
use Qualimetrix\Reporting\GraphProjection\Contract\DependencyGraphProjectionInterface;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphDirection;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphExportFormat;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphProjectionRequest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/** Defines graph-export input and judges its projection selectors. */
final readonly class GraphExportInput
{
    public function __construct(private readonly CliSelectorDecoder $selectorDecoder = new CliSelectorDecoder()) {}

    public function configure(Command $command): void
    {
        $command
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to configuration file')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Apply a named preset or preset file', [])
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Directories to exclude', [])
            ->addOption('include-generated', null, InputOption::VALUE_NONE, 'Include generated files')
            ->addOption('include-autoload-dev', null, InputOption::VALUE_NONE, 'Include composer autoload-dev files')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Disable caching')
            ->addOption('no-progress', null, InputOption::VALUE_NONE, 'Disable progress bar')
            ->addOption('workers', 'w', InputOption::VALUE_REQUIRED, 'Number of parallel workers')
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'PHP memory limit')
            ->addArgument(
                'paths',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Paths to analyze',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file (default: stdout)',
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                \sprintf(
                    'Output format (%s)',
                    implode(', ', array_map(static fn(GraphExportFormat $format): string => $format->value, GraphExportFormat::cases())),
                ),
                GraphExportFormat::Dot->value,
            )
            ->addOption(
                'direction',
                null,
                InputOption::VALUE_REQUIRED,
                \sprintf(
                    'Graph direction (%s)',
                    implode(', ', array_map(static fn(GraphDirection $direction): string => $direction->value, GraphDirection::cases())),
                ),
                GraphDirection::LR->value,
            )
            ->addOption(
                'no-clusters',
                null,
                InputOption::VALUE_NONE,
                'Do not group by namespace',
            )
            ->addOption(
                'namespace',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Include only these namespaces (a value matching no analyzed class is refused with exit code 3)',
            )
            ->addOption(
                'exclude-namespace',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude these namespaces (a value matching no analyzed class is refused with exit code 3)',
            )
            ->setHelp(\sprintf('Docs: %s', ProductIdentity::llmsTxtUrl()));
    }

    public static function format(InputInterface $input): GraphExportFormat
    {
        return self::resolveFormat(CommandLineSpelling::option($input, 'format') ?? '');
    }

    public static function direction(InputInterface $input): GraphDirection
    {
        return self::resolveDirection(CommandLineSpelling::option($input, 'direction') ?? '');
    }

    /** @throws ConfigurationRefusal */
    private static function resolveFormat(string $rawFormat): GraphExportFormat
    {
        $format = GraphExportFormat::tryFrom($rawFormat);
        if ($format === null) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--format',
                \sprintf(
                    'Unknown format "%s". Supported formats: %s.',
                    $rawFormat,
                    implode(', ', array_map(static fn(GraphExportFormat $f): string => $f->value, GraphExportFormat::cases())),
                ),
            );
        }

        return $format;
    }

    /** @throws ConfigurationRefusal */
    private static function resolveDirection(string $rawDirection): GraphDirection
    {
        $direction = GraphDirection::tryFrom($rawDirection);
        if ($direction === null) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--direction',
                \sprintf(
                    'Unknown direction "%s". Supported directions: %s.',
                    $rawDirection,
                    implode(', ', array_map(static fn(GraphDirection $d): string => $d->value, GraphDirection::cases())),
                ),
            );
        }

        return $direction;
    }

    public function projectionRequest(InputInterface $input, GraphExportFormat $format, GraphDirection $direction): GraphProjectionRequest
    {
        $includeNamespaces = CommandLineSpelling::options($input, 'namespace');
        $excludeNamespaces = CommandLineSpelling::options($input, 'exclude-namespace');

        return new GraphProjectionRequest(
            format: $format,
            direction: $direction,
            groupByNamespace: $input->getOption('no-clusters') !== true,
            includeNamespaces: $includeNamespaces !== [] ? array_values(array_map(
                fn(string $value): NamespacePattern => $this->selectorDecoder->decodeNamespace($value, '--namespace'),
                $includeNamespaces,
            )) : null,
            excludeNamespaces: array_values(array_map(
                fn(string $value): NamespacePattern => $this->selectorDecoder->decodeNamespace($value, '--exclude-namespace'),
                $excludeNamespaces,
            )),
        );
    }

    /** @throws ConfigurationRefusal */
    public function assertFiltersBind(DependencyGraphProjectionInterface $projection, DependencyGraphAnalysisResult $result, GraphProjectionRequest $request): void
    {
        $unbound = $projection->unboundIncludeNamespaces($result->graph, $request);
        if ($unbound !== []) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--namespace',
                \sprintf(
                    'No analyzed class belongs to %s: %s.',
                    \count($unbound) === 1 ? 'namespace' : 'namespaces',
                    implode(', ', array_map(static fn(string $namespace): string => '"' . $namespace . '"', $unbound)),
                ),
            );
        }

        $unboundExcludes = $projection->unboundExcludeNamespaces($result->graph, $request);
        if ($unboundExcludes === []) {
            return;
        }

        $written = implode(', ', array_map(
            static fn(array $entry): string => '"' . $entry['selector'] . '"',
            $unboundExcludes,
        ));
        $suggestions = array_values(array_filter(array_map(
            static fn(array $entry): ?string => $entry['suggestion'] === null
                ? null
                : \sprintf('For "%s", did you mean "%s"?', $entry['selector'], $entry['suggestion']),
            $unboundExcludes,
        )));

        throw ConfigurationRefusal::aboutCommandLineInput(
            '--exclude-namespace',
            \sprintf(
                'No analyzed class belongs to excluded %s: %s.%s',
                \count($unboundExcludes) === 1 ? 'namespace' : 'namespaces',
                $written,
                $suggestions === [] ? '' : ' ' . implode(' ', $suggestions),
            ),
        );
    }
}
