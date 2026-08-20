<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\NamespaceMetricIndex;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(NamespaceMetricIndex::class)]
final class NamespaceMetricIndexTest extends TestCase
{
    #[Test]
    public function itProjectsNonAggregateNamespaceSubjectsOnce(): void
    {
        $index = new NamespaceMetricIndex();
        $class = new SymbolInfo(MetricSubject::logicalClass(new LogicalClassPath(SymbolPath::forClass('App', 'Service'))), null, null);
        $callable = new SymbolInfo(
            MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'run'), RelativePath::fromString('src/Service.php'), DeclarationOrdinal::fromRank(0))),
            RelativePath::fromString('src/Service.php'),
            10,
        );
        $namespace = new SymbolInfo(MetricSubject::aggregate(SymbolPath::forNamespace('App')), null, null);
        $file = new SymbolInfo(MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Service.php'))), null, null);
        $project = new SymbolInfo(MetricSubject::aggregate(SymbolPath::forProject()), null, null);

        foreach ([$class, $callable, $namespace, $file, $project, $callable] as $info) {
            $index->add($info);
        }

        self::assertSame(['App'], $index->namespaces());
        self::assertCount(2, $index->forNamespace('App'));
        self::assertSame([], $index->forNamespace(''));
    }

    #[Test]
    public function itRebuildsWithoutExactClassDeclarations(): void
    {
        $index = new NamespaceMetricIndex();
        $logicalClass = SymbolPath::forClass('App', 'Service');
        $exactClass = new SymbolInfo(
            MetricSubject::declaration(DeclarationPath::of($logicalClass, RelativePath::fromString('src/Service.php'), DeclarationOrdinal::fromRank(0))),
            RelativePath::fromString('src/Service.php'),
            2,
        );
        $projection = new SymbolInfo(MetricSubject::logicalClass(new LogicalClassPath($logicalClass)), null, null);

        $index->rebuild([], [$exactClass, $projection, $projection]);

        self::assertSame(['App'], $index->namespaces());
        self::assertCount(1, $index->forNamespace('App'));
    }

    #[Test]
    public function itHasNoDegreeZeroNamespaceProjection(): void
    {
        $index = new NamespaceMetricIndex();

        self::assertSame([], $index->namespaces());
        self::assertSame([], $index->forNamespace('App'));
    }
}
