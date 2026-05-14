<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Architecture\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Architecture\Validation\MutualAllowDetector;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;

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
    public function emptyMapEmitsNoWarning(): void
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
            [
                'a' => ['b'],
                'b' => ['c'],
            ],
            $warnings,
        );

        self::assertSame([], $warnings);
    }

    #[Test]
    public function singleMutualPairEmitsOneWarning(): void
    {
        $warnings = [];
        $this->detector->detect(
            [
                'a' => ['b'],
                'b' => ['a'],
            ],
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
            [
                'a' => ['b'],
                'b' => ['a'],
                'c' => ['d'],
                'd' => ['c'],
            ],
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
            [
                'b' => ['a'],
                'a' => ['b'],
            ],
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
            [
                'a' => ['a'],
            ],
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
            [
                'a' => ['b'],
            ],
            $warnings,
        );

        self::assertSame([], $warnings);
    }

    #[Test]
    public function existingWarningsArePreserved(): void
    {
        $warnings = [DeferredWarning::warning('prior')];
        $this->detector->detect(
            [
                'a' => ['b'],
                'b' => ['a'],
            ],
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
            [
                'a' => ['b', 'c'],
                'b' => ['a', 'c'],
                'c' => ['a', 'b'],
            ],
            $warnings,
        );

        self::assertCount(1, $warnings);
        self::assertSame(3, substr_count($warnings[0]->message, '↔'));
    }
}
