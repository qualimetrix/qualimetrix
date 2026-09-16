<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\FindingVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;

/**
 * Split off from `FindingFilterStageTest` (which keeps the hand-pinned
 * membership table itself): every case is covered by that table; a new one
 * must not default into the measured set by being forgotten there.
 */
#[CoversClass(FindingFilterStage::class)]
final class FindingFilterStageMembershipCensusTest extends TestCase
{
    #[Test]
    public function itCoversEveryStageInTheMembershipTable(): void
    {
        $covered = array_map(
            static fn(array $case): FindingFilterStage => $case[0],
            iterator_to_array(self::provideStageMembership()),
        );

        self::assertSame(FindingFilterStage::cases(), array_values($covered));
    }

    /**
     * @return iterable<string, array{FindingFilterStage, bool}>
     */
    private static function provideStageMembership(): iterable
    {
        yield 'suppression' => [FindingFilterStage::Suppression, true];
        yield 'path exclusion' => [FindingFilterStage::PathExclusion, true];
        yield 'namespace exclusion' => [FindingFilterStage::NamespaceExclusion, true];
        yield 'baseline' => [FindingFilterStage::Baseline, false];
        yield 'git scope' => [FindingFilterStage::GitScope, false];
    }
}
