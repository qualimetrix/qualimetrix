<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Aggregator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\NamespaceMetricContributions;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

#[CoversClass(NamespaceMetricContributions::class)]
final class NamespaceMetricContributionsTest extends TestCase
{
    #[Test]
    public function itSelectsNamespaceContributionsButKeepsProjectValuesFileDerived(): void
    {
        $repository = new InMemoryMetricRepository();
        $file = RelativePath::fromString('src/Multi.php');
        $repository->add(SymbolPath::forFile($file), MetricBag::fromArray(['loc' => 20]), $file, 1);
        $repository->add(SymbolPath::forNamespace('One'), MetricBag::fromArray([
            'loc' => 8,
            'loc.count' => 1,
        ]), $file, 2);

        $definition = new MetricDefinition('loc', SymbolLevel::File, [
            SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
            SymbolLevel::Project->value => [AggregationStrategy::Sum],
        ]);
        $fileSymbols = array_values(iterator_to_array($repository->all(SymbolType::File)));
        $namespaceSymbols = $repository->forNamespace('One');

        self::assertSame(
            ['loc' => [8]],
            NamespaceMetricContributions::collectValues(
                $repository,
                $namespaceSymbols,
                $fileSymbols,
                [$definition],
                SymbolLevel::Namespace_,
            ),
        );
        self::assertSame(
            ['loc' => [20]],
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
        $repository = new InMemoryMetricRepository();
        $file = RelativePath::fromString('src/Multi.php');
        $classPath = new DeclarationPath(SymbolPath::forClass('One', 'Service'), $file, 10);
        $methodPath = new DeclarationPath(SymbolPath::forMethod('One', 'Service', 'run'), $file, 20);
        $functionPath = new DeclarationPath(SymbolPath::forGlobalFunction('One', 'helper'), $file, 30);

        $repository->add(SymbolPath::forFile($file), MetricBag::fromArray(['loc' => 20, 'tokens' => 30]), $file, 1);
        $repository->addSubject(
            MetricSubject::declaration($classPath),
            MetricBag::fromArray(['classScore' => 7]),
            $file,
            2,
        );
        $repository->addCallable(new CallableWithMetrics(
            $methodPath,
            CallableKind::Method,
            null,
            $classPath,
            new LogicalClassPath(SymbolPath::forClass('One', 'Service')),
            MetricBag::fromArray(['callableScore' => 3]),
        ));
        $repository->addCallable(new CallableWithMetrics(
            $functionPath,
            CallableKind::Function,
            null,
            null,
            null,
            MetricBag::fromArray(['callableScore' => 5]),
        ));
        $repository->add(SymbolPath::forNamespace('One'), MetricBag::fromArray([
            'loc' => 8,
            'loc.count' => 2,
            'tokens' => 6,
            'tokens.count' => 3,
        ]), $file, 2);

        $definitions = [
            new MetricDefinition('callableScore', SymbolLevel::Callable),
            new MetricDefinition('classScore', SymbolLevel::Class_),
            new MetricDefinition('loc', SymbolLevel::File, [
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
            ]),
            new MetricDefinition('tokens', SymbolLevel::File, [
                SymbolLevel::Namespace_->value => [AggregationStrategy::Average],
            ]),
        ];

        self::assertSame([
            'callableScore' => [3, 5],
            'classScore' => [7],
            'loc' => [8],
            'tokens' => [2, 2, 2],
        ], NamespaceMetricContributions::collectValues(
            $repository,
            $repository->forNamespace('One'),
            array_values(iterator_to_array($repository->all(SymbolType::File))),
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
            MetricSubject::declaration(new DeclarationPath(SymbolPath::forClass('One', 'First'), $file, 10)),
            new MetricBag(),
            $file,
            2,
        );
        $repository->addSubject(
            MetricSubject::declaration(new DeclarationPath(SymbolPath::forClass('One', 'First'), $file, 20)),
            new MetricBag(),
            $file,
            3,
        );
        $repository->add(SymbolPath::forClass('Two', 'Second'), new MetricBag(), $file, 10);

        $fileMap = NamespaceMetricContributions::mapFilesToNamespaces($repository);
        $namespaceMap = NamespaceMetricContributions::mapNamespacesToFileSymbols($repository, $fileMap);

        self::assertSame(['One', 'Two'], $fileMap['src/Multi.php']);
        self::assertCount(1, $namespaceMap['One']);
        self::assertCount(1, $namespaceMap['Two']);
        self::assertSame($file, $namespaceMap['One'][0]->file);
        self::assertSame($file, $namespaceMap['Two'][0]->file);
    }
}
