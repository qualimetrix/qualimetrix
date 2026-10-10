<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Aggregation;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\NamespaceMetricContributions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassKeyScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(NamespaceMetricContributions::class)]
#[CoversClass(FileNamespaceIndex::class)]
final class NamespaceMetricContributionsTest extends TestCase
{
    /** @return iterable<string, array{AggregationStrategy}> */
    public static function fileContributionStrategies(): iterable
    {
        yield 'sum' => [AggregationStrategy::Sum];
        yield 'count' => [AggregationStrategy::Count];
        yield 'average' => [AggregationStrategy::Average];
    }

    #[Test]
    #[DataProvider('fileContributionStrategies')]
    public function itRefusesNonzeroTotalsWithoutContributingFiles(AggregationStrategy $strategy): void
    {
        self::expectException(LogicException::class);
        NamespaceMetricContributions::applyFileContributions($strategy, [['total' => 8, 'files' => 0]]);
    }

    #[Test]
    public function itReducesWeightedFileContributionsWithoutLosingIntegerTotalsOrFileCounts(): void
    {
        $values = [['total' => 8, 'files' => 2], 3, ['total' => 4, 'files' => 1]];

        self::assertSame(15, NamespaceMetricContributions::applyFileContributions(AggregationStrategy::Sum, $values));
        self::assertSame(4, NamespaceMetricContributions::applyFileContributions(AggregationStrategy::Count, $values));
        self::assertSame(3.75, NamespaceMetricContributions::applyFileContributions(AggregationStrategy::Average, $values));
        self::assertSame(0, NamespaceMetricContributions::applyFileContributions(AggregationStrategy::Average, []));
        self::assertSame(0, NamespaceMetricContributions::applyFileContributions(AggregationStrategy::Average, [['total' => 0, 'files' => 0]]));
        self::assertSame(0, NamespaceMetricContributions::applyFileContributions(AggregationStrategy::Average, [['total' => 0.0, 'files' => 0]]));

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Unsupported namespace file-contribution strategy');
        NamespaceMetricContributions::applyFileContributions(AggregationStrategy::Max, $values);
    }

    #[Test]
    public function itSelectsNamespaceContributionsButKeepsProjectValuesFileDerived(): void
    {
        $definition = new MetricDefinition('size.loc', SymbolLevel::File, [
            SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
            SymbolLevel::Project->value => [AggregationStrategy::Sum],
        ], namespaceFileContribution: true);
        $repository = new InMemoryMetricRepository([$definition]);
        $file = RelativePath::fromString('src/Multi.php');
        $repository->add(SymbolPath::forFile($file), MetricBag::fromArray(['size.loc' => 20]), $file, 1);
        $repository->add(SymbolPath::forNamespace('One'), MetricBag::fromArray([
            'size.loc' => 8,
        ])->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.loc']), $file, 2);
        $fileSymbols = array_values(iterator_to_array($repository->all(SymbolLevel::File)));
        $namespaceSymbols = $repository->forNamespace('One');

        self::assertSame(
            ['size.loc' => [['total' => 8, 'files' => 1]]],
            NamespaceMetricContributions::collectValues(
                $repository,
                $namespaceSymbols,
                $fileSymbols,
                [$definition],
                SymbolLevel::Namespace_,
            ),
        );
        self::assertSame(
            ['size.loc' => [['total' => 20, 'files' => 1]]],
            NamespaceMetricContributions::collectValues(
                $repository,
                $namespaceSymbols,
                $fileSymbols,
                [$definition],
                SymbolLevel::Project,
            ),
        );
    }

    #[Test]
    public function itCollectsEachTypedSymbolLevelAndExpandsNamespaceOwnedAverages(): void
    {
        $definitions = [
            new MetricDefinition('callableScore', SymbolLevel::Callable),
            new MetricDefinition('classScore', SymbolLevel::Class_),
            new MetricDefinition('size.loc', SymbolLevel::File, [SymbolLevel::Namespace_->value => [AggregationStrategy::Sum]], namespaceFileContribution: true),
            new MetricDefinition('tokens', SymbolLevel::File, [SymbolLevel::Namespace_->value => [AggregationStrategy::Average]], namespaceFileContribution: true),
        ];
        $repository = new InMemoryMetricRepository($definitions);
        $file = RelativePath::fromString('src/Multi.php');
        $classPath = DeclarationPath::of(SymbolPath::forClass('One', 'Service'), $file, DeclarationOrdinal::fromRank(0));
        $methodPath = DeclarationPath::of(SymbolPath::forMethod('One', 'Service', 'run'), $file, DeclarationOrdinal::fromRank(0));
        $functionPath = DeclarationPath::of(SymbolPath::forGlobalFunction('One', 'helper'), $file, DeclarationOrdinal::fromRank(0));

        $repository->add(SymbolPath::forFile($file), MetricBag::fromArray(['size.loc' => 20, 'tokens' => 30]), $file, 1);
        $repository->addSubject(
            MetricSubject::declaration($classPath),
            MetricBag::fromArray(['classScore' => 7]),
            $file,
            2,
        );
        $repository->addCallable(new CallableWithMetrics(
            $methodPath,
            0,
            CallableKind::Method,
            null,
            $classPath,
            new LogicalClassPath(SymbolPath::forClass('One', 'Service')),
            MetricBag::fromArray(['callableScore' => 3]),
        ));
        $repository->addCallable(new CallableWithMetrics(
            $functionPath,
            0,
            CallableKind::Function,
            null,
            null,
            null,
            MetricBag::fromArray(['callableScore' => 5]),
        ));
        $repository->add(SymbolPath::forNamespace('One'), MetricBag::fromArray([
            'size.loc' => 8,
            'tokens' => 6,
        ])
            ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.loc'])
            ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'size.loc'])
            ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'tokens'])
            ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'tokens'])
            ->withEntry(MetricName::NAMESPACE_FILE_CONTRIBUTION, ['metric' => 'tokens']), $file, 2);

        self::assertSame([
            'callableScore' => [3, 5],
            'classScore' => [7],
            'size.loc' => [['total' => 8, 'files' => 2]],
            'tokens' => [['total' => 6, 'files' => 3]],
        ], NamespaceMetricContributions::collectValues(
            $repository,
            $repository->forNamespace('One'),
            array_values(iterator_to_array($repository->all(SymbolLevel::File))),
            $definitions,
            SymbolLevel::Namespace_,
        ));
    }

    #[Test]
    public function itMapsOnePhysicalFileToEveryOwnedNamespace(): void
    {
        $repository = new InMemoryMetricRepository();
        $file = RelativePath::fromString('src/Multi.php');
        $repository->add(SymbolPath::forFile($file), new MetricBag(), $file, 1);
        $repository->addSubject(
            MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('One', 'First'), $file, DeclarationOrdinal::fromRank(0))),
            new MetricBag(),
            $file,
            2,
        );
        $repository->addSubject(
            MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('One', 'First'), $file, DeclarationOrdinal::fromRank(1))),
            new MetricBag(),
            $file,
            3,
        );
        $repository->add(SymbolPath::forClass('Two', 'Second'), new MetricBag(), $file, 10);

        $fileMap = FileNamespaceIndex::fromRepository($repository);
        $namespaceMap = NamespaceMetricContributions::mapNamespacesToFileSymbols($repository, $fileMap);

        self::assertSame(['One', 'Two'], $fileMap->namespacesOf($file));
        self::assertCount(1, $namespaceMap['One']);
        self::assertCount(1, $namespaceMap['Two']);
        self::assertSame($file, $namespaceMap['One'][0]->file);
        self::assertSame($file, $namespaceMap['Two'][0]->file);
    }

    #[Test]
    public function itSamplesLogicalGraphValuesOncePerExactClassDeclaration(): void
    {
        $definitions = [
            new MetricDefinition('size.method-count', SymbolLevel::Class_, [SymbolLevel::Namespace_->value => [AggregationStrategy::Count]]),
            new MetricDefinition('coupling.cbo', SymbolLevel::Class_, [SymbolLevel::Namespace_->value => [AggregationStrategy::Count]], classKeyScope: ClassKeyScope::LogicalName),
        ];
        $repository = new InMemoryMetricRepository($definitions);
        $file = RelativePath::fromString('src/Duplicate.php');
        $logical = SymbolPath::forClass('One', 'Duplicate');

        foreach ([1, 2] as $ordinal => $methodCount) {
            $repository->addSubject(
                MetricSubject::declaration(DeclarationPath::of($logical, $file, DeclarationOrdinal::fromRank($ordinal))),
                MetricBag::fromArray(['size.method-count' => $methodCount]),
                $file,
                $ordinal + 2,
            );
        }
        $repository->addSubject(
            MetricSubject::logicalClass(new LogicalClassPath($logical)),
            MetricBag::fromArray(['coupling.cbo' => 3]),
            $file,
            2,
        );

        self::assertSame(
            ['size.method-count' => [1, 2], 'coupling.cbo' => [3, 3]],
            NamespaceMetricContributions::collectValues(
                $repository,
                $repository->forNamespace('One'),
                [],
                $definitions,
                SymbolLevel::Namespace_,
            ),
        );
    }
    #[Test]
    public function itDoesNotAttributeAnUndeclaredFileToANamespaceAggregate(): void
    {
        $repository = new InMemoryMetricRepository();
        $file = RelativePath::fromString('src/Script.php');
        $repository->add(SymbolPath::forFile($file), MetricBag::fromArray(['size.loc' => 17]), $file, 1);
        $index = FileNamespaceIndex::fromRepository($repository);

        self::assertSame([], $index->namespacesOf($file));
        self::assertSame([], $index->namespacesOf(RelativePath::fromString('src/Unknown.php')));
        self::assertSame([], FileNamespaceIndex::fromRepository(null)->namespacesOf($file));
        self::assertSame([], NamespaceMetricContributions::mapNamespacesToFileSymbols($repository, $index));
    }

}
