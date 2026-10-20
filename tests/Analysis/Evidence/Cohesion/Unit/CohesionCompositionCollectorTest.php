<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Cohesion\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Cohesion\CohesionCompositionCollector;
use Qualimetrix\Analysis\Evidence\Cohesion\CohesionFactWire;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomClassData;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomCollector;
use Qualimetrix\Analysis\Evidence\Cohesion\TccLccClassData;
use Qualimetrix\Analysis\Evidence\Cohesion\TccLccCollector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(CohesionCompositionCollector::class)]
final class CohesionCompositionCollectorTest extends TestCase
{
    #[Test]
    public function itRefusesTruncatedRawFacts(): void
    {
        $data = new LcomClassData('App', 'Twin', 1, 0);
        $data->addMethod('one');
        $rows = CohesionFactWire::lcomRows($data);
        array_pop($rows);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('incomplete');
        CohesionFactWire::lcomFromRows($rows);
    }

    #[Test]
    #[DataProvider('invalidBooleanFacts')]
    public function itRejectsNonBooleanLcomFacts(int $rowIndex, string $key, int|string $value): void
    {
        $data = new LcomClassData('App', 'Twin', 1, 0);
        $data->addMethod('one');
        $rows = CohesionFactWire::lcomRows($data);
        $rows[$rowIndex][$key] = $value;

        $this->expectException(LogicException::class);
        CohesionFactWire::lcomFromRows($rows);
    }

    /** @return iterable<string, array{int, string, int|string}> */
    public static function invalidBooleanFacts(): iterable
    {
        yield 'nonTrivial' => [0, 'nonTrivial', 'true'];
        yield 'static' => [1, 'static', 1];
        yield 'stateless' => [1, 'stateless', 'false'];
        yield 'abstract' => [2, 'abstract', 'false'];
    }

    #[Test]
    public function itRejectsNonIntegerInstancePropertyCount(): void
    {
        $rows = CohesionFactWire::lcomRows(new LcomClassData('App', 'Twin', 1, 0));
        $rows[1]['count'] = '0';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('instance property count');
        CohesionFactWire::lcomFromRows($rows);
    }

    #[Test]
    public function itKeepsSerializedSameNameDeclarationsSeparate(): void
    {
        $first = self::declaration('src/First.php');
        $second = self::declaration('src/Second.php');
        $lcomFirst = new LcomClassData('App', 'Twin', 1, 0);
        $lcomFirst->addMethod('one');
        $lcomFirst->addMethod('two');
        $lcomFirst->addPropertyAccess('one', 'a');
        $lcomFirst->addPropertyAccess('two', 'a');
        $lcomSecond = new LcomClassData('App', 'Twin', 1, 0);
        $lcomSecond->addMethod('one');
        $lcomSecond->addMethod('two');
        $tccFirst = self::tcc(true);
        $tccSecond = self::tcc(false);

        $collector = new CohesionCompositionCollector();
        $repository = new InMemoryMetricRepository($collector->getMetricDefinitions());
        $repository->addSubject(MetricSubject::declaration($first), self::wire($lcomFirst, $tccFirst), $first->file, 1);
        $repository->addSubject(MetricSubject::declaration($second), self::wire($lcomSecond, $tccSecond), $second->file, 1);
        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getClassLikeDeclarations')->willReturn([
            ClassLikeDeclaration::of($first, ClassType::Class_, false, false),
            ClassLikeDeclaration::of($second, ClassType::Class_, false, false),
        ]);

        $collector->calculate($graph, $repository);

        self::assertSame(1, $repository->getSubject(MetricSubject::declaration($first))->get('cohesion.lcom'));
        self::assertSame(2, $repository->getSubject(MetricSubject::declaration($second))->get('cohesion.lcom'));
        self::assertSame(1.0, $repository->getSubject(MetricSubject::declaration($first))->get('cohesion.tcc'));
        self::assertSame(0.0, $repository->getSubject(MetricSubject::declaration($second))->get('cohesion.tcc'));
    }

    #[Test]
    public function itReplacesExcludedMethodsBetweenInvocations(): void
    {
        $declaration = self::declaration('src/Configured.php');
        $lcom = new LcomClassData('App', 'Twin', 1, 0);
        $lcom->addMethod('work');
        $lcom->addMethod('metadata');
        $tcc = self::tcc(true);
        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getClassLikeDeclarations')->willReturn([ClassLikeDeclaration::of($declaration, ClassType::Class_, false, false)]);
        $collector = new CohesionCompositionCollector();

        $collector->applyLcomCollectionConfiguration(new LcomCollectionConfiguration(['metadata']));
        $configured = new InMemoryMetricRepository($collector->getMetricDefinitions());
        $configured->addSubject(MetricSubject::declaration($declaration), self::wire($lcom, $tcc), $declaration->file, 1);
        $collector->calculate($graph, $configured);
        self::assertSame(1, $configured->getSubject(MetricSubject::declaration($declaration))->get('cohesion.lcom'));

        $collector->applyLcomCollectionConfiguration(LcomCollectionConfiguration::defaults());
        $default = new InMemoryMetricRepository($collector->getMetricDefinitions());
        $default->addSubject(MetricSubject::declaration($declaration), self::wire($lcom, $tcc), $declaration->file, 1);
        $collector->calculate($graph, $default);
        self::assertSame(2, $default->getSubject(MetricSubject::declaration($declaration))->get('cohesion.lcom'));
    }

    private static function declaration(string $file): DeclarationPath
    {
        return DeclarationPath::of(SymbolPath::forClass('App', 'Twin'), RelativePath::fromString($file), DeclarationOrdinal::fromRank(0));
    }

    private static function tcc(bool $shared): TccLccClassData
    {
        $data = new TccLccClassData('App', 'Twin', 1, 0);
        $data->incrementPropertyCount(2);
        $data->addMethod('one');
        $data->addMethod('two');
        $data->addPropertyAccess('one', 'a');
        $data->addPropertyAccess('two', $shared ? 'a' : 'b');

        return $data;
    }

    private static function wire(LcomClassData $lcom, TccLccClassData $tcc): MetricBag
    {
        $bag = new MetricBag();
        foreach (CohesionFactWire::lcomRows($lcom) as $row) {
            $bag = $bag->withEntry(LcomCollector::FACTS, $row);
        }
        foreach (CohesionFactWire::tccRows($tcc) as $row) {
            $bag = $bag->withEntry(TccLccCollector::FACTS, $row);
        }
        $wire = unserialize(serialize($bag));
        self::assertInstanceOf(MetricBag::class, $wire);

        return $wire;
    }
}
