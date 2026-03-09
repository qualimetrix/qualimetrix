<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Aggregator;

use AiMessDetector\Analysis\Aggregator\AggregationHelper;
use AiMessDetector\Analysis\Aggregator\ClassToNamespaceAggregator;
use AiMessDetector\Analysis\Aggregator\MetricAggregator;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Metrics\Size\LocCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassToNamespaceAggregator::class)]
final class ClassToNamespaceAggregatorTest extends TestCase
{
    #[Test]
    public function itAggregatesProceduralFileLocToNamespace(): void
    {
        $repository = new InMemoryMetricRepository();

        // Add a global function in namespace App\Utils (no class in the file)
        $functionPath = SymbolPath::forGlobalFunction('App\\Utils', 'helper');
        $functionMetrics = (new MetricBag())->with('ccn', 2);
        $repository->add($functionPath, $functionMetrics, 'src/Utils/helpers.php', 10);

        // Add file-level LOC metrics for the same file
        $fileMetrics = (new MetricBag())
            ->with('loc', 50)
            ->with('lloc', 40)
            ->with('cloc', 5);
        $repository->add(
            SymbolPath::forFile('src/Utils/helpers.php'),
            $fileMetrics,
            'src/Utils/helpers.php',
            1,
        );

        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([
            new LocCollector(),
        ]));
        $aggregator->aggregate($repository);

        // Namespace-level LOC should include the procedural file's LOC
        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Utils'));

        self::assertSame(50, (int) $nsMetrics->get('loc.sum'));
        self::assertSame(40, (int) $nsMetrics->get('lloc.sum'));
        self::assertSame(5, (int) $nsMetrics->get('cloc.sum'));
    }

    #[Test]
    public function itAggregatesMixedClassAndFunctionFileLocToNamespace(): void
    {
        $repository = new InMemoryMetricRepository();

        // File with a class
        $repository->add(
            SymbolPath::forClass('App\\Service', 'UserService'),
            new MetricBag(),
            'src/Service/UserService.php',
            5,
        );
        $repository->add(
            SymbolPath::forFile('src/Service/UserService.php'),
            (new MetricBag())->with('loc', 100),
            'src/Service/UserService.php',
            1,
        );

        // File with only functions (no class)
        $repository->add(
            SymbolPath::forGlobalFunction('App\\Service', 'serviceHelper'),
            new MetricBag(),
            'src/Service/helpers.php',
            3,
        );
        $repository->add(
            SymbolPath::forFile('src/Service/helpers.php'),
            (new MetricBag())->with('loc', 30),
            'src/Service/helpers.php',
            1,
        );

        $aggregator = new MetricAggregator(AggregationHelper::collectDefinitions([
            new LocCollector(),
        ]));
        $aggregator->aggregate($repository);

        // Namespace LOC should include both files
        $nsMetrics = $repository->get(SymbolPath::forNamespace('App\\Service'));

        self::assertSame(130, (int) $nsMetrics->get('loc.sum')); // 100 + 30
    }
}
