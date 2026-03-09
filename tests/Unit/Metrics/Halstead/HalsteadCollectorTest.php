<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Halstead;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\Halstead\HalsteadCollector;
use AiMessDetector\Metrics\Halstead\HalsteadMetrics;
use AiMessDetector\Metrics\Halstead\HalsteadVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(HalsteadCollector::class)]
#[CoversClass(HalsteadVisitor::class)]
#[CoversClass(HalsteadMetrics::class)]
final class HalsteadCollectorTest extends TestCase
{
    private HalsteadCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new HalsteadCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('halstead', $this->collector->getName());
    }

    public function testProvides(): void
    {
        $provides = $this->collector->provides();

        self::assertContains('halstead.volume', $provides);
        self::assertContains('halstead.difficulty', $provides);
        self::assertContains('halstead.effort', $provides);
        self::assertContains('halstead.bugs', $provides);
        self::assertContains('halstead.time', $provides);
    }

    public function testEmptyMethodHasZeroMetrics(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function empty(): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0.0, $metrics->get('halstead.volume:App\Test::empty'));
        self::assertSame(0.0, $metrics->get('halstead.difficulty:App\Test::empty'));
        self::assertSame(0.0, $metrics->get('halstead.effort:App\Test::empty'));
        self::assertSame(0.0, $metrics->get('halstead.bugs:App\Test::empty'));
        self::assertSame(0.0, $metrics->get('halstead.time:App\Test::empty'));
    }

    public function testSimpleMethodWithAssignment(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Operators: return, +  (2 unique, 2 total)
        // Operands: $a, $b (2 unique, 2 total)
        // Volume should be > 0
        $volume = $metrics->get('halstead.volume:App\Test::add');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);
    }

    public function testMethodWithMultipleOperators(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Calculator
{
    public function calculate(int $x, int $y): int
    {
        $result = $x + $y;
        $result = $result * 2;
        return $result - 1;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Operators: =, +, =, *, return, - (4 unique types: =, +, *, return, -)
        // Operands: $result, $x, $y, 2, 1 (5 unique)
        $volume = $metrics->get('halstead.volume:App\Calculator::calculate');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);

        $difficulty = $metrics->get('halstead.difficulty:App\Calculator::calculate');
        self::assertIsFloat($difficulty);
        self::assertGreaterThan(0, $difficulty);
    }

    public function testMethodWithControlFlow(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Flow
{
    public function check(int $x): string
    {
        if ($x > 0) {
            return 'positive';
        } else {
            return 'non-positive';
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Operators: if, >, return, else, return (5 total, 3 unique types)
        // Operands: $x, 0, 'positive', 'non-positive' (4 unique)
        $volume = $metrics->get('halstead.volume:App\Flow::check');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);
    }

    public function testMethodWithMethodCalls(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Service
{
    public function process(object $obj): mixed
    {
        return $obj->getData()->transform();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Operators: return, ->, -> (3 total, 2 unique)
        // Operands: $obj, getData, transform (3 unique)
        $volume = $metrics->get('halstead.volume:App\Service::process');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);
    }

    public function testMethodWithBooleanOperators(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Logic
{
    public function check(bool $a, bool $b, bool $c): bool
    {
        return $a && $b || !$c;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Operators: return, &&, ||, ! (4 unique, 4 total)
        // Operands: $a, $b, $c (3 unique, 3 total)
        $volume = $metrics->get('halstead.volume:App\Logic::check');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);
    }

    public function testMethodWithNewOperator(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Factory
{
    public function create(): object
    {
        return new \stdClass();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Operators: return, new (2 unique)
        // Operands: stdClass (1 unique)
        $volume = $metrics->get('halstead.volume:App\Factory::create');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);
    }

    public function testMethodWithArrayOperations(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class ArrayOps
{
    public function get(array $arr, int $index): mixed
    {
        return $arr[$index];
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Operators: return, [] (2 unique)
        // Operands: $arr, $index (2 unique)
        $volume = $metrics->get('halstead.volume:App\ArrayOps::get');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);
    }

    public function testMethodWithTernary(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Ternary
{
    public function max(int $a, int $b): int
    {
        return $a > $b ? $a : $b;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Operators: return, >, ?: (3 unique)
        // Operands: $a, $b (2 unique, 4 uses)
        $volume = $metrics->get('halstead.volume:App\Ternary::max');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);
    }

    public function testMethodWithNullCoalescing(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class NullCoalesce
{
    public function getOrDefault(?string $value): string
    {
        return $value ?? 'default';
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Operators: return, ?? (2 unique)
        // Operands: $value, 'default' (2 unique)
        $volume = $metrics->get('halstead.volume:App\NullCoalesce::getOrDefault');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);
    }

    public function testClosure(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithClosure
{
    public function withCallback(): callable
    {
        return function (int $x): int {
            return $x + 1;
        };
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Main method metrics
        self::assertNotNull($metrics->get('halstead.volume:App\WithClosure::withCallback'));

        // Closure metrics
        $closureVolume = $metrics->get('halstead.volume:App\WithClosure::{closure#1}');
        self::assertIsFloat($closureVolume);
        self::assertGreaterThan(0, $closureVolume);
    }

    public function testArrowFunction(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithArrow
{
    public function mapper(): callable
    {
        return fn(int $x): int => $x * 2;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Arrow function metrics
        $arrowVolume = $metrics->get('halstead.volume:App\WithArrow::{closure#1}');
        self::assertIsFloat($arrowVolume);
        self::assertGreaterThan(0, $arrowVolume);
    }

    public function testGlobalFunction(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Utils;

function helper(int $x): int
{
    return $x + 1;
}
PHP;

        $metrics = $this->collectMetrics($code);

        $volume = $metrics->get('halstead.volume:App\Utils\helper');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);
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

        foreach ($items as $key => $item) {
            if ($validate && $this->isValid($item)) {
                $value = $item['value'] ?? 0;
                $result[$key] = $value > 50 ? 'high' : 'low';
            }
        }

        return $result;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        $volume = $metrics->get('halstead.volume:App\Service\ComplexService::process');
        $difficulty = $metrics->get('halstead.difficulty:App\Service\ComplexService::process');
        $effort = $metrics->get('halstead.effort:App\Service\ComplexService::process');
        $bugs = $metrics->get('halstead.bugs:App\Service\ComplexService::process');
        $time = $metrics->get('halstead.time:App\Service\ComplexService::process');

        // Complex method should have higher values
        self::assertGreaterThan(50, $volume);
        self::assertGreaterThan(0, $difficulty);
        self::assertGreaterThan(0, $effort);
        self::assertGreaterThan(0, $bugs);
        self::assertGreaterThan(0, $time);
    }

    public function testReset(): void
    {
        $code1 = <<<'PHP'
<?php

namespace App;

class First
{
    public function method(): int
    {
        return 1 + 2;
    }
}
PHP;

        $code2 = <<<'PHP'
<?php

namespace App;

class Second
{
    public function other(): int
    {
        return 3;
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
        self::assertNull($metrics->get('halstead.volume:App\First::method'));
        self::assertNotNull($metrics->get('halstead.volume:App\Second::other'));
    }

    public function testGetMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(5, $definitions);

        $metricNames = array_map(fn($d) => $d->name, $definitions);
        self::assertContains('halstead.volume', $metricNames);
        self::assertContains('halstead.difficulty', $metricNames);
        self::assertContains('halstead.effort', $metricNames);
        self::assertContains('halstead.bugs', $metricNames);
        self::assertContains('halstead.time', $metricNames);

        // Check collected at level
        foreach ($definitions as $def) {
            self::assertSame(SymbolLevel::Method, $def->collectedAt);
        }

        // Check aggregations for volume (representative)
        $volumeDef = $definitions[0];
        $classStrategies = $volumeDef->getStrategiesForLevel(SymbolLevel::Class_);
        self::assertContains(AggregationStrategy::Average, $classStrategies);
        self::assertContains(AggregationStrategy::Max, $classStrategies);
    }

    public function testGetMethodsWithMetrics(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function method1(): int
    {
        return 1 + 2;
    }

    public function method2(): int
    {
        return 3 * 4;
    }
}
PHP;

        $this->collectMetrics($code);
        $methodsWithMetrics = $this->collector->getMethodsWithMetrics();

        self::assertCount(2, $methodsWithMetrics);

        $method1 = $methodsWithMetrics[0];
        self::assertSame('App', $method1->namespace);
        self::assertSame('Test', $method1->class);
        self::assertSame('method1', $method1->method);
        self::assertNotNull($method1->metrics->get('halstead.volume'));
    }

    public function testMethodWithCastOperators(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Caster
{
    public function cast(mixed $value): int
    {
        return (int) $value;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        $volume = $metrics->get('halstead.volume:App\Caster::cast');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);
    }

    public function testMethodWithIncrementDecrement(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Counter
{
    public function count(): int
    {
        $i = 0;
        $i++;
        ++$i;
        $i--;
        return $i;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        $volume = $metrics->get('halstead.volume:App\Counter::count');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);
    }

    public function testMethodWithCompoundAssignment(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Compound
{
    public function accumulate(int $x): int
    {
        $sum = 0;
        $sum += $x;
        $sum *= 2;
        $sum -= 1;
        return $sum;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        $volume = $metrics->get('halstead.volume:App\Compound::accumulate');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);
    }

    public function testMethodWithTryCatch(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Exceptional
{
    public function risky(): void
    {
        try {
            throw new \RuntimeException('error');
        } catch (\RuntimeException $e) {
            return;
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        $volume = $metrics->get('halstead.volume:App\Exceptional::risky');
        self::assertIsFloat($volume);
        self::assertGreaterThan(0, $volume);
    }

    public function testMethodWithLoops(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Looper
{
    public function loop(array $items): int
    {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += $i;
        }
        foreach ($items as $item) {
            $sum += $item;
        }
        while ($sum > 100) {
            $sum--;
        }
        return $sum;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        $volume = $metrics->get('halstead.volume:App\Looper::loop');
        self::assertIsFloat($volume);
        self::assertGreaterThan(50, $volume); // Complex method
    }

    public function testAnonymousClassMethodsAreNotAttributedToOuterClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Outer
{
    public function simple(): int
    {
        return 1;
    }

    public function factory(): object
    {
        return new class {
            public function innerComplex(): int
            {
                $a = 1 + 2;
                $b = $a * 3;
                $c = $b - $a;
                return $c + $a + $b;
            }
        };
    }

    public function afterAnonymous(): int
    {
        return 2 + 3;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // simple should have low volume (just return + literal)
        $simpleVolume = $metrics->get('halstead.volume:App\Outer::simple');
        self::assertIsFloat($simpleVolume);
        self::assertGreaterThan(0, $simpleVolume);

        // factory should have minimal volume — anonymous class operators/operands should NOT leak
        $factoryVolume = $metrics->get('halstead.volume:App\Outer::factory');
        self::assertIsFloat($factoryVolume);
        // factory only has 'return' and 'new' — should be less than innerComplex would be
        self::assertLessThan(30, $factoryVolume);

        // afterAnonymous should work correctly
        $afterVolume = $metrics->get('halstead.volume:App\Outer::afterAnonymous');
        self::assertIsFloat($afterVolume);
        self::assertGreaterThan(0, $afterVolume);

        // Anonymous class methods should NOT appear in metrics
        self::assertNull($metrics->get('halstead.volume:App\Outer::innerComplex'));
    }

    /**
     * Closure inside anonymous class method should NOT appear in Halstead metrics of outer class.
     */
    public function testClosureInsideAnonymousClassNotInOuterMetrics(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Outer
{
    public function outerMethod(): int
    {
        $obj = new class {
            public function innerMethod(): int
            {
                $fn = function() {
                    $a = 1 + 2 + 3;
                    $b = $a * 4;
                    return $a + $b;
                };
                return $fn();
            }
        };
        return 1;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // outerMethod should have low volume — closure inside anonymous class is ignored
        $outerVolume = $metrics->get('halstead.volume:App\Outer::outerMethod');
        self::assertIsFloat($outerVolume);
        // Should be small (just 'return', 'new', num:1)
        self::assertLessThan(30, $outerVolume);
    }

    /**
     * Echo statement should be counted as an operator, symmetrically with print.
     */
    public function testEchoIsCountedAsOperator(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class EchoTest
{
    public function sayHello(): void
    {
        echo "hello";
    }
}
PHP;

        $halstead = $this->collectHalsteadMetrics($code, 'App\EchoTest::sayHello');

        // Operators: echo (1 unique, 1 total)
        // Operands: str:... (1 unique, 1 total)
        self::assertSame(1, $halstead->n1, 'Expected 1 unique operator (echo)');
        self::assertSame(1, $halstead->N1, 'Expected 1 total operator (echo)');
        self::assertSame(1, $halstead->n2, 'Expected 1 unique operand (string literal)');
    }

    /**
     * Echo and print should be counted as separate operators.
     */
    public function testEchoAndPrintAreSeparateOperators(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class EchoPrintTest
{
    public function output(): void
    {
        echo "hello";
        print "world";
    }
}
PHP;

        $halstead = $this->collectHalsteadMetrics($code, 'App\EchoPrintTest::output');

        // Operators: echo, print (2 unique, 2 total)
        self::assertSame(2, $halstead->n1, 'Expected 2 unique operators (echo, print)');
        self::assertSame(2, $halstead->N1, 'Expected 2 total operators');
    }

    /**
     * Match arms should be counted as operators, symmetrically with case in switch.
     */
    public function testMatchArmIsCountedAsOperator(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MatchTest
{
    public function classify(int $x): string
    {
        return match($x) {
            1 => 'a',
            2 => 'b',
        };
    }
}
PHP;

        $halstead = $this->collectHalsteadMetrics($code, 'App\MatchTest::classify');

        // Operators: return, match, match_arm, match_arm (3 unique, 4 total)
        // Operands: $x, num:1, str:...(a), num:2, str:...(b) (5 unique, 5 total)
        self::assertSame(3, $halstead->n1, 'Expected 3 unique operators (return, match, match_arm)');
        self::assertSame(4, $halstead->N1, 'Expected 4 total operators (return + match + 2x match_arm)');
    }

    /**
     * Match with default arm should also count the default arm as operator.
     */
    public function testMatchWithDefaultArmCountsAllArms(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MatchDefaultTest
{
    public function label(int $x): string
    {
        return match($x) {
            1 => 'one',
            2 => 'two',
            default => 'other',
        };
    }
}
PHP;

        $halstead = $this->collectHalsteadMetrics($code, 'App\MatchDefaultTest::label');

        // Operators: return, match, match_arm x3 (3 unique, 5 total)
        self::assertSame(3, $halstead->n1, 'Expected 3 unique operators (return, match, match_arm)');
        self::assertSame(5, $halstead->N1, 'Expected 5 total operators (return + match + 3x match_arm)');
    }

    /**
     * Symmetry: switch/case and match should count branch operators similarly.
     */
    public function testMatchAndSwitchOperatorSymmetry(): void
    {
        $switchCode = <<<'PHP'
<?php

namespace App;

class SwitchSymmetry
{
    public function classify(int $x): string
    {
        switch ($x) {
            case 1:
                return 'a';
            case 2:
                return 'b';
        }
        return 'c';
    }
}
PHP;

        $matchCode = <<<'PHP'
<?php

namespace App;

class MatchSymmetry
{
    public function classify(int $x): string
    {
        return match($x) {
            1 => 'a',
            2 => 'b',
        };
    }
}
PHP;

        $switchMetrics = $this->collectHalsteadMetrics($switchCode, 'App\SwitchSymmetry::classify');
        $matchMetrics = $this->collectHalsteadMetrics($matchCode, 'App\MatchSymmetry::classify');

        // switch: switch + 2x case + 3x return = 3 unique, 6 total operators
        // match:  return + match + 2x match_arm = 3 unique, 4 total operators
        // Both should have branch operators counted (case/match_arm), not just the keyword
        self::assertSame(3, $switchMetrics->n1, 'Switch: 3 unique operators (switch, case, return)');
        self::assertSame(3, $matchMetrics->n1, 'Match: 3 unique operators (return, match, match_arm)');

        // Total operators differ due to structural differences, but arms/cases ARE counted
        self::assertGreaterThanOrEqual(4, $matchMetrics->N1, 'Match arms should be counted as operators');
    }

    private function collectMetrics(string $code): MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->collect(new SplFileInfo(__FILE__), $ast);
    }

    /**
     * Helper to get raw HalsteadMetrics for a specific method FQN.
     */
    private function collectHalsteadMetrics(string $code, string $methodFqn): HalsteadMetrics
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $visitor = new HalsteadVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $metrics = $visitor->getMetrics();
        self::assertArrayHasKey($methodFqn, $metrics, "Method {$methodFqn} not found in Halstead metrics");

        return $metrics[$methodFqn];
    }
}
