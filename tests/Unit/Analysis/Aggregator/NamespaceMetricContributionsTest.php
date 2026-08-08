<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Aggregator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\NamespaceMetricContributions;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Path\RelativePath;
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
    public function itMapsOnePhysicalFileToEveryOwnedNamespace(): void
    {
        $repository = new InMemoryMetricRepository();
        $file = RelativePath::fromString('src/Multi.php');
        $repository->add(SymbolPath::forFile($file), new MetricBag(), $file, 1);
        $repository->add(SymbolPath::forClass('One', 'First'), new MetricBag(), $file, 2);
        $repository->add(SymbolPath::forClass('Two', 'Second'), new MetricBag(), $file, 10);

        $fileMap = NamespaceMetricContributions::mapFilesToNamespaces($repository);
        $namespaceMap = NamespaceMetricContributions::mapNamespacesToFileSymbols($repository, $fileMap);

        self::assertSame(['One', 'Two'], $fileMap['src/Multi.php']);
        self::assertSame($file, $namespaceMap['One'][0]->file);
        self::assertSame($file, $namespaceMap['Two'][0]->file);
    }
}
