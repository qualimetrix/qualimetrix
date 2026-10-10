<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\LogicalClassMetricIndex;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\MetricSubjectIndex;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(MetricSubjectIndex::class)]
#[CoversClass(LogicalClassMetricIndex::class)]
final class MetricSubjectIndexTest extends TestCase
{
    #[Test]
    public function itKeepsDuplicateLogicalDeclarationsAsSeparateExactSubjects(): void
    {
        $index = new MetricSubjectIndex();
        $logical = SymbolPath::forMethod('App', 'Service', 'run');
        $first = DeclarationPath::of($logical, RelativePath::fromString('src/First.php'), DeclarationOrdinal::fromRank(0));
        $second = DeclarationPath::of($logical, RelativePath::fromString('src/Second.php'), DeclarationOrdinal::fromRank(0));
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Service'));

        $index->addCallable(new CallableWithMetrics(
            $first,
            0,
            CallableKind::Method,
            null,
            null,
            $owner,
            MetricBag::fromArray(['complexity.ccn' => 3]),
            10,
        ));
        $index->addCallable(new CallableWithMetrics(
            $second,
            0,
            CallableKind::Method,
            null,
            null,
            $owner,
            MetricBag::fromArray(['complexity.ccn' => 5]),
            20,
        ));

        self::assertSame(3, $index->get(MetricSubject::declaration($first))->get('complexity.ccn'));
        self::assertSame(5, $index->get(MetricSubject::declaration($second))->get('complexity.ccn'));
        self::assertCount(2, iterator_to_array($index->allCallables(), false));
    }

    #[Test]
    public function itStartsWithEmptyTypedIndexes(): void
    {
        $index = new MetricSubjectIndex();
        $logical = new LogicalClassMetricIndex();

        self::assertSame([], $index->infos());
        self::assertSame([], iterator_to_array($index->allDeclarations(), false));
        self::assertSame([], iterator_to_array($logical->allLogicalClasses(), false));
    }

    #[Test]
    public function itPreservesExactDeclarationsWhileFoldingLogicalClassSpellings(): void
    {
        $index = new MetricSubjectIndex();
        $logicalIndex = new LogicalClassMetricIndex();
        $first = DeclarationPath::of(
            SymbolPath::forClass('App', 'Service'),
            RelativePath::fromString('src/First.php'),
            DeclarationOrdinal::fromRank(0),
        );
        $second = DeclarationPath::of(
            SymbolPath::forClass('app', 'service'),
            RelativePath::fromString('src/Second.php'),
            DeclarationOrdinal::fromRank(0),
        );

        $index->add(MetricSubject::declaration($first), MetricBag::fromArray(['first' => 1]), $first->file, 1);
        $index->add(MetricSubject::declaration($second), MetricBag::fromArray(['second' => 2]), $second->file, 2);
        $logicalIndex->addLogicalClass($first->logical, MetricBag::fromArray(['first' => 1]), null, null);
        $logicalIndex->addLogicalClass($second->logical, MetricBag::fromArray(['second' => 2]), null, null);

        self::assertCount(2, iterator_to_array($index->allDeclarations(), false));
        $logical = iterator_to_array($logicalIndex->allLogicalClasses(), false);
        self::assertCount(1, $logical);
        self::assertSame('App\\Service', $logical[0]->symbolPath->toString());
        self::assertSame(1, $logicalIndex->logicalClassMetrics($first->logical)?->get('first'));
        self::assertSame(2, $logicalIndex->logicalClassMetrics($second->logical)?->get('second'));
        self::assertCount(1, $logicalIndex->mixedSpellings());
        self::assertSame(['App\\Service', 'app\\service'], $logicalIndex->mixedSpellings()[0]->spellings);
    }

    #[Test]
    public function itPreservesObservedLogicalSpellingsAcrossMerges(): void
    {
        $mixed = new LogicalClassMetricIndex();
        $mixed->addLogicalClass(SymbolPath::fromClassFqn('App\\Service'), MetricBag::fromArray(['first' => 1]), null, null);
        $mixed->addLogicalClass(SymbolPath::fromClassFqn('app\\service'), MetricBag::fromArray(['second' => 2]), null, null);
        $other = new LogicalClassMetricIndex();
        $other->addLogicalClass(SymbolPath::fromClassFqn('Other\\Thing'), MetricBag::fromArray(['other' => 3]), null, null);

        $merged = $mixed->mergeWith($other);

        self::assertCount(1, $merged->mixedSpellings());
        self::assertSame(['App\\Service', 'app\\service'], $merged->mixedSpellings()[0]->spellings);
        self::assertCount(2, iterator_to_array($merged->allLogicalClasses(), false));
        $metrics = $merged->logicalClassMetrics(SymbolPath::fromClassFqn('APP\\SERVICE'));
        self::assertNotNull($metrics);
        self::assertSame(1, $metrics->get('first'));
        self::assertSame(2, $metrics->get('second'));
    }
}
