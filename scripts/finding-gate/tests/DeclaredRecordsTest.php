<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\DeclaredRecords;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\Tsv;

final class DeclaredRecordsTest extends TestCase
{
    private string $root;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->root = Fs::temporaryDirectory('declared-records-test-');
    }

    protected function tearDown(): void
    {
        Fs::removeRecursively($this->root);
    }

    #[Test]
    public function itClaimsIdenticalDerivedRecordsOneInstanceEach(): void
    {
        $this->intent('json', '{"channel":"a.b"}');
        $record = '{"channel":"a.b","subject":"x","message":"m"}';
        Fs::write($this->root . '/' . DeclaredRecords::DERIVED, Tsv::render(DeclaredRecords::DERIVED_COLUMNS, [
            ['withdrawn', 'smells', 'json', 'format:json', $record], ['withdrawn', 'smells', 'json', 'format:json', $record],
        ]));
        $records = $this->load();
        self::assertSame(1, $records->count());
        self::assertCount(2, $records->derived('json', 'format:json'));
        self::assertTrue($records->claim('withdrawn', 'smells', 'json', 'format:json', $record));
        self::assertCount(1, $records->stale());
        self::assertTrue($records->claim('withdrawn', 'smells', 'json', 'format:json', $record));
        self::assertFalse($records->claim('withdrawn', 'smells', 'json', 'format:json', $record));
        self::assertSame([], $records->stale());
    }

    #[Test]
    public function itLoadsReportSpecificRecordsWithoutTheJsonTuple(): void
    {
        $this->intent('metrics', '{"type":"class","name":"A"}');
        $record = '{"type":"class","name":"A","metrics":{"ccn":2}}';
        Fs::write($this->root . '/' . DeclaredRecords::DERIVED, Tsv::render(DeclaredRecords::DERIVED_COLUMNS, [['withdrawn', 'smells', 'metrics', 'format:metrics', $record]]));
        self::assertSame($record, $this->load()->derived('metrics', 'format:metrics')[0]['record']);
        self::assertSame([], $this->load()->derived('json', 'format:json'));
    }

    #[Test]
    public function itRefusesADerivedNeighbourOutsideTheDeclaredSelector(): void
    {
        $this->intent('metrics', '{"type":"class","name":"A"}');
        Fs::write($this->root . '/' . DeclaredRecords::DERIVED, Tsv::render(DeclaredRecords::DERIVED_COLUMNS, [['withdrawn', 'smells', 'metrics', 'format:metrics', '{"type":"class","name":"B"}']]));
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('no intent declares');
        $this->load();
    }

    #[Test]
    public function itRefusesOverlappingSelectorsAcrossWildcardCases(): void
    {
        Fs::write($this->root . '/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [
            ['withdrawn', '*', 'json', 'format:json', '{"channel":"a.b"}', 'why'],
            ['withdrawn', 'smells', 'json', 'format:json', '{"subject":"x"}', 'why'],
        ]));
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('overlaps');
        $this->load();
    }

    #[Test]
    public function itKeepsAnIntentWithoutAnyConsumedRecordStale(): void
    {
        $this->intent('directives', '{"effect":"inert"}');
        self::assertCount(1, $this->load()->stale());
        self::assertSame('directives', $this->load()->intents('directives', 'directives')[0]['report']);
    }

    /** @return iterable<string,array{string,string,string,string}> */
    public static function malformedSelectors(): iterable
    {
        yield 'not JSON' => ['withdrawn', 'json', '{channel', 'not JSON'];
        yield 'not object' => ['withdrawn', 'json', '["a"]', 'JSON object'];
        yield 'empty object' => ['withdrawn', 'json', '{}', 'JSON object'];
        yield 'not canonical' => ['withdrawn', 'json', '{"channel": "a"}', 'canonically'];
        yield 'nested selector' => ['withdrawn', 'json', '{"channel":{"x":1}}', 'scalar equality'];
        yield 'unknown change' => ['withdrawnx', 'json', '{"channel":"a"}', '"change"'];
        yield 'unknown report' => ['withdrawn', 'sarif', '{"channel":"a"}', '"report"'];
        yield 'missing metrics group' => ['withdrawn', 'metrics', '{"type":"class"}', 'type and name'];
    }

    #[Test]
    #[DataProvider('malformedSelectors')]
    public function itRefusesAMalformedSelector(string $change, string $report, string $selector, string $reason): void
    {
        $this->intent($report, $selector, $change);
        $this->expectException(GateError::class);
        $this->expectExceptionMessage($reason);
        $this->load();
    }

    #[Test]
    public function itRefusesAnIntentWithoutAnExplainedReason(): void
    {
        Fs::write($this->root . '/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', '*', 'json', 'format:json', '{"channel":"a.b"}', '?']]));
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('no reason');
        $this->load();
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function malformedDerivedRecords(): iterable
    {
        yield 'wildcard case' => ['*', '{"channel":"a.b"}', 'concrete cases'];
        yield 'empty record' => ['smells', '{}', 'JSON object'];
        yield 'list record' => ['smells', '["a.b"]', 'JSON object'];
        yield 'noncanonical record' => ['smells', '{"channel": "a.b"}', 'canonically'];
    }

    #[Test]
    #[DataProvider('malformedDerivedRecords')]
    public function itRefusesAMalformedMeasuredRecord(string $case, string $record, string $reason): void
    {
        $this->intent('json', '{"channel":"a.b"}');
        Fs::write($this->root . '/' . DeclaredRecords::DERIVED, Tsv::render(DeclaredRecords::DERIVED_COLUMNS, [['withdrawn', $case, 'json', 'format:json', $record]]));
        $this->expectException(GateError::class);
        $this->expectExceptionMessage($reason);
        $this->load();
    }

    #[Test]
    public function itKeepsIdenticalSelectorsAndRecordsInDifferentViewsIndependent(): void
    {
        $record = '{"channel":"a.b"}';
        Fs::write($this->root . '/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [
            ['withdrawn', '*', 'json', 'format:json', $record, 'Retire the main record.'],
            ['withdrawn', '*', 'json', 'check:baseline-source', $record, 'Retire the source record.'],
        ]));
        Fs::write($this->root . '/' . DeclaredRecords::DERIVED, Tsv::render(DeclaredRecords::DERIVED_COLUMNS, [
            ['withdrawn', 'smells', 'json', 'format:json', $record],
            ['withdrawn', 'smells', 'json', 'check:baseline-source', $record],
        ]));
        $records = $this->load();
        self::assertTrue($records->claim('withdrawn', 'smells', 'json', 'format:json', $record));
        self::assertFalse($records->claim('withdrawn', 'smells', 'json', 'format:json', $record));
        self::assertCount(2, $records->stale());
        self::assertTrue($records->claim('withdrawn', 'smells', 'json', 'check:baseline-source', $record));
        self::assertSame([], $records->stale());
    }

    #[Test]
    public function itRefusesUnknownReportViewCombinations(): void
    {
        Fs::write($this->root . '/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', '*', 'metrics', 'format:json', '{"type":"class","name":"A"}', 'Wrong invocation.']]));
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('report/view');
        $this->load();
    }

    private function intent(string $report, string $selector, string $change = 'withdrawn'): void
    {
        Fs::write($this->root . '/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [[$change, '*', $report, $report === 'directives' ? 'directives' : 'format:' . $report, $selector, 'why']]));
    }

    private function load(): DeclaredRecords
    {
        return DeclaredRecords::load($this->root);
    }
}
