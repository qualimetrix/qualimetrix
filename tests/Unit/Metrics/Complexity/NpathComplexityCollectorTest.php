<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Complexity;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\Complexity\NpathComplexityCollector;
use Qualimetrix\Metrics\Complexity\NpathComplexityVisitor;
use SplFileInfo;

#[CoversClass(NpathComplexityCollector::class)]
#[CoversClass(NpathComplexityVisitor::class)]
final class NpathComplexityCollectorTest extends TestCase
{
    private NpathComplexityCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new NpathComplexityCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('npath-complexity', $this->collector->getName());
    }

    public function testProvides(): void
    {
        self::assertSame(['npath'], $this->collector->provides());
    }

    public function testGetMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);
        self::assertSame('npath', $definitions[0]->name);
        self::assertSame(SymbolLevel::Method, $definitions[0]->collectedAt);
        self::assertSame(
            [AggregationStrategy::Max, AggregationStrategy::Average],
            $definitions[0]->aggregations[SymbolLevel::Class_->value],
        );
    }

    public function testEmptyMethodHasNpathOne(): void
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

        self::assertSame(1, $metrics->get('npath:App\Test::empty'));
    }

    public function testSimpleMethodHasNpathOne(): void
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

        self::assertSame(1, $metrics->get('npath:App\Service\Calculator::add'));
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

        // NPath = cond(0) + then(1) + skip(1) = 2, then * return(1) = 2
        self::assertSame(2, $metrics->get('npath:App\Test::check'));
    }

    public function testMethodWithIfElse(): void
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
        } else {
            return false;
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // NPath = cond(0) + then(1) + else(1) = 2
        self::assertSame(2, $metrics->get('npath:App\Test::check'));
    }

    public function testMethodWithTwoSequentialIfs(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function check(int $a, int $b): void
    {
        if ($a > 0) {
            // A
        }
        if ($b > 0) {
            // B
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // First if: NPath = cond(0) + then(1) + skip(1) = 2
        // Second if: NPath = cond(0) + then(1) + skip(1) = 2
        // Sequence: 2 × 2 = 4
        self::assertSame(4, $metrics->get('npath:App\Test::check'));
    }

    public function testMethodWithNestedIf(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function check(int $a, int $b): void
    {
        if ($a > 0) {
            if ($b > 0) {
                // Inner
            }
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Inner if: cond(0) + then(1) + skip(1) = 2
        // Outer if: cond(0) + then(2) + skip(1) = 3
        self::assertSame(3, $metrics->get('npath:App\Test::check'));
    }

    public function testMethodWithWhileLoop(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function loop(int $n): void
    {
        while ($n > 0) {
            $n--;
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // NPath = cond(0) + body(1) + exit(1) = 2
        self::assertSame(2, $metrics->get('npath:App\Test::loop'));
    }

    public function testMethodWithForLoop(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function loop(): void
    {
        for ($i = 0; $i < 10; $i++) {
            // body
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Nejmeh 1988: NPath(for) = NPath(cond) + NPath(body) + 1 = 0 + 1 + 1 = 2
        self::assertSame(2, $metrics->get('npath:App\Test::loop'));
    }

    public function testMethodWithForeach(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function iterate(array $items): void
    {
        foreach ($items as $item) {
            // body
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Foreach NPath = NPath(body) + 1 = 1 + 1 = 2
        self::assertSame(2, $metrics->get('npath:App\Test::iterate'));
    }

    public function testMethodWithSwitch(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function grade(int $score): string
    {
        switch ($score) {
            case 1:
                return 'A';
            case 2:
                return 'B';
            default:
                return 'F';
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // NPath = cond(0) + case1_body(1) + case2_body(1) + default_body(1) = 3
        self::assertSame(3, $metrics->get('npath:App\Test::grade'));
    }

    public function testMethodWithTryCatch(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function risky(): void
    {
        try {
            // risky
        } catch (\Exception $e) {
            // handle
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // NPath = try(1) + catch(1) + 1 = 3
        self::assertSame(3, $metrics->get('npath:App\Test::risky'));
    }

    public function testMethodWithTernary(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function conditional(bool $flag): int
    {
        return $flag ? 1 : 0;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Nejmeh: NPath = cond(0) + true(0) + false(0) + 2 = 2
        self::assertSame(2, $metrics->get('npath:App\Test::conditional'));
    }

    public function testMethodWithBooleanAnd(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function check(bool $a, bool $b): bool
    {
        return $a && $b;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Nejmeh: NPath = left(0) + right(0) + 1 = 1; return max(1, 1) = 1
        self::assertSame(1, $metrics->get('npath:App\Test::check'));
    }

    public function testMethodWithBooleanOr(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function check(bool $a, bool $b): bool
    {
        return $a || $b;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Nejmeh: NPath = left(0) + right(0) + 1 = 1; return max(1, 1) = 1
        self::assertSame(1, $metrics->get('npath:App\Test::check'));
    }

    public function testMethodWithNullCoalesce(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function check(?int $a): int
    {
        return $a ?? 0;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Nejmeh: NPath = left(0) + right(0) + 1 = 1; return max(1, 1) = 1
        self::assertSame(1, $metrics->get('npath:App\Test::check'));
    }

    public function testMethodWithMatch(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function grade(int $score): string
    {
        return match ($score) {
            1 => 'A',
            2 => 'B',
            default => 'F',
        };
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // NPath = cond(0) + arm1(0) + arm2(0) + default(0) = 0; max(1, 0) = 1
        // return max(1, 1) = 1
        self::assertSame(1, $metrics->get('npath:App\Test::grade'));
    }

    public function testMaxValueCap(): void
    {
        // Create many sequential ifs to exceed MAX_NPATH
        // Each if has NPath = cond(0)+then(1)+skip(1) = 2, so 30 sequential ifs give 2^30 > 10^9
        $code = '<?php class Test { public function deep() {';
        for ($i = 0; $i < 30; $i++) {
            $code .= ' if ($x' . $i . ') { $a = 1; } ';
        }
        $code .= ' } }';

        $metrics = $this->collectMetrics($code);

        // Should be capped at MAX_NPATH = 1_000_000_000
        self::assertSame(1_000_000_000, $metrics->get('npath:Test::deep'));
    }

    public function testAnonymousClassMethodsAreNotAttributedToOuterClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Outer
{
    public function simple(): void
    {
    }

    public function factory(): object
    {
        return new class {
            public function innerComplex(): void
            {
                if (true) {
                    if (false) {
                        // nested ifs
                    }
                }
            }
        };
    }

    public function afterAnonymous(): void
    {
        if (true) {
            // one path
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // simple: NPath = 1 (empty)
        self::assertSame(1, $metrics->get('npath:App\Outer::simple'));

        // factory: NPath = 1 — anonymous class complexity should NOT leak
        self::assertSame(1, $metrics->get('npath:App\Outer::factory'));

        // afterAnonymous: NPath = cond(0) + then(1) + skip(1) = 2
        self::assertSame(2, $metrics->get('npath:App\Outer::afterAnonymous'));

        // Anonymous class methods should NOT appear in metrics
        self::assertNull($metrics->get('npath:App\Outer::innerComplex'));
    }

    public function testWhileWithBooleanAndCondition(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function loop(bool $a, bool $b): void
    {
        while ($a && $b) {
            $x = 1;
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // NPath = cond(0+0+1=1) + body(1) + exit(1) = 3
        self::assertSame(3, $metrics->get('npath:App\Test::loop'));
    }

    public function testWhileWithTripleBooleanOrCondition(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function loop(bool $a, bool $b, bool $c): void
    {
        while ($a || $b || $c) {
            $x = 1;
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // ($a || $b) has NPath=0+0+1=1, then (($a || $b) || $c) has NPath=1+0+1=2
        // NPath = cond(2) + body(1) + exit(1) = 4
        self::assertSame(4, $metrics->get('npath:App\Test::loop'));
    }

    public function testDoWhileWithBooleanAndCondition(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function loop(bool $a, bool $b): void
    {
        do {
            $x = 1;
        } while ($a && $b);
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // NPath = cond(0+0+1=1) + body(1) + exit(1) = 3
        self::assertSame(3, $metrics->get('npath:App\Test::loop'));
    }

    public function testForWithBooleanAndCondition(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function loop(int $n, bool $flag): void
    {
        for ($i = 0; $i < $n && $flag; $i++) {
            $x = 1;
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Nejmeh 1988: NPath(for) = NPath(cond: 0+0+1=1) + NPath(body: 1) + 1 = 3
        self::assertSame(3, $metrics->get('npath:App\Test::loop'));
    }

    public function testForeachHasNoConditionExpression(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function iterate(array $items): void
    {
        foreach ($items as $item) {
            $x = 1;
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Foreach NPath = NPath(body) + 1 = 1 + 1 = 2
        self::assertSame(2, $metrics->get('npath:App\Test::iterate'));
    }

    public function testWhileWithTernaryCondition(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function loop(bool $a, int $b, int $c): void
    {
        while ($a ? $b : $c) {
            $x = 1;
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Ternary condition NPath = cond(0) + true(0) + false(0) + 2 = 2
        // NPath = cond(2) + body(1) + exit(1) = 4
        self::assertSame(4, $metrics->get('npath:App\Test::loop'));
    }

    /**
     * Fix 3: if ($a && $b) should have NPath reflecting the condition complexity.
     */
    public function testIfWithBooleanAndCondition(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function check(bool $a, bool $b): void
    {
        if ($a && $b) {
            // body
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // NPath(if) = NPath(cond: $a && $b = 0+0+1=1) + NPath(then: 1) + NPath(skip: 1) = 3
        self::assertSame(3, $metrics->get('npath:App\Test::check'));
    }

    /**
     * Fix 3: elseif condition should also be evaluated.
     */
    public function testIfElseifWithBooleanConditions(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function check(bool $a, bool $b, bool $c, bool $d): void
    {
        if ($a && $b) {
            // body1
        } elseif ($c || $d) {
            // body2
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // NPath(if) = NPath(cond1: $a && $b = 1) + NPath(then1: 1)
        //           + NPath(cond2: $c || $d = 1) + NPath(then2: 1)
        //           + NPath(skip: 1) = 5
        self::assertSame(5, $metrics->get('npath:App\Test::check'));
    }

    /**
     * Fix 5: Arrow function with conditional logic should be handled by NPath.
     */
    public function testArrowFunctionWithTernary(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function getMapper(): callable
    {
        return fn($x) => $x > 0 ? $x * 2 : 0;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Method itself: NPath = 1
        self::assertSame(1, $metrics->get('npath:App\Test::getMapper'));

        // Arrow function: NPath = ternary cond(0) + true(0) + false(0) + 2 = 2
        self::assertSame(2, $metrics->get('npath:App\Test::{closure#1}'));
    }

    /**
     * Fix 5: Arrow function with simple expression.
     */
    public function testArrowFunctionSimple(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function getDoubler(): callable
    {
        return fn($x) => $x * 2;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Arrow function with no branching: NPath = 1
        self::assertSame(1, $metrics->get('npath:App\Test::{closure#1}'));
    }

    /**
     * Ternary inside an assignment should be detected via Assign recursion.
     */
    public function testTernaryInAssignment(): void
    {
        $code = <<<'PHP'
<?php
function f($x) { $r = $x ? "a" : "b"; }
PHP;

        $metrics = $this->collectFunctionMetrics($code);

        // $r = $x ? "a" : "b" -> Assign recurses into Ternary
        // Ternary NPath = cond(0) + true(0) + false(0) + 2 = 2; stmt max(1, 2) = 2
        self::assertSame(2, $metrics->get('npath:f'));
    }

    /**
     * Null coalesce inside an assignment should be detected via Assign recursion.
     */
    public function testCoalesceInAssignment(): void
    {
        $code = <<<'PHP'
<?php
function f($x) { $r = $x ?? "b"; }
PHP;

        $metrics = $this->collectFunctionMetrics($code);

        // $r = $x ?? "b" -> Assign recurses into Coalesce
        // Coalesce NPath = left(0) + right(0) + 1 = 1; stmt max(1, 1) = 1
        self::assertSame(1, $metrics->get('npath:f'));
    }

    /**
     * Closure inside anonymous class method should NOT appear in NPath metrics of outer class.
     */
    public function testClosureInsideAnonymousClassNotInOuterMetrics(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Outer
{
    public function outerMethod(): void
    {
        $obj = new class {
            public function innerMethod(): void
            {
                $fn = function() {
                    if (true) { return 1; }
                    return 0;
                };
            }
        };
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // outerMethod: NPath = 1 — closure inside anonymous class is ignored
        self::assertSame(1, $metrics->get('npath:App\Outer::outerMethod'));
    }

    /**
     * ArrowFunction inside anonymous class method should NOT appear in NPath metrics of outer class.
     */
    public function testArrowFunctionInsideAnonymousClassNotInOuterMetrics(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Outer
{
    public function outerMethod(): void
    {
        $obj = new class {
            public function innerMethod(): void
            {
                $fn = fn() => true;
            }
        };
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // outerMethod: NPath = 1 — arrow function inside anonymous class is ignored
        self::assertSame(1, $metrics->get('npath:App\Outer::outerMethod'));
    }

    /**
     * BooleanNot should recurse into its operand for NPath calculation.
     */
    public function testBooleanNotRecursion(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Foo
{
    public function bar(bool $a, bool $b): int
    {
        if (!($a && $b)) { return 1; }
        return 0;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // NPath(if) = NPath(cond: !($a && $b)) + NPath(then: 1) + NPath(skip: 1)
        // NPath(!expr) = NPath(expr) = NPath($a && $b) = 0+0+1 = 1
        // NPath(if) = 1 + 1 + 1 = 3, then * NPath(return 0) = 1 => total = 3
        self::assertSame(3, $metrics->get('npath:App\Foo::bar'));
    }

    /**
     * Try-catch-finally: NPath should multiply try-catch by finally.
     */
    public function testTryCatchFinallyMultiplication(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Foo
{
    public function bar(bool $a, bool $b): void
    {
        try {
            if ($a) { $this->f(); }
        } catch (\Exception $e) {
            $this->g();
        } finally {
            if ($b) { $this->h(); }
        }
    }

    private function f(): void {}
    private function g(): void {}
    private function h(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // NP(try body) = cond(0) + then(1) + skip(1) = 2
        // NP(catch body) = 1
        // NP(try-catch) = NP(try) + NP(catch) + 1 = 2 + 1 + 1 = 4
        // NP(finally body) = cond(0) + then(1) + skip(1) = 2
        // Total = NP(try-catch) * NP(finally) = 4 * 2 = 8
        self::assertSame(8, $metrics->get('npath:App\Foo::bar'));
    }

    /**
     * Collect metrics for a standalone function (no class wrapper).
     */
    private function collectFunctionMetrics(string $code): \Qualimetrix\Core\Metric\MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code);

        $collector = new NpathComplexityCollector();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector->getVisitor());
        $traverser->traverse($ast ?? []);

        $file = new SplFileInfo(__FILE__);

        return $collector->collect($file, $ast ?? []);
    }

    public function testFactorsTrackedForMultiplicativeStatements(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function complex($a, $b): int
    {
        if ($a > 0) {       // NPath: 3 (if with else)
            return 1;
        } else {
            $x = 2;
        }

        switch ($b) {       // NPath: 3 (3 cases)
            case 1: return 10;
            case 2: return 20;
            default: return 30;
        }
    }
}
PHP;

        $visitor = new NpathComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $factors = $visitor->getFactors();
        $fqn = 'App\Test::complex';

        self::assertArrayHasKey($fqn, $factors);
        self::assertCount(2, $factors[$fqn]);

        // First factor: if/else
        self::assertSame('if/else', $factors[$fqn][0]['type']);
        self::assertGreaterThan(1, $factors[$fqn][0]['factor']);

        // Second factor: switch
        self::assertSame('switch', $factors[$fqn][1]['type']);
        self::assertGreaterThan(1, $factors[$fqn][1]['factor']);

        // All factors have line numbers
        foreach ($factors[$fqn] as $factor) {
            self::assertArrayHasKey('line', $factor);
            self::assertGreaterThan(0, $factor['line']);
        }
    }

    public function testFactorsPassedViaMethodsWithMetrics(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function simple($a): int
    {
        if ($a) {           // NPath: 2 (if without else)
            return 1;
        }
        return 0;
    }
}
PHP;

        $visitor = new NpathComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $methods = $visitor->getMethodsWithMetrics();
        self::assertCount(1, $methods);

        $entries = $methods[0]->metrics->entries('npath-complexity.factors');
        self::assertCount(1, $entries);
        self::assertSame('if', $entries[0]['type']);
        self::assertSame(2, $entries[0]['factor']);
    }

    private function collectMetrics(string $code): \Qualimetrix\Core\Metric\MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast ?? []);

        $file = new SplFileInfo(__FILE__);

        return $this->collector->collect($file, $ast ?? []);
    }
}
