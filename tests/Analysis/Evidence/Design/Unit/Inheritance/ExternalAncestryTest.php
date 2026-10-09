<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit\Inheritance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\ExternalAncestry;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\ExternalChainOutcome;
use Qualimetrix\Tests\Analysis\Evidence\Design\Support\FixedParentSource;

/**
 * What counts as a depth, given what the sources say. Reading those sources is
 * the adapter's job and is covered where that lives.
 */
#[CoversClass(ExternalAncestry::class)]
final class ExternalAncestryTest extends TestCase
{
    #[Test]
    public function itCountsEveryStepToTheRoot(): void
    {
        $depth = $this->ancestry([
            'Vendor\\Leaf' => 'Vendor\\Middle',
            'Vendor\\Middle' => 'Vendor\\Base',
            'Vendor\\Base' => null,
        ])->depthOf('Vendor\\Leaf');

        self::assertSame(2, $depth->depth);
        self::assertSame(ExternalChainOutcome::ReachedRoot, $depth->outcome);
        self::assertTrue($depth->isComplete());
    }

    #[Test]
    public function itScoresAClassWithNoParentAsZero(): void
    {
        $depth = $this->ancestry(['Vendor\\Base' => null])->depthOf('Vendor\\Base');

        self::assertSame(0, $depth->depth);
        self::assertSame(ExternalChainOutcome::ReachedRoot, $depth->outcome);
    }

    #[Test]
    public function itFollowsEveryBuiltinParent(): void
    {
        $depth = $this->ancestry(['Vendor\\Failure' => 'RuntimeException'])->depthOf('Vendor\\Failure');

        self::assertSame(2, $depth->depth);
        self::assertSame(ExternalChainOutcome::ReachedRoot, $depth->outcome);
    }

    /**
     * The state the metric never had: part of the chain was followed and the
     * rest is unreadable. That is neither "no parent" nor "nothing to read",
     * and reporting all three as depth 0 is what hid it.
     */
    #[Test]
    public function itReportsAChainThatStopsBeingReadable(): void
    {
        $depth = $this->ancestry(['Vendor\\Child' => 'Absent\\Parental'])->depthOf('Vendor\\Child');

        self::assertSame(1, $depth->depth);
        self::assertSame(ExternalChainOutcome::BrokeAt, $depth->outcome);
        self::assertSame('Absent\\Parental', $depth->unresolved);
        self::assertFalse($depth->isComplete());
    }

    #[Test]
    public function itSeparatesHavingNoInstallFromHavingNoEntry(): void
    {
        $noInstall = (new ExternalAncestry(FixedParentSource::unconfigured()))->depthOf('Vendor\\Anything');
        $noEntry = $this->ancestry([])->depthOf('Vendor\\Anything');

        self::assertSame(ExternalChainOutcome::NoMapForIt, $noInstall->outcome);
        self::assertSame(ExternalChainOutcome::BrokeAt, $noEntry->outcome);
    }

    #[Test]
    public function itRefusesToCallACycleADepth(): void
    {
        $depth = $this->ancestry([
            'Vendor\\A' => 'Vendor\\B',
            'Vendor\\B' => 'Vendor\\A',
        ])->depthOf('Vendor\\A');

        self::assertSame(ExternalChainOutcome::Loop, $depth->outcome);
        self::assertNull($depth->depth);
    }

    #[Test]
    public function itAnswersTheSameWayTwice(): void
    {
        $ancestry = $this->ancestry(['Vendor\\Child' => 'Vendor\\Base', 'Vendor\\Base' => null]);

        self::assertEquals($ancestry->depthOf('Vendor\\Child'), $ancestry->depthOf('Vendor\\Child'));
    }

    #[Test]
    public function itFollowsBuiltinAncestryWithoutAnInstall(): void
    {
        $ancestry = new ExternalAncestry(FixedParentSource::unconfigured());
        self::assertSame(1, $ancestry->depthOf('runtimeexception')->depth);
        self::assertSame(2, $ancestry->depthOf('ArgumentCountError')->depth);
    }

    #[Test]
    public function itOmitsNumericDepthForACanonicalExternalLoop(): void
    {
        $depth = $this->ancestry(['Vendor\\A' => 'Vendor\\B', 'Vendor\\B' => 'vendor\\a'])->depthOf('Vendor\\A');
        self::assertNull($depth->depth);
    }

    #[Test]
    public function itBoundsStaticSourceWalksAtSixtyFourVisits(): void
    {
        $parents = [];
        for ($i = 0; $i < 80; ++$i) {
            $parents['Vendor\\C' . $i] = 'Vendor\\C' . ($i + 1);
        }
        $answer = $this->ancestry($parents)->depthOf('Vendor\\C0');
        self::assertSame(64, $answer->depth);
        self::assertSame(ExternalChainOutcome::BrokeAt, $answer->outcome);
        self::assertSame('Vendor\\C64', $answer->unresolved);
        self::assertNull($answer->reachesThrowable);
    }

    #[Test]
    public function itTreatsAnUnregisteredExtensionNameAsUnreadInsteadOfRuntimeEvidence(): void
    {
        $answer = $this->ancestry([])->depthOf('Probe\\PeclException');
        self::assertSame(0, $answer->depth);
        self::assertSame(ExternalChainOutcome::BrokeAt, $answer->outcome);
        self::assertNull($answer->reachesThrowable);
    }

    /**
     * @param array<string, string|null> $parents
     */
    private function ancestry(array $parents): ExternalAncestry
    {
        return new ExternalAncestry(new FixedParentSource($parents));
    }
}
