<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Configuration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Configuration\Validation\MutualAllowDetector;
use Qualimetrix\Architecture\Domain\Allow\AllowListEntry;
use Qualimetrix\Architecture\Domain\Allow\AllowTarget;
use Qualimetrix\Architecture\Domain\Allow\LayerSelector;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Tests\Architecture\Support\AllowListBuilder;

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

    // -------------------------------------------------------------------------
    // Phase 5 (M5): relations-aware + allow_cross_instance opt-out
    // -------------------------------------------------------------------------

    #[Test]
    public function disjointRelationsDoNotEmitMutualWarning(): void
    {
        // A → B restricted to `extends`; B → A restricted to `static_call`.
        // No DependencyType in common → the two directions describe
        // non-collapsible cross-relations, not a true mutual allow.
        $entries = [
            new AllowListEntry(
                LayerSelector::exact('a'),
                [new AllowTarget(LayerSelector::exact('b'), [DependencyType::Extends])],
            ),
            new AllowListEntry(
                LayerSelector::exact('b'),
                [new AllowTarget(LayerSelector::exact('a'), [DependencyType::StaticCall])],
            ),
        ];

        $warnings = [];
        $this->detector->detect($entries, $warnings);

        self::assertSame([], $warnings);
    }

    #[Test]
    public function intersectingRelationsEmitMutualWarning(): void
    {
        // A → B and B → A both whitelist `extends` (among others).
        // The shared relation kind → genuine mutual allow → warning fires.
        // (Regression pin: the existing non-filtered behaviour must keep firing
        // when overlap exists.)
        $entries = [
            new AllowListEntry(
                LayerSelector::exact('a'),
                [new AllowTarget(LayerSelector::exact('b'), [DependencyType::Extends, DependencyType::TypeHint])],
            ),
            new AllowListEntry(
                LayerSelector::exact('b'),
                [new AllowTarget(LayerSelector::exact('a'), [DependencyType::Extends])],
            ),
        ];

        $warnings = [];
        $this->detector->detect($entries, $warnings);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('a ↔ b', $warnings[0]->message);
    }

    #[Test]
    public function nullRelationsIntersectsWithEveryFilter(): void
    {
        // Pre-Step-G bare entries (relations = null) keep "all relations"
        // semantics; pairing one bare entry with a filtered one still counts
        // as a mutual allow because the bare side trivially intersects every
        // filter.
        $entries = [
            new AllowListEntry(
                LayerSelector::exact('a'),
                [new AllowTarget(LayerSelector::exact('b'))],
            ),
            new AllowListEntry(
                LayerSelector::exact('b'),
                [new AllowTarget(LayerSelector::exact('a'), [DependencyType::StaticCall])],
            ),
        ];

        $warnings = [];
        $this->detector->detect($entries, $warnings);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('a ↔ b', $warnings[0]->message);
    }

    #[Test]
    public function allowCrossInstanceForwardOptOutSilencesMutualWarning(): void
    {
        $entries = [
            new AllowListEntry(
                LayerSelector::exact('a'),
                [new AllowTarget(LayerSelector::exact('b'), null, allowCrossInstance: true)],
            ),
            new AllowListEntry(
                LayerSelector::exact('b'),
                [new AllowTarget(LayerSelector::exact('a'))],
            ),
        ];

        $warnings = [];
        $this->detector->detect($entries, $warnings);

        self::assertSame([], $warnings);
    }

    #[Test]
    public function allowCrossInstanceBackwardOptOutSilencesMutualWarning(): void
    {
        // Either direction setting the flag is enough — the user signalled
        // intent for the cross-edge pattern, suppress the redundant warning.
        $entries = [
            new AllowListEntry(
                LayerSelector::exact('a'),
                [new AllowTarget(LayerSelector::exact('b'))],
            ),
            new AllowListEntry(
                LayerSelector::exact('b'),
                [new AllowTarget(LayerSelector::exact('a'), null, allowCrossInstance: true)],
            ),
        ];

        $warnings = [];
        $this->detector->detect($entries, $warnings);

        self::assertSame([], $warnings);
    }
}
