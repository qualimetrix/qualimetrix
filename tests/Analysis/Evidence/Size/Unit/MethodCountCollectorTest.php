<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Size\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Size\MethodCountCollector;
use Qualimetrix\Analysis\Evidence\Size\MethodCountMetrics;
use Qualimetrix\Analysis\Evidence\Size\MethodCountVisitor;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;
use Qualimetrix\Core\Symbol\SymbolLevel;
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

        self::assertContains('size.method-count', $provides);
        self::assertContains('size.method-count.total', $provides);
        self::assertContains('size.method-count.public', $provides);
        self::assertContains('size.method-count.protected', $provides);
        self::assertContains('size.method-count.private', $provides);
        self::assertContains('size.getter-count', $provides);
        self::assertContains('size.setter-count', $provides);
        self::assertContains('size.property-count', $provides);
        self::assertContains('size.property-count.public', $provides);
        self::assertContains('size.property-count.protected', $provides);
        self::assertContains('size.property-count.private', $provides);
        self::assertContains('size.promoted-property-count', $provides);
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

        self::assertSame(0, $metrics->get('size.method-count:App\EmptyClass'));
        self::assertSame(0, $metrics->get('size.method-count.total:App\EmptyClass'));
        self::assertSame(0, $metrics->get('size.method-count.public:App\EmptyClass'));
        self::assertSame(0, $metrics->get('size.method-count.protected:App\EmptyClass'));
        self::assertSame(0, $metrics->get('size.method-count.private:App\EmptyClass'));
        self::assertSame(0, $metrics->get('size.getter-count:App\EmptyClass'));
        self::assertSame(0, $metrics->get('size.setter-count:App\EmptyClass'));
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

        self::assertSame(3, $metrics->get('size.method-count:App\PublicClass'));
        self::assertSame(3, $metrics->get('size.method-count.total:App\PublicClass'));
        self::assertSame(3, $metrics->get('size.method-count.public:App\PublicClass'));
        self::assertSame(0, $metrics->get('size.method-count.protected:App\PublicClass'));
        self::assertSame(0, $metrics->get('size.method-count.private:App\PublicClass'));
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

        self::assertSame(5, $metrics->get('size.method-count:App\MixedVisibility'));
        self::assertSame(5, $metrics->get('size.method-count.total:App\MixedVisibility'));
        self::assertSame(2, $metrics->get('size.method-count.public:App\MixedVisibility'));
        self::assertSame(1, $metrics->get('size.method-count.protected:App\MixedVisibility'));
        self::assertSame(2, $metrics->get('size.method-count.private:App\MixedVisibility'));
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

        self::assertSame(4, $metrics->get('size.getter-count:App\WithGetters'));
        self::assertSame(0, $metrics->get('size.setter-count:App\WithGetters'));
        self::assertSame(0, $metrics->get('size.method-count:App\WithGetters')); // Excluded
        self::assertSame(4, $metrics->get('size.method-count.total:App\WithGetters'));
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

        self::assertSame(0, $metrics->get('size.getter-count:App\WithSetters'));
        self::assertSame(3, $metrics->get('size.setter-count:App\WithSetters'));
        self::assertSame(0, $metrics->get('size.method-count:App\WithSetters')); // Excluded
        self::assertSame(3, $metrics->get('size.method-count.total:App\WithSetters'));
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

        self::assertSame(3, $metrics->get('size.getter-count:App\Entity'));
        self::assertSame(3, $metrics->get('size.setter-count:App\Entity'));
        self::assertSame(2, $metrics->get('size.method-count:App\Entity')); // save, validate
        self::assertSame(8, $metrics->get('size.method-count.total:App\Entity'));
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

        self::assertSame(1, $metrics->get('size.method-count:App\MyInterface'));
        self::assertSame(3, $metrics->get('size.method-count.total:App\MyInterface'));
        self::assertSame(1, $metrics->get('size.getter-count:App\MyInterface'));
        self::assertSame(1, $metrics->get('size.setter-count:App\MyInterface'));
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

        self::assertSame(2, $metrics->get('size.method-count:App\MyTrait'));
        self::assertSame(3, $metrics->get('size.method-count.total:App\MyTrait'));
        self::assertSame(1, $metrics->get('size.method-count.public:App\MyTrait'));
        self::assertSame(1, $metrics->get('size.method-count.protected:App\MyTrait'));
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

        self::assertSame(0, $metrics->get('size.method-count:App\Status'));
        self::assertSame(2, $metrics->get('size.method-count.total:App\Status'));
        self::assertSame(2, $metrics->get('size.getter-count:App\Status')); // getLabel, isActive
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

        self::assertSame(2, $metrics->get('size.method-count:App\First'));
        self::assertSame(2, $metrics->get('size.method-count.public:App\First'));

        self::assertSame(3, $metrics->get('size.method-count:App\Second'));
        self::assertSame(1, $metrics->get('size.method-count.public:App\Second'));
        self::assertSame(2, $metrics->get('size.method-count.private:App\Second'));
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

        self::assertSame(1, $metrics->get('size.method-count:App\Factory'));
        // Anonymous class should not appear in metrics
        self::assertNull($metrics->get('size.method-count:'));
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

        self::assertSame(1, $metrics->get('size.method-count:GlobalClass'));
        self::assertSame(2, $metrics->get('size.method-count.total:GlobalClass'));
        self::assertSame(1, $metrics->get('size.getter-count:GlobalClass'));
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
        self::assertNull($metrics->get('size.method-count:App\First'));
        self::assertSame(1, $metrics->get('size.method-count:App\Second'));
    }

    #[Test]
    public function itGetsMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(19, $definitions);

        $metricNames = array_map(fn($d) => $d->name, $definitions);
        self::assertContains('size.method-count', $metricNames);
        self::assertContains('size.method-count.total', $metricNames);
        self::assertContains('size.method-count.public', $metricNames);
        self::assertContains('size.method-count.protected', $metricNames);
        self::assertContains('size.method-count.private', $metricNames);
        self::assertContains('size.getter-count', $metricNames);
        self::assertContains('size.setter-count', $metricNames);
        self::assertContains('size.property-count', $metricNames);
        self::assertContains('size.property-count.public', $metricNames);
        self::assertContains('size.property-count.protected', $metricNames);
        self::assertContains('size.property-count.private', $metricNames);
        self::assertContains('size.promoted-property-count', $metricNames);
        self::assertContains('design.is-readonly', $metricNames);
        self::assertContains('design.is-promoted-properties-only', $metricNames);
        self::assertContains('design.is-data-class', $metricNames);
        self::assertContains('design.is-abstract', $metricNames);
        self::assertContains('design.is-interface', $metricNames);
        self::assertContains('design.is-exception', $metricNames);
        self::assertContains('design.woc', $metricNames);

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
        self::assertSame(3, $metrics->get('size.getter-count:App\CaseTest'));
        self::assertSame(2, $metrics->get('size.setter-count:App\CaseTest'));
        self::assertSame(1, $metrics->get('size.method-count:App\CaseTest')); // HAS_permission
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

        self::assertSame(0, $metrics->get('size.getter-count:App\WithConstructor'));
        self::assertSame(0, $metrics->get('size.setter-count:App\WithConstructor'));
        self::assertSame(3, $metrics->get('size.method-count:App\WithConstructor'));
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

        self::assertSame(3, $metrics->get('size.getter-count:App\ExactPrefixes')); // get, is, has
        self::assertSame(1, $metrics->get('size.setter-count:App\ExactPrefixes')); // set
        self::assertSame(0, $metrics->get('size.method-count:App\ExactPrefixes'));
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

        self::assertSame(0, $metrics->get('size.getter-count:App\FalsePositives'));
        self::assertSame(0, $metrics->get('size.setter-count:App\FalsePositives'));
        self::assertSame(9, $metrics->get('size.method-count:App\FalsePositives'));
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

        self::assertSame(3, $metrics->get('size.property-count:App\WithPublicProperties'));
        self::assertSame(3, $metrics->get('size.property-count.public:App\WithPublicProperties'));
        self::assertSame(0, $metrics->get('size.property-count.protected:App\WithPublicProperties'));
        self::assertSame(0, $metrics->get('size.property-count.private:App\WithPublicProperties'));
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

        self::assertSame(2, $metrics->get('size.property-count:App\WithProtectedProperties'));
        self::assertSame(0, $metrics->get('size.property-count.public:App\WithProtectedProperties'));
        self::assertSame(2, $metrics->get('size.property-count.protected:App\WithProtectedProperties'));
        self::assertSame(0, $metrics->get('size.property-count.private:App\WithProtectedProperties'));
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

        self::assertSame(4, $metrics->get('size.property-count:App\WithPrivateProperties'));
        self::assertSame(0, $metrics->get('size.property-count.public:App\WithPrivateProperties'));
        self::assertSame(0, $metrics->get('size.property-count.protected:App\WithPrivateProperties'));
        self::assertSame(4, $metrics->get('size.property-count.private:App\WithPrivateProperties'));
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

        self::assertSame(3, $metrics->get('size.property-count:App\WithPromotedProperties'));
        self::assertSame(1, $metrics->get('size.property-count.public:App\WithPromotedProperties'));
        self::assertSame(1, $metrics->get('size.property-count.protected:App\WithPromotedProperties'));
        self::assertSame(1, $metrics->get('size.property-count.private:App\WithPromotedProperties'));
        self::assertSame(3, $metrics->get('size.promoted-property-count:App\WithPromotedProperties'));
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

        self::assertSame(5, $metrics->get('size.property-count:App\MultiDeclaration'));
        self::assertSame(3, $metrics->get('size.property-count.public:App\MultiDeclaration'));
        self::assertSame(2, $metrics->get('size.property-count.private:App\MultiDeclaration'));
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

        self::assertSame(3, $metrics->get('size.property-count:App\WithStaticProperties'));
        self::assertSame(1, $metrics->get('size.property-count.public:App\WithStaticProperties'));
        self::assertSame(1, $metrics->get('size.property-count.protected:App\WithStaticProperties'));
        self::assertSame(1, $metrics->get('size.property-count.private:App\WithStaticProperties'));
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

        self::assertSame(4, $metrics->get('size.property-count:App\TypedProperties'));
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

        self::assertSame(2, $metrics->get('size.property-count:App\ReadonlyProperties'));
        self::assertSame(1, $metrics->get('size.property-count.public:App\ReadonlyProperties'));
        self::assertSame(1, $metrics->get('size.property-count.private:App\ReadonlyProperties'));
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

        self::assertSame(0, $metrics->get('size.property-count:App\NoProperties'));
        self::assertSame(0, $metrics->get('size.property-count.public:App\NoProperties'));
        self::assertSame(0, $metrics->get('size.promoted-property-count:App\NoProperties'));
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

        self::assertSame(3, $metrics->get('size.property-count:App\MixedProperties'));
        self::assertSame(1, $metrics->get('size.property-count.public:App\MixedProperties'));
        self::assertSame(2, $metrics->get('size.property-count.private:App\MixedProperties'));
        self::assertSame(2, $metrics->get('size.promoted-property-count:App\MixedProperties'));
    }

    #[Test]
    public function itCountsAccessorsAgainstWocRatherThanTowardsIt(): void
    {
        // 5 public accessors + 1 public functional method + 1 private method:
        // WOC = round(1/6 * 100) = 17. Private methods are not public members.
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
    public function publish(): void {}
    private function validate(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(17, $metrics->get('design.woc:App\EntityWithAccessors'));
    }

    #[Test]
    public function itReturnsZeroWocWhenAllPublicMembersAreAccessors(): void
    {
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

        self::assertSame(0, $metrics->get('design.woc:App\PureDto'));
    }

    #[Test]
    public function itCountsPublicPropertiesAsPublicMembersInWoc(): void
    {
        // 1 functional public method against 1 public method + 3 public
        // properties = 4 public members: WOC = 25.
        $code = <<<'PHP'
<?php

namespace App;

class OpenState
{
    public string $name = '';
    public int $id = 0;
    public bool $active = false;

    public function publish(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(25, $metrics->get('design.woc:App\OpenState'));
    }

    #[Test]
    public function itCountsADelegatingMethodAsBehaviour(): void
    {
        // A body that only forwards to a collaborator is still a functional
        // public method: WOC is about the shape of the interface.
        $code = <<<'PHP'
<?php

namespace App;

class Forwarder
{
    private object $inner;

    public function enterNode(object $node): void { $this->inner->handle($node); }
    public function leaveNode(object $node): void { $this->inner->release($node); }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(100, $metrics->get('design.woc:App\Forwarder'));
    }

    #[Test]
    public function itReturns100WocForAClassWithoutPublicMembers(): void
    {
        // No public surface at all — nothing is exposed, so nothing is data
        // access. The degenerate case is defined, not left undefined.
        $code = <<<'PHP'
<?php

namespace App;

class EmptyWoc {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(100, $metrics->get('design.woc:App\EmptyWoc'));
    }

    #[Test]
    public function itDoesNotCountPropertyHooksAsMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Profile
{
    public string $name {
        get => $this->name;
        set (string $value) => $value;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('size.method-count:App\Profile'));
        self::assertSame(0, $metrics->get('size.method-count.total:App\Profile'));
    }

    #[Test]
    public function itDeliberatelyDoesNotProvideCallableMetrics(): void
    {
        self::assertNotContains(\Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface::class, class_implements($this->collector));
    }

    #[Test]
    public function itProjectsTheSameClassMetricsAsTheFileBagIncludingDegenerateWoc(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class EmptyClass {}

class ConstructorAccessors
{
    public function __construct() {}
    public function getName(): string { return ''; }
    public function setName(string $name): void {}
}

class MixedPromoted
{
    public function __construct(
        public string $name,
        private int $id,
    ) {}

    public function getName(): string { return $this->name; }
    protected function compute(): void {}
    private function validate(): void {}
}

class SecondClass
{
    private function work(): void {}
}
PHP;

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $this->collector->useDeclarationIndex(new FileDeclarationIndex());

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        $fileBag = $this->collector->collect(new SplFileInfo(__FILE__), $ast);
        $classes = $this->collector->getClassesWithMetrics(RelativePath::fromString('fixtures/MethodCount.php'));
        $classNames = array_map(
            static fn(ClassWithMetrics $class): string => $class->subject->toSymbolPath()->toString(),
            $classes,
        );

        self::assertSame(
            ['App\EmptyClass', 'App\ConstructorAccessors', 'App\MixedPromoted', 'App\SecondClass'],
            $classNames,
        );
        self::assertSame(100, $classes[0]->metrics->get('design.woc'));

        foreach ($classes as $index => $class) {
            foreach ($this->collector->provides() as $metricName) {
                self::assertSame(
                    $fileBag->get($metricName . ':' . $classNames[$index]),
                    $class->metrics->get($metricName),
                    $classNames[$index] . ' ' . $metricName,
                );
            }
        }
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
