<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\DeclaredFields;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\Tsv;
use ReflectionMethod;

/**
 * The declaration forms as tables: what a row must look like, what loading
 * refuses, and which rows are stale when nothing consumed them.
 */
final class DeclaredFieldsTest extends TestCase
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
    public function itDerivesTheReferenceFieldsFromTheCandidates(): void
    {
        $this->write(DeclaredFields::INDEX, DeclaredFields::COLUMNS, [
            [DeclaredFields::ADDED, 'json', 'format:json', 'probe', 'new'],
            [DeclaredFields::REMOVED, 'json', 'format:json', 'retired', 'gone'],
        ]);

        self::assertSame(['channel', 'subject', 'retired'], DeclaredFields::load($this->root)->referenceFields('json', 'format:json', ['channel', 'probe', 'subject']));
    }

    #[Test]
    public function itRefusesAValueTableForAFieldNoRowAdds(): void
    {
        $this->write(DeclaredFields::INDEX, DeclaredFields::COLUMNS, [[DeclaredFields::REMOVED, 'json', 'format:json', 'retired', 'gone']]);
        $this->write(DeclaredFields::DERIVED, DeclaredFields::DERIVED_COLUMNS, [['json', 'format:json', 'retired', 'smells', '{}', '1']]);

        $this->assertRefused(static fn(string $root): mixed => DeclaredFields::load($root), 'which no row of declared-fields.tsv adds');
    }

    #[Test]
    public function itKeepsChangesCreditsAndDerivedRowsWithinTheirReport(): void
    {
        $this->write(DeclaredFields::INDEX, DeclaredFields::COLUMNS, [
            ['added', 'json', 'format:json', 'probe', 'why'], ['removed', 'metrics', 'format:metrics', 'probe', 'why'], ['added', 'directives', 'directives', 'probe', 'why'],
        ]);
        $this->write(DeclaredFields::DERIVED, DeclaredFields::DERIVED_COLUMNS, [
            ['json', 'format:json', 'probe', 'smells', '{}', '1'], ['json', 'format:json', 'probe', 'smells', '{}', '1'], ['directives', 'directives', 'probe', 'smells', '{}', '2'],
        ]);
        $fields = DeclaredFields::load($this->root);
        self::assertSame(3, $fields->count());
        self::assertSame(['probe' => 'added'], $fields->changes('json', 'format:json'));
        self::assertSame(['x'], $fields->referenceFields('json', 'format:json', ['x', 'probe']));
        self::assertSame(['x', 'probe'], $fields->referenceFields('metrics', 'format:metrics', ['x']));
        self::assertCount(2, $fields->derived('json', 'format:json'));
        self::assertCount(1, $fields->derived('directives', 'directives'));
        $fields->credit('json', 'format:json', 'probe');
        self::assertCount(2, $fields->stale());
    }

    #[Test]
    public function itRefusesAFieldDerivedUnderAnotherReportsIntent(): void
    {
        $this->write(DeclaredFields::INDEX, DeclaredFields::COLUMNS, [['added', 'json', 'format:json', 'probe', 'why']]);
        $this->write(DeclaredFields::DERIVED, DeclaredFields::DERIVED_COLUMNS, [['metrics', 'format:metrics', 'probe', 'smells', '{}', '1']]);
        $this->assertRefused(static fn(string $root): mixed => DeclaredFields::load($root), 'which no row');
    }

    #[Test]
    public function itPreservesDuplicateMeasurementsAndAnObservedEmptyPublication(): void
    {
        $fields = DeclaredFields::load($this->root);
        $record = ['record' => '{"name":"A"}', 'fields' => ['name' => 'A', 'probe' => 1]];
        $fields->requireMeasurements('json', 'smells', 'format:json', 'candidate');
        $fields->requireMeasurements('metrics', 'smells', 'format:metrics', 'candidate');
        $fields->supply('json', 'smells', 'format:json', 'candidate', [$record, $record]);
        $fields->supply('metrics', 'smells', 'format:metrics', 'candidate', []);
        self::assertSame([$record, $record], $fields->measurements('json')[0]['records']);
        self::assertSame([], $fields->measurements('metrics')[0]['records']);
    }

    #[Test]
    public function itRefusesARequiredPublicationWithNoSupplier(): void
    {
        $fields = DeclaredFields::load($this->root);
        $fields->requireMeasurements('json', 'smells', 'format:json', 'candidate');
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('not supplied');
        $fields->measurements('json');
    }

    #[Test]
    public function itRefusesAnUnregisteredSupplier(): void
    {
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('unregistered');
        DeclaredFields::load($this->root)->supply('json', 'smells', 'format:json', 'candidate', []);
    }

    #[Test]
    public function itRefusesADuplicateSupplier(): void
    {
        $fields = DeclaredFields::load($this->root);
        $fields->requireMeasurements('json', 'smells', 'format:json', 'candidate');
        $fields->supply('json', 'smells', 'format:json', 'candidate', []);
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('twice');
        $fields->supply('json', 'smells', 'format:json', 'candidate', []);
    }

    #[Test]
    public function itRefusesADeclaredReportWithNoRegisteredPopulation(): void
    {
        $this->write(DeclaredFields::INDEX, DeclaredFields::COLUMNS, [['added', 'json', 'format:json', 'probe', 'why']]);
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('No record publications');
        DeclaredFields::load($this->root)->measurements('json');
    }

    #[Test]
    public function itKeepsEqualRecordsAndCreditsSeparateForEveryView(): void
    {
        $this->write(DeclaredFields::INDEX, DeclaredFields::COLUMNS, [
            ['added', 'json', 'format:json', 'probe', 'main observation'],
            ['added', 'json', 'check:baseline-source', 'probe', 'variant observation'],
        ]);
        $this->write(DeclaredFields::DERIVED, DeclaredFields::DERIVED_COLUMNS, [
            ['json', 'format:json', 'probe', 'smells', '{}', '1'],
            ['json', 'check:baseline-source', 'probe', 'smells', '{}', '2'],
        ]);
        $fields = DeclaredFields::load($this->root);
        self::assertSame(['format:json', 'check:baseline-source'], $fields->views('json'));
        self::assertSame('1', $fields->derived('json', 'format:json')[0]['value']);
        self::assertSame('2', $fields->derived('json', 'check:baseline-source')[0]['value']);
        $record = ['record' => '{}', 'fields' => ['probe' => 1]];
        foreach ($fields->views('json') as $view) {
            $fields->requireMeasurements('json', 'smells', $view, 'candidate');
            $fields->supply('json', 'smells', $view, 'candidate', [$record, $record]);
        }
        self::assertSame([
            ['case' => 'smells', 'view' => 'format:json', 'side' => 'candidate', 'records' => [$record, $record]],
            ['case' => 'smells', 'view' => 'check:baseline-source', 'side' => 'candidate', 'records' => [$record, $record]],
        ], $fields->measurements('json'));
        $fields->credit('json', 'format:json', 'probe');
        self::assertCount(1, $fields->stale());
        self::assertStringContainsString('check:baseline-source', $fields->stale()[0]['detail']);
    }

    #[Test]
    public function itRefusesADerivedInstanceLicensedOnlyInAnotherView(): void
    {
        $this->write(DeclaredFields::INDEX, DeclaredFields::COLUMNS, [['added', 'json', 'format:json', 'probe', 'main only']]);
        $this->write(DeclaredFields::DERIVED, DeclaredFields::DERIVED_COLUMNS, [['json', 'check:baseline-source', 'probe', 'smells', '{}', '1']]);
        $this->assertRefused(static fn(string $root): mixed => DeclaredFields::load($root), 'which no row');
    }

    #[Test]
    public function itRefusesADeclaredViewWhosePopulationWasReplacedByAnotherView(): void
    {
        $this->write(DeclaredFields::INDEX, DeclaredFields::COLUMNS, [['added', 'json', 'check:baseline-source', 'probe', 'variant only']]);
        $fields = DeclaredFields::load($this->root);
        $fields->requireMeasurements('json', 'smells', 'format:json', 'candidate');
        $fields->supply('json', 'smells', 'format:json', 'candidate', []);
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('No record publications');
        $fields->measurements('json');
    }

    #[Test]
    public function itRefusesANoncanonicalOrNonJsonMeasuredValue(): void
    {
        $this->write(DeclaredFields::INDEX, DeclaredFields::COLUMNS, [['added', 'json', 'format:json', 'probe', 'a measured field']]);
        foreach (['{}', '{"0":1}', '[1,2]', '1.0', 'null'] as $value) {
            $this->write(DeclaredFields::DERIVED, DeclaredFields::DERIVED_COLUMNS, [['json', 'format:json', 'probe', 'smells', '{}', $value]]);
            self::assertSame($value, DeclaredFields::load($this->root)->derived('json', 'format:json')[0]['value']);
        }
        foreach (['undefined', '[1, 2]'] as $value) {
            $this->write(DeclaredFields::DERIVED, DeclaredFields::DERIVED_COLUMNS, [['json', 'format:json', 'probe', 'smells', '{}', $value]]);
            $this->assertRefused(static fn(string $root): mixed => DeclaredFields::load($root), 'JSON field value');
        }
    }

    /** @return iterable<string,array{array<mixed>,string}> */
    public static function malformedPublications(): iterable
    {
        yield 'outer map loses instances' => [['A' => ['record' => 'A', 'fields' => ['name' => 'A']]], 'multiset as a list'];
        yield 'missing fields' => [[['record' => 'A']], 'complete fields object'];
        yield 'empty record key' => [[['record' => '', 'fields' => ['name' => 'A']]], 'record key'];
        yield 'fields list' => [[['record' => 'A', 'fields' => ['A']]], 'fields object'];
        yield 'empty field name' => [[['record' => 'A', 'fields' => ['' => 'A']]], 'string names'];
    }

    /** @param array<mixed> $records */
    #[Test]
    #[DataProvider('malformedPublications')]
    public function itRefusesAPublicationThatDoesNotPreserveCompleteRecordInstances(array $records, string $reason): void
    {
        $fields = DeclaredFields::load($this->root);
        $fields->requireMeasurements('json', 'smells', 'format:json', 'candidate');
        $this->expectException(GateError::class);
        $this->expectExceptionMessage($reason);
        (new ReflectionMethod(DeclaredFields::class, 'supply'))->invoke($fields, 'json', 'smells', 'format:json', 'candidate', $records);
    }

    /** @return iterable<string,array{string,string,string,string}> */
    public static function malformedRegistrations(): iterable
    {
        yield 'unknown report' => ['sarif', 'smells', 'format:sarif', 'candidate'];
        yield 'wildcard case' => ['json', '*', 'format:json', 'candidate'];
        yield 'empty case' => ['json', '', 'format:json', 'candidate'];
        yield 'unknown side' => ['json', 'smells', 'format:json', 'left'];
        yield 'unknown view' => ['json', 'smells', 'format:metrics', 'candidate'];
        yield 'wildcard view' => ['json', 'smells', '*', 'candidate'];
    }

    #[Test]
    #[DataProvider('malformedRegistrations')]
    public function itRefusesAnAmbiguousPublicationAddress(string $report, string $case, string $view, string $side): void
    {
        $this->expectException(GateError::class);
        DeclaredFields::load($this->root)->requireMeasurements($report, $case, $view, $side);
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
