<?php

declare(strict_types=1);

namespace Qualimetrix\PromiseEffect\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\PromiseEffect\Ledger;
use Qualimetrix\PromiseEffect\LedgerError;

/**
 * The ledger's promise vocabulary is closed. `Classifier` must explicitly
 * handle every accepted value: `pair()` recognizes `one-wins:` and `refuse`,
 * while an unrecognized value otherwise falls through to composition and
 * silently changes the promise's meaning.
 *
 * `scripts/promise-effect.php` runs on include and exits, so `Ledger` is
 * required directly, the way `FloorTest` reaches its own subject.
 */
final class LedgerVocabularyTest extends TestCase
{
    /** @var list<string> */
    private array $scratchRoots = [];

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 3) . '/scripts/promise-effect/Ledger.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->scratchRoots as $root) {
            self::removeTree($root);
        }

        $this->scratchRoots = [];
    }

    #[Test]
    public function itRefusesACompositionPromiseNoClassifierBranchAwards(): void
    {
        $this->expectException(LedgerError::class);
        $this->expectExceptionMessageMatches('/promises "replaces", which no classifier branch awards/');

        Ledger::load($this->rootWith($this->replaceFirst(
            '/^(composition-triple\t[^\t]*\t[^\t]*\t[^\t]*\t[^\t]*\t)survives/m',
            '$1replaces',
        )));
    }

    #[Test]
    public function itRefusesACoexistenceTheClassifierWouldSilentlyReadAsCompose(): void
    {
        $this->expectException(LedgerError::class);
        $this->expectExceptionMessageMatches('/would read as "compose"/');

        Ledger::load($this->rootWith($this->replaceFirst(
            '/^(pair\t[^\t]*\t[^\t]*\t[^\t]*\t[^\t]*\t)compose/m',
            '$1supersedes',
        )));
    }

    #[Test]
    public function itRefusesAnEmptyCoexistenceOnARowThatReachesTheClassifier(): void
    {
        // Empty is legitimate and means "no promise", but only because every row
        // carrying it is DEFERRED and never reaches `Classifier::pair()`. On a
        // row that does reach it, the same emptiness is read as composition.
        $this->expectException(LedgerError::class);
        $this->expectExceptionMessageMatches('/carries no coexistence and is PROMISED rather than DEFERRED/');

        Ledger::load($this->rootWith($this->promoteFirstDeferredPairRow()));
    }

    #[Test]
    public function itAcceptsTheOneWinsFormWhichCarriesItsWinnerInTheValue(): void
    {
        $ledger = Ledger::load($this->rootWith($this->replaceFirst(
            '/^(pair\t[^\t]*\t([^\t]*)\t[^\t]*\t[^\t]*\t)compose/m',
            '$1one-wins:$2',
        )));

        $winners = array_filter($ledger->pairs, static fn(object $row): bool => str_starts_with($row->coexistence, 'one-wins:'));

        self::assertCount(1, $winners, 'the plant wrote exactly one `one-wins:` row and the loader kept it');
    }

    private function replaceFirst(string $pattern, string $replacement): string
    {
        $source = $this->trackedLedger();
        $patched = preg_replace($pattern, $replacement, $source, 1);

        self::assertIsString($patched);
        self::assertNotSame($source, $patched, 'the plant matched nothing, so the case proves nothing');

        return $patched;
    }

    private function promoteFirstDeferredPairRow(): string
    {
        $lines = explode("\n", $this->trackedLedger());

        foreach ($lines as $index => $line) {
            $cells = explode("\t", $line);

            if (($cells[0] ?? '') !== 'pair' || ($cells[5] ?? 'unset') !== '' || !isset($cells[7])) {
                continue;
            }

            $cells[7] = 'PROMISED';
            $lines[$index] = implode("\t", $cells);

            return implode("\n", $lines);
        }

        self::fail('no DEFERRED pair row without a coexistence to promote, so the case proves nothing');
    }

    private function trackedLedger(): string
    {
        $source = file_get_contents(\dirname(__DIR__, 3) . '/' . self::LEDGER);

        self::assertIsString($source);

        return $source;
    }

    private function rootWith(string $ledger): string
    {
        $root = sys_get_temp_dir() . '/promise-ledger-vocabulary-' . bin2hex(random_bytes(6));
        $this->scratchRoots[] = $root;

        mkdir(\dirname($root . '/' . self::LEDGER), 0o775, true);
        file_put_contents($root . '/' . self::LEDGER, $ledger);

        return $root;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            is_dir($child) ? self::removeTree($child) : unlink($child);
        }

        rmdir($path);
    }

    private const string LEDGER = 'promise-effect/promise-ledger.tsv';
}
