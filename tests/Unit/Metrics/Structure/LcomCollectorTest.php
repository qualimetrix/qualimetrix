<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Structure;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\CollectorConfigHolder;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\Structure\LcomClassData;
use Qualimetrix\Metrics\Structure\LcomCollector;
use Qualimetrix\Metrics\Structure\LcomVisitor;
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
        self::assertContains(AggregationStrategy::Percentile95, $namespaceStrategies);

        $projectStrategies = $def->getStrategiesForLevel(SymbolLevel::Project);
        self::assertContains(AggregationStrategy::Average, $projectStrategies);
        self::assertContains(AggregationStrategy::Max, $projectStrategies);
        self::assertContains(AggregationStrategy::Percentile95, $projectStrategies);
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

    public function testAbstractMethodsExcludedFromLcom(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

abstract class AbstractClass
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

    abstract public function process(): void;
    abstract public function validate(): bool;
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Abstract methods have no body and no property access — they should NOT
        // be added to the LCOM graph. Only getData and setData counted, both share $data => LCOM 1.
        // Without fix: abstract methods would be disconnected nodes => LCOM 3.
        self::assertSame(1, $metrics->get('lcom:App\AbstractClass'));
    }

    public function testAbstractMethodsDoNotInflateLcom(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

abstract class ManyAbstract
{
    private $value;

    public function getValue()
    {
        return $this->value;
    }

    abstract public function a(): void;
    abstract public function b(): void;
    abstract public function c(): void;
    abstract public function d(): void;
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Only 1 concrete method (getValue) => LCOM 1
        // Without fix: 5 methods, 4 abstract disconnected => LCOM 5
        self::assertSame(1, $metrics->get('lcom:App\ManyAbstract'));
    }

    public function testEnumIsIgnored(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Enums cannot have instance properties, so LCOM (method-property cohesion)
        // is meaningless for them. They should be completely ignored.
        self::assertNull($metrics->get('lcom:App\Status'));
    }

    public function testEnumDoesNotAppearInMetrics(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class RealClass
{
    private $data;
    public function method() { return $this->data; }
}

enum Color
{
    case Red;
    case Blue;

    public function label(): string
    {
        return $this->name;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Class metrics should be present
        self::assertSame(1, $metrics->get('lcom:App\RealClass'));
        // Enum should not produce LCOM metrics
        self::assertNull($metrics->get('lcom:App\Color'));
    }

    public function testNullObjectPatternReturnsLcomOne(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class NullLogger
{
    public function log(string $message): void {}
    public function warning(string $message): void {}
    public function error(string $message): void {}
    public function debug(string $message): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // All methods have empty bodies (trivial) => LCOM 1, not 4
        self::assertSame(1, $metrics->get('lcom:App\NullLogger'));
    }

    public function testTrivialReturnMethodsGetLcomOne(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class NullCache
{
    public function get(string $key): ?string { return null; }
    public function has(string $key): bool { return false; }
    public function set(string $key, string $value): void {}
    public function delete(string $key): bool { return true; }
    public function getMultiple(array $keys): array { return []; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // All methods are trivial (return null/scalar/constant/empty array or empty body)
        self::assertSame(1, $metrics->get('lcom:App\NullCache'));
    }

    public function testClassWithMixedTrivialAndNonTrivialMethodsGetsCalculatedLcom(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Service
{
    private int $count = 0;

    public function increment(): void { $this->count++; }
    public function reset(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // One non-trivial method exists => normal LCOM calculation
        // increment uses $count, reset is empty but class is not all-trivial
        // increment and reset are disconnected => LCOM 2
        self::assertSame(2, $metrics->get('lcom:App\Service'));
    }

    public function testNonEmptyArrayOfConstantsReturnIsTrivialAndStateless(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Config
{
    public function getDefaults(): array { return ['debug' => false]; }
    public function getTypes(): array { return ['string', 'int']; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Both methods return arrays of constants — they are stateless constants,
        // merged into one virtual node => LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\Config'));
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

    public function testAnonymousClassDoesNotCorruptMethodTracking(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class OuterClass
{
    private $sharedProp;

    public function methodA(): object
    {
        $obj = new class {
            private $innerProp;
            public function innerMethod() { return $this->innerProp; }
        };

        // This access must be tracked for methodA, not lost
        return $this->sharedProp;
    }

    public function methodB()
    {
        return $this->sharedProp;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Both methodA and methodB access $sharedProp => connected => LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\OuterClass'));
    }

    // ──────────────────────────────────────────────────────────────────
    // Stateless method grouping tests
    // ──────────────────────────────────────────────────────────────────

    public function testStatelessMethodsGroupedReducesLcom(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Rule
{
    private $config;

    public function getName(): string { return 'foo'; }
    public function getDescription(): string { return 'bar'; }
    public function analyze(): void { $this->config; }
    public function validate(): void { $this->config; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // getName and getDescription are stateless constants => merged into 1 virtual node
        // analyze and validate share $config => 1 component
        // Virtual node not connected to stateful group => LCOM 2
        // Without grouping: LCOM would be 4 (getName, getDescription each separate + analyze/validate)
        // Was 4, now 2
        self::assertSame(2, $metrics->get('lcom:App\Rule'));
    }

    public function testAllStatelessMethodsGiveLcomOne(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MetadataOnly
{
    public function getName(): string { return 'test'; }
    public function getDescription(): string { return 'desc'; }
    public function priority(): int { return 1; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // All methods are stateless constants => merged into 1 virtual node => LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\MetadataOnly'));
    }

    public function testNoStatelessMethodsBehaviorUnchanged(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class AllStateful
{
    private $a;
    private $b;

    public function methodA(): void { $this->a = 1; }
    public function methodB(): void { $this->b = 1; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // No stateless methods — standard LCOM calculation: 2 disconnected groups
        self::assertSame(2, $metrics->get('lcom:App\AllStateful'));
    }

    public function testGetterAccessingPropertyNotStateless(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithGetter
{
    private $name;
    private $other;

    public function getName(): string { return $this->name; }
    public function getOther(): string { return $this->other; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Both methods access properties => NOT stateless => LCOM 2
        self::assertSame(2, $metrics->get('lcom:App\WithGetter'));
    }

    public function testMethodCallingInstanceMethodNotStateless(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithDelegation
{
    private $data;

    public function process(): string { return $this->format(); }
    public function format(): string { return $this->data; }
    public function getName(): string { return 'test'; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // process calls $this->format() => NOT stateless (has method call)
        // format accesses $data => NOT stateless
        // getName returns constant => stateless
        // process->format share edge, both connected with data => 1 component
        // getName merged into virtual stateless node => separate component
        // LCOM = 2 (stateful group + stateless virtual)
        self::assertSame(2, $metrics->get('lcom:App\WithDelegation'));
    }

    public function testStaticCallsDoNotMakeMethodStateful(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithStaticCall
{
    public function getName(): string
    {
        return 'test';
    }

    public function getLabel(): string
    {
        return self::DEFAULT_LABEL;
    }

    private const DEFAULT_LABEL = 'default';

    public static function factory(): self
    {
        return new self();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // getName: returns scalar => stateless
        // getLabel: returns self::X => stateless
        // factory: static => excluded from graph
        // Both stateless merged into 1 virtual node => LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\WithStaticCall'));
    }

    public function testOneStatelessWithTwoDisconnectedStatefulGroups(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class ThreeGroups
{
    private $db;
    private $cache;

    public function getName(): string { return 'service'; }
    public function query(): void { $this->db = 1; }
    public function cache(): void { $this->cache = 1; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // getName is stateless => virtual node (1)
        // query uses $db (1), cache uses $cache (1) => 2 disconnected stateful
        // Total: 3 components
        self::assertSame(3, $metrics->get('lcom:App\ThreeGroups'));
    }

    public function testStatefulMethodCallingStatelessConnectsToVirtualNode(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithStatelessCall
{
    private $data;

    public function getName(): string { return 'test'; }
    public function getDescription(): string { return 'desc'; }

    public function process(): void
    {
        $name = $this->getName();
        $this->data = $name;
    }

    public function read(): void
    {
        $this->data;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // getName, getDescription: stateless => merged into virtual node
        // process: calls $this->getName() => edge to virtual node; accesses $data
        // read: accesses $data => connected to process via shared $data
        // process -> virtual (via method call), process -> read (via $data)
        // All in one component => LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\WithStatelessCall'));
    }

    public function testReturnSelfConstantIsStateless(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithConstants
{
    public const NAME = 'test';
    public const TYPE = 'service';

    public function getName(): string { return self::NAME; }
    public function getType(): string { return static::TYPE; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Both methods return class constants => stateless, merged => LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\WithConstants'));
    }

    public function testReturnArrayOfConstantsIsStateless(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithConstantArray
{
    public function getOptions(): array
    {
        return [self::KEY => 'value', 'other' => true];
    }

    public function getDefaults(): array
    {
        return ['a', 'b', 'c'];
    }

    private const KEY = 'key';
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Both methods return arrays of constants => stateless, merged => LCOM 1
        self::assertSame(1, $metrics->get('lcom:App\WithConstantArray'));
    }

    public function testMethodAccessingPropertyViaCallNotStateless(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithPropertyViaCall
{
    private $config;

    public function getValue(): string
    {
        return $this->config->get('key');
    }

    public function getName(): string { return 'test'; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // getValue accesses $this->config (property access) => NOT stateless
        // getName returns constant => stateless => virtual node
        // Two components => LCOM 2
        self::assertSame(2, $metrics->get('lcom:App\WithPropertyViaCall'));
    }

    public function testStatelessMethodCallingAnotherStatelessMergesInternally(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class StatelessCalling
{
    private $data;

    public function getLabel(): string { return 'label'; }
    public function getFullLabel(): string { return 'full-label'; }
    public function process(): void { $this->data = 1; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // getLabel and getFullLabel: both stateless => merged into virtual
        // process: accesses $data => separate
        // LCOM = 2 (virtual + process)
        self::assertSame(2, $metrics->get('lcom:App\StatelessCalling'));
    }

    public function testExistingTrivialClassExemptionStillWorks(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class AllTrivial
{
    public function a(): void {}
    public function b(): void { return; }
    public function c(): ?string { return null; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // All methods are trivial AND all are stateless => LCOM 1
        // (both the trivial exemption and stateless grouping would produce LCOM 1)
        self::assertSame(1, $metrics->get('lcom:App\AllTrivial'));
    }

    public function testMatchReturningDifferentConstantsIsNotStateless(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithMatch
{
    private $type;

    public function getLabel(): string
    {
        return match($this->type) {
            'a' => 'Alpha',
            'b' => 'Beta',
            default => 'Unknown',
        };
    }

    public function getName(): string { return 'test'; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // getLabel: accesses $this->type => NOT stateless (has property access)
        // getName: returns constant => stateless
        // LCOM = 2
        self::assertSame(2, $metrics->get('lcom:App\WithMatch'));
    }

    public function testCollectorRespectsExcludeMethodsFromConfig(): void
    {
        CollectorConfigHolder::set(CollectorConfigHolder::LCOM_EXCLUDE_METHODS, ['getName']);

        $code = <<<'PHP'
<?php

namespace App;

class Service
{
    private $data;

    public function doWork(): void
    {
        $this->data = 1;
    }

    public function getName(): string
    {
        return 'service';
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // With getName excluded: only doWork remains => LCOM 1
        // Without exclusion: doWork uses $data (1 component), getName is stateless (virtual node) => LCOM 2
        self::assertSame(1, $metrics->get('lcom:App\Service'));
    }

    protected function tearDown(): void
    {
        CollectorConfigHolder::reset();
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
