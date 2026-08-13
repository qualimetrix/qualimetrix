<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Aggregation;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileMeasurementCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Profiler\ProfilerInterface;

/** Owns initial aggregation, global collection, and global re-aggregation. */
final class MeasurementAggregationService implements MeasurementAggregationInterface
{
    /** @var list<GlobalContextCollectorInterface> */
    private readonly array $sortedCollectors;

    /** @var list<MetricDefinition> */
    private readonly array $allDefinitions;

    /** @var list<MetricDefinition> */
    private readonly array $globalDefinitions;

    /** @param iterable<GlobalContextCollectorInterface> $collectors */
    public function __construct(
        iterable $collectors,
        FileMeasurementCollectorInterface $fileCollector,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?ProfilerHolder $profilerHolder = null,
    ) {
        $this->sortedCollectors = (new GlobalCollectorSorter())->sort($collectors);
        $regularDefinitions = AggregationHelper::collectDefinitions($fileCollector->getCollectors());
        $derivedDefinitions = self::definitions($fileCollector->getDerivedCollectors());
        $this->globalDefinitions = self::definitions($this->sortedCollectors);
        $this->allDefinitions = [...$regularDefinitions, ...$derivedDefinitions, ...$this->globalDefinitions];
    }

    public function aggregate(MetricRepositoryInterface $repository, DependencyGraphInterface $dependencies): NamespaceTree
    {
        $profiler = $this->profilerHolder === null ? null : ProfilerHolder::get();
        $phaseStartTime = microtime(true);
        $this->logger->debug('Starting aggregation phase');
        self::start($profiler, 'aggregation');
        $namespaceTree = (new MetricAggregator($this->allDefinitions))->aggregate($repository);
        self::stop($profiler, 'aggregation');
        $this->logger->info('Aggregation completed', [
            'duration' => \sprintf('%.2fs', microtime(true) - $phaseStartTime),
        ]);

        if ($this->sortedCollectors !== []) {
            $this->logger->debug('Running global collectors', ['count' => \count($this->sortedCollectors)]);
        }
        self::start($profiler, 'global');
        foreach ($this->sortedCollectors as $collector) {
            $collector->calculate($dependencies, $repository);
        }
        self::stop($profiler, 'global');

        if ($this->globalDefinitions !== []) {
            self::start($profiler, 'aggregation.global');
            (new MetricAggregator($this->globalDefinitions))->aggregate($repository, $namespaceTree);
            self::stop($profiler, 'aggregation.global');
        }

        return $namespaceTree;
    }

    /**
     * @param iterable<\Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface|GlobalContextCollectorInterface> $collectors
     *
     * @return list<MetricDefinition>
     */
    private static function definitions(iterable $collectors): array
    {
        $definitions = [];
        foreach ($collectors as $collector) {
            array_push($definitions, ...$collector->getMetricDefinitions());
        }

        return $definitions;
    }

    private static function start(?ProfilerInterface $profiler, string $name): void
    {
        $profiler?->start($name, 'pipeline');
    }

    private static function stop(?ProfilerInterface $profiler, string $name): void
    {
        $profiler?->stop($name);
    }
}
