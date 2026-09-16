<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\FindingVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Tests\Analysis\Finding\Unit\FindingFilterStageTest;

/**
 * Split off from `FindingFilterStageTest` (which keeps the hand-pinned
 * membership table itself): every case is covered by that table; a new one
 * must not default into the measured set by being forgotten there.
 *
 * Reads `FindingFilterStageTest::provideStageMembership()` directly rather
 * than a copy — the table IS the behavioural test's case list, so a census
 * over its own copy would only prove the copy covers the enum, never the
 * table the behavioural test actually executes.
 */
#[CoversClass(FindingFilterStage::class)]
final class FindingFilterStageMembershipCensusTest extends TestCase
{
    #[Test]
    public function itCoversEveryStageInTheMembershipTable(): void
    {
        $covered = array_map(
            static fn(array $case): FindingFilterStage => $case[0],
            iterator_to_array(FindingFilterStageTest::provideStageMembership()),
        );

        self::assertSame(FindingFilterStage::cases(), array_values($covered));
    }
}
