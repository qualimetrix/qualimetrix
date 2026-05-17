<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Structure;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\Structure\MethodCountCollector;
use Qualimetrix\Metrics\Structure\MethodCountMetrics;
use Qualimetrix\Metrics\Structure\MethodCountVisitor;
use SplFileInfo;

#[CoversClass(MethodCountCollector::class)]
#[CoversClass(MethodCountVisitor::class)]
#[CoversClass(MethodCountMetrics::class)]
final class MethodCountCollectorTest extends TestCase
{
    private MethodCountCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new MethodCountCollector();
    }

    #[Test]
    public function itGetsName(): void
    {
        self::assertSame('method-count', $this->collector->getName());
    }

    #[Test]
    public function itProvides(): void
    {
        $provides = $this->collector->provides();

        self::assertContains('methodCount', $provides);
        self::assertContains('methodCountTotal', $provides);
        self::assertContains('methodCountPublic', $provides);
        self::assertContains('methodCountProtected', $provides);
        self::assertContains('methodCountPrivate', $provides);
        self::assertContains('getterCount', $provides);
        self::assertContains('setterCount', $provides);
        self::assertContains('propertyCount', $provides);
        self::assertContains('propertyCountPublic', $provides);
        self::assertContains('propertyCountProtected', $provides);
        self::assertContains('propertyCountPrivate', $provides);
        self::assertContains('promotedPropertyCount', $provides);
    }

    #[Test]
    public function itReturnsZeroCountsForEmptyClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class EmptyClass
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('methodCount:App\EmptyClass'));
        self::assertSame(0, $metrics->get('methodCountTotal:App\EmptyClass'));
        self::assertSame(0, $metrics->get('methodCountPublic:App\EmptyClass'));
        self::assertSame(0, $metrics->get('methodCountProtected:App\EmptyClass'));
        self::assertSame(0, $metrics->get('methodCountPrivate:App\EmptyClass'));
        self::assertSame(0, $metrics->get('getterCount:App\EmptyClass'));
        self::assertSame(0, $metrics->get('setterCount:App\EmptyClass'));
    }

    #[Test]
    public function itCountsPublicMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class PublicClass
{
    public function method1(): void {}
    public function method2(): void {}
    public function method3(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('methodCount:App\PublicClass'));
        self::assertSame(3, $metrics->get('methodCountTotal:App\PublicClass'));
        self::assertSame(3, $metrics->get('methodCountPublic:App\PublicClass'));
        self::assertSame(0, $metrics->get('methodCountProtected:App\PublicClass'));
        self::assertSame(0, $metrics->get('methodCountPrivate:App\PublicClass'));
    }

    #[Test]
    public function itCountsMethodsWithMixedVisibility(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MixedVisibility
{
    public function publicMethod(): void {}
    protected function protectedMethod(): void {}
    private function privateMethod(): void {}
    public function anotherPublic(): void {}
    private function anotherPrivate(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(5, $metrics->get('methodCount:App\MixedVisibility'));
        self::assertSame(5, $metrics->get('methodCountTotal:App\MixedVisibility'));
        self::assertSame(2, $metrics->get('methodCountPublic:App\MixedVisibility'));
        self::assertSame(1, $metrics->get('methodCountProtected:App\MixedVisibility'));
        self::assertSame(2, $metrics->get('methodCountPrivate:App\MixedVisibility'));
    }

    #[Test]
    public function itCountsGetters(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithGetters
{
    public function getName(): string { return ''; }
    public function getId(): int { return 0; }
    public function isActive(): bool { return true; }
    public function hasPermission(): bool { return false; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(4, $metrics->get('getterCount:App\WithGetters'));
        self::assertSame(0, $metrics->get('setterCount:App\WithGetters'));
        self::assertSame(0, $metrics->get('methodCount:App\WithGetters')); // Excluded
        self::assertSame(4, $metrics->get('methodCountTotal:App\WithGetters'));
    }

    #[Test]
    public function itCountsSetters(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithSetters
{
    public function setName(string $name): void {}
    public function setId(int $id): void {}
    public function setValue(mixed $value): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('getterCount:App\WithSetters'));
        self::assertSame(3, $metrics->get('setterCount:App\WithSetters'));
        self::assertSame(0, $metrics->get('methodCount:App\WithSetters')); // Excluded
        self::assertSame(3, $metrics->get('methodCountTotal:App\WithSetters'));
    }

    #[Test]
    public function itCountsGettersAndSettersSeparately(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Entity
{
    public function getId(): int { return 0; }
    public function setId(int $id): void {}
    public function getName(): string { return ''; }
    public function setName(string $name): void {}
    public function isActive(): bool { return true; }
    public function setActive(bool $active): void {}

    public function save(): void {}
    public function validate(): bool { return true; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('getterCount:App\Entity'));
        self::assertSame(3, $metrics->get('setterCount:App\Entity'));
        self::assertSame(2, $metrics->get('methodCount:App\Entity')); // save, validate
        self::assertSame(8, $metrics->get('methodCountTotal:App\Entity'));
    }

    #[Test]
    public function itCountsInterfaceMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

interface MyInterface
{
    public function method1(): void;
    public function getName(): string;
    public function setName(string $name): void;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('methodCount:App\MyInterface'));
        self::assertSame(3, $metrics->get('methodCountTotal:App\MyInterface'));
        self::assertSame(1, $metrics->get('getterCount:App\MyInterface'));
        self::assertSame(1, $metrics->get('setterCount:App\MyInterface'));
    }

    #[Test]
    public function itCountsTraitMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait MyTrait
{
    public function traitMethod(): void {}
    protected function protectedTrait(): void {}
    public function getName(): string { return ''; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('methodCount:App\MyTrait'));
        self::assertSame(3, $metrics->get('methodCountTotal:App\MyTrait'));
        self::assertSame(1, $metrics->get('methodCountPublic:App\MyTrait'));
        self::assertSame(1, $metrics->get('methodCountProtected:App\MyTrait'));
    }

    #[Test]
    public function itCountsEnumMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function getLabel(): string
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

        self::assertSame(0, $metrics->get('methodCount:App\Status'));
        self::assertSame(2, $metrics->get('methodCountTotal:App\Status'));
        self::assertSame(2, $metrics->get('getterCount:App\Status')); // getLabel, isActive
    }

    #[Test]
    public function itCountsMethodsForMultipleClasses(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class First
{
    public function a(): void {}
    public function b(): void {}
}

class Second
{
    public function c(): void {}
    private function d(): void {}
    private function e(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('methodCount:App\First'));
        self::assertSame(2, $metrics->get('methodCountPublic:App\First'));

        self::assertSame(3, $metrics->get('methodCount:App\Second'));
        self::assertSame(1, $metrics->get('methodCountPublic:App\Second'));
        self::assertSame(2, $metrics->get('methodCountPrivate:App\Second'));
    }

    #[Test]
    public function itIgnoresAnonymousClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Factory
{
    public function create(): object
    {
        return new class {
            public function method(): void {}
        };
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('methodCount:App\Factory'));
        // Anonymous class should not appear in metrics
        self::assertNull($metrics->get('methodCount:'));
    }

    #[Test]
    public function itHandlesClassWithoutNamespace(): void
    {
        $code = <<<'PHP'
<?php

class GlobalClass
{
    public function method(): void {}
    public function getName(): string { return ''; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('methodCount:GlobalClass'));
        self::assertSame(2, $metrics->get('methodCountTotal:GlobalClass'));
        self::assertSame(1, $metrics->get('getterCount:GlobalClass'));
    }

    #[Test]
    public function itResetsState(): void
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
        self::assertNull($metrics->get('methodCount:App\First'));
        self::assertSame(1, $metrics->get('methodCount:App\Second'));
    }

    #[Test]
    public function itGetsMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(19, $definitions);

        $metricNames = array_map(fn($d) => $d->name, $definitions);
        self::assertContains('methodCount', $metricNames);
        self::assertContains('methodCountTotal', $metricNames);
        self::assertContains('methodCountPublic', $metricNames);
        self::assertContains('methodCountProtected', $metricNames);
        self::assertContains('methodCountPrivate', $metricNames);
        self::assertContains('getterCount', $metricNames);
        self::assertContains('setterCount', $metricNames);
        self::assertContains('propertyCount', $metricNames);
        self::assertContains('propertyCountPublic', $metricNames);
        self::assertContains('propertyCountProtected', $metricNames);
        self::assertContains('propertyCountPrivate', $metricNames);
        self::assertContains('promotedPropertyCount', $metricNames);
        self::assertContains('isReadonly', $metricNames);
        self::assertContains('isPromotedPropertiesOnly', $metricNames);
        self::assertContains('isDataClass', $metricNames);
        self::assertContains('isAbstract', $metricNames);
        self::assertContains('isInterface', $metricNames);
        self::assertContains('isException', $metricNames);
        self::assertContains('woc', $metricNames);

        // Check collected at level
        foreach ($definitions as $def) {
            self::assertSame(SymbolLevel::Class_, $def->collectedAt);
        }

        // Check aggregations for methodCount (representative)
        $methodCountDef = $definitions[0];
        $namespaceStrategies = $methodCountDef->getStrategiesForLevel(SymbolLevel::Namespace_);
        self::assertContains(AggregationStrategy::Sum, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Average, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Max, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Percentile95, $namespaceStrategies);

        $projectStrategies = $methodCountDef->getStrategiesForLevel(SymbolLevel::Project);
        self::assertContains(AggregationStrategy::Percentile95, $projectStrategies);
    }

    #[Test]
    public function itDetectsGetterSetterCaseInsensitively(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class CaseTest
{
    public function GetValue(): int { return 0; }
    public function GETNAME(): string { return ''; }
    public function SetValue(int $v): void {}
    public function SETNAME(string $n): void {}
    public function IsActive(): bool { return true; }
    public function HAS_permission(): bool { return false; }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // HAS_permission is NOT a getter: underscore after prefix, not uppercase letter
        self::assertSame(3, $metrics->get('getterCount:App\CaseTest'));
        self::assertSame(2, $metrics->get('setterCount:App\CaseTest'));
        self::assertSame(1, $metrics->get('methodCount:App\CaseTest')); // HAS_permission
    }

    #[Test]
    public function itDoesNotCountConstructorAsGetter(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithConstructor
{
    public function __construct() {}
    public function __destruct() {}
    public function process(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('getterCount:App\WithConstructor'));
        self::assertSame(0, $metrics->get('setterCount:App\WithConstructor'));
        self::assertSame(3, $metrics->get('methodCount:App\WithConstructor'));
    }

    #[Test]
    public function itDetectsExactPrefixMatchAsAccessor(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class ExactPrefixes
{
    public function get(): void {} // Exact "get" - getter
    public function is(): void {}  // Exact "is" - getter
    public function has(): void {} // Exact "has" - getter
    public function set(): void {} // Exact "set" - setter
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('getterCount:App\ExactPrefixes')); // get, is, has
        self::assertSame(1, $metrics->get('setterCount:App\ExactPrefixes')); // set
        self::assertSame(0, $metrics->get('methodCount:App\ExactPrefixes'));
    }

    #[Test]
    public function itDoesNotDetectFalsePositiveGetterSetterPrefixes(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class FalsePositives
{
    public function isolate(): void {}   // NOT a getter (is + lowercase)
    public function island(): void {}    // NOT a getter (is + lowercase)
    public function isset(): void {}     // NOT a getter (is + lowercase)
    public function getaway(): void {}   // NOT a getter (get + lowercase)
    public function getting(): void {}   // NOT a getter (get + lowercase)
    public function hasty(): void {}     // NOT a getter (has + lowercase)
    public function setup(): void {}     // NOT a setter (set + lowercase)
    public function settle(): void {}    // NOT a setter (set + lowercase)
    public function setting(): void {}   // NOT a setter (set + lowercase)
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('getterCount:App\FalsePositives'));
        self::assertSame(0, $metrics->get('setterCount:App\FalsePositives'));
        self::assertSame(9, $metrics->get('methodCount:App\FalsePositives'));
    }

    #[Test]
    public function itCountsPublicProperties(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithPublicProperties
{
    public string $name;
    public int $age;
    public bool $active;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('propertyCount:App\WithPublicProperties'));
        self::assertSame(3, $metrics->get('propertyCountPublic:App\WithPublicProperties'));
        self::assertSame(0, $metrics->get('propertyCountProtected:App\WithPublicProperties'));
        self::assertSame(0, $metrics->get('propertyCountPrivate:App\WithPublicProperties'));
    }

    #[Test]
    public function itCountsProtectedProperties(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithProtectedProperties
{
    protected string $name;
    protected int $age;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('propertyCount:App\WithProtectedProperties'));
        self::assertSame(0, $metrics->get('propertyCountPublic:App\WithProtectedProperties'));
        self::assertSame(2, $metrics->get('propertyCountProtected:App\WithProtectedProperties'));
        self::assertSame(0, $metrics->get('propertyCountPrivate:App\WithProtectedProperties'));
    }

    #[Test]
    public function itCountsPrivateProperties(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithPrivateProperties
{
    private string $name;
    private int $age;
    private bool $active;
    private array $data;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(4, $metrics->get('propertyCount:App\WithPrivateProperties'));
        self::assertSame(0, $metrics->get('propertyCountPublic:App\WithPrivateProperties'));
        self::assertSame(0, $metrics->get('propertyCountProtected:App\WithPrivateProperties'));
        self::assertSame(4, $metrics->get('propertyCountPrivate:App\WithPrivateProperties'));
    }

    #[Test]
    public function itCountsPromotedProperties(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithPromotedProperties
{
    public function __construct(
        public string $name,
        private int $age,
        protected bool $active,
    ) {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('propertyCount:App\WithPromotedProperties'));
        self::assertSame(1, $metrics->get('propertyCountPublic:App\WithPromotedProperties'));
        self::assertSame(1, $metrics->get('propertyCountProtected:App\WithPromotedProperties'));
        self::assertSame(1, $metrics->get('propertyCountPrivate:App\WithPromotedProperties'));
        self::assertSame(3, $metrics->get('promotedPropertyCount:App\WithPromotedProperties'));
    }

    #[Test]
    public function itCountsMultiplePropsInDeclaration(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MultiDeclaration
{
    public $a, $b, $c;
    private $x, $y;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(5, $metrics->get('propertyCount:App\MultiDeclaration'));
        self::assertSame(3, $metrics->get('propertyCountPublic:App\MultiDeclaration'));
        self::assertSame(2, $metrics->get('propertyCountPrivate:App\MultiDeclaration'));
    }

    #[Test]
    public function itIncludesStaticProperties(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithStaticProperties
{
    public static string $instance;
    private static int $counter = 0;
    protected $regular;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('propertyCount:App\WithStaticProperties'));
        self::assertSame(1, $metrics->get('propertyCountPublic:App\WithStaticProperties'));
        self::assertSame(1, $metrics->get('propertyCountProtected:App\WithStaticProperties'));
        self::assertSame(1, $metrics->get('propertyCountPrivate:App\WithStaticProperties'));
    }

    #[Test]
    public function itCountsTypedProperties(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class TypedProperties
{
    public string $name;
    public int $age;
    public ?bool $active = null;
    public array $items = [];
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(4, $metrics->get('propertyCount:App\TypedProperties'));
    }

    #[Test]
    public function itCountsReadonlyProperties(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class ReadonlyProperties
{
    public readonly string $name;
    private readonly int $age;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('propertyCount:App\ReadonlyProperties'));
        self::assertSame(1, $metrics->get('propertyCountPublic:App\ReadonlyProperties'));
        self::assertSame(1, $metrics->get('propertyCountPrivate:App\ReadonlyProperties'));
    }

    #[Test]
    public function itReturnsZeroWhenNoProperties(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class NoProperties
{
    public function method(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('propertyCount:App\NoProperties'));
        self::assertSame(0, $metrics->get('propertyCountPublic:App\NoProperties'));
        self::assertSame(0, $metrics->get('promotedPropertyCount:App\NoProperties'));
    }

    #[Test]
    public function itCountsMixedPropertiesAndPromoted(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MixedProperties
{
    private string $regular;

    public function __construct(
        private string $promoted,
        public int $publicPromoted,
    ) {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('propertyCount:App\MixedProperties'));
        self::assertSame(1, $metrics->get('propertyCountPublic:App\MixedProperties'));
        self::assertSame(2, $metrics->get('propertyCountPrivate:App\MixedProperties'));
        self::assertSame(2, $metrics->get('promotedPropertyCount:App\MixedProperties'));
    }

    #[Test]
    public function itIncludesPublicGettersAndSettersInWoc(): void
    {
        // Class with 3 public getters + 2 public setters + 1 private method = 6 total
        // All public methods (including getters/setters) = 5, WOC = round(5/6 * 100) = 83
        $code = <<<'PHP'
<?php

namespace App;

class EntityWithAccessors
{
    public function getName(): string { return ''; }
    public function getId(): int { return 0; }
    public function isActive(): bool { return true; }
    public function setName(string $name): void {}
    public function setId(int $id): void {}
    private function validate(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(83, $metrics->get('woc:App\EntityWithAccessors'));
    }

    #[Test]
    public function itReturns100WocWhenAllPublicGettersAndSetters(): void
    {
        // Class with only public getters/setters: WOC = 100
        $code = <<<'PHP'
<?php

namespace App;

class PureDto
{
    public function getName(): string { return ''; }
    public function setName(string $name): void {}
    public function getId(): int { return 0; }
    public function setId(int $id): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(100, $metrics->get('woc:App\PureDto'));
    }

    #[Test]
    public function itReturnsZeroWocForEmptyClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class EmptyWoc {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('woc:App\EmptyWoc'));
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
