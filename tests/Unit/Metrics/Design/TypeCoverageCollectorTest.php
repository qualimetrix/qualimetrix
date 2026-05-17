<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Design;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\Design\TypeCoverageCollector;
use Qualimetrix\Metrics\Design\TypeCoverageVisitor;
use SplFileInfo;

#[CoversClass(TypeCoverageCollector::class)]
#[CoversClass(TypeCoverageVisitor::class)]
final class TypeCoverageCollectorTest extends TestCase
{
    private TypeCoverageCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new TypeCoverageCollector();
    }

    #[Test]
    public function itReturnsCollectorName(): void
    {
        self::assertSame('type-coverage', $this->collector->getName());
    }

    #[Test]
    public function itProvidesExpectedMetricKeys(): void
    {
        self::assertSame([
            'typeCoverage.paramTotal',
            'typeCoverage.paramTyped',
            'typeCoverage.param',
            'typeCoverage.returnTotal',
            'typeCoverage.returnTyped',
            'typeCoverage.return',
            'typeCoverage.propertyTotal',
            'typeCoverage.propertyTyped',
            'typeCoverage.property',
        ], $this->collector->provides());
    }

    #[Test]
    public function itReports100PercentForFullyTypedClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

class UserService
{
    private string $name;
    private int $age;

    public function getName(): string
    {
        return $this->name;
    }

    public function setAge(int $age): void
    {
        $this->age = $age;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(100.0, $metrics->get('typeCoverage.param:App\Service\UserService'));
        self::assertSame(100.0, $metrics->get('typeCoverage.return:App\Service\UserService'));
        self::assertSame(100.0, $metrics->get('typeCoverage.property:App\Service\UserService'));
    }

    #[Test]
    public function itReports0PercentWhenNoTypesAtAll(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

class LegacyService
{
    public $data;
    public $status;

    public function process($input)
    {
        return $input;
    }

    public function handle($request, $response)
    {
        return $response;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0.0, $metrics->get('typeCoverage.param:App\Service\LegacyService'));
        self::assertSame(0.0, $metrics->get('typeCoverage.return:App\Service\LegacyService'));
        self::assertSame(0.0, $metrics->get('typeCoverage.property:App\Service\LegacyService'));
    }

    #[Test]
    public function itReportsCorrectPercentageForMixedTypeCoverage(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MixedService
{
    public function process(int $a, $b, string $c)
    {
        return $a;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // 2 out of 3 params typed = 66.67%
        self::assertSame(66.67, $metrics->get('typeCoverage.param:App\MixedService'));
        // 0 out of 1 method has return type = 0%
        self::assertSame(0.0, $metrics->get('typeCoverage.return:App\MixedService'));
    }

    #[Test]
    public function itCountsPromotedPropertiesAsBothParamAndProperty(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class ValueObject
{
    public function __construct(
        private readonly string $name,
        private readonly int $age,
        private $untyped,
    ) {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // 3 params: 2 typed, 1 untyped = 66.67%
        self::assertSame(66.67, $metrics->get('typeCoverage.param:App\ValueObject'));

        // 3 promoted properties: 2 typed, 1 untyped = 66.67%
        self::assertSame(66.67, $metrics->get('typeCoverage.property:App\ValueObject'));

        // Totals
        self::assertSame(3, $metrics->get('typeCoverage.paramTotal:App\ValueObject'));
        self::assertSame(2, $metrics->get('typeCoverage.paramTyped:App\ValueObject'));
        self::assertSame(3, $metrics->get('typeCoverage.propertyTotal:App\ValueObject'));
        self::assertSame(2, $metrics->get('typeCoverage.propertyTyped:App\ValueObject'));
    }

    #[Test]
    public function itHandlesClassWithNoMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class EmptyClass
{
    public string $name;
}
PHP;

        $metrics = $this->collectMetrics($code);

        // No methods -> no param or return metrics
        self::assertNull($metrics->get('typeCoverage.param:App\EmptyClass'));
        self::assertNull($metrics->get('typeCoverage.return:App\EmptyClass'));
        self::assertSame(0, $metrics->get('typeCoverage.paramTotal:App\EmptyClass'));
        self::assertSame(0, $metrics->get('typeCoverage.returnTotal:App\EmptyClass'));

        // Property is typed
        self::assertSame(100.0, $metrics->get('typeCoverage.property:App\EmptyClass'));
    }

    #[Test]
    public function itHandlesClassWithNoProperties(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class NoPropsClass
{
    public function doSomething(): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertNull($metrics->get('typeCoverage.property:App\NoPropsClass'));
        self::assertSame(0, $metrics->get('typeCoverage.propertyTotal:App\NoPropsClass'));
    }

    #[Test]
    public function itExcludesConstructorFromReturnTypeCount(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithConstructor
{
    public function __construct(int $value)
    {
    }

    public function getValue(): int
    {
        return 0;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Only getValue() counted for return types (not __construct)
        self::assertSame(1, $metrics->get('typeCoverage.returnTotal:App\WithConstructor'));
        self::assertSame(1, $metrics->get('typeCoverage.returnTyped:App\WithConstructor'));
        self::assertSame(100.0, $metrics->get('typeCoverage.return:App\WithConstructor'));
    }

    #[Test]
    public function itExcludesDestructorFromReturnTypeCount(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class WithDestructor
{
    public function __destruct()
    {
    }

    public function process(): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Only process() counted for return types (not __destruct)
        self::assertSame(1, $metrics->get('typeCoverage.returnTotal:App\WithDestructor'));
        self::assertSame(100.0, $metrics->get('typeCoverage.return:App\WithDestructor'));
    }

    #[Test]
    public function itIncludesToStringInReturnTypeCount(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Stringable
{
    public function __toString(): string
    {
        return '';
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // __toString IS counted and has return type
        self::assertSame(1, $metrics->get('typeCoverage.returnTotal:App\Stringable'));
        self::assertSame(1, $metrics->get('typeCoverage.returnTyped:App\Stringable'));
        self::assertSame(100.0, $metrics->get('typeCoverage.return:App\Stringable'));
    }

    #[Test]
    public function itCountsNullableTypeAsTyped(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class NullableTest
{
    private ?string $name;

    public function getName(?int $id): ?string
    {
        return null;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(100.0, $metrics->get('typeCoverage.param:App\NullableTest'));
        self::assertSame(100.0, $metrics->get('typeCoverage.return:App\NullableTest'));
        self::assertSame(100.0, $metrics->get('typeCoverage.property:App\NullableTest'));
    }

    #[Test]
    public function itCountsUnionTypeAsTyped(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class UnionTest
{
    public string|int $value;

    public function process(string|int $input): string|bool
    {
        return '';
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(100.0, $metrics->get('typeCoverage.param:App\UnionTest'));
        self::assertSame(100.0, $metrics->get('typeCoverage.return:App\UnionTest'));
        self::assertSame(100.0, $metrics->get('typeCoverage.property:App\UnionTest'));
    }

    #[Test]
    public function itCountsMixedTypeAsTyped(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MixedTypeTest
{
    public mixed $data;

    public function process(mixed $input): mixed
    {
        return $input;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(100.0, $metrics->get('typeCoverage.param:App\MixedTypeTest'));
        self::assertSame(100.0, $metrics->get('typeCoverage.return:App\MixedTypeTest'));
        self::assertSame(100.0, $metrics->get('typeCoverage.property:App\MixedTypeTest'));
    }

    #[Test]
    public function itCollectsTypeCoverageForInterfaceMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Contracts;

interface ServiceInterface
{
    public function process(string $input): string;

    public function handle($request);
}
PHP;

        $metrics = $this->collectMetrics($code);

        // 1 typed param out of 2 = 50%
        self::assertSame(50.0, $metrics->get('typeCoverage.param:App\Contracts\ServiceInterface'));
        // 1 typed return out of 2 = 50%
        self::assertSame(50.0, $metrics->get('typeCoverage.return:App\Contracts\ServiceInterface'));
    }

    #[Test]
    public function itClearsStateOnReset(): void
    {
        $code1 = <<<'PHP'
<?php

namespace App;

class First
{
    public function method(int $a): void
    {
    }
}
PHP;

        $code2 = <<<'PHP'
<?php

namespace App;

class Second
{
    public function other($b)
    {
        return $b;
    }
}
PHP;

        $this->collectMetrics($code1);
        $this->collector->reset();
        $metrics = $this->collectMetrics($code2);

        // First class should not be present
        self::assertNull($metrics->get('typeCoverage.param:App\First'));

        // Second class should be present
        self::assertSame(0.0, $metrics->get('typeCoverage.param:App\Second'));
        self::assertSame(0.0, $metrics->get('typeCoverage.return:App\Second'));
    }

    #[Test]
    public function itReturnsCorrectMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(9, $definitions);

        // Check that total metrics have Sum aggregation
        $paramTotal = $definitions[0];
        self::assertSame('typeCoverage.paramTotal', $paramTotal->name);
        self::assertSame(SymbolLevel::Class_, $paramTotal->collectedAt);
        self::assertContains(AggregationStrategy::Sum, $paramTotal->getStrategiesForLevel(SymbolLevel::Namespace_));
        self::assertContains(AggregationStrategy::Sum, $paramTotal->getStrategiesForLevel(SymbolLevel::Project));

        // Check that percentage metrics have no aggregation
        $paramPercent = $definitions[2];
        self::assertSame('typeCoverage.param', $paramPercent->name);
        self::assertSame(SymbolLevel::Class_, $paramPercent->collectedAt);
        self::assertEmpty($paramPercent->getStrategiesForLevel(SymbolLevel::Namespace_));
        self::assertEmpty($paramPercent->getStrategiesForLevel(SymbolLevel::Project));
    }

    #[Test]
    public function itExcludesCloneFromReturnTypeCount(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Cloneable
{
    public function __clone()
    {
    }

    public function process(): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Only process() counted for return types (not __clone)
        self::assertSame(1, $metrics->get('typeCoverage.returnTotal:App\Cloneable'));
        self::assertSame(100.0, $metrics->get('typeCoverage.return:App\Cloneable'));
    }

    #[Test]
    public function itCollectsTypeCoverageForTraitMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Traits;

trait LoggableTrait
{
    private string $log;

    public function log(string $message): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(100.0, $metrics->get('typeCoverage.param:App\Traits\LoggableTrait'));
        self::assertSame(100.0, $metrics->get('typeCoverage.return:App\Traits\LoggableTrait'));
        self::assertSame(100.0, $metrics->get('typeCoverage.property:App\Traits\LoggableTrait'));
    }

    #[Test]
    public function itCollectsTypeCoverageForEnumMethods(): void
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

        self::assertSame(100.0, $metrics->get('typeCoverage.return:App\Enums\Status'));
    }

    #[Test]
    public function itSkipsAnonymousClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

$obj = new class {
    public $data;

    public function process($input)
    {
        return $input;
    }
};
PHP;

        $metrics = $this->collectMetrics($code);

        // Anonymous class should not produce any metrics
        self::assertSame([], $metrics->all());
    }

    #[Test]
    public function itCountsAllPropertiesInSingleDeclaration(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MultiProp
{
    public int $a, $b, $c;
    public $d;
}
PHP;

        $metrics = $this->collectMetrics($code);

        // 3 typed + 1 untyped = 4 total, 3 typed = 75%
        self::assertSame(4, $metrics->get('typeCoverage.propertyTotal:App\MultiProp'));
        self::assertSame(3, $metrics->get('typeCoverage.propertyTyped:App\MultiProp'));
        self::assertSame(75.0, $metrics->get('typeCoverage.property:App\MultiProp'));
    }

    #[Test]
    public function itDetectsReadonlyPromotedPropertyWithoutVisibility(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class ReadonlyVO
{
    public function __construct(
        readonly string $name,
        readonly int $age,
    ) {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // 2 params, both typed
        self::assertSame(2, $metrics->get('typeCoverage.paramTotal:App\ReadonlyVO'));
        self::assertSame(2, $metrics->get('typeCoverage.paramTyped:App\ReadonlyVO'));
        self::assertSame(100.0, $metrics->get('typeCoverage.param:App\ReadonlyVO'));

        // 2 promoted properties (readonly without visibility), both typed
        self::assertSame(2, $metrics->get('typeCoverage.propertyTotal:App\ReadonlyVO'));
        self::assertSame(2, $metrics->get('typeCoverage.propertyTyped:App\ReadonlyVO'));
        self::assertSame(100.0, $metrics->get('typeCoverage.property:App\ReadonlyVO'));
    }

    #[Test]
    public function itDetectsMixedReadonlyAndVisibilityPromotedProperties(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class ReadonlyMixed
{
    public function __construct(
        public readonly string $typed,
        readonly string $readonlyOnly,
        private $notPromotedButVisible,
    ) {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // 3 params: 2 typed (typed + readonlyOnly), 1 untyped
        self::assertSame(3, $metrics->get('typeCoverage.paramTotal:App\ReadonlyMixed'));
        self::assertSame(2, $metrics->get('typeCoverage.paramTyped:App\ReadonlyMixed'));

        // 3 promoted properties: typed (public readonly), readonlyOnly (readonly), notPromotedButVisible (private)
        self::assertSame(3, $metrics->get('typeCoverage.propertyTotal:App\ReadonlyMixed'));
        // 2 typed properties
        self::assertSame(2, $metrics->get('typeCoverage.propertyTyped:App\ReadonlyMixed'));
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
