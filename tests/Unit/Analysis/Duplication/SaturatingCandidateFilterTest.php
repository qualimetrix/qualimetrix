<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Duplication;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Duplication\SaturatingCandidateFilter;

#[CoversClass(SaturatingCandidateFilter::class)]
final class SaturatingCandidateFilterTest extends TestCase
{
    #[Test]
    public function itPromotesRepeatedHashesWithoutRetainingTheirPositions(): void
    {
        $filter = new SaturatingCandidateFilter(slotCount: 4);

        $filter->observe(1);
        self::assertFalse($filter->hasCandidates());
        self::assertFalse($filter->isCandidate(1));

        $filter->observe(1);

        self::assertTrue($filter->hasCandidates());
        self::assertTrue($filter->isCandidate(1));
    }

    #[Test]
    public function itNeverDemotesATrueCandidateWhenACollisionOccurs(): void
    {
        $filter = new SaturatingCandidateFilter(slotCount: 4);

        // 1 and 5 share one fixed-size slot. The collision may promote 5,
        // but the already repeated hash 1 must remain a candidate.
        $filter->observe(1);
        $filter->observe(1);
        $filter->observe(5);

        self::assertTrue($filter->isCandidate(1));
        self::assertTrue($filter->isCandidate(5));
    }
}
