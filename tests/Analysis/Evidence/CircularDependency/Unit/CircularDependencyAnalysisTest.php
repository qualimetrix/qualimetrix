<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyAnalysis;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyDetector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;

#[CoversClass(CircularDependencyAnalysis::class)]
final class CircularDependencyAnalysisTest extends TestCase
{
    #[Test]
    public function itResetsPreparedCyclesWithoutTraversingTheGraph(): void
    {
        $detector = new class extends CircularDependencyDetector {
            public int $detectCalls = 0;

            public function detect(DependencyGraphInterface $graph): array
            {
                $this->detectCalls++;

                return parent::detect($graph);
            }
        };
        $analysis = new CircularDependencyAnalysis($detector);

        $analysis->prepare(AdjacencyGraphBuilder::build([
            'App\\A' => ['App\\B'],
            'App\\B' => ['App\\A'],
        ]));
        self::assertCount(1, $analysis->all());
        self::assertSame(1, $detector->detectCalls);

        $analysis->reset();

        self::assertSame([], $analysis->all());
        self::assertSame(1, $detector->detectCalls);
    }

    #[Test]
    public function itReplacesResultsAcrossSequentialPrepares(): void
    {
        $analysis = new CircularDependencyAnalysis(new CircularDependencyDetector());

        $analysis->prepare(AdjacencyGraphBuilder::build([
            'App\\A' => ['App\\B'],
            'App\\B' => ['App\\A'],
        ]));
        self::assertCount(1, $analysis->all());

        $analysis->prepare(AdjacencyGraphBuilder::build([
            'App\\C' => ['App\\D'],
            'App\\D' => [],
        ]));

        self::assertSame([], $analysis->all());
    }
}
