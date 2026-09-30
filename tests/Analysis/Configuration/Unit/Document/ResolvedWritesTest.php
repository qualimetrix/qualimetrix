<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;
use Qualimetrix\Analysis\Configuration\Document\LayerMerge;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedList;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedScalar;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document\SampleDocument;

#[CoversClass(LayerMerge::class)]
#[CoversClass(ResolvedScalar::class)]
#[CoversClass(ResolvedList::class)]
final class ResolvedWritesTest extends TestCase
{
    #[Test]
    public function itKeepsEveryScalarWriteWithoutChangingTheWinner(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['fail_on' => 'warning']),
            SampleDocument::file(['fail_on' => null]),
            SampleDocument::preset(['fail_on' => 'error'], 'later'),
        );

        $value = $document->get('fail_on');
        self::assertInstanceOf(ResolvedWriteHistoryInterface::class, $value);
        self::assertSame('error', $value->plain());
        self::assertSame(['warning', 'error'], array_column($value->writes(), 'value'));
        self::assertSame([0, 2], array_map(static fn(array $write): int => $write['provenance']->layerIndex, $value->writes()));
        self::assertSame(['later'], array_map(static fn($writer): ?string => $writer->origin->locator(), $value->contributors()));
    }

    #[Test]
    public function itRetainsReplacedListsIncludingAnEmptyWinningListAndItsNotice(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['only_rules' => ['complexity']]),
            SampleDocument::preset(['only_rules' => []], 'empty'),
            SampleDocument::file(['only_rules' => []]),
        );

        $value = $document->get('only_rules');
        self::assertInstanceOf(ResolvedWriteHistoryInterface::class, $value);
        self::assertSame([], $value->plain());
        self::assertSame([['complexity'], [], []], array_column($value->writes(), 'value'));
        self::assertSame([0, 1, 2], array_map(static fn(array $write): int => $write['provenance']->layerIndex, $value->writes()));
        self::assertCount(1, $value->contributors());
        self::assertCount(1, $document->diagnostics());
        self::assertSame(['strict', '/p/qmx.yaml'], array_map(static fn($writer): ?string => $writer->origin->locator(), $document->diagnostics()[0]->sources));
    }

    #[Test]
    public function itKeepsEachSourceListBeforeAccumulationDeduplicatesValues(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['exclude' => ['vendor', 'build']]),
            SampleDocument::file(['exclude' => ['build', 'var']]),
        );

        $value = $document->get('exclude');
        self::assertInstanceOf(ResolvedWriteHistoryInterface::class, $value);
        self::assertSame(['vendor', 'build', 'var'], $value->plain());
        self::assertSame([['vendor', 'build'], ['build', 'var']], array_column($value->writes(), 'value'));
        self::assertCount(2, $value->contributors());
    }
}
