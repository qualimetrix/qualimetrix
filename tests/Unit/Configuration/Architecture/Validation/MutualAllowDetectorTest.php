<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Architecture\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Architecture\Validation\MutualAllowDetector;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Core\Architecture\Allow\AllowListEntry;
use Qualimetrix\Core\Architecture\Allow\AllowTarget;
use Qualimetrix\Core\Architecture\Allow\LayerSelector;
use Qualimetrix\Tests\Support\Architecture\AllowListBuilder;

#[CoversClass(MutualAllowDetector::class)]
#[CoversClass(DeferredWarning::class)]
final class MutualAllowDetectorTest extends TestCase
{
    private MutualAllowDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new MutualAllowDetector();
    }

    #[Test]
    public function emptyEntryListEmitsNoWarning(): void
    {
        $warnings = [];
        $this->detector->detect([], $warnings);

        self::assertSame([], $warnings);
    }

    #[Test]
    public function noMutualEdgesEmitsNoWarning(): void
    {
        $warnings = [];
        $this->detector->detect(
            AllowListBuilder::entriesFromExactMap([
                'a' => ['b'],
                'b' => ['c'],
            ]),
            $warnings,
        );

        self::assertSame([], $warnings);
    }

    #[Test]
    public function singleMutualPairEmitsOneWarning(): void
    {
        $warnings = [];
        $this->detector->detect(
            AllowListBuilder::entriesFromExactMap([
                'a' => ['b'],
                'b' => ['a'],
            ]),
            $warnings,
        );

        self::assertCount(1, $warnings);
        self::assertSame('warning', $warnings[0]->level);
        self::assertStringContainsString('mutual-allow', $warnings[0]->message);
        self::assertStringContainsString('a', $warnings[0]->message);
        self::assertStringContainsString('b', $warnings[0]->message);
    }

    #[Test]
    public function multipleMutualPairsAreListedInSingleWarning(): void
    {
        $warnings = [];
        $this->detector->detect(
            AllowListBuilder::entriesFromExactMap([
                'a' => ['b'],
                'b' => ['a'],
                'c' => ['d'],
                'd' => ['c'],
            ]),
            $warnings,
        );

        self::assertCount(1, $warnings);
        self::assertStringContainsString('a ↔ b', $warnings[0]->message);
        self::assertStringContainsString('c ↔ d', $warnings[0]->message);
    }

    #[Test]
    public function mutualPairIsDeduplicatedRegardlessOfDirection(): void
    {
        // Both 'a' and 'b' mention each other; the pair (a, b) should appear only once.
        $warnings = [];
        $this->detector->detect(
            AllowListBuilder::entriesFromExactMap([
                'b' => ['a'],
                'a' => ['b'],
            ]),
            $warnings,
        );

        self::assertCount(1, $warnings);
        // The rendered pair includes both names exactly once.
        self::assertSame(1, substr_count($warnings[0]->message, '↔'));
    }

    #[Test]
    public function selfReferenceIsIgnored(): void
    {
        // 'a' allows 'a' — not a mutual cycle, just a self-reference.
        $warnings = [];
        $this->detector->detect(
            AllowListBuilder::entriesFromExactMap([
                'a' => ['a'],
            ]),
            $warnings,
        );

        self::assertSame([], $warnings);
    }

    #[Test]
    public function oneWayEdgeWithoutReturnEdgeEmitsNoWarning(): void
    {
        // 'a' allows 'b', but 'b' is not in the map at all.
        $warnings = [];
        $this->detector->detect(
            AllowListBuilder::entriesFromExactMap([
                'a' => ['b'],
            ]),
            $warnings,
        );

        self::assertSame([], $warnings);
    }

    #[Test]
    public function existingWarningsArePreserved(): void
    {
        $warnings = [DeferredWarning::warning('prior')];
        $this->detector->detect(
            AllowListBuilder::entriesFromExactMap([
                'a' => ['b'],
                'b' => ['a'],
            ]),
            $warnings,
        );

        self::assertCount(2, $warnings);
        self::assertSame('prior', $warnings[0]->message);
        self::assertStringContainsString('mutual-allow', $warnings[1]->message);
    }

    #[Test]
    public function threeWayCycleEmitsThreePairs(): void
    {
        // a↔b, b↔c, a↔c — three distinct mutual pairs.
        $warnings = [];
        $this->detector->detect(
            AllowListBuilder::entriesFromExactMap([
                'a' => ['b', 'c'],
                'b' => ['a', 'c'],
                'c' => ['a', 'b'],
            ]),
            $warnings,
        );

        self::assertCount(1, $warnings);
        self::assertSame(3, substr_count($warnings[0]->message, '↔'));
    }

    #[Test]
    public function nonExactSelectorsAreSkipped(): void
    {
        // Step C scope: glob-source / glob-target mutual edges are deliberately
        // ignored by the detector (cross-shape overlap is a Step E concern).
        // Phase-1 byte-for-byte BC is preserved because Phase-1 configs use only
        // exact selectors.
        $entries = [
            new AllowListEntry(
                LayerSelector::glob('*-store'),
                [new AllowTarget(LayerSelector::exact('orders'))],
            ),
            new AllowListEntry(
                LayerSelector::exact('orders'),
                [new AllowTarget(LayerSelector::glob('*-store'))],
            ),
        ];

        $warnings = [];
        $this->detector->detect($entries, $warnings);

        self::assertSame([], $warnings);
    }
}
