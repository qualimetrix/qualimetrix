<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Structure;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Metrics\Structure\UnusedPrivateClassData;
use Qualimetrix\Metrics\Structure\UnusedPrivateCollector;
use Qualimetrix\Metrics\Structure\UnusedPrivateVisitor;
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

    #[Test]
    public function itReturnsCollectorName(): void
    {
        self::assertSame('unused-private', $this->collector->getName());
    }

    #[Test]
    public function itProvidesExpectedMetricNames(): void
    {
        $provides = $this->collector->provides();

        self::assertContains('unusedPrivate.method', $provides);
        self::assertContains('unusedPrivate.property', $provides);
        self::assertContains('unusedPrivate.constant', $provides);
        self::assertContains('unusedPrivate.total', $provides);
    }

    #[Test]
    public function itReturnsZeroForFullyUsedClass(): void
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

        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\FullyUsed'));
        self::assertSame(0, $metrics->entryCount('unusedPrivate.property:App\FullyUsed'));
        self::assertSame(0, $metrics->entryCount('unusedPrivate.constant:App\FullyUsed'));
        self::assertSame(0, $metrics->get('unusedPrivate.total:App\FullyUsed'));
    }

    #[Test]
    public function itDetectsUnusedPrivateMethod(): void
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

        self::assertSame(1, $metrics->entryCount('unusedPrivate.method:App\WithUnusedMethod'));
        $entries = $metrics->entries('unusedPrivate.method:App\WithUnusedMethod');
        self::assertSame(7, $entries[0]['line']);
        self::assertSame(1, $metrics->get('unusedPrivate.total:App\WithUnusedMethod'));
    }

    #[Test]
    public function itDetectsUnusedPrivateProperty(): void
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

        self::assertSame(1, $metrics->entryCount('unusedPrivate.property:App\WithUnusedProperty'));
        $entries = $metrics->entries('unusedPrivate.property:App\WithUnusedProperty');
        self::assertSame(7, $entries[0]['line']);
    }

    #[Test]
    public function itDetectsUnusedPrivateConstant(): void
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

        self::assertSame(1, $metrics->entryCount('unusedPrivate.constant:App\WithUnusedConstant'));
        $entries = $metrics->entries('unusedPrivate.constant:App\WithUnusedConstant');
        self::assertSame(7, $entries[0]['line']);
    }

    #[Test]
    public function itDetectsMultipleUnusedMembers(): void
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

        self::assertSame(2, $metrics->entryCount('unusedPrivate.method:App\ManyUnused'));
        self::assertSame(2, $metrics->entryCount('unusedPrivate.property:App\ManyUnused'));
        self::assertSame(1, $metrics->entryCount('unusedPrivate.constant:App\ManyUnused'));
        self::assertSame(5, $metrics->get('unusedPrivate.total:App\ManyUnused'));
    }

    #[Test]
    public function itRecognizesStaticMethodUsageViaSelf(): void
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

        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\StaticUsage'));
    }

    #[Test]
    public function itRecognizesStaticMethodUsageViaStatic(): void
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

        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\StaticUsage2'));
    }

    #[Test]
    public function itRecognizesStaticPropertyUsage(): void
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

        self::assertSame(0, $metrics->entryCount('unusedPrivate.property:App\StaticPropUsage'));
    }

    #[Test]
    public function itRecognizesConstantUsageViaStatic(): void
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

        self::assertSame(0, $metrics->entryCount('unusedPrivate.constant:App\ConstUsageViaStatic'));
    }

    #[Test]
    public function itDoesNotFlagMagicMethodsAsUnused(): void
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
        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\WithMagicMethods'));
    }

    #[Test]
    public function itSkipsMethodDetectionWhenMagicCallPresent(): void
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
        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\WithMagicCall'));
    }

    #[Test]
    public function itSkipsMethodDetectionWhenMagicCallStaticPresent(): void
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

        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\WithMagicCallStatic'));
    }

    #[Test]
    public function itSkipsPropertyDetectionWhenMagicGetPresent(): void
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
        self::assertSame(0, $metrics->entryCount('unusedPrivate.property:App\WithMagicGet'));
        // But method detection still works
        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\WithMagicGet'));
    }

    #[Test]
    public function itSkipsPropertyDetectionWhenMagicSetPresent(): void
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

        self::assertSame(0, $metrics->entryCount('unusedPrivate.property:App\WithMagicSet'));
    }

    #[Test]
    public function itContinuesCheckingConstantsWhenMagicMethodsPresent(): void
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
        self::assertSame(1, $metrics->entryCount('unusedPrivate.constant:App\MagicButConstantsStillChecked'));
        // But methods and properties are skipped
        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\MagicButConstantsStillChecked'));
        self::assertSame(0, $metrics->entryCount('unusedPrivate.property:App\MagicButConstantsStillChecked'));
    }

    #[Test]
    public function itDetectsUnusedPromotedConstructorProperty(): void
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
        self::assertSame(1, $metrics->entryCount('unusedPrivate.property:App\WithPromotion'));
    }

    #[Test]
    public function itDoesNotFlagUsedPromotedConstructorProperty(): void
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

        self::assertSame(0, $metrics->entryCount('unusedPrivate.property:App\PromotedUsed'));
    }

    #[Test]
    public function itIsolatesAnonymousClassFromOuter(): void
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
        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\Outer'));
        // Anonymous class should NOT be tracked
        self::assertNull($metrics->get('unusedPrivate.total:'));
    }

    #[Test]
    public function itDoesNotCountAnonymousClassUsageForOuterClass(): void
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
        self::assertSame(1, $metrics->entryCount('unusedPrivate.method:App\Outer2'));
    }

    #[Test]
    public function itIgnoresInterfaces(): void
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

    #[Test]
    public function itIgnoresTraits(): void
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

    #[Test]
    public function itDetectsUnusedPrivateMethodInEnum(): void
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

        self::assertSame(1, $metrics->entryCount('unusedPrivate.method:App\Status'));
    }

    #[Test]
    public function itDoesNotFlagUsedPrivateMethodInEnum(): void
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

        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\Color'));
    }

    #[Test]
    public function itDoesNotFlagPublicOrProtectedMembers(): void
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
        self::assertSame(1, $metrics->entryCount('unusedPrivate.method:App\VisibilityTest'));
        self::assertSame(1, $metrics->entryCount('unusedPrivate.property:App\VisibilityTest'));
    }

    #[Test]
    public function itDoesNotTreatSelfClassAsConstantUsage(): void
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
        self::assertSame(1, $metrics->entryCount('unusedPrivate.constant:App\WithClassConst'));
    }

    #[Test]
    public function itTracksMultipleClassesIndependently(): void
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

        self::assertSame(1, $metrics->entryCount('unusedPrivate.method:App\First'));
        self::assertSame(0, $metrics->get('unusedPrivate.total:App\Second'));
    }

    #[Test]
    public function itHandlesClassWithoutNamespace(): void
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

        self::assertSame(1, $metrics->entryCount('unusedPrivate.method:GlobalClass'));
    }

    #[Test]
    public function itDetectsUnusedPropertyAmongMultipleOnOneLine(): void
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
        self::assertSame(1, $metrics->entryCount('unusedPrivate.property:App\MultiProp'));
    }

    #[Test]
    public function itDetectsUnusedConstantAmongMultipleOnOneLine(): void
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

        self::assertSame(1, $metrics->entryCount('unusedPrivate.constant:App\MultiConst'));
    }

    #[Test]
    public function itClearsStateOnReset(): void
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

    #[Test]
    public function itReturnsClassesWithComputedMetrics(): void
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
        self::assertSame(1, $class->metrics->entryCount('unusedPrivate.method'));
        self::assertSame(1, $class->metrics->entryCount('unusedPrivate.property'));
        self::assertSame(0, $class->metrics->entryCount('unusedPrivate.constant'));
        self::assertSame(2, $class->metrics->get('unusedPrivate.total'));
    }

    #[Test]
    public function itReturnsZeroMetricsForEmptyClass(): void
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

    #[Test]
    public function itRecognizesPropertyUsedInStaticContext(): void
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

        self::assertSame(0, $metrics->entryCount('unusedPrivate.property:App\StaticPropAccess'));
    }

    #[Test]
    public function itRecognizesPrivateMethodCalledFromSameFileTrait(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyClass
{
    use MyTrait;

    private function helper(): void {}

    public function doWork(): void {}
}

trait MyTrait
{
    public function traitWork(): void
    {
        $this->helper();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // helper() is called from the trait, so it should NOT be flagged
        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\MyClass'));
    }

    #[Test]
    public function itRecognizesPrivatePropertyReadBySameFileTrait(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyClass
{
    use MyTrait;

    private string $secret = 'value';
}

trait MyTrait
{
    public function getSecret(): string
    {
        return $this->secret;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->entryCount('unusedPrivate.property:App\MyClass'));
    }

    #[Test]
    public function itRecognizesPrivateConstantReadBySameFileTrait(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyClass
{
    use MyTrait;

    private const SECRET = 42;
}

trait MyTrait
{
    public function getSecret(): int
    {
        return self::SECRET;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->entryCount('unusedPrivate.constant:App\MyClass'));
    }

    #[Test]
    public function itHandlesSameFileMultipleTraitsCorrectly(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyClass
{
    use TraitA, TraitB;

    private function helperA(): void {}
    private function helperB(): void {}
    private function unused(): void {}
}

trait TraitA
{
    public function workA(): void
    {
        $this->helperA();
    }
}

trait TraitB
{
    public function workB(): void
    {
        $this->helperB();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // helperA and helperB are used via traits, only "unused" should be flagged
        self::assertSame(1, $metrics->entryCount('unusedPrivate.method:App\MyClass'));
        $entries = $metrics->entries('unusedPrivate.method:App\MyClass');
        self::assertSame('unused', $entries[0]['name']);
    }

    #[Test]
    public function itStillFlagsPrivateMemberWhenTraitIsExternal(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyClass
{
    use \Some\External\Trait_;

    private function helper(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // External trait cannot be resolved, so helper is still flagged
        self::assertSame(1, $metrics->entryCount('unusedPrivate.method:App\MyClass'));
    }

    #[Test]
    public function itResolvesNestedSameFileTraitCalls(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyClass
{
    use TraitA;

    private function deepHelper(): void {}
}

trait TraitA
{
    use TraitB;

    public function workA(): void {}
}

trait TraitB
{
    public function workB(): void
    {
        $this->deepHelper();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // deepHelper is called from TraitB which is used by TraitA which is used by MyClass
        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\MyClass'));
    }

    #[Test]
    public function itRecognizesStaticUsageFromSameFileTrait(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyClass
{
    use MyTrait;

    private static function staticHelper(): void {}
    private static string $staticProp = '';
}

trait MyTrait
{
    public function work(): void
    {
        self::staticHelper();
        $x = static::$staticProp;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\MyClass'));
        self::assertSame(0, $metrics->entryCount('unusedPrivate.property:App\MyClass'));
    }

    #[Test]
    public function itDoesNotTrackTraitDefinitionItself(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait MyTrait
{
    private function traitPrivate(): void {}
}

class MyClass
{
    use MyTrait;

    private function classHelper(): void {}

    public function work(): void
    {
        $this->classHelper();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Trait itself should not be tracked (no unused metrics for it)
        self::assertNull($metrics->get('unusedPrivate.total:App\MyTrait'));
        // Class helper is used directly, not flagged
        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:App\MyClass'));
    }

    #[Test]
    public function itHandlesSameFileTraitWithoutNamespace(): void
    {
        $code = <<<'PHP'
<?php

class MyClass
{
    use MyTrait;

    private function helper(): void {}
}

trait MyTrait
{
    public function work(): void
    {
        $this->helper();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->entryCount('unusedPrivate.method:MyClass'));
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
