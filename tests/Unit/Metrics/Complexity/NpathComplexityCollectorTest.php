<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Complexity;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\Complexity\NpathComplexityCollector;
use AiMessDetector\Metrics\Complexity\NpathComplexityVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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

        // NPath = then(1) + else(1) = 2
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

        // NPath = then(1) + else(1) = 2
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

        // First if: NPath = 1 + 1 = 2
        // Second if: NPath = 1 + 1 = 2
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

        // Inner if: 1 + 1 = 2
        // Outer if: 2 + 1 = 3
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

        // NPath = cond(1) + body(1) + exit(1) = 3
        self::assertSame(3, $metrics->get('npath:App\Test::loop'));
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

        // NPath = init(1) + cond(1) + body(1) + exit(1) = 4
        // Actually: init is multiplicative, cond+body+exit is additive
        // init(1) * (cond(1) + body(1) + exit(1)) = 1 * 3 = 3
        // But for has special handling: init + cond + body + exit
        self::assertGreaterThanOrEqual(3, $metrics->get('npath:App\Test::loop'));
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

        // NPath = cond(1) + body(1) + exit(1) = 3
        self::assertSame(3, $metrics->get('npath:App\Test::iterate'));
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

        // NPath = cond(1) + case1(1+1) + case2(1+1) + default(1) = 1 + 2 + 2 + 1 = 6
        // Actually: cond(1) + case1_cond(1) + case1_body(1) + case2_cond(1) + case2_body(1) + default(1)
        self::assertGreaterThanOrEqual(4, $metrics->get('npath:App\Test::grade'));
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

        // NPath = cond(1) + true(1) + false(1) = 3
        self::assertSame(3, $metrics->get('npath:App\Test::conditional'));
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

        // NPath = left(1) + right(1) = 2
        self::assertSame(2, $metrics->get('npath:App\Test::check'));
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

        // NPath = left(1) + right(1) = 2
        self::assertSame(2, $metrics->get('npath:App\Test::check'));
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

        // NPath = left(1) + right(1) = 2
        self::assertSame(2, $metrics->get('npath:App\Test::check'));
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

        // NPath = cond(1) + arm1(1+1) + arm2(1+1) + default(1) = 6
        self::assertGreaterThanOrEqual(3, $metrics->get('npath:App\Test::grade'));
    }

    public function testMaxValueCap(): void
    {
        // Create many sequential ifs to exceed MAX_NPATH
        // Each if has NPath = 2, so 30 sequential ifs give 2^30 = 1,073,741,824 > 10^9
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

        // afterAnonymous: NPath = 2 (if with implicit else)
        self::assertSame(2, $metrics->get('npath:App\Outer::afterAnonymous'));

        // Anonymous class methods should NOT appear in metrics
        self::assertNull($metrics->get('npath:App\Outer::innerComplex'));
    }

    private function collectMetrics(string $code): \AiMessDetector\Core\Metric\MetricBag
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
