<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command\Debug;

use Exception;
use Qualimetrix\Analysis\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraphBuilder;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Repository\MetricRepositoryFactoryInterface;
use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Architecture\Domain\Layer\LayerMatch;
use Qualimetrix\Architecture\Domain\Layer\MatchedCriterion;
use Qualimetrix\Architecture\Processing\ArchitectureProcessorInterface;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Per-class introspection of layer assignment.
 *
 * Reports which layer the supplied class would be assigned to under the
 * project's architecture configuration, and which other layers' patterns
 * would have matched the class as well. Useful for understanding silent
 * shadowing — when a class falls into one layer because an earlier layer's
 * pattern happened to match it, even though a later, more specific layer
 * looks like a better fit.
 *
 * The command runs the full Discovery + Collection phases so the per-class
 * answer matches {@code qmx check} byte-for-byte under both template-layer
 * and graph-criteria configurations (ADR 0008). Resolution itself is
 * performed by the shared {@see ArchitectureProcessorInterface} so the
 * matching algorithm has a single source of truth.
 *
 * Exits 0 for any informational result (including "no layer matches"), 2
 * (`Command::INVALID`) for malformed input, and 1 (`Command::FAILURE`) for
 * configuration-load errors.
 */
#[AsCommand(
    name: 'debug:layer-assignment',
    description: 'Show which architecture layer a class would be assigned to',
)]
final class LayerAssignmentCommand extends Command
{
    public function __construct(
        private readonly ConfigurationPipeline $configurationPipeline,
        private readonly FileDiscoveryInterface $fileDiscovery,
        private readonly CollectionOrchestratorInterface $collectionOrchestrator,
        private readonly DependencyGraphBuilder $graphBuilder,
        private readonly ArchitectureProcessorInterface $processor,
        private readonly MetricRepositoryFactoryInterface $repositoryFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'fqn',
                InputArgument::REQUIRED,
                'Fully qualified class name to inspect (e.g. App\\Service\\Foo)',
            )
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to qmx.yaml (defaults to qmx.yaml in the current working directory)',
            )
            ->setHelp(
                'Reports the layer the given class is assigned to under the project'
                . "\n" . 'architecture configuration, plus every other layer whose criteria'
                . "\n" . 'would have matched the class (would have been the assignment if'
                . "\n" . 'declared earlier).' . "\n\n"
                . 'Layer evaluation follows declaration order: the first layer whose'
                . "\n" . 'criteria match wins. Reorder layers in qmx.yaml or tighten broad'
                . "\n" . 'patterns to resolve unwanted shadowing.' . "\n\n"
                . 'The command runs full Discovery + Collection internally so the answer'
                . "\n" . 'matches `qmx check` byte-for-byte for template-layer and'
                . "\n" . 'graph-based configurations. Expect roughly 50–70% of `qmx check`'
                . "\n" . 'runtime.' . "\n\n"
                . 'Examples:' . "\n"
                . '  <info>bin/qmx debug:layer-assignment \'App\\Service\\Foo\'</info>' . "\n"
                . '  <info>bin/qmx debug:layer-assignment \'App\\Service\\Foo\' --config qmx.yaml</info>',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $rawFqn */
        $rawFqn = $input->getArgument('fqn');

        $validationError = $this->validateFqn($rawFqn);
        if ($validationError !== null) {
            $output->writeln(\sprintf('<error>%s</error>', $validationError));

            return self::INVALID;
        }

        $symbol = SymbolPath::fromClassFqn($rawFqn);
        $normalized = $this->fqnFor($symbol);

        try {
            $matches = $this->resolveLayerMatches($input, $symbol);
        } catch (ConfigLoadException $e) {
            $output->writeln(\sprintf('<error>Configuration error: %s</error>', $e->getMessage()));

            return self::FAILURE;
        } catch (Exception $e) {
            // Catches recoverable failures while bubbling up Errors (TypeError, etc.)
            // so genuine programming bugs in the pipeline surface in CI rather than
            // being silently reported as exit code 1.
            $output->writeln(\sprintf('<error>Failed to load configuration: %s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $this->renderReport($output, $normalized, $matches);

        return self::SUCCESS;
    }

    /**
     * Runs the configuration pipeline, file discovery, the collection phase,
     * and template expansion through the processor — then asks the processor
     * to classify the requested class.
     *
     * @return list<LayerMatch>
     */
    private function resolveLayerMatches(InputInterface $input, SymbolPath $symbol): array
    {
        $resolved = $this->loadResolvedConfiguration($input);

        $repository = $this->repositoryFactory->create();
        $files = array_values(iterator_to_array(
            $this->fileDiscovery->discover($resolved->paths->paths),
            false,
        ));

        $collection = $this->collectionOrchestrator->collect($files, $repository);
        $graph = $this->graphBuilder->build($collection->dependencies);

        $classPaths = [];
        foreach ($repository->all(SymbolType::Class_) as $classSymbol) {
            $classPaths[] = $classSymbol->symbolPath;
        }
        $classSet = new ClassSet($classPaths, new \Qualimetrix\Architecture\Domain\Layer\ClassContextFactory());

        $this->processor->reset();
        $this->processor->bind($resolved->architecture);
        $this->processor->prepare($graph, $classSet);

        // classify() yields the head match per class; the debug command
        // wants the full match list (assignment + shadowed alternatives),
        // so we reach into the prepared registry directly. The processor's
        // prepare() already bound the graph and expanded templates, so the
        // matching algorithm is identical to what the rule layer sees.
        $prepared = $this->processor->getPreparedConfiguration();
        \assert($prepared !== null);

        return $prepared->registry()->resolveAll($symbol);
    }

    /**
     * Loads the resolved configuration via the configuration pipeline.
     */
    private function loadResolvedConfiguration(InputInterface $input): \Qualimetrix\Configuration\Pipeline\ResolvedConfiguration
    {
        /** @var string|null $configPath */
        $configPath = $input->getOption('config');
        $cwd = getcwd();
        $workingDirectory = $cwd !== false ? $cwd : '.';

        if ($configPath !== null && $configPath !== '' && !file_exists($configPath)) {
            throw ConfigLoadException::fileNotFound($configPath);
        }

        $context = new ConfigurationContext(
            $input,
            $workingDirectory,
            \is_string($configPath) && $configPath !== '' ? $configPath : null,
        );

        return $this->configurationPipeline->resolve($context);
    }

    /**
     * Validates the raw FQN argument. Returns null on success, error message on failure.
     */
    private function validateFqn(string $rawFqn): ?string
    {
        if (trim($rawFqn) === '') {
            return 'Class FQN must not be empty.';
        }

        if (preg_match('/\s/', $rawFqn) === 1) {
            return \sprintf('Class FQN "%s" must not contain whitespace.', $rawFqn);
        }

        // Strip leading backslash before validating identifier characters so
        // that `\App\Foo` is treated like `App\Foo`.
        $normalized = ltrim($rawFqn, '\\');
        if ($normalized === '') {
            return 'Class FQN must contain at least one identifier segment.';
        }

        // PHP identifier segments are [A-Za-z_][A-Za-z0-9_]* joined by `\`.
        // Reject anything outside that grammar (e.g. dashes, dots, slashes).
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $normalized) !== 1) {
            return \sprintf(
                'Class FQN "%s" is not a valid PHP fully qualified class name.',
                $rawFqn,
            );
        }

        return null;
    }

    /**
     * Reconstructs the canonical FQN form (`Namespace\Type` or bare `Type`) from
     * a class-level SymbolPath. {@see SymbolPath::fromClassFqn()} normalises any
     * leading backslash and splits on the last separator, so the FQN we build
     * here is the canonical form to match against layer patterns.
     */
    private function fqnFor(SymbolPath $symbol): string
    {
        $namespace = $symbol->namespace;
        $type = $symbol->type ?? '';

        if ($namespace === null || $namespace === '') {
            return $type;
        }

        return $namespace . '\\' . $type;
    }

    /**
     * @param list<LayerMatch> $matches
     */
    private function renderReport(
        OutputInterface $output,
        string $fqn,
        array $matches,
    ): void {
        $output->writeln(\sprintf('Class: <info>%s</info>', $fqn));
        $output->writeln('');

        if ($matches === []) {
            $output->writeln('  Assigned to: <comment>(no layer)</comment>');
            $output->writeln('');
            $registry = $this->processor->getPreparedConfiguration()?->registry();
            if ($registry === null || $registry->isEmpty()) {
                $output->writeln('  Suggestion: no layers are declared in the configuration. Add an');
                $output->writeln('  <comment>architecture.layers</comment> section to qmx.yaml to start enforcing');
                $output->writeln('  layer boundaries.');
            } else {
                $output->writeln('  Suggestion: declare a catch-all layer with pattern <comment>\'**\'</comment> at the');
                $output->writeln('  end of the layers list to capture unclassified classes.');
            }

            return;
        }

        $assigned = $matches[0];
        $output->writeln(\sprintf('  Assigned to: <info>%s</info>', $assigned->layerName));
        $output->writeln(\sprintf('    Matched by: <comment>%s</comment>', self::describeCriteria($assigned)));
        $output->writeln('');

        $shadowed = \array_slice($matches, 1);

        $output->writeln('  Would also match (in declaration order):');
        if ($shadowed === []) {
            $output->writeln('    <comment>(none — the assignment is unique)</comment>');

            return;
        }

        $maxLayerNameWidth = max(array_map(
            static fn(LayerMatch $entry): int => \strlen($entry->layerName),
            $shadowed,
        ));

        foreach ($shadowed as $entry) {
            $output->writeln(\sprintf(
                "    - %-{$maxLayerNameWidth}s (matched by: '<comment>%s</comment>')",
                $entry->layerName,
                self::describeCriteria($entry),
            ));
        }

        $output->writeln('');
        $output->writeln('  Diagnostic hint:');
        $firstShadowed = $shadowed[0]->layerName;
        $output->writeln(\sprintf(
            "    Class is shadowed: would have matched '<info>%s</info>' if '<info>%s</info>' was declared later.",
            $firstShadowed,
            $assigned->layerName,
        ));
        $output->writeln('    See <comment>architecture.potential-shadow</comment> diagnostic for the broader picture.');
    }

    /**
     * Joins every matched criterion descriptor with a comma so the command
     * line surface mirrors the order that
     * {@see \Qualimetrix\Architecture\Domain\Layer\LayerDefinition::matches()}
     * scans (pattern → suffix → attribute → implements → extends).
     */
    private static function describeCriteria(LayerMatch $entry): string
    {
        return implode(', ', array_map(
            static fn(MatchedCriterion $criterion): string => $criterion->describe(),
            $entry->matchedCriteria,
        ));
    }
}
