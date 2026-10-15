<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Support\Rfc;

use LogicException;
use PhpParser\ParserFactory;
use Qualimetrix\Analysis\Evidence\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Analysis\Evidence\Coupling\Rfc\RfcCollector;
use Qualimetrix\Analysis\Evidence\Coupling\Rfc\RfcGlobalCollector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ExternalClassSpellingInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\DependencyGraphBuilder;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MeasurementAggregationService;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Run\Contract\Collection\SuccessfulFileProcessing;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use SplFileInfo;

/** Exercises real traversal/owner registration without native per-case processes. */
final class RfcMeasurement
{
    /** @param array<string, string> $files */
    public static function measure(array $files): InMemoryMetricRepository
    {
        $global = new RfcGlobalCollector();
        $rfc = new RfcCollector();
        $callables = new CyclomaticComplexityCollector();
        $dependency = new DependencyVisitor();
        $composite = new CompositeCollector([$rfc, $callables], new DeclarationRegistrarFactory(), dependencyTraversalParticipant: $dependency);
        $profiler = new class implements ProfilerInterface {
            public function start(string $name, ?string $category = null, array $attributes = []): void {}
            public function stop(string $name): void {}
        };
        $aggregation = new MeasurementAggregationService([$global], $composite, $profiler);
        $definitions = $aggregation->all();
        $repository = new InMemoryMetricRepository($definitions);
        $dependencies = $declarations = [];
        foreach ($files as $path => $code) {
            $file = RelativePath::fromString($path);
            $composite->reset();
            $ast = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
            $result = $composite->collect(new SplFileInfo($path), $ast, $file);
            $classes = [];
            foreach ($rfc->getClassesWithMetrics($file) as $class) {
                $classes[$class->subject->toCanonical()] = ['subject' => $class->subject, 'metrics' => $class->metrics, 'line' => $class->line, 'start' => $class->startFilePos];
            }
            $payload = new SuccessfulFileProcessing($result->metrics, $result->classLikeDeclarations, $callables->getCallablesWithMetrics($file), $classes, dependencies: $result->dependencies);
            $restored = unserialize(serialize($payload));
            \assert($restored instanceof SuccessfulFileProcessing);
            array_push($dependencies, ...$restored->dependencies);
            array_push($declarations, ...$restored->classLikeDeclarations);
            $part = new InMemoryMetricRepository($definitions);
            foreach ($restored->classMetrics as $class) {
                $part->addSubject($class['subject'], $class['metrics'], $file, $class['line']);
            }
            foreach ($restored->callableMetrics as $callable) {
                $part->addCallable($callable);
            }
            $repository = $repository->mergedWith($part) ?? throw new LogicException('In-memory repositories must merge');
        }
        $spelling = new class implements ExternalClassSpellingInterface {
            public function declaredSpelling(string $className): ?string
            {
                return null;
            }
        };
        $graph = (new DependencyGraphBuilder($spelling))->build($dependencies, $declarations)->graph;
        $aggregation->aggregate($repository, $graph);

        return $repository;
    }

    /** @return array<string, MetricBag> */
    public static function byName(InMemoryMetricRepository $repository): array
    {
        $result = [];
        foreach ($repository->allClassDeclarations() as $class) {
            \assert($class->subject !== null);
            $result[$class->symbolPath->toString()] = $repository->getSubject($class->subject);
        }

        return $result;
    }
}
