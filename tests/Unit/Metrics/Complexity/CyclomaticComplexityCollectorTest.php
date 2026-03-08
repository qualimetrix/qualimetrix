<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Complexity;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\Complexity\CyclomaticComplexityCollector;
use AiMessDetector\Metrics\Complexity\CyclomaticComplexityVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(CyclomaticComplexityCollector::class)]
#[CoversClass(CyclomaticComplexityVisitor::class)]
final class CyclomaticComplexityCollectorTest extends TestCase
{
    private CyclomaticComplexityCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new CyclomaticComplexityCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('cyclomatic-complexity', $this->collector->getName());
    }

    public function testProvides(): void
    {
        self::assertSame(['ccn'], $this->collector->provides());
    }

    public function testSimpleMethodHasComplexityOne(): void
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

        self::assertSame(1, $metrics->get('ccn:App\Service\Calculator::add'));
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

        // CC = 1 (base) + 1 (if) = 2
        self::assertSame(2, $metrics->get('ccn:App\Test::check'));
    }

    public function testMethodWithIfElseIf(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Test
{
    public function grade(int $score): string
    {
        if ($score >= 90) {
            return 'A';
        } elseif ($score >= 80) {
            return 'B';
        } elseif ($score >= 70) {
            return 'C';
        }
        return 'F';
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // CC = 1 (base) + 1 (if) + 2 (elseif) = 4
        self::assertSame(4, $metrics->get('ccn:App\Test::grade'));
    }

    public function testMethodWithLoops(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class LoopTest
{
    public function process(array $items): void
    {
        for ($i = 0; $i < 10; $i++) {
            // for loop
        }

        foreach ($items as $item) {
            // foreach loop
        }

        while (true) {
            break;
        }

        do {
            break;
        } while (false);
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // CC = 1 (base) + 1 (for) + 1 (foreach) + 1 (while) + 1 (do-while) = 5
        self::assertSame(5, $metrics->get('ccn:App\LoopTest::process'));
    }

    public function testMethodWithSwitchCase(): void
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

        // CC = 1 (base) + 3 (cases, default doesn't count) = 4
        self::assertSame(4, $metrics->get('ccn:App\SwitchTest::dayName'));
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

        // CC = 1 (base) + 2 (catches) = 3
        self::assertSame(3, $metrics->get('ccn:App\ExceptionTest::risky'));
    }

    public function testMethodWithBooleanOperators(): void
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

        return $a > $b and $c > 0;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // CC = 1 (base) + 1 (if) + 1 (&&) + 1 (if) + 2 (||) + 1 (and) = 7
        self::assertSame(7, $metrics->get('ccn:App\BooleanTest::check'));
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

        // CC = 1 (base) + 1 (ternary) = 2
        self::assertSame(2, $metrics->get('ccn:App\TernaryTest::max'));
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

        // CC = 1 (base) + 1 (??) = 2
        self::assertSame(2, $metrics->get('ccn:App\NullCoalescingTest::getName'));
    }

    public function testMethodWithNullsafeOperator(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class NullsafeTest
{
    public function getLength(?object $obj): ?int
    {
        return $obj?->name?->length;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // CC = 1 (base) + 2 (?->) = 3
        self::assertSame(3, $metrics->get('ccn:App\NullsafeTest::getLength'));
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

        // CC = 1 (base) + 1 (if) = 2
        self::assertSame(2, $metrics->get('ccn:App\Utils\validate'));
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

        // CC = 1 (base) + 1 (if) = 2
        self::assertSame(2, $metrics->get('ccn:globalHelper'));
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

        // Method itself: CC = 1
        self::assertSame(1, $metrics->get('ccn:App\ClosureTest::withClosure'));

        // Closure: CC = 1 (base) + 1 (if) = 2
        self::assertSame(2, $metrics->get('ccn:App\ClosureTest::{closure#1}'));
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

        self::assertSame(1, $metrics->get('ccn:App\MultiMethod::simple'));
        self::assertSame(2, $metrics->get('ccn:App\MultiMethod::withIf'));
        self::assertSame(2, $metrics->get('ccn:App\MultiMethod::withLoop'));
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
        self::assertNull($metrics->get('ccn:App\First::method'));
        self::assertSame(1, $metrics->get('ccn:App\Second::otherMethod'));
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

        if (empty($items)) {
            return $result;
        }

        foreach ($items as $key => $item) {
            if ($validate && !$this->isValid($item)) {
                continue;
            }

            try {
                $value = $item['value'] ?? 0;

                if ($value > 100 || $value < 0) {
                    throw new \InvalidArgumentException('Invalid value');
                }

                $result[$key] = $value > 50 ? 'high' : 'low';
            } catch (\InvalidArgumentException $e) {
                $result[$key] = 'error';
            } catch (\RuntimeException $e) {
                $result[$key] = 'runtime_error';
            }
        }

        return $result;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // CC = 1 (base) + 1 (if empty) + 1 (foreach) + 1 (if validate) + 1 (&&)
        //    + 1 (??) + 1 (if value) + 1 (||) + 1 (ternary) + 2 (catches) = 11
        self::assertSame(11, $metrics->get('ccn:App\Service\ComplexService::process'));
    }

    public function testTraitMethod(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Traits;

trait LoggableTrait
{
    public function log(string $message, bool $debug = false): void
    {
        if ($debug) {
            echo $message;
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // CC = 1 (base) + 1 (if) = 2
        self::assertSame(2, $metrics->get('ccn:App\Traits\LoggableTrait::log'));
    }

    public function testInterfaceMethodsAreIgnored(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Contracts;

interface ServiceInterface
{
    public function execute(): void;
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Interface methods have no body, so CC = 1
        self::assertSame(1, $metrics->get('ccn:App\Contracts\ServiceInterface::execute'));
    }

    public function testEnumMethod(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Enums;

enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // CC = 1 (base)
        self::assertSame(1, $metrics->get('ccn:App\Enums\Status::isActive'));
    }

    public function testGetMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);

        $ccnDefinition = $definitions[0];
        self::assertSame('ccn', $ccnDefinition->name);
        self::assertSame(SymbolLevel::Method, $ccnDefinition->collectedAt);

        // Check Class_ level aggregations
        $classStrategies = $ccnDefinition->getStrategiesForLevel(SymbolLevel::Class_);
        self::assertCount(3, $classStrategies);
        self::assertContains(AggregationStrategy::Sum, $classStrategies);
        self::assertContains(AggregationStrategy::Average, $classStrategies);
        self::assertContains(AggregationStrategy::Max, $classStrategies);

        // Check Namespace_ level aggregations
        $namespaceStrategies = $ccnDefinition->getStrategiesForLevel(SymbolLevel::Namespace_);
        self::assertCount(3, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Sum, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Average, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Max, $namespaceStrategies);

        // Check Project level aggregations
        $projectStrategies = $ccnDefinition->getStrategiesForLevel(SymbolLevel::Project);
        self::assertCount(3, $projectStrategies);
        self::assertContains(AggregationStrategy::Sum, $projectStrategies);
        self::assertContains(AggregationStrategy::Average, $projectStrategies);
        self::assertContains(AggregationStrategy::Max, $projectStrategies);
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
                        while (true) {
                            break;
                        }
                    }
                }
            }
        };
    }

    public function afterAnonymous(): void
    {
        if (true) {
            // one decision point
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // simple: CC = 1 (base)
        self::assertSame(1, $metrics->get('ccn:App\Outer::simple'));

        // factory: CC = 1 (base) — anonymous class complexity should NOT leak
        self::assertSame(1, $metrics->get('ccn:App\Outer::factory'));

        // afterAnonymous: CC = 1 (base) + 1 (if) = 2
        self::assertSame(2, $metrics->get('ccn:App\Outer::afterAnonymous'));

        // Anonymous class methods should NOT appear in metrics
        self::assertNull($metrics->get('ccn:App\Outer::innerComplex'));
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
