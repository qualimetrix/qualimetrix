<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Support;

use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContextFactory;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassSet;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerPolicy;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;

/**
 * Helper for unit tests that exercise rules consuming
 * {@see \Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy}.
 *
 * Wires a concrete {@see ArchitecturePolicy} through the same lifecycle
 * the pipeline uses in production: {@code bind} → {@code prepare}. The
 * graph and metric repository are optional — pass them when the rule needs
 * them, otherwise empty stand-ins are used.
 */
final class ProcessorBuilder
{
    /**
     * Returns a processor in the {@code prepared} state with the supplied
     * configuration bound. Pass {@code null} to leave the processor in the
     * empty state (matching the production "no architecture: section" case
     * where ConfigurationPipeline still hands an empty configuration to the
     * processor).
     */
    public static function prepared(
        ?ArchitectureConfiguration $configuration,
        ?DependencyGraphInterface $graph = null,
        ?MetricRepositoryInterface $repository = null,
        ?ArchitecturePolicy $processor = null,
    ): ArchitecturePolicy {
        $processor ??= new ArchitecturePolicy();
        $processor->reset();

        if ($configuration === null) {
            return $processor;
        }

        $processor->bind($configuration);
        $processor->prepare(
            $graph ?? AdjacencyGraphBuilder::empty(),
            self::classSetFromRepository($repository),
        );

        return $processor;
    }

    public static function empty(): ArchitectureConfiguration
    {
        return new ArchitectureConfiguration(
            new LayerRegistry([]),
            new LayerPolicy([]),
            CoverageMode::Ignore,
        );
    }

    private static function classSetFromRepository(?MetricRepositoryInterface $repository): ClassSet
    {
        if ($repository === null) {
            return new ClassSet([], new ClassContextFactory());
        }

        /** @var list<SymbolPath> $paths */
        $paths = [];
        foreach ($repository->all(SymbolType::Class_) as $symbol) {
            $paths[] = $symbol->symbolPath;
        }

        return new ClassSet($paths, new ClassContextFactory());
    }

    /**
     * Shared lightweight repository factory for callers that just need a
     * fresh InMemoryMetricRepository instance.
     */
    public static function emptyRepository(): InMemoryMetricRepository
    {
        return new InMemoryMetricRepository();
    }
}
