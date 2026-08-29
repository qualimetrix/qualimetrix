<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\MetricSubjectIndex;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(MetricSubjectIndex::class)]
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

        self::assertSame(2, \count($index->declarationsForLogical($logical->toCanonical())));
        self::assertSame(3, $index->get(MetricSubject::declaration($first))->get('complexity.ccn'));
        self::assertSame(5, $index->get(MetricSubject::declaration($second))->get('complexity.ccn'));
        self::assertCount(2, iterator_to_array($index->allCallables(), false));
    }

    #[Test]
    public function itStartsWithEmptyTypedIndexes(): void
    {
        $index = new MetricSubjectIndex();

        self::assertSame([], $index->infos());
        self::assertSame([], $index->declarationsForLogical('callable:App\\Service::run'));
        self::assertSame([], iterator_to_array($index->allDeclarations(), false));
        self::assertSame([], iterator_to_array($index->allLogicalClasses(), false));
    }
}
