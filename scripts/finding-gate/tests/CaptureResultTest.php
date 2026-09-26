<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\{DataProvider, Test};
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

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function malformedProcessEvidence(): iterable
    {
        $valid = ['stdout' => '', 'stderr' => '', 'exit' => 2];
        yield 'string exit' => [array_replace($valid, ['exit' => '2'])];
        yield 'non-string stdout' => [array_replace($valid, ['stdout' => 0])];
        yield 'non-string stderr' => [array_replace($valid, ['stderr' => false])];
        yield 'unclassified process member' => [$valid + ['extra' => 'unknown']];
        yield 'missing process member' => [array_diff_key($valid, ['stdout' => true])];
    }

    /** @param array<string,mixed> $process */
    #[Test]
    #[DataProvider('malformedProcessEvidence')]
    public function itRefusesMalformedPrivateProcessEvidence(array $process): void
    {
        $this->expectException(GateError::class);
        new CaptureResult([], ['case:alpha|format:json' => [
            'ranked' => $process,
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
    /** @return iterable<string,array{bool}> */
    public static function captureSideOperations(): iterable
    {
        yield 'supply' => [false];
        yield 'read' => [true];
    }

    #[Test]
    #[DataProvider('captureSideOperations')]
    public function itRefusesAnUnclassifiedCaptureSideBeforeReadingOrSupplyingEvidence(bool $read): void
    {
        $captures = new RankingCaptures();
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('Unknown internal ranking capture side: another');
        if ($read) {
            $captures->of('another', 'case:alpha|format:json');
        } else {
            $captures->supply('another', []);
        }
    }

}
