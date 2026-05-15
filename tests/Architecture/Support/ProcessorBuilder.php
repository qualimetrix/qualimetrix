<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Support;

use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\CoverageMode;
use Qualimetrix\Architecture\Domain\Layer\ClassContextFactory;
use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Architecture\Domain\Layer\LayerPolicy;
use Qualimetrix\Architecture\Domain\Layer\LayerRegistry;
use Qualimetrix\Architecture\Processing\ArchitectureProcessor;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Dependency\EmptyDependencyGraph;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Helper for unit tests that exercise rules consuming
 * {@see \Qualimetrix\Architecture\Processing\ArchitectureProcessorInterface}.
 *
 * Wires a concrete {@see ArchitectureProcessor} through the same lifecycle
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
        ?ArchitectureProcessor $processor = null,
    ): ArchitectureProcessor {
        $processor ??= new ArchitectureProcessor();
        $processor->reset();

        if ($configuration === null) {
            return $processor;
        }

        $processor->bind($configuration);
        $processor->prepare(
            $graph ?? new EmptyDependencyGraph(),
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
