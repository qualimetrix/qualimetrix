<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Declarations;
use QmxFindingGate\DeclaredFields;
use QmxFindingGate\DeclaredRecords;
use QmxFindingGate\DeclaredStructuralMaps;
use QmxFindingGate\DeclaredSurfaces;
use QmxFindingGate\DeclaredValues;
use QmxFindingGate\FailureClass;
use QmxFindingGate\Fs;
use QmxFindingGate\GateReport;
use QmxFindingGate\StaleDeclarationCheck;
use QmxFindingGate\Tsv;

/**
 * A declaration row nothing in the run consumed fails under its form's own
 * class, and the same row consumed passes.
 */
final class StaleDeclarationCheckTest extends TestCase
{
    private const RECORD = ['channel' => 'replay.alpha', 'subject' => 'declaration:callable:Replay\\Alpha::run@src/Alpha.php', 'occurrence' => null, 'edge' => null, 'message' => 'a record no side publishes'];

    private string $root;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->root = Fs::temporaryDirectory('stale-declaration-test-');
        Fs::write($this->root . '/finding-gate/cases/alpha/case.json', '{}');
    }

    protected function tearDown(): void
    {
        Fs::removeRecursively($this->root);
    }

    #[Test]
    public function itRaisesRecordStaleForAnIntentAndADerivedRecordNoRecordOfTheRunMatched(): void
    {
        $this->declareRecord();

        self::assertSame(
            [[FailureClass::RECORD_STALE, 'case:alpha|format:json'], [FailureClass::RECORD_STALE, DeclaredRecords::INDEX]],
            $this->staleAfter(static fn(Declarations $declarations): null => null),
        );
        self::assertSame([], $this->staleAfter(static function (Declarations $declarations): void {
            self::assertTrue($declarations->records->claim(DeclaredRecords::INTRODUCED, 'alpha', 'json', 'format:json', DeclaredRecords::canonical(self::RECORD)));
        }));
    }

    #[Test]
    public function itRaisesValueStaleForAnIntentUnderWhichNoValueMoved(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [[DeclaredValues::METRIC, 'ccn', DeclaredValues::EVERY_LEVEL, 'A measured value changes.']]);

        self::assertSame(
            [[FailureClass::VALUE_STALE, DeclaredValues::INDEX]],
            $this->staleAfter(static fn(Declarations $declarations): null => null),
        );
        self::assertSame([], $this->staleAfter(static function (Declarations $declarations): void {
            $declarations->values->credit(DeclaredValues::METRIC, 'ccn');
        }));
    }

    #[Test]
    public function itRaisesFieldDeclarationStaleForAFieldNeitherSideAddsOrDrops(): void
    {
        $this->write(DeclaredFields::INDEX, DeclaredFields::COLUMNS, [[DeclaredFields::REMOVED, 'json', 'format:json', 'retired', 'A retired field.']]);

        self::assertSame(
            [[FailureClass::FIELD_DECLARATION_STALE, DeclaredFields::INDEX]],
            $this->staleAfter(static fn(Declarations $declarations): null => null),
        );
        self::assertSame([], $this->staleAfter(static function (Declarations $declarations): void {
            $declarations->fields->credit('json', 'format:json', 'retired');
        }));
    }

    #[Test]
    public function itRaisesSurfaceDeclarationStaleForAnIntroducedSurfaceTheRunNeverCaptured(): void
    {
        $this->write(DeclaredSurfaces::INDEX, DeclaredSurfaces::COLUMNS, [[DeclaredSurfaces::INTRODUCED, 'format:metrics', DeclaredSurfaces::NO_FILE, '*', 'A new native metrics publication.']]);

        self::assertSame(
            [[FailureClass::SURFACE_DECLARATION_STALE, 'format:metrics']],
            $this->staleAfter(static fn(Declarations $declarations): null => null),
        );
        self::assertSame([], $this->staleAfter(static function (Declarations $declarations): void {
            $declarations->surfaces->credit('format:metrics');
        }));
    }

    #[Test]
    public function itRaisesStructuralMapStaleForARowThatMovedNoKeyOfAnyCaseInput(): void
    {
        $this->write(DeclaredStructuralMaps::INDEX, DeclaredStructuralMaps::COLUMNS, [['config', 'old.key', 'new.key', DeclaredStructuralMaps::SHAPE_SAME, 'A moved key.']]);

        self::assertSame(
            [[FailureClass::STRUCTURAL_MAP_STALE, DeclaredStructuralMaps::INDEX]],
            $this->staleAfter(static fn(Declarations $declarations): null => null),
        );
        self::assertSame([], $this->staleAfter(static function (Declarations $declarations): void {
            $declarations->structuralMaps->credit('config', 'old.key');
        }));
    }

    #[Test]
    public function itJudgesNothingWhileDeriving(): void
    {
        $this->declareRecord();
        $this->write(DeclaredSurfaces::INDEX, DeclaredSurfaces::COLUMNS, [[DeclaredSurfaces::INTRODUCED, 'format:metrics', DeclaredSurfaces::NO_FILE, '*', 'A new native metrics publication.']]);
        $report = new GateReport();
        $check = new StaleDeclarationCheck($report, Declarations::load($this->root));
        $check->startDeriving();

        $check->checkStaleDeclarations();

        self::assertSame([], $report->raised());
    }

    private function declareRecord(): void
    {
        $this->write(DeclaredRecords::INDEX, DeclaredRecords::COLUMNS, [
            [DeclaredRecords::INTRODUCED, 'alpha', 'json', 'format:json', DeclaredRecords::canonical(['message' => self::RECORD['message']]), 'An introduced record.'],
        ]);
        $this->write(DeclaredRecords::DERIVED, DeclaredRecords::DERIVED_COLUMNS, [
            [DeclaredRecords::INTRODUCED, 'alpha', 'json', 'format:json', DeclaredRecords::canonical(self::RECORD)],
        ]);
    }

    /**
     * @param callable(Declarations): mixed $consume
     *
     * @return list<array{0: string, 1: string}>
     */
    private function staleAfter(callable $consume): array
    {
        $declarations = Declarations::load($this->root);
        $consume($declarations);
        $report = new GateReport();
        (new StaleDeclarationCheck($report, $declarations))->checkStaleDeclarations();

        return array_map(static fn(array $failure): array => [$failure['class'], $failure['scope']], $report->raised());
    }

    /**
     * @param list<string> $columns
     * @param list<list<string>> $rows
     */
    private function write(string $file, array $columns, array $rows): void
    {
        Fs::write($this->root . '/finding-gate/' . $file, Tsv::render($columns, $rows));
    }
}
