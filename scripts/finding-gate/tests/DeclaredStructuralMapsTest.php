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
