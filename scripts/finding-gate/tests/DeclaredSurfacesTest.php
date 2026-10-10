<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\DeclaredSurfaces;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\Tsv;

/**
 * The declaration forms as tables: what a row must look like, what loading
 * refuses, and which rows are stale when nothing consumed them.
 */
final class DeclaredSurfacesTest extends TestCase
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
    public function itDerivesTheReferenceSurfacesFromTheCandidates(): void
    {
        $this->write(DeclaredSurfaces::INDEX, DeclaredSurfaces::COLUMNS, [
            [DeclaredSurfaces::INTRODUCED, 'graph:export', DeclaredSurfaces::NO_FILE, '*', 'new'],
        ]);
        $surfaces = DeclaredSurfaces::load($this->root);

        self::assertSame(['format:json'], $surfaces->referenceSurfaces(['format:json', 'graph:export']));
        $this->assertRefused(static fn(string $root): mixed => $surfaces->referenceSurfaces(['format:json']), 'does not have it');
    }

    #[Test]
    public function itRefusesASurfaceOfOneScope(): void
    {
        $this->write(DeclaredSurfaces::INDEX, DeclaredSurfaces::COLUMNS, [[DeclaredSurfaces::INTRODUCED, 'case:smells|format:text', '-' , '*', 'why']]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredSurfaces::load($root), 'an artifact of one scope');
    }

    #[Test]
    public function itRefusesRetiredWithdrawalAndSnapshotDeclarations(): void
    {
        $this->write(DeclaredSurfaces::INDEX, DeclaredSurfaces::COLUMNS, [
            ['withdrawn', 'format:text', 'declared-surfaces/text.txt', '*', 'removed'],
        ]);
        $this->assertRefused(static fn(string $root): mixed => DeclaredSurfaces::load($root), 'may only be one of introduced');
        $this->write(DeclaredSurfaces::INDEX, DeclaredSurfaces::COLUMNS, [
            [DeclaredSurfaces::INTRODUCED, 'graph:export', 'declared-surfaces/graph.txt', '*', 'new'],
        ]);
        $this->assertRefused(static fn(string $root): mixed => DeclaredSurfaces::load($root), 'an introduced surface names no file');
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
