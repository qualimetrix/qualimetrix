<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\DeclaredStructuralMaps;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\Tsv;

/**
 * The declaration forms as tables: what a row must look like, what loading
 * refuses, and which rows are stale when nothing consumed them.
 */
final class DeclaredStructuralMapsTest extends TestCase
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

    /** @return iterable<string, array{list<string>, string}> */
    public static function provideRefusedStructuralMaps(): iterable
    {
        yield 'an unknown document' => [['baseline', 'a', 'b', 'same', 'why'], '"document"'];
        yield 'an unknown shape' => [['config', 'a', 'b', 'list', 'why'], '"shape"'];
        yield 'a path that is no key path' => [['config', 'a..b', 'b', 'same', 'why'], 'not a dotted key path'];
        yield 'a move onto itself' => [['config', 'a.b', 'a.b', 'same', 'why'], 'onto itself'];
    }

    /** @param list<string> $row */
    #[Test]
    #[DataProvider('provideRefusedStructuralMaps')]
    public function itRefusesAStructuralMapRowItCouldNotApply(array $row, string $reason): void
    {
        $this->write(DeclaredStructuralMaps::INDEX, DeclaredStructuralMaps::COLUMNS, [$row]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredStructuralMaps::load($root), $reason);
    }

    #[Test]
    public function itMovesOriginalValuesSimultaneouslyWithoutInventingCredit(): void
    {
        $this->write(DeclaredStructuralMaps::INDEX, DeclaredStructuralMaps::COLUMNS, [
            ['config', 'a', 'b', 'same', 'First original value.'],
            ['config', 'b', 'c', 'same', 'Second original value.'],
        ]);
        $maps = DeclaredStructuralMaps::load($this->root);
        $text = $maps->reverseDocument('config', "b: 1\nc: 2\nneighbor: retained\n");
        self::assertStringContainsString('a: 1', $text);
        self::assertStringContainsString('b: 2', $text);
        self::assertStringContainsString('neighbor: retained', $text);
        self::assertSame([], $maps->stale());
        self::assertSame(["config\0a" => 1, "config\0b" => 1], $maps->firedRows());
        $parent = DeclaredStructuralMaps::load($this->root);
        $parent->creditRowsFiredElsewhere($maps->firedRows());
        self::assertSame([], $parent->stale());
    }

    #[Test]
    public function itPreservesIdleInputBytesAndKeepsTheMoveStale(): void
    {
        $this->write(DeclaredStructuralMaps::INDEX, DeclaredStructuralMaps::COLUMNS, [['config', 'old', 'new', 'same', 'An unused move.']]);
        $maps = DeclaredStructuralMaps::load($this->root);
        $original = "# Keep layout\r\nneighbor: 'retained'\r\n";
        self::assertSame($original, $maps->reverseDocument('config', $original));
        self::assertCount(1, $maps->stale());
        self::assertSame([], $maps->firedRows());
    }

    #[Test]
    public function itRefusesConflictingMovesWithoutCreditingThem(): void
    {
        $this->write(DeclaredStructuralMaps::INDEX, DeclaredStructuralMaps::COLUMNS, [['config', 'old', 'new', 'same', 'Move a value.']]);
        $maps = DeclaredStructuralMaps::load($this->root);
        try {
            $maps->reverseDocument('config', "old: retained\nnew: 7\n");
            self::fail('A destination collision was accepted.');
        } catch (GateError $error) {
            self::assertStringContainsString('collides', $error->getMessage());
        }
        self::assertSame([], $maps->firedRows());
        foreach ([["unknown\0old" => 1], ["config\0old" => 0]] as $hits) {
            try {
                $maps->creditRowsFiredElsewhere($hits);
                self::fail('Invalid worker credit was accepted.');
            } catch (GateError $error) {
                self::assertStringContainsString('unknown or invalid', $error->getMessage());
            }
        }
    }

    #[Test]
    public function itRefusesOverlappingSourcePathsInsteadOfApplyingARowTwice(): void
    {
        $this->write(DeclaredStructuralMaps::INDEX, DeclaredStructuralMaps::COLUMNS, [
            ['config', 'old', 'new', 'same', 'Move the parent.'],
            ['config', 'other', 'new.child', 'same', 'Move its child.'],
        ]);
        $maps = DeclaredStructuralMaps::load($this->root);
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('disjoint');
        $maps->reverseDocument('config', "new:\n  child: 7\n");
    }

    /**
     * @param list<string> $columns
     * @param list<list<string>> $rows
     */
    private function write(string $file, array $columns, array $rows): string
    {
        $text = Tsv::render($columns, $rows);
        Fs::write($this->root . '/' . $file, $text);

        return $text;
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
