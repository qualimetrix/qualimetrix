<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\DeclaredValues;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\Tsv;

/**
 * The declaration forms as tables: what a row must look like, what loading
 * refuses, and which rows are stale when nothing consumed them.
 */
final class DeclaredValuesTest extends TestCase
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
    public function itRefusesADerivedValueNoIntentDeclares(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [[DeclaredValues::METRIC, 'ccn', 'class', 'recalibrated']]);
        $this->write(DeclaredValues::DERIVED, DeclaredValues::DERIVED_COLUMNS, [[DeclaredValues::METRIC, 'npath', 'App\\A', '1', '2']]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredValues::load($root), 'which no intent in declared-values.tsv declares');
    }

    #[Test]
    public function itKeepsTheDerivedValueTableAsBytesAndAnIntentUncreditedIsStale(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [
            [DeclaredValues::METRIC, 'ccn', DeclaredValues::EVERY_LEVEL, 'recalibrated'],
            [DeclaredValues::FIELD, 'message', 'class', 'reworded'],
        ]);
        $derived = $this->write(DeclaredValues::DERIVED, DeclaredValues::DERIVED_COLUMNS, [[DeclaredValues::METRIC, 'ccn', 'App\\A', '1', '2']]);
        $values = DeclaredValues::load($this->root);
        $values->credit(DeclaredValues::METRIC, 'ccn');

        self::assertSame($derived, $values->derivedText());
        self::assertSame([['scope' => DeclaredValues::INDEX, 'detail' => 'The field "message" at level class']], $values->stale());
    }

    #[Test]
    public function itRefusesAnIntentAtALevelTheProductDoesNotHave(): void
    {
        $this->write(DeclaredValues::INDEX, DeclaredValues::COLUMNS, [[DeclaredValues::METRIC, 'ccn', 'galaxy', 'why']]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredValues::load($root), '"level"');
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
