<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassKeyScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\DefaultMetricRepositoryFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\MeasurementRepositoryFactory;

#[CoversClass(MeasurementRepositoryFactory::class)]
final class MeasurementRepositoryFactoryTest extends TestCase
{
    #[Test]
    public function itCreatesFreshStoresWithMeasuredComputedAndSuppliedDefinitions(): void
    {
        $measured = self::createStub(MetricDefinitionCatalogInterface::class);
        $measured->method('all')->willReturn([new MetricDefinition('size.fixture', SymbolLevel::Class_)]);
        $computed = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $computed->method('all')->willReturn([new ComputedMetricDefinition(
            'computed.fixture',
            ['class' => '1'],
            'fixture',
            [SymbolLevel::Class_],
        )]);
        $factory = new MeasurementRepositoryFactory($measured, $computed, new DefaultMetricRepositoryFactory());
        $subject = self::classSubject();
        $first = $factory->create([new MetricDefinition('custom.fixture', SymbolLevel::Class_)]);
        $first->addSubject($subject, MetricBag::fromArray([
            'size.fixture' => 1,
            'computed.fixture' => 2,
            'custom.fixture' => 3,
        ]), RelativePath::fromString('src/Fixture.php'), 1);

        self::assertSame(1, $first->getSubject($subject)->get('size.fixture'));
        self::assertSame(2, $first->getSubject($subject)->get('computed.fixture'));
        self::assertSame(3, $first->getSubject($subject)->get('custom.fixture'));
        self::assertFalse($factory->create()->hasSubject($subject));
    }

    #[Test]
    public function itResolvesCatalogsAgainForEachCreate(): void
    {
        $definitions = [new MetricDefinition('fixture.first', SymbolLevel::Class_)];
        $measured = self::createStub(MetricDefinitionCatalogInterface::class);
        $measured->method('all')->willReturnCallback(static function () use (&$definitions): array {
            return $definitions;
        });
        $computed = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $computed->method('all')->willReturn([]);
        $factory = new MeasurementRepositoryFactory($measured, $computed, new DefaultMetricRepositoryFactory());
        $first = $factory->create();
        $definitions = [new MetricDefinition('fixture.second', SymbolLevel::Class_)];
        $second = $factory->create();

        $first->addSubject(self::classSubject(), MetricBag::fromArray(['fixture.first' => 1]), null, null);
        $second->addSubject(self::classSubject(), MetricBag::fromArray(['fixture.second' => 2]), null, null);
        self::assertSame(1, $first->getSubject(self::classSubject())->get('fixture.first'));
        self::assertSame(2, $second->getSubject(self::classSubject())->get('fixture.second'));
    }

    #[Test]
    public function itRefusesConflictingSuppliedClassScope(): void
    {
        $measured = self::createStub(MetricDefinitionCatalogInterface::class);
        $measured->method('all')->willReturn([new MetricDefinition('fixture.scope', SymbolLevel::Class_)]);
        $computed = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $computed->method('all')->willReturn([]);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Conflicting class scope');
        (new MeasurementRepositoryFactory($measured, $computed, new DefaultMetricRepositoryFactory()))->create([
            new MetricDefinition('fixture.scope', SymbolLevel::Class_, classKeyScope: ClassKeyScope::LogicalName),
        ]);
    }

    #[Test]
    public function itNativeFactoryAppliesSuppliedFiniteDefinitions(): void
    {
        $repository = (new DefaultMetricRepositoryFactory())->create([
            new MetricDefinition('fixture.native', SymbolLevel::Class_),
        ]);
        $repository->addSubject(self::classSubject(), MetricBag::fromArray(['fixture.native' => 4]), null, null);

        self::assertSame(4, $repository->getSubject(self::classSubject())->get('fixture.native'));
    }

    private static function classSubject(): MetricSubject
    {
        return MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forClass('App', 'Fixture'),
            RelativePath::fromString('src/Fixture.php'),
            DeclarationOrdinal::fromRank(0),
        ));
    }
}
