<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\GateError;
use QmxFindingGate\MetricsRecords;

final class MetricsRecordsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itPairsBySubjectOnlyWhenBothSidesPublishIt(): void
    {
        $a = self::record('A', 1) + ['subject' => 'first'];
        $b = self::record('B', 2) + ['subject' => 'first'];
        $paired = MetricsRecords::pair([$a], [$b]);
        self::assertCount(1, $paired['pairs']);
        self::assertSame('{"subject":"first"}', $paired['pairs'][0]['key']);
        self::assertSame([], $paired['introduced']);
    }

    #[Test]
    public function itFallsBackToTypeAndNameAcrossASubjectPublicationTransition(): void
    {
        $paired = MetricsRecords::pair([self::record('A', 2) + ['subject' => 'new']], [self::record('A', 1)]);
        self::assertSame('{"type":"class","name":"A"}', $paired['pairs'][0]['key']);
        self::assertSame(2, $paired['pairs'][0]['candidate']['metrics']['ccn']);
    }

    #[Test]
    public function itMatchesDuplicateKeysAsACompleteRecordMultiset(): void
    {
        $a = self::record('A', 1);
        $b = self::record('A', 2);
        $paired = MetricsRecords::pair([$a, $a, $b], [$b, $a, $a]);
        self::assertCount(3, $paired['pairs']);
        self::assertSame([], $paired['introduced']);
        self::assertSame([], $paired['withdrawn']);
    }

    #[Test]
    public function itLeavesOnlyUnmatchedDuplicateInstancesAsResiduals(): void
    {
        $a = self::record('A', 1);
        $b = self::record('A', 2);
        $paired = MetricsRecords::pair([$a, $a], [$a, $b]);
        self::assertCount(1, $paired['pairs']);
        self::assertSame([$a], $paired['introduced']);
        self::assertSame([$b], $paired['withdrawn']);
    }

    #[Test]
    public function itDoesNotPairAnIdentityPublishedByOnlyOneSide(): void
    {
        $a = self::record('A', 1);
        $b = self::record('B', 1);
        $paired = MetricsRecords::pair([$a], [$b]);
        self::assertSame([], $paired['pairs']);
        self::assertSame([$a], $paired['introduced']);
        self::assertSame([$b], $paired['withdrawn']);
    }

    #[Test]
    public function itUsesCallableLevelsForBothMethodAndFunctionRecords(): void
    {
        self::assertSame('callable', MetricsRecords::level(['type' => 'method']));
        self::assertSame('callable', MetricsRecords::level(['type' => 'function']));
        self::assertSame('namespace', MetricsRecords::level(['type' => 'namespace']));
        $this->expectException(GateError::class);
        MetricsRecords::level(['type' => 'unknown']);
    }

    /** @return array<string,mixed> */
    private static function record(string $name, int $value): array
    {
        return ['type' => 'class', 'name' => $name, 'file' => 'src/A.php', 'line' => 1, 'metrics' => ['ccn' => $value]];
    }
}
