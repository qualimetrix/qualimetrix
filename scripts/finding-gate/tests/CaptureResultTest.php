<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CaptureResult;
use QmxFindingGate\GateError;
use QmxFindingGate\RankingCaptures;

final class CaptureResultTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itCarriesPrivateEvidenceWithoutCreatingPublishedArtifacts(): void
    {
        $slot = ['ranked' => ['stdout' => 'private', 'stderr' => '', 'exit' => 2], 'physical' => null];
        $result = (new CaptureResult(['tree|rules' => 'catalog'], []))->merge(
            new CaptureResult(['case:alpha|format:json' => 'original'], ['case:alpha|format:json' => $slot]),
        );
        self::assertSame(['tree|rules' => 'catalog', 'case:alpha|format:json' => 'original'], $result->artifacts);
        self::assertSame(['case:alpha|format:json' => $slot], $result->rankings);
        $captures = new RankingCaptures();
        $captures->supply('candidate', $result->rankings);
        self::assertSame($slot, $captures->of('candidate', 'case:alpha|format:json'));
    }

    #[Test]
    public function itRefusesMalformedPrivateProcessEvidence(): void
    {
        $this->expectException(GateError::class);
        new CaptureResult([], ['case:alpha|format:json' => [
            'ranked' => ['stdout' => '', 'stderr' => '', 'exit' => '2'],
            'physical' => null,
        ]]);
    }

    #[Test]
    public function itRefusesUnclassifiedPrivateCaptureSlots(): void
    {
        $this->expectException(GateError::class);
        new CaptureResult([], ['case:alpha|format:json' => [
            'ranked' => ['stdout' => '', 'stderr' => '', 'exit' => 2],
            'physical' => null,
            'extra' => [],
        ]]);
    }

    #[Test]
    public function itRefusesMergingDuplicatePrivateSources(): void
    {
        $result = new CaptureResult([], ['case:alpha|format:json' => [
            'ranked' => ['stdout' => '', 'stderr' => '', 'exit' => 2],
            'physical' => null,
        ]]);
        $this->expectException(GateError::class);
        $result->merge($result);
    }

    #[Test]
    public function itRefusesMergingDuplicatePublishedArtifacts(): void
    {
        $result = new CaptureResult(['tree|rules' => 'catalog'], []);
        $this->expectException(GateError::class);
        $result->merge($result);
    }

    #[Test]
    public function itRefusesSupplyingAnObservedSideTwiceEvenWhenEmpty(): void
    {
        $captures = new RankingCaptures();
        $captures->supply('candidate', []);
        $this->expectException(GateError::class);
        $captures->supply('candidate', []);
    }

    #[Test]
    public function itRefusesInventingEvidenceForAMissingSource(): void
    {
        $captures = new RankingCaptures();
        $captures->supply('reference', []);
        $this->expectException(GateError::class);
        $captures->of('reference', 'case:alpha|format:json');
    }
}
