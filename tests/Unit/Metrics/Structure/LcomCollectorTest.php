<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Structure;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\Structure\LcomClassData;
use AiMessDetector\Metrics\Structure\LcomCollector;
use AiMessDetector\Metrics\Structure\LcomVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(LcomCollector::class)]
#[CoversClass(LcomVisitor::class)]
#[CoversClass(LcomClassData::class)]
final class LcomCollectorTest extends TestCase
{
    private LcomCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new LcomCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('lcom', $this->collector->getName());
    }

    public function testProvides(): void
    {
        $provides = $this->collector->provides();

        self::assertContains('lcom', $provides);
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

        // Empty class has LCOM = 0 (no methods)
        self::assertSame(0, $metrics->get('lcom:App\EmptyClass'));
    }

    public function testClassWithSingleMethod(): void
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

        // Single method = LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\SingleMethod'));
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

        // All methods share $data property = LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\CohesiveClass'));
    }

    public function testTwoDisconnectedGroups(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class DisconnectedClass
{
    private $property1;
    private $property2;

    public function method1a(): void
    {
        $this->property1 = 1;
    }

    public function method1b()
    {
        return $this->property1;
    }

    public function method2a(): void
    {
        $this->property2 = 2;
    }

    public function method2b()
    {
        return $this->property2;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Two groups: (method1a, method1b) and (method2a, method2b) = LCOM 2
        self::assertSame(2, $metrics->get('lcom:App\DisconnectedClass'));
    }

    public function testCompletelyDisconnectedMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class NoSharedProperties
{
    private $a;
    private $b;
    private $c;

    public function methodA()
    {
        return $this->a;
    }

    public function methodB()
    {
        return $this->b;
    }

    public function methodC()
    {
        return $this->c;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Each method uses different property = LCOM 3
        self::assertSame(3, $metrics->get('lcom:App\NoSharedProperties'));
    }

    public function testMethodsWithNoPropertyAccess(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class StatelessClass
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function multiply(int $a, int $b): int
    {
        return $a * $b;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Methods don't access any properties - each is its own component = LCOM 2
        self::assertSame(2, $metrics->get('lcom:App\StatelessClass'));
    }

    public function testPartiallyConnectedGraph(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class PartiallyConnected
{
    private $shared;
    private $unique;

    public function method1()
    {
        return $this->shared;
    }

    public function method2()
    {
        $this->shared = 1;
        return $this->unique;
    }

    public function method3()
    {
        return $this->unique;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // method1 -- method2 (share $shared)
        // method2 -- method3 (share $unique)
        // All connected = LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\PartiallyConnected'));
    }

    public function testInterfaceIsIgnored(): void
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

        // Interfaces are ignored - LCOM is not meaningful for interfaces (no properties, no implementations)
        self::assertNull($metrics->get('lcom:App\MyInterface'));
    }

    public function testTraitIsIgnored(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait MyTrait
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
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Traits are intentionally ignored - LCOM is not meaningful for traits
        // as they are not standalone classes and their cohesion depends on the using class
        self::assertNull($metrics->get('lcom:App\MyTrait'));
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
        $this->config;
        return new class {
            private $inner;
            public function method1() { return $this->inner; }
            public function method2() { $this->inner = 1; }
        };
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Factory has 1 method = LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\Factory'));
        // Anonymous class should not appear
        self::assertNull($metrics->get('lcom:'));
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

        // Both methods share $data = LCOM 1
        self::assertSame(1, $metrics->get('lcom:GlobalClass'));
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

        self::assertSame(1, $metrics->get('lcom:App\First'));
        self::assertSame(2, $metrics->get('lcom:App\Second'));
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
        self::assertNull($metrics->get('lcom:App\First'));
        self::assertSame(1, $metrics->get('lcom:App\Second'));
    }

    public function testGetMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);

        $def = $definitions[0];
        self::assertSame('lcom', $def->name);
        self::assertSame(SymbolLevel::Class_, $def->collectedAt);

        $namespaceStrategies = $def->getStrategiesForLevel(SymbolLevel::Namespace_);
        self::assertContains(AggregationStrategy::Average, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Max, $namespaceStrategies);
    }

    public function testGodClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class GodClass
{
    private $db;
    private $cache;
    private $logger;
    private $mailer;
    private $validator;

    public function findUser() { return $this->db; }
    public function saveUser() { $this->db = 1; }

    public function cacheGet() { return $this->cache; }
    public function cacheSet() { $this->cache = 1; }

    public function log() { $this->logger = 1; }
    public function getLog() { return $this->logger; }

    public function sendEmail() { $this->mailer = 1; }

    public function validate() { return $this->validator; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // 4-5 groups depending on connections
        // db: findUser, saveUser
        // cache: cacheGet, cacheSet
        // logger: log, getLog
        // mailer: sendEmail
        // validator: validate
        self::assertSame(5, $metrics->get('lcom:App\GodClass'));
    }

    public function testMethodCallsConnectMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MethodCallCohesion
{
    public function doWork(): void
    {
        $this->helper();
    }

    public function doOtherWork(): void
    {
        $this->helper();
    }

    private function helper(): void
    {
        // shared helper with no property access
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // doWork -> helper, doOtherWork -> helper => all connected via helper = LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\MethodCallCohesion'));
    }

    public function testMethodCallChainConnectsAllMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MethodCallChain
{
    public function a(): void
    {
        $this->b();
    }

    public function b(): void
    {
        $this->c();
    }

    public function c(): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // a -> b -> c => all in one component = LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\MethodCallChain'));
    }

    public function testMethodCallWithoutSharedProperties(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class DirectMethodCall
{
    public function caller(): void
    {
        $this->callee();
    }

    public function callee(): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // caller calls callee => connected = LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\DirectMethodCall'));
    }

    public function testStaticMethodsExcludedFromLcom(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithStaticMethod
{
    private $data;

    public function getData()
    {
        return $this->data;
    }

    public function setData($value): void
    {
        $this->data = $value;
    }

    public static function create(): self
    {
        return new self();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Static method excluded; getData and setData share $data = LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\WithStaticMethod'));
    }

    public function testStaticMethodDoesNotInflateLcom(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class StaticInflation
{
    private $value;

    public function getValue()
    {
        return $this->value;
    }

    public static function factory(): self
    {
        return new self();
    }

    public static function anotherStatic(): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Only 1 instance method (getValue) => LCOM 1, static methods excluded
        self::assertSame(1, $metrics->get('lcom:App\StaticInflation'));
    }

    public function testSelfAndStaticCallsDoNotCreateEdges(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class StaticCallClass
{
    private $a;
    private $b;

    public function methodA(): void
    {
        $this->a = 1;
        self::staticHelper();
    }

    public function methodB(): void
    {
        $this->b = 1;
        static::anotherStaticHelper();
    }

    public static function staticHelper(): void
    {
    }

    public static function anotherStaticHelper(): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // methodA uses $a, methodB uses $b, self::/static:: do not create edges
        // Static methods excluded from graph, methodA and methodB disconnected = LCOM 2
        self::assertSame(2, $metrics->get('lcom:App\StaticCallClass'));
    }

    public function testOnlyStaticMethodsResultsInZeroLcom(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class AllStatic
{
    public static function a(): void {}
    public static function b(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // All methods are static => 0 instance methods => LCOM 0
        self::assertSame(0, $metrics->get('lcom:App\AllStatic'));
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
        // They don't share properties = LCOM 2
        self::assertSame(2, $metrics->get('lcom:App\DynamicAccess'));
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
