<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Structure;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\Structure\TccLccClassData;
use AiMessDetector\Metrics\Structure\TccLccCollector;
use AiMessDetector\Metrics\Structure\TccLccVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(TccLccCollector::class)]
#[CoversClass(TccLccVisitor::class)]
#[CoversClass(TccLccClassData::class)]
final class TccLccCollectorTest extends TestCase
{
    private TccLccCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new TccLccCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('tcc_lcc', $this->collector->getName());
    }

    public function testProvides(): void
    {
        $provides = $this->collector->provides();

        self::assertCount(2, $provides);
        self::assertContains('tcc', $provides);
        self::assertContains('lcc', $provides);
    }

    public function testEmptyClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class EmptyClass
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Empty class has no methods = TCC/LCC = 1.0
        self::assertSame(1.0, $metrics->get('tcc:App\EmptyClass'));
        self::assertSame(1.0, $metrics->get('lcc:App\EmptyClass'));
    }

    public function testClassWithSinglePublicMethod(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class SingleMethod
{
    private $value;

    public function getValue(): int
    {
        return $this->value;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Single method = perfect cohesion
        self::assertSame(1.0, $metrics->get('tcc:App\SingleMethod'));
        self::assertSame(1.0, $metrics->get('lcc:App\SingleMethod'));
    }

    public function testPerfectlyCohesiveClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class CohesiveClass
{
    private $data;

    public function setData($value): void
    {
        $this->data = $value;
    }

    public function getData()
    {
        return $this->data;
    }

    public function processData()
    {
        return strtoupper($this->data);
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // All methods share $data property
        // TCC = LCC = 1.0
        self::assertSame(1.0, $metrics->get('tcc:App\CohesiveClass'));
        self::assertSame(1.0, $metrics->get('lcc:App\CohesiveClass'));
    }

    public function testNoCohesion(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class GodClass
{
    private $users;
    private $orders;
    private $payments;

    public function findUser(): void
    {
        $this->users = [];
    }

    public function createOrder(): void
    {
        $this->orders = [];
    }

    public function processPayment(): void
    {
        $this->payments = [];
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // No methods share properties
        // TCC = LCC = 0.0
        self::assertSame(0.0, $metrics->get('tcc:App\GodClass'));
        self::assertSame(0.0, $metrics->get('lcc:App\GodClass'));
    }

    public function testTransitiveClosure(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class TransitiveExample
{
    private $a;
    private $b;

    public function method1()
    {
        return $this->a;
    }

    public function method2()
    {
        $this->a = 1;
        return $this->b;
    }

    public function method3()
    {
        return $this->b;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Direct: m1-m2 (via $a), m2-m3 (via $b)
        // TCC = 2/3 ≈ 0.667
        self::assertSame(0.667, $metrics->get('tcc:App\TransitiveExample'));

        // Transitive: m1 reaches m3 via m2
        // LCC = 3/3 = 1.0
        self::assertSame(1.0, $metrics->get('lcc:App\TransitiveExample'));
    }

    public function testIgnoresPrivateMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithPrivateMethods
{
    private $shared;

    public function publicMethod1()
    {
        return $this->shared;
    }

    private function privateMethod()
    {
        return $this->shared;
    }

    public function publicMethod2()
    {
        return $this->shared;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Only public methods counted: publicMethod1, publicMethod2
        // Both share $shared
        // TCC = LCC = 1.0
        self::assertSame(1.0, $metrics->get('tcc:App\WithPrivateMethods'));
        self::assertSame(1.0, $metrics->get('lcc:App\WithPrivateMethods'));
    }

    public function testIgnoresProtectedMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithProtectedMethods
{
    private $prop1;
    private $prop2;

    public function publicMethod1()
    {
        return $this->prop1;
    }

    protected function protectedMethod()
    {
        return $this->prop1;
    }

    public function publicMethod2()
    {
        return $this->prop2;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Only public methods counted: publicMethod1, publicMethod2
        // They use different properties
        // TCC = LCC = 0.0
        self::assertSame(0.0, $metrics->get('tcc:App\WithProtectedMethods'));
        self::assertSame(0.0, $metrics->get('lcc:App\WithProtectedMethods'));
    }

    public function testIgnoresAbstractMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

abstract class AbstractClass
{
    private $shared;

    public function concreteMethod1()
    {
        return $this->shared;
    }

    abstract public function abstractMethod();

    public function concreteMethod2()
    {
        return $this->shared;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Only concrete public methods counted
        // Both share $shared
        // TCC = LCC = 1.0
        self::assertSame(1.0, $metrics->get('tcc:App\AbstractClass'));
        self::assertSame(1.0, $metrics->get('lcc:App\AbstractClass'));
    }

    public function testInterface(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

interface MyInterface
{
    public function method1(): void;
    public function method2(): void;
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Interfaces are skipped entirely — cohesion metrics are not applicable
        self::assertNull($metrics->get('tcc:App\MyInterface'));
        self::assertNull($metrics->get('lcc:App\MyInterface'));
    }

    public function testAnonymousClassIgnored(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Factory
{
    private $config;

    public function create(): object
    {
        $this->config = 1;
        return new class {
            private $inner;
            public function method1() { return $this->inner; }
            public function method2() { $this->inner = 1; }
        };
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Factory has 1 method = TCC/LCC = 1.0
        self::assertSame(1.0, $metrics->get('tcc:App\Factory'));
        self::assertSame(1.0, $metrics->get('lcc:App\Factory'));
        // Anonymous class should not appear
        self::assertNull($metrics->get('tcc:'));
        self::assertNull($metrics->get('lcc:'));
    }

    public function testClassWithoutNamespace(): void
    {
        $code = <<<'PHP'
<?php

class GlobalClass
{
    private $data;

    public function method1() { return $this->data; }
    public function method2() { $this->data = 1; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Both methods share $data
        self::assertSame(1.0, $metrics->get('tcc:GlobalClass'));
        self::assertSame(1.0, $metrics->get('lcc:GlobalClass'));
    }

    public function testMultipleClasses(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class First
{
    private $a;
    public function method() { return $this->a; }
}

class Second
{
    private $x;
    private $y;
    public function methodX() { return $this->x; }
    public function methodY() { return $this->y; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // First: single method = 1.0
        self::assertSame(1.0, $metrics->get('tcc:App\First'));
        self::assertSame(1.0, $metrics->get('lcc:App\First'));

        // Second: two methods, no shared properties = 0.0
        self::assertSame(0.0, $metrics->get('tcc:App\Second'));
        self::assertSame(0.0, $metrics->get('lcc:App\Second'));
    }

    public function testReset(): void
    {
        $code1 = <<<'PHP'
<?php

namespace App;

class First
{
    public function a(): void {}
}
PHP;

        $code2 = <<<'PHP'
<?php

namespace App;

class Second
{
    public function b(): void {}
}
PHP;

        // Collect first file
        $this->collectMetrics($code1);

        // Reset
        $this->collector->reset();

        // Collect second file
        $metrics = $this->collectMetrics($code2);

        // Should only contain metrics from second file
        self::assertNull($metrics->get('tcc:App\First'));
        self::assertNull($metrics->get('lcc:App\First'));
        self::assertSame(1.0, $metrics->get('tcc:App\Second'));
        self::assertSame(1.0, $metrics->get('lcc:App\Second'));
    }

    public function testGetMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(2, $definitions);

        // Check TCC definition
        $tccDef = $definitions[0];
        self::assertSame('tcc', $tccDef->name);
        self::assertSame(SymbolLevel::Class_, $tccDef->collectedAt);

        $namespaceStrategies = $tccDef->getStrategiesForLevel(SymbolLevel::Namespace_);
        self::assertContains(AggregationStrategy::Average, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Min, $namespaceStrategies);

        // Check LCC definition
        $lccDef = $definitions[1];
        self::assertSame('lcc', $lccDef->name);
        self::assertSame(SymbolLevel::Class_, $lccDef->collectedAt);
    }

    public function testDynamicPropertyAccessIgnored(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class DynamicAccess
{
    private $static;

    public function method1()
    {
        return $this->static;
    }

    public function method2($prop)
    {
        return $this->$prop; // Dynamic access - should be ignored
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // method1 uses $static, method2 has dynamic access (ignored)
        // They don't share properties = TCC/LCC = 0.0
        self::assertSame(0.0, $metrics->get('tcc:App\DynamicAccess'));
        self::assertSame(0.0, $metrics->get('lcc:App\DynamicAccess'));
    }

    public function testRoundingToThreeDecimals(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class RoundingTest
{
    private $a;
    private $b;

    public function m1() { return $this->a; }
    public function m2() { $this->a = 1; return $this->b; }
    public function m3() { return $this->b; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // TCC = 2/3 = 0.6666... should be rounded to 0.667
        self::assertSame(0.667, $metrics->get('tcc:App\RoundingTest'));
    }

    public function testRealWorldRectangleExample(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Rectangle
{
    private float $width;
    private float $height;

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    public function getArea(): float
    {
        return $this->width * $this->height;
    }

    public function getPerimeter(): float
    {
        return 2 * ($this->width + $this->height);
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Most pairs share properties, but getWidth-getHeight don't
        // TCC ≈ 0.833
        self::assertSame(0.833, $metrics->get('tcc:App\Rectangle'));
        // All reachable via transitive closure
        // LCC = 1.0
        self::assertSame(1.0, $metrics->get('lcc:App\Rectangle'));
    }

    public function testGetClassesWithMetrics(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class TestClass
{
    private $prop;

    public function method1()
    {
        return $this->prop;
    }

    public function method2()
    {
        $this->prop = 1;
    }
}
PHP;

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        $this->collector->collect(new SplFileInfo(__FILE__), $ast);

        $classes = $this->collector->getClassesWithMetrics();

        self::assertCount(1, $classes);
        $class = $classes[0];

        self::assertSame('App', $class->namespace);
        self::assertSame('TestClass', $class->class);
        self::assertSame(1.0, $class->metrics->get('tcc'));
        self::assertSame(1.0, $class->metrics->get('lcc'));
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
}
