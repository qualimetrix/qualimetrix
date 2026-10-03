<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\DeclaredFields;
use QmxFindingGate\DeclaredOutcomes;
use QmxFindingGate\DeclaredRecords;
use QmxFindingGate\DeclaredStructuralMaps;
use QmxFindingGate\DeclaredSurfaces;
use QmxFindingGate\DeclaredValues;
use QmxFindingGate\DerivedTable;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\Tsv;

/**
 * The declaration forms as tables: what a row must look like, what loading
 * refuses, and which rows are stale when nothing consumed them.
 */
final class DeclarationsTest extends TestCase
{
    private string $root;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->root = Fs::temporaryDirectory('declarations-test-');
    }

    protected function tearDown(): void
    {
        Fs::removeRecursively($this->root);
    }

    #[Test]
    public function itReadsAMissingTableAsNoDeclaration(): void
    {
        self::assertSame(0, DeclaredRecords::load($this->root)->count());
        self::assertSame(0, DeclaredValues::load($this->root)->count());
        self::assertSame(0, DeclaredFields::load($this->root)->count());
        self::assertSame(0, DeclaredOutcomes::load($this->root)->count());
        self::assertSame(0, DeclaredSurfaces::load($this->root)->count());
        self::assertSame(0, DeclaredStructuralMaps::load($this->root)->count());
    }

    #[Test]
    public function itWritesADerivedTableOnlyUnderADeclaredIntentAndInOneOrder(): void
    {
        $rows = [['metric', 'ccn', 'App\\B', '1', '2'], ['metric', 'ccn', 'App\\A', '3', '4']];
        $rendered = DerivedTable::render(DeclaredValues::DERIVED_COLUMNS, ['kind', 'key'], ["metric\tccn"], $rows);

        self::assertSame(Tsv::render(DeclaredValues::DERIVED_COLUMNS, array_reverse($rows)), $rendered);
        $this->assertRefused(
            static fn(string $root): mixed => DerivedTable::render(DeclaredValues::DERIVED_COLUMNS, ['kind', 'key'], ["metric\tccn"], [['metric', 'npath', 'App\\A', '1', '2']]),
            'which no intent declares',
        );
    }

    #[Test]
    public function itKeepsAReasonOnlyWhileItsRowIsUnchanged(): void
    {
        $existing = ['k' => ['row' => 'a', 'reason' => 'because']];

        self::assertSame('because', DerivedTable::reasonFor($existing, 'k', 'a'));
        self::assertSame(DerivedTable::UNEXPLAINED, DerivedTable::reasonFor($existing, 'k', 'b'));
        self::assertSame(DerivedTable::UNEXPLAINED, DerivedTable::reasonFor($existing, 'other', 'a'));
    }

    /** @param callable(string): mixed $load */
    private function assertRefused(callable $load, string $reason): void
    {
        try {
            $load($this->root);
        } catch (GateError $error) {
            self::assertStringContainsString($reason, $error->getMessage());

            return;
        }

        self::fail('The declaration was accepted.');
    }
}
