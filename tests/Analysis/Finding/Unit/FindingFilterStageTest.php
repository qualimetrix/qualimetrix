<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;

#[CoversClass(FindingFilterStage::class)]
final class FindingFilterStageTest extends TestCase
{
    /**
     * The boundary of the measured set, pinned case by case: everything
     * before the baseline defines it, the baseline and git scope consume it.
     * A stage added later has to state which side it is on, and this test is
     * what makes that a decision rather than an accident of ordering.
     */
    #[Test]
    #[DataProvider('provideStageMembership')]
    public function itKnowsWhetherItDefinesTheMeasuredSet(FindingFilterStage $stage, bool $defines): void
    {
        self::assertSame($defines, $stage->definesMeasuredSet());
    }

    /**
     * @return iterable<string, array{FindingFilterStage, bool}>
     */
    public static function provideStageMembership(): iterable
    {
        yield 'suppression' => [FindingFilterStage::Suppression, true];
        yield 'path exclusion' => [FindingFilterStage::PathExclusion, true];
        yield 'namespace exclusion' => [FindingFilterStage::NamespaceExclusion, true];
        yield 'baseline' => [FindingFilterStage::Baseline, false];
        yield 'git scope' => [FindingFilterStage::GitScope, false];
    }
}
