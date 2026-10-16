<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\FindingVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Reporting\FindingProjection\SuppressionMechanism;

/**
 * Regression guard for the closed vocabulary the `suppressed` format uses:
 * eight values, five derived one-to-one from
 * {@see FindingFilterStage}, the two per-rule ledger halves and selection.
 *
 * `itMapsEveryStageToADistinctMechanism()` is what actually fails the build
 * when a sixth {@see FindingFilterStage} case is declared:
 * {@see SuppressionMechanism::fromStage()}'s `match` is exhaustive over
 * `FindingFilterStage`, so PHPStan refuses an unhandled case at the type
 * level; this test additionally proves the enum's own case count has not
 * drifted from that mapping at runtime.
 */
#[CoversClass(SuppressionMechanism::class)]
final class SuppressionMechanismTest extends TestCase
{
    #[Test]
    public function itHasExactlyEightValues(): void
    {
        self::assertCount(
            \count(FindingFilterStage::cases()) + \count(SuppressionMechanism::ledgerHalves()) + 1,
            SuppressionMechanism::cases(),
        );
        self::assertCount(8, SuppressionMechanism::cases());
        self::assertSame('selection', SuppressionMechanism::Selection->value);
        self::assertNotContains(SuppressionMechanism::Selection, SuppressionMechanism::ledgerHalves());
    }

    #[Test]
    public function itMapsEveryStageToADistinctMechanism(): void
    {
        $mapped = array_map(
            static fn(FindingFilterStage $stage): SuppressionMechanism => SuppressionMechanism::fromStage($stage),
            FindingFilterStage::cases(),
        );

        self::assertCount(\count(FindingFilterStage::cases()), array_unique(array_map(
            static fn(SuppressionMechanism $m): string => $m->value,
            $mapped,
        )));
    }

    #[Test]
    public function itKeepsTheLedgerHalvesDistinctFromEveryStageMechanism(): void
    {
        $stageMechanisms = array_map(
            static fn(FindingFilterStage $stage): SuppressionMechanism => SuppressionMechanism::fromStage($stage),
            FindingFilterStage::cases(),
        );

        foreach (SuppressionMechanism::ledgerHalves() as $ledgerMechanism) {
            self::assertNotContains($ledgerMechanism, $stageMechanisms);
        }
    }
}
