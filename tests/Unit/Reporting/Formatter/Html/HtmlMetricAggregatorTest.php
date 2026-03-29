<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\Formatter\Html\HtmlMetricAggregator;
use Qualimetrix\Reporting\Formatter\Html\HtmlTreeNode;

#[CoversClass(HtmlMetricAggregator::class)]
final class HtmlMetricAggregatorTest extends TestCase
{
    private HtmlMetricAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new HtmlMetricAggregator();
    }

    public function testLeafNodeUnchanged(): void
    {
        $leaf = new HtmlTreeNode('Service', 'App\\Service', 'class');
        $leaf->metrics = ['loc.sum' => 100, 'health.overall' => 85.0];

        $this->aggregator->aggregateBottomUp($leaf);

        self::assertSame(100, $leaf->metrics['loc.sum']);
        self::assertSame(85.0, $leaf->metrics['health.overall']);
    }

    public function testEmptyMetricsNoError(): void
    {
        $root = new HtmlTreeNode('project', '<project>', 'project');
        $child = new HtmlTreeNode('App', 'App', 'namespace');
        $child->metrics = [];
        $root->children = [$child];

        $this->aggregator->aggregateBottomUp($root);

        self::assertArrayNotHasKey('loc.sum', $root->metrics);
        self::assertArrayNotHasKey('health.overall', $root->metrics);
    }

    public function testLocSumAggregatedFromChildren(): void
    {
        $root = new HtmlTreeNode('project', '<project>', 'project');

        $childA = new HtmlTreeNode('A', 'App\\A', 'class');
        $childA->metrics = ['loc.sum' => 100];

        $childB = new HtmlTreeNode('B', 'App\\B', 'class');
        $childB->metrics = ['loc.sum' => 200];

        $root->children = [$childA, $childB];

        $this->aggregator->aggregateBottomUp($root);

        self::assertSame(300, $root->metrics['loc.sum']);
    }

    public function testLocSumNotOverwrittenIfAlreadySet(): void
    {
        $root = new HtmlTreeNode('project', '<project>', 'project');
        $root->metrics = ['loc.sum' => 999];

        $child = new HtmlTreeNode('A', 'App\\A', 'class');
        $child->metrics = ['loc.sum' => 100];
        $root->children = [$child];

        $this->aggregator->aggregateBottomUp($root);

        // Existing value preserved
        self::assertSame(999, $root->metrics['loc.sum']);
    }

    public function testHealthScoresWeightedAverageByLoc(): void
    {
        $root = new HtmlTreeNode('project', '<project>', 'project');

        // Child A: 100 LOC, health.overall = 80
        $childA = new HtmlTreeNode('A', 'App\\A', 'class');
        $childA->metrics = ['loc.sum' => 100, 'health.overall' => 80.0];

        // Child B: 300 LOC, health.overall = 90
        $childB = new HtmlTreeNode('B', 'App\\B', 'class');
        $childB->metrics = ['loc.sum' => 300, 'health.overall' => 90.0];

        $root->children = [$childA, $childB];

        $this->aggregator->aggregateBottomUp($root);

        // Weighted avg: (80*100 + 90*300) / (100+300) = (8000+27000)/400 = 87.5
        self::assertSame(87.5, $root->metrics['health.overall']);
    }

    public function testHealthScoreDefaultWeightOneWhenNoLocSum(): void
    {
        $root = new HtmlTreeNode('project', '<project>', 'project');

        // Children without loc.sum get weight = 1
        $childA = new HtmlTreeNode('A', 'App\\A', 'class');
        $childA->metrics = ['health.complexity' => 70.0];

        $childB = new HtmlTreeNode('B', 'App\\B', 'class');
        $childB->metrics = ['health.complexity' => 90.0];

        $root->children = [$childA, $childB];

        $this->aggregator->aggregateBottomUp($root);

        // Equal weight: (70*1 + 90*1) / 2 = 80.0
        self::assertSame(80.0, $root->metrics['health.complexity']);
    }

    public function testHealthScoreNotOverwrittenIfAlreadySet(): void
    {
        $root = new HtmlTreeNode('project', '<project>', 'project');
        $root->metrics = ['health.overall' => 50.0];

        $child = new HtmlTreeNode('A', 'App\\A', 'class');
        $child->metrics = ['loc.sum' => 100, 'health.overall' => 90.0];
        $root->children = [$child];

        $this->aggregator->aggregateBottomUp($root);

        self::assertSame(50.0, $root->metrics['health.overall']);
    }

    public function testMultipleHealthKeysAggregated(): void
    {
        $root = new HtmlTreeNode('project', '<project>', 'project');

        $child = new HtmlTreeNode('A', 'App\\A', 'class');
        $child->metrics = [
            'loc.sum' => 100,
            'health.overall' => 80.0,
            'health.complexity' => 70.0,
            'health.cohesion' => 60.0,
            'health.coupling' => 90.0,
            'health.typing' => 85.0,
            'health.maintainability' => 75.0,
        ];

        $root->children = [$child];

        $this->aggregator->aggregateBottomUp($root);

        self::assertSame(80.0, $root->metrics['health.overall']);
        self::assertSame(70.0, $root->metrics['health.complexity']);
        self::assertSame(60.0, $root->metrics['health.cohesion']);
        self::assertSame(90.0, $root->metrics['health.coupling']);
        self::assertSame(85.0, $root->metrics['health.typing']);
        self::assertSame(75.0, $root->metrics['health.maintainability']);
    }

    public function testDeepHierarchyAggregation(): void
    {
        // Root -> NS -> ClassA (loc=100, health.overall=80)
        //               ClassB (loc=100, health.overall=60)
        $root = new HtmlTreeNode('project', '<project>', 'project');
        $ns = new HtmlTreeNode('App', 'App', 'namespace');

        $classA = new HtmlTreeNode('ClassA', 'App\\ClassA', 'class');
        $classA->metrics = ['loc.sum' => 100, 'health.overall' => 80.0];

        $classB = new HtmlTreeNode('ClassB', 'App\\ClassB', 'class');
        $classB->metrics = ['loc.sum' => 100, 'health.overall' => 60.0];

        $ns->children = [$classA, $classB];
        $root->children = [$ns];

        $this->aggregator->aggregateBottomUp($root);

        // NS: (80*100 + 60*100) / 200 = 70.0
        self::assertSame(70.0, $ns->metrics['health.overall']);
        self::assertSame(200, $ns->metrics['loc.sum']);

        // Root: only child = NS with loc=200, health=70 -> 70.0
        self::assertSame(70.0, $root->metrics['health.overall']);
        self::assertSame(200, $root->metrics['loc.sum']);
    }

    public function testChildWithoutHealthScoreSkippedInWeighting(): void
    {
        $root = new HtmlTreeNode('project', '<project>', 'project');

        $childA = new HtmlTreeNode('A', 'App\\A', 'class');
        $childA->metrics = ['loc.sum' => 100, 'health.overall' => 80.0];

        // Child B has no health.overall
        $childB = new HtmlTreeNode('B', 'App\\B', 'class');
        $childB->metrics = ['loc.sum' => 200];

        $root->children = [$childA, $childB];

        $this->aggregator->aggregateBottomUp($root);

        // Only childA contributes: 80.0
        self::assertSame(80.0, $root->metrics['health.overall']);
        // loc.sum still includes both
        self::assertSame(300, $root->metrics['loc.sum']);
    }
}
