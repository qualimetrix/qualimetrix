<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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
    private const array FIELDS = ['channel', 'subject', 'message'];

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
        self::assertSame(0, DeclaredRecords::load($this->root, self::FIELDS, self::FIELDS)->count());
        self::assertSame(0, DeclaredValues::load($this->root)->count());
        self::assertSame(0, DeclaredFields::load($this->root)->count());
        self::assertSame(0, DeclaredOutcomes::load($this->root)->count());
        self::assertSame(0, DeclaredSurfaces::load($this->root)->count());
        self::assertSame(0, DeclaredStructuralMaps::load($this->root)->count());
    }

    #[Test]
    public function itClaimsIdenticalRecordRowsOneInstanceEach(): void
    {
        $record = DeclaredRecords::canonical(['channel' => 'a.b', 'subject' => 'x', 'message' => 'm']);
        $this->write(DeclaredRecords::INDEX, DeclaredRecords::COLUMNS, [
            [DeclaredRecords::WITHDRAWN, 'smells', 'json', $record, 'twice'],
            [DeclaredRecords::WITHDRAWN, 'smells', 'json', $record, 'twice'],
        ]);
        $records = DeclaredRecords::load($this->root, self::FIELDS, self::FIELDS);

        self::assertTrue($records->claim(DeclaredRecords::WITHDRAWN, 'smells', 'json', $record));
        self::assertCount(1, $records->stale());
        self::assertTrue($records->claim(DeclaredRecords::WITHDRAWN, 'smells', 'json', $record));
        self::assertFalse($records->claim(DeclaredRecords::WITHDRAWN, 'smells', 'json', $record));
        self::assertSame([], $records->stale());
    }

    #[Test]
    public function itJudgesAWithdrawnRecordByTheReferenceFields(): void
    {
        $record = DeclaredRecords::canonical(['channel' => 'a.b', 'subject' => 'x']);
        $this->write(DeclaredRecords::INDEX, DeclaredRecords::COLUMNS, [[DeclaredRecords::WITHDRAWN, 'smells', 'json', $record, 'why']]);

        self::assertSame(1, DeclaredRecords::load($this->root, self::FIELDS, ['channel', 'subject'])->count());
        $this->assertRefused(
            static fn(string $root): mixed => DeclaredRecords::load($root, ['channel', 'subject'], self::FIELDS),
            'it lacks message',
        );
    }

    /**
     * Spelled as literals: a data provider runs before the gate's classes load.
     *
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideRefusedRecordRows(): iterable
    {
        $whole = '{"channel":"a.b","subject":"x","message":"m"}';

        yield 'not JSON' => [['withdrawn', 'smells', 'json', '{channel', 'why'], 'is not JSON'];
        yield 'not an object' => [['withdrawn', 'smells', 'json', '["a"]', 'why'], 'not a JSON object'];
        yield 'not canonical' => [['withdrawn', 'smells', 'json', '{"channel": "a.b","subject":"x","message":"m"}', 'why'], 'otherwise than canonically'];
        yield 'partial' => [['withdrawn', 'smells', 'json', '{"channel":"a.b"}', 'why'], 'is not a whole json record'];
        yield 'a field the side does not compare' => [
            ['introduced', 'smells', 'json', '{"channel":"a.b","subject":"x","message":"m","extra":1}', 'why'],
            'which the side does not compare',
        ];
        yield 'a suppressed entry that is no entry' => [['withdrawn', 'smells', 'suppressed', $whole, 'why'], 'is not a whole suppressed record'];
        yield 'an unknown change' => [['withdrawnx', 'smells', 'json', $whole, 'why'], '"change"'];
        yield 'an unknown report' => [['withdrawn', 'smells', 'sarif', $whole, 'why'], '"report"'];
        yield 'an unexplained row' => [['withdrawn', 'smells', 'json', $whole, '?'], 'has no reason'];
    }

    /** @param list<string> $row */
    #[Test]
    #[DataProvider('provideRefusedRecordRows')]
    public function itRefusesARecordRowThatCouldNotMatchExactlyOneRecord(array $row, string $reason): void
    {
        $this->write(DeclaredRecords::INDEX, DeclaredRecords::COLUMNS, [$row]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredRecords::load($root, self::FIELDS, self::FIELDS), $reason);
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

    #[Test]
    public function itDerivesTheReferenceFieldsFromTheCandidates(): void
    {
        $this->write(DeclaredFields::INDEX, DeclaredFields::COLUMNS, [
            [DeclaredFields::ADDED, 'probe', 'new'],
            [DeclaredFields::REMOVED, 'retired', 'gone'],
        ]);

        self::assertSame(['channel', 'subject', 'retired'], DeclaredFields::load($this->root)->referenceFields(['channel', 'probe', 'subject']));
    }

    #[Test]
    public function itRefusesAValueTableForAFieldNoRowAdds(): void
    {
        $this->write(DeclaredFields::INDEX, DeclaredFields::COLUMNS, [[DeclaredFields::REMOVED, 'retired', 'gone']]);
        $this->write(DeclaredFields::DERIVED, DeclaredFields::DERIVED_COLUMNS, [['retired', 'smells', '{}', '1']]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredFields::load($root), 'which no row of declared-fields.tsv adds');
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
    public function itDerivesTheReferenceSurfacesFromTheCandidates(): void
    {
        $this->write(DeclaredSurfaces::INDEX, DeclaredSurfaces::COLUMNS, [
            [DeclaredSurfaces::INTRODUCED, 'graph:export', DeclaredSurfaces::NO_FILE, 'new'],
            [DeclaredSurfaces::WITHDRAWN, 'format:text-verbose', DeclaredSurfaces::DIRECTORY . '/text-verbose.txt', 'removed'],
        ]);
        Fs::write($this->root . '/' . DeclaredSurfaces::DIRECTORY . '/text-verbose.txt', "Unknown format\n");
        $surfaces = DeclaredSurfaces::load($this->root);

        self::assertSame("Unknown format\n", $surfaces->refusalOf('format:text-verbose'));
        self::assertNull($surfaces->refusalOf('graph:export'));

        self::assertSame(['format:json', 'format:text-verbose'], $surfaces->referenceSurfaces(['format:json', 'graph:export']));
        $this->assertRefused(static fn(string $root): mixed => $surfaces->referenceSurfaces(['format:json']), 'does not have it');
    }

    #[Test]
    public function itRefusesASurfaceOfOneScope(): void
    {
        $this->write(DeclaredSurfaces::INDEX, DeclaredSurfaces::COLUMNS, [[DeclaredSurfaces::WITHDRAWN, 'case:smells|format:text', 'declared-surfaces/x.txt', 'why']]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredSurfaces::load($root), 'an artifact of one scope');
    }

    #[Test]
    public function itRefusesAWithdrawnSurfaceWithoutItsMeasuredRefusal(): void
    {
        $this->write(DeclaredSurfaces::INDEX, DeclaredSurfaces::COLUMNS, [
            [DeclaredSurfaces::WITHDRAWN, 'format:text-verbose', DeclaredSurfaces::DIRECTORY . '/text-verbose.txt', 'removed'],
        ]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredSurfaces::load($root), 'missing or empty');

        $this->write(DeclaredSurfaces::INDEX, DeclaredSurfaces::COLUMNS, [
            [DeclaredSurfaces::INTRODUCED, 'graph:export', DeclaredSurfaces::DIRECTORY . '/graph.txt', 'new'],
        ]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredSurfaces::load($root), 'is refused by nothing');
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
