<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Structure;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\Structure\UnusedPrivateClassData;
use AiMessDetector\Metrics\Structure\UnusedPrivateCollector;
use AiMessDetector\Metrics\Structure\UnusedPrivateVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(UnusedPrivateCollector::class)]
#[CoversClass(UnusedPrivateVisitor::class)]
#[CoversClass(UnusedPrivateClassData::class)]
final class UnusedPrivateCollectorTest extends TestCase
{
    private UnusedPrivateCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new UnusedPrivateCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('unused-private', $this->collector->getName());
    }

    public function testProvidesMetrics(): void
    {
        $provides = $this->collector->provides();

        self::assertContains('unusedPrivate.method.count', $provides);
        self::assertContains('unusedPrivate.property.count', $provides);
        self::assertContains('unusedPrivate.constant.count', $provides);
        self::assertContains('unusedPrivate.total', $provides);
    }

    public function testNoUnusedMembers(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class FullyUsed
{
    private string $name;
    private const MAX = 100;

    public function getName(): string
    {
        return $this->name;
    }

    public function getMax(): int
    {
        return self::MAX;
    }

    private function helper(): void
    {
    }

    public function doWork(): void
    {
        $this->helper();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unusedPrivate.method.count:App\FullyUsed'));
        self::assertSame(0, $metrics->get('unusedPrivate.property.count:App\FullyUsed'));
        self::assertSame(0, $metrics->get('unusedPrivate.constant.count:App\FullyUsed'));
        self::assertSame(0, $metrics->get('unusedPrivate.total:App\FullyUsed'));
    }

    public function testUnusedPrivateMethod(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithUnusedMethod
{
    private function unusedHelper(): void
    {
    }

    public function doWork(): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('unusedPrivate.method.count:App\WithUnusedMethod'));
        self::assertSame(7, $metrics->get('unusedPrivate.method.line.0:App\WithUnusedMethod'));
        self::assertSame(1, $metrics->get('unusedPrivate.total:App\WithUnusedMethod'));
    }

    public function testUnusedPrivateProperty(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithUnusedProperty
{
    private string $unused;
    private string $used;

    public function getUsed(): string
    {
        return $this->used;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('unusedPrivate.property.count:App\WithUnusedProperty'));
        self::assertSame(7, $metrics->get('unusedPrivate.property.line.0:App\WithUnusedProperty'));
    }

    public function testUnusedPrivateConstant(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithUnusedConstant
{
    private const UNUSED = 'unused';
    private const USED = 'used';

    public function getValue(): string
    {
        return self::USED;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('unusedPrivate.constant.count:App\WithUnusedConstant'));
        self::assertSame(7, $metrics->get('unusedPrivate.constant.line.0:App\WithUnusedConstant'));
    }

    public function testMultipleUnusedMembers(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class ManyUnused
{
    private string $unusedProp1;
    private string $unusedProp2;
    private const UNUSED_CONST = 1;

    private function unusedMethod1(): void {}
    private function unusedMethod2(): void {}

    public function doNothing(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('unusedPrivate.method.count:App\ManyUnused'));
        self::assertSame(2, $metrics->get('unusedPrivate.property.count:App\ManyUnused'));
        self::assertSame(1, $metrics->get('unusedPrivate.constant.count:App\ManyUnused'));
        self::assertSame(5, $metrics->get('unusedPrivate.total:App\ManyUnused'));
    }

    public function testStaticMethodUsageViaSelf(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class StaticUsage
{
    private static function helper(): void {}

    public function doWork(): void
    {
        self::helper();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unusedPrivate.method.count:App\StaticUsage'));
    }

    public function testStaticMethodUsageViaStatic(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class StaticUsage2
{
    private static function helper(): void {}

    public function doWork(): void
    {
        static::helper();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unusedPrivate.method.count:App\StaticUsage2'));
    }

    public function testStaticPropertyUsage(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class StaticPropUsage
{
    private static string $instance = '';

    public function getInstance(): string
    {
        return self::$instance;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unusedPrivate.property.count:App\StaticPropUsage'));
    }

    public function testConstantUsageViaStatic(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class ConstUsageViaStatic
{
    private const VALUE = 42;

    public function get(): int
    {
        return static::VALUE;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unusedPrivate.constant.count:App\ConstUsageViaStatic'));
    }

    public function testMagicMethodsNotFlaggedAsUnused(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithMagicMethods
{
    private function __clone(): void {}
    private function __debugInfo(): array { return []; }

    public function doWork(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Magic methods should NOT be flagged as unused
        self::assertSame(0, $metrics->get('unusedPrivate.method.count:App\WithMagicMethods'));
    }

    public function testMagicCallSkipsMethodDetection(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithMagicCall
{
    private function __call(string $name, array $args): mixed { return null; }
    private function secretMethod(): void {}

    public function doWork(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // __call means any method can be called dynamically, so skip method detection
        self::assertSame(0, $metrics->get('unusedPrivate.method.count:App\WithMagicCall'));
    }

    public function testMagicCallStaticSkipsMethodDetection(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithMagicCallStatic
{
    private static function __callStatic(string $name, array $args): mixed { return null; }
    private function unused(): void {}

    public function doWork(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unusedPrivate.method.count:App\WithMagicCallStatic'));
    }

    public function testMagicGetSkipsPropertyDetection(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithMagicGet
{
    private function __get(string $name): mixed { return null; }
    private string $unused = '';

    public function doWork(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // __get means properties can be accessed dynamically
        self::assertSame(0, $metrics->get('unusedPrivate.property.count:App\WithMagicGet'));
        // But method detection still works
        self::assertSame(0, $metrics->get('unusedPrivate.method.count:App\WithMagicGet'));
    }

    public function testMagicSetSkipsPropertyDetection(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithMagicSet
{
    private function __set(string $name, mixed $value): void {}
    private string $unused = '';

    public function doWork(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unusedPrivate.property.count:App\WithMagicSet'));
    }

    public function testMagicMethodDoesNotAffectConstants(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MagicButConstantsStillChecked
{
    private function __call(string $name, array $args): mixed { return null; }
    private function __get(string $name): mixed { return null; }
    private const UNUSED_CONST = 42;

    public function doWork(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Magic methods do NOT affect constant detection
        self::assertSame(1, $metrics->get('unusedPrivate.constant.count:App\MagicButConstantsStillChecked'));
        // But methods and properties are skipped
        self::assertSame(0, $metrics->get('unusedPrivate.method.count:App\MagicButConstantsStillChecked'));
        self::assertSame(0, $metrics->get('unusedPrivate.property.count:App\MagicButConstantsStillChecked'));
    }

    public function testConstructorPromotedPropertyUnused(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithPromotion
{
    public function __construct(
        private string $unused,
        private string $used,
    ) {}

    public function getUsed(): string
    {
        return $this->used;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // $unused is promoted but never read anywhere
        self::assertSame(1, $metrics->get('unusedPrivate.property.count:App\WithPromotion'));
    }

    public function testConstructorPromotedPropertyUsed(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class PromotedUsed
{
    public function __construct(
        private string $name,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unusedPrivate.property.count:App\PromotedUsed'));
    }

    public function testAnonymousClassIsolation(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Outer
{
    private function outerHelper(): void {}

    public function create(): object
    {
        $this->outerHelper();

        return new class {
            private function innerUnused(): void {}

            public function doWork(): void {}
        };
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Outer: outerHelper is used -> no unused
        self::assertSame(0, $metrics->get('unusedPrivate.method.count:App\Outer'));
        // Anonymous class should NOT be tracked
        self::assertNull($metrics->get('unusedPrivate.total:'));
    }

    public function testAnonymousClassUsageDoesNotCountForOuter(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Outer2
{
    private function outerHelper(): void {}

    public function create(): object
    {
        return new class {
            private function innerHelper(): void {}

            public function doWork(): void
            {
                // This $this->outerHelper() is actually the inner class's scope
                $this->innerHelper();
            }
        };
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // outerHelper is never called from Outer2's own scope
        self::assertSame(1, $metrics->get('unusedPrivate.method.count:App\Outer2'));
    }

    public function testInterfaceIgnored(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

interface MyInterface
{
    public function doWork(): void;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertNull($metrics->get('unusedPrivate.total:App\MyInterface'));
    }

    public function testTraitIgnored(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait MyTrait
{
    private function traitMethod(): void {}
    private string $traitProp = '';
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertNull($metrics->get('unusedPrivate.total:App\MyTrait'));
    }

    public function testEnumWithPrivateMethod(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    private function unused(): string
    {
        return 'test';
    }

    public function label(): string
    {
        return $this->name;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('unusedPrivate.method.count:App\Status'));
    }

    public function testEnumPrivateMethodUsed(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

enum Color
{
    case Red;
    case Blue;

    private function helper(): string
    {
        return 'test';
    }

    public function label(): string
    {
        return $this->helper();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unusedPrivate.method.count:App\Color'));
    }

    public function testPublicAndProtectedNotFlagged(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class VisibilityTest
{
    public string $publicProp = '';
    protected string $protectedProp = '';
    private string $privateProp = '';

    public function publicMethod(): void {}
    protected function protectedMethod(): void {}
    private function privateMethod(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Only private members should be flagged
        self::assertSame(1, $metrics->get('unusedPrivate.method.count:App\VisibilityTest'));
        self::assertSame(1, $metrics->get('unusedPrivate.property.count:App\VisibilityTest'));
    }

    public function testSelfConstClassNotFlagged(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithClassConst
{
    private const VALUE = 42;

    public function getClass(): string
    {
        // self::class should NOT be treated as constant usage
        return self::class;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // VALUE is unused (self::class is not a constant reference)
        self::assertSame(1, $metrics->get('unusedPrivate.constant.count:App\WithClassConst'));
    }

    public function testMultipleClassesInOneFile(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class First
{
    private function unused(): void {}
    public function doWork(): void {}
}

class Second
{
    private string $used;

    public function get(): string
    {
        return $this->used;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('unusedPrivate.method.count:App\First'));
        self::assertSame(0, $metrics->get('unusedPrivate.total:App\Second'));
    }

    public function testClassWithoutNamespace(): void
    {
        $code = <<<'PHP'
<?php

class GlobalClass
{
    private function unused(): void {}
    public function doWork(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('unusedPrivate.method.count:GlobalClass'));
    }

    public function testMultiplePropertiesOnOneLine(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MultiProp
{
    private string $a, $b;

    public function useA(): string
    {
        return $this->a;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // $b is unused, $a is used
        self::assertSame(1, $metrics->get('unusedPrivate.property.count:App\MultiProp'));
    }

    public function testMultipleConstantsOnOneLine(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MultiConst
{
    private const A = 1, B = 2;

    public function useA(): int
    {
        return self::A;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('unusedPrivate.constant.count:App\MultiConst'));
    }

    public function testReset(): void
    {
        $code1 = <<<'PHP'
<?php

namespace App;

class First
{
    private function unused(): void {}
}
PHP;

        $code2 = <<<'PHP'
<?php

namespace App;

class Second
{
    private function unused(): void {}
}
PHP;

        $this->collectMetrics($code1);
        $this->collector->reset();
        $metrics = $this->collectMetrics($code2);

        self::assertNull($metrics->get('unusedPrivate.total:App\First'));
        self::assertSame(1, $metrics->get('unusedPrivate.total:App\Second'));
    }

    public function testGetClassesWithMetrics(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyClass
{
    private function unused(): void {}
    private string $unusedProp;

    public function doWork(): void {}
}
PHP;

        $this->parseAndTraverse($code);

        $classMetrics = $this->collector->getClassesWithMetrics();

        self::assertCount(1, $classMetrics);

        $class = $classMetrics[0];
        self::assertSame('App', $class->namespace);
        self::assertSame('MyClass', $class->class);

        // Metrics without FQN prefix (ClassWithMetrics pattern)
        self::assertSame(1, $class->metrics->get('unusedPrivate.method.count'));
        self::assertSame(1, $class->metrics->get('unusedPrivate.property.count'));
        self::assertSame(0, $class->metrics->get('unusedPrivate.constant.count'));
        self::assertSame(2, $class->metrics->get('unusedPrivate.total'));
    }

    public function testEmptyClassHasZeroMetrics(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class EmptyClass
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unusedPrivate.total:App\EmptyClass'));
    }

    public function testPropertyUsedInStaticContext(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class StaticPropAccess
{
    private static string $value = '';

    public function getValue(): string
    {
        return static::$value;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unusedPrivate.property.count:App\StaticPropAccess'));
    }

    private function collectMetrics(string $code): MetricBag
    {
        $this->parseAndTraverse($code);

        return $this->collector->collect(new SplFileInfo(__FILE__), []);
    }

    private function parseAndTraverse(string $code): void
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);
    }
}
