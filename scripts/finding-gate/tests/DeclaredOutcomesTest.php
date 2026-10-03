<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\DeclaredOutcomes;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\Tsv;

/**
 * The declaration forms as tables: what a row must look like, what loading
 * refuses, and which rows are stale when nothing consumed them.
 */
final class DeclaredOutcomesTest extends TestCase
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
    public function itRefusesAnOutcomeWithoutItsMeasuredOutput(): void
    {
        $this->write(DeclaredOutcomes::INDEX, DeclaredOutcomes::COLUMNS, [
            ['smells', DeclaredOutcomes::ANALYSIS_TO_REFUSAL, DeclaredOutcomes::DIRECTORY . '/smells.txt', 'refused'],
        ]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredOutcomes::load($root), 'missing or empty');

        Fs::write($this->root . '/' . DeclaredOutcomes::DIRECTORY . '/smells.txt', "refused\n");
        $outcomes = DeclaredOutcomes::load($this->root);

        self::assertSame("refused\n", $outcomes->of('smells')['output'] ?? null);
        self::assertSame([['scope' => 'case:smells', 'detail' => 'The transition analysis->refusal']], $outcomes->stale());
        $outcomes->credit('smells');
        self::assertSame([], $outcomes->stale());
    }

    #[Test]
    public function itRefusesAnOutcomeFileOutsideItsDirectory(): void
    {
        $this->write(DeclaredOutcomes::INDEX, DeclaredOutcomes::COLUMNS, [['smells', DeclaredOutcomes::ANALYSIS_TO_REFUSAL, '../x.txt', 'why']]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredOutcomes::load($root), 'is not a file under declared-outcomes/');
    }

    #[Test]
    public function itRefusesAnOutcomeFileThatEscapesThroughALink(): void
    {
        Fs::write($this->root . '/outside.txt', 'An outside snapshot.');
        mkdir($this->root . '/' . DeclaredOutcomes::DIRECTORY);
        symlink('../outside.txt', $this->root . '/declared-outcomes/linked.txt');
        $this->write(DeclaredOutcomes::INDEX, DeclaredOutcomes::COLUMNS, [['alpha', DeclaredOutcomes::ANALYSIS_TO_REFUSAL, 'declared-outcomes/linked.txt', 'why']]);
        $this->assertRefused(static fn(string $root): mixed => DeclaredOutcomes::load($root), 'remain inside');
    }

    #[Test]
    public function itRefusesSharedSnapshotFilesAndUnknownCredit(): void
    {
        Fs::write($this->root . '/declared-outcomes/shared.txt', 'Measured output.');
        $this->write(DeclaredOutcomes::INDEX, DeclaredOutcomes::COLUMNS, [
            ['alpha', DeclaredOutcomes::ANALYSIS_TO_REFUSAL, 'declared-outcomes/shared.txt', 'why'],
            ['beta', DeclaredOutcomes::ANALYSIS_TO_REFUSAL, 'declared-outcomes/shared.txt', 'why'],
        ]);
        $this->assertRefused(static fn(string $root): mixed => DeclaredOutcomes::load($root), 'two cases');
        $this->write(DeclaredOutcomes::INDEX, DeclaredOutcomes::COLUMNS, []);
        $outcomes = DeclaredOutcomes::load($this->root);
        $this->expectException(GateError::class);
        $outcomes->credit('alpha');
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
