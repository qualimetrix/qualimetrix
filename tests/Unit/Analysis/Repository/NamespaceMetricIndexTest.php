<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Repository\NamespaceMetricIndex;
use Qualimetrix\Core\Path\RelativePath;
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
            MetricSubject::declaration(new DeclarationPath(SymbolPath::forMethod('App', 'Service', 'run'), RelativePath::fromString('src/Service.php'), 100)),
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
            MetricSubject::declaration(new DeclarationPath($logicalClass, RelativePath::fromString('src/Service.php'), 10)),
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
