<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\ChannelSplit;
use QmxFindingGate\DeclaredDelta;
use QmxFindingGate\DeclaredDeltaCheck;
use QmxFindingGate\DeclaredFieldMoves;
use QmxFindingGate\FailureClass;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\GateReport;
use QmxFindingGate\Options;
use QmxFindingGate\RenameMaps;
use QmxFindingGate\Tsv;

/**
 * The licence `delta-overreach` consults besides normalization: an exact,
 * directional move of one field on one surface, refused at load when nothing
 * could consult it, and stale when no diff line performed it.
 */
final class DeclaredFieldMovesTest extends TestCase
{
    private const SURFACE = 'case:annotations|format:json';

    /** @var list<string> */
    private array $roots = [];

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            Fs::removeRecursively($root);
        }
    }

    #[Test]
    public function itLicensesNoMoveWithoutAnIndex(): void
    {
        self::assertSame(0, DeclaredFieldMoves::load($this->root())->count());
    }

    /**
     * Every column of the key is part of the equality: a licence that fired on
     * containment has already been paid for once.
     */
    #[Test]
    public function itLicensesExactlyTheDeclaredDirectionalMoveOfOneFieldOnOneSurface(): void
    {
        $moves = $this->moves([[self::SURFACE, 'message', 'said A and B', 'said A', 'B stopped being suggested']]);

        self::assertTrue($moves->allows(self::SURFACE, 'message', 'said A and B', 'said A'));
        self::assertFalse($moves->allows(self::SURFACE, 'message', 'said A and B', 'said A too'), 'a value the declared one is a prefix of');
        self::assertFalse($moves->allows(self::SURFACE, 'message', 'and B', 'said A'), 'a substring of the declared value');
        self::assertFalse($moves->allows('case:annotations|format:sarif', 'message', 'said A and B', 'said A'), 'another surface');
        self::assertFalse($moves->allows(self::SURFACE, 'recommendation', 'said A and B', 'said A'), 'another field');
        self::assertFalse($moves->allows(self::SURFACE, 'message', 'said A', 'said A and B'), 'the reverse move');
        self::assertSame([], $moves->staleMoves());
    }

    #[Test]
    public function itRaisesFieldMoveStaleForALicensedMoveNoDiffLinePerformed(): void
    {
        $root = $this->root();
        $this->writeMoves($root, [
            [self::SURFACE, 'message', 'from', 'to', 'used'],
            [self::SURFACE, 'message', 'never', 'happened', 'unused'],
        ]);
        $moves = DeclaredFieldMoves::load($root);
        $moves->allows(self::SURFACE, 'message', 'from', 'to');
        $report = new GateReport();

        $this->deltaCheck($root, $report, $moves)->checkStaleFieldMoves();

        self::assertSame([[FailureClass::FIELD_MOVE_STALE, self::SURFACE]], array_map(static fn(array $failure): array => [$failure['class'], $failure['scope']], $report->raised()));
        self::assertStringContainsString('"message" ("never" -> "happened")', $report->raised()[0]['detail']);

        $moves->allows(self::SURFACE, 'message', 'never', 'happened');
        $performed = new GateReport();
        $this->deltaCheck($root, $performed, $moves)->checkStaleFieldMoves();
        self::assertSame([], $performed->raised());
    }

    #[Test]
    public function itJudgesNoFieldMoveWhileDeriving(): void
    {
        $root = $this->root();
        $this->writeMoves($root, [[self::SURFACE, 'message', 'never', 'happened', 'unused']]);
        $report = new GateReport();
        $check = $this->deltaCheck($root, $report, DeclaredFieldMoves::load($root));
        $check->startDeriving();

        $check->checkStaleFieldMoves();

        self::assertSame([], $report->raised());
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function provideRefusedRows(): iterable
    {
        yield 'a row with no reason' => [[self::SURFACE, 'message', 'from', 'to', ''], 'licenses a move of "message" on "' . self::SURFACE . '" with no reason'];
        yield 'the derived placeholder reason' => [[self::SURFACE, 'message', 'from', 'to', '?'], 'licenses a move of "message" on "' . self::SURFACE . '" with no reason'];
        yield 'a row naming no field' => [[self::SURFACE, '', 'from', 'to', 'why'], 'has a row naming no field'];
        yield 'a row naming no surface' => [['', 'message', 'from', 'to', 'why'], 'has a row naming no surface'];
        yield 'a row declaring no movement' => [[self::SURFACE, 'message', 'same', 'same', 'why'], 'moving from a value to itself'];
        yield 'a surface no run produces' => [['case:annotations|format:sarrif', 'message', 'from', 'to', 'why'], 'where nothing can read that field'];
        yield 'a field outside every compared schema' => [['case:annotations|format:text', 'unknown-field', 'from', 'to', 'why'], 'where nothing can read that field'];
        yield 'a field that surface does not publish' => [['case:annotations|format:sarif', 'threshold', 'from', 'to', 'why'], 'where nothing can read that field'];
        yield 'a value with an invisible edge' => [[self::SURFACE, 'message', 'trailing space ', 'to', 'why'], 'has whitespace around the "from" field'];
    }

    /** @param list<string> $row */
    #[Test]
    #[DataProvider('provideRefusedRows')]
    public function itRefusesARowNothingCouldConsultWhereItIsStillReadable(array $row, string $fragment): void
    {
        $this->expectException(GateError::class);
        $this->expectExceptionMessage($fragment);

        $this->moves([$row]);
    }

    #[Test]
    public function itRefusesTheSameMoveDeclaredTwice(): void
    {
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('declares the same move of "message" on "' . self::SURFACE . '" twice');

        $this->moves([
            [self::SURFACE, 'message', 'from', 'to', 'first'],
            [self::SURFACE, 'message', 'from', 'to', 'second'],
        ]);
    }

    /** @param list<list<string>> $rows */
    private function moves(array $rows): DeclaredFieldMoves
    {
        $root = $this->root();
        $this->writeMoves($root, $rows);

        return DeclaredFieldMoves::load($root);
    }

    /** @param list<list<string>> $rows */
    private function writeMoves(string $root, array $rows): void
    {
        Fs::write($root . '/' . DeclaredFieldMoves::INDEX, Tsv::render(DeclaredFieldMoves::COLUMNS, $rows));
    }

    private function deltaCheck(string $root, GateReport $report, DeclaredFieldMoves $moves): DeclaredDeltaCheck
    {
        return new DeclaredDeltaCheck(
            Options::parse(['gate', '--reference=HEAD'], \dirname(__DIR__, 3)),
            $report,
            DeclaredDelta::load($root),
            $moves,
            ChannelSplit::of(RenameMaps::fromPairs([])),
        );
    }

    private function root(): string
    {
        $root = Fs::temporaryDirectory('field-moves-test-');
        $this->roots[] = $root;

        return $root;
    }
}
