<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Complexity;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\Complexity\CognitiveComplexityCollector;
use AiMessDetector\Metrics\Complexity\CognitiveComplexityVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(CognitiveComplexityCollector::class)]
#[CoversClass(CognitiveComplexityVisitor::class)]
final class CognitiveComplexityCollectorTest extends TestCase
{
    private CognitiveComplexityCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new CognitiveComplexityCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('cognitive-complexity', $this->collector->getName());
    }

    public function testProvides(): void
    {
        self::assertSame(['cognitive'], $this->collector->provides());
    }

    public function testSimpleMethodHasComplexityZero(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('cognitive:App\Service\Calculator::add'));
    }

    public function testMethodWithSingleIf(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function check(int $x): bool
    {
        if ($x > 0) {
            return true;
        }
        return false;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (if)
        self::assertSame(1, $metrics->get('cognitive:App\Test::check'));
    }

    public function testMethodWithNestedIf(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function nested(int $x, int $y): bool
    {
        if ($x > 0) {           // +1
            if ($y > 0) {       // +2 (1 + nesting=1)
                return true;
            }
        }
        return false;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (outer if) + +2 (inner if with nesting) = 3
        self::assertSame(3, $metrics->get('cognitive:App\Test::nested'));
    }

    public function testMethodWithLogicalOperators(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class BooleanTest
{
    public function check(int $a, int $b, int $c): bool
    {
        if ($a > 0 && $b > 0) {
            return true;
        }

        if ($a < 0 || $b < 0 || $c < 0) {
            return false;
        }

        return true;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (first if) + 1 (logical &&) + 1 (second if) + 1 (logical ||) = 4
        self::assertSame(4, $metrics->get('cognitive:App\BooleanTest::check'));
    }

    public function testMethodWithSwitch(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class SwitchTest
{
    public function dayName(int $day): string
    {
        switch ($day) {
            case 1:
                return 'Monday';
            case 2:
                return 'Tuesday';
            case 3:
                return 'Wednesday';
            default:
                return 'Unknown';
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (switch, not per case)
        self::assertSame(1, $metrics->get('cognitive:App\SwitchTest::dayName'));
    }

    public function testMethodWithTryCatch(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class ExceptionTest
{
    public function risky(): void
    {
        try {
            // risky code
        } catch (\InvalidArgumentException $e) {
            // handle
        } catch (\RuntimeException $e) {
            // handle
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (first catch) + +1 (second catch) = 2
        self::assertSame(2, $metrics->get('cognitive:App\ExceptionTest::risky'));
    }

    public function testMethodWithTernaryOperator(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class TernaryTest
{
    public function max(int $a, int $b): int
    {
        return $a > $b ? $a : $b;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (ternary)
        self::assertSame(1, $metrics->get('cognitive:App\TernaryTest::max'));
    }

    public function testMethodWithNullCoalescing(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class NullCoalescingTest
{
    public function getName(?string $name): string
    {
        return $name ?? 'Unknown';
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (??)
        self::assertSame(1, $metrics->get('cognitive:App\NullCoalescingTest::getName'));
    }

    public function testGlobalFunction(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Utils;

function validate(mixed $value): bool
{
    if ($value === null) {
        return false;
    }
    return true;
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (if)
        self::assertSame(1, $metrics->get('cognitive:App\Utils\validate'));
    }

    public function testGlobalFunctionWithoutNamespace(): void
    {
        $code = <<<'PHP'
<?php

function globalHelper(): void
{
    if (true) {
        // do something
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (if)
        self::assertSame(1, $metrics->get('cognitive:globalHelper'));
    }

    public function testClosure(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class ClosureTest
{
    public function withClosure(): callable
    {
        return function (int $x): int {
            if ($x > 0) {
                return $x * 2;
            }
            return $x;
        };
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Method itself: Cognitive = 0
        self::assertSame(0, $metrics->get('cognitive:App\ClosureTest::withClosure'));

        // Closure: Cognitive = +1 (if)
        self::assertSame(1, $metrics->get('cognitive:App\ClosureTest::{closure#1}'));
    }

    public function testMultipleMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MultiMethod
{
    public function simple(): void
    {
    }

    public function withIf(): void
    {
        if (true) {}
    }

    public function withLoop(): void
    {
        foreach ([] as $item) {}
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('cognitive:App\MultiMethod::simple'));
        self::assertSame(1, $metrics->get('cognitive:App\MultiMethod::withIf'));
        self::assertSame(1, $metrics->get('cognitive:App\MultiMethod::withLoop'));
    }

    public function testReset(): void
    {
        $code1 = <<<'PHP'
<?php

namespace App;

class First
{
    public function method(): void
    {
        if (true) {}
    }
}
PHP;

        $code2 = <<<'PHP'
<?php

namespace App;

class Second
{
    public function otherMethod(): void
    {
    }
}
PHP;

        // Collect first file
        $this->collectMetrics($code1);

        // Reset
        $this->collector->reset();

        // Collect second file
        $metrics = $this->collectMetrics($code2);

        // Should only contain metrics from second file
        self::assertNull($metrics->get('cognitive:App\First::method'));
        self::assertSame(0, $metrics->get('cognitive:App\Second::otherMethod'));
    }

    public function testComplexMethod(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

class ComplexService
{
    public function process(array $items, bool $validate): array
    {
        $result = [];

        if (empty($items)) {                            // +1
            return $result;
        }

        foreach ($items as $key => $item) {             // +1
            if ($validate && !$this->isValid($item)) {  // +2 (nesting=1) + 1 (logical) = 3
                continue;
            }

            try {
                $value = $item['value'] ?? 0;           // +1 (null coalescing)

                if ($value > 100 || $value < 0) {       // +2 (nesting=1) + 1 (logical) = 3
                    throw new \InvalidArgumentException('Invalid value');
                }

                $result[$key] = $value > 50 ? 'high' : 'low'; // +1 (ternary)
            } catch (\InvalidArgumentException $e) {    // +2 (nesting=1)
                $result[$key] = 'error';
            } catch (\RuntimeException $e) {            // +2 (nesting=1)
                $result[$key] = 'runtime_error';
            }
        }

        return $result;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (if empty) + 1 (foreach) + 2 (if validate) + 1 (&&)
        //           + 1 (??) + 2 (if value) + 1 (||) + 1 (ternary) + 2 (first catch) + 2 (second catch)
        //           = 14
        self::assertSame(14, $metrics->get('cognitive:App\Service\ComplexService::process'));
    }

    public function testRecursiveFunction(): void
    {
        $code = <<<'PHP'
<?php

function factorial(int $n): int
{
    if ($n <= 1) {
        return 1;
    }
    return $n * factorial($n - 1);
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (if) + 1 (recursive call) = 2
        self::assertSame(2, $metrics->get('cognitive:factorial'));
    }

    public function testGetMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);

        $cognitiveDefinition = $definitions[0];
        self::assertSame('cognitive', $cognitiveDefinition->name);
        self::assertSame(SymbolLevel::Method, $cognitiveDefinition->collectedAt);

        // Check Class_ level aggregations
        $classStrategies = $cognitiveDefinition->getStrategiesForLevel(SymbolLevel::Class_);
        self::assertCount(3, $classStrategies);
        self::assertContains(AggregationStrategy::Sum, $classStrategies);
        self::assertContains(AggregationStrategy::Average, $classStrategies);
        self::assertContains(AggregationStrategy::Max, $classStrategies);

        // Check Namespace_ level aggregations
        $namespaceStrategies = $cognitiveDefinition->getStrategiesForLevel(SymbolLevel::Namespace_);
        self::assertCount(3, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Sum, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Average, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Max, $namespaceStrategies);

        // Check Project level aggregations
        $projectStrategies = $cognitiveDefinition->getStrategiesForLevel(SymbolLevel::Project);
        self::assertCount(3, $projectStrategies);
        self::assertContains(AggregationStrategy::Sum, $projectStrategies);
        self::assertContains(AggregationStrategy::Average, $projectStrategies);
        self::assertContains(AggregationStrategy::Max, $projectStrategies);
    }

    private function collectMetrics(string $code): \AiMessDetector\Core\Metric\MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->collect(new SplFileInfo(__FILE__), $ast);
    }
}
