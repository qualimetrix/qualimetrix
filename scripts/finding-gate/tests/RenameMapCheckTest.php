<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\ChannelSplit;
use QmxFindingGate\Corpus;
use QmxFindingGate\FailureClass;
use QmxFindingGate\GateReport;
use QmxFindingGate\RenameMapCheck;
use QmxFindingGate\RenameMaps;
use QmxFindingGate\SyntheticTree;

/**
 * Every reference record under a declared split half is explained by a row the
 * candidate actually published, case by case.
 */
final class RenameMapCheckTest extends TestCase
{
    private const REFERENCE = ['subject' => 'declaration:class:A@a.php', 'rule' => 'design.type-coverage', 'code' => 'design.type-coverage.param', 'channel' => 'design.type-coverage#design.type-coverage.param'];

    private const EXPLAINED = ['subject' => 'declaration:class:A@a.php', 'rule' => 'design.param-typing', 'code' => 'design.param-typing', 'channel' => 'design.param-typing#design.param-typing'];

    private string $root;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->root = SyntheticTree::fixture(SyntheticTree::clean());
    }

    protected function tearDown(): void
    {
        SyntheticTree::remove($this->root);
    }

    #[Test]
    public function itRaisesSplitUnmappedAgainstTheCaseWhoseSplitRecordTheCandidateNeverPublished(): void
    {
        self::assertSame([], $this->splitFailures([self::EXPLAINED]));
        self::assertSame([[FailureClass::SPLIT_UNMAPPED, 'case:alpha']], $this->splitFailures([]));
    }

    /**
     * @param list<array<string, string>> $candidate
     *
     * @return list<array{0: string, 1: string}>
     */
    private function splitFailures(array $candidate): array
    {
        $maps = RenameMaps::fromPairs([
            ['old' => 'design.type-coverage#design.type-coverage.param', 'new' => 'design.param-typing#design.param-typing', 'source' => RenameMaps::CHANNELS],
            ['old' => 'design.type-coverage#design.type-coverage.return', 'new' => 'design.return-typing#design.return-typing', 'source' => RenameMaps::CHANNELS],
        ]);
        $report = new GateReport();
        (new RenameMapCheck($report, Corpus::load($this->root), $maps, ChannelSplit::of($maps)))->checkSplitExplanation(
            ['case:alpha|format:json' => (string) json_encode(['violations' => $candidate], \JSON_THROW_ON_ERROR)],
            ['case:alpha|format:json' => (string) json_encode(['violations' => [self::REFERENCE]], \JSON_THROW_ON_ERROR)],
        );

        return array_map(static fn(array $failure): array => [$failure['class'], $failure['scope']], $report->raised());
    }
}
