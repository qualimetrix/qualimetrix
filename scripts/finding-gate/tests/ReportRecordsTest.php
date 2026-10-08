<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\{CaseOutcome, ChannelSplit, Corpus, Declarations, DeclaredFields, DeclaredRecords, FailureClass, Fs, GateError, GateReport, MetricVocabulary, Normalization, Options, RecordCheck, RecordDerivation, RecordStage, RenameMaps, ReportRecords, RunContext, SurfacePair, SyntheticTree, Tsv, ValueCheck};
use ReflectionProperty;
use WeakReference;

final class ReportRecordsTest extends TestCase
{
    private string $root;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->root = SyntheticTree::fixture(SyntheticTree::clean());
    }

    protected function tearDown(): void
    {
        SyntheticTree::remove($this->root);
    }

    #[Test]
    #[TestWith(['unknown'])]
    #[TestWith(['mixed'])]
    public function itRefusesAnUnknownOrMixedActualPublisherInsteadOfGuessingLegacy(string $shape): void
    {
        $source = $shape === 'mixed'
            ? 'src/Reporting/Formatter/Json/JsonFindingSection.php::formatFinding'
            : 'src/Reporting/Formatter/ForeignFinding.php::of';
        [$file, $method] = explode('::', $source);
        Fs::write($this->root . '/' . $file, '<?php final class Publisher { public function ' . $method . '() { return []; } }');
        $tuple = \QmxFindingGate\EquivalenceTuple::load($this->root);
        $rows = array_map(
            static fn(string $field, int $index): array => [$field, $shape === 'mixed' && $index === 0 ? \QmxFindingGate\EquivalenceTuple::source() : $source],
            $tuple->fields,
            array_keys($tuple->fields),
        );
        Fs::write($this->root . '/' . \QmxFindingGate\EquivalenceTuple::TRACKED_PATH, Tsv::render(\QmxFindingGate\EquivalenceTuple::COLUMNS, $rows));
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('unsupported finding publisher');
        ReportRecords::codecOf($this->root);
    }

    #[Test]
    public function itReadsTheCurrentPublisherOnTheReferenceTreeAsCurrentHtmlAndProse(): void
    {
        $record = array_replace(self::finding(), ['recommendation' => 'Advice']);
        $run = $this->context(true);
        $artifacts = self::artifacts([$record], 'candidate') + [
            'case:alpha|format:text-detail' => "src/A.php (1 violation)\n  ERROR at line 1  A\n    M  [a.b]\n    Recommendation: Advice\n",
        ];
        $this->observeRecords($run, RecordCheck::create($run), 'reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
        self::assertSame([], $run->report->raised());
    }

    #[Test]
    public function itReadsCurrentShowSuppressedDetailsAsSeparateAdviceAndJudgement(): void
    {
        $record = array_replace(self::finding(), ['recommendation' => 'Advice', 'acceptedLevel' => ['shape' => 'magnitude', 'describe' => '3', 'count' => 1]]);
        foreach (['Advice', 'Different advice'] as $advice) {
            $run = $this->context(true);
            $artifacts = self::artifacts([$record]) + [
                'case:alpha|show-suppressed' => "src/A.php (1 violation)\n  ERROR at line 1  A\n    M  [a.b]\n    Recommendation: " . $advice . "\n    " . \QmxFindingGate\ReportRecords::baselineText($record) . "\n",
            ];
            $sarif = ReportRecords::decode($artifacts['case:alpha|format:sarif']);
            $sarif['runs'][0]['results'][0]['message']['text'] = ReportRecords::message($record, codec: 'current');
            $artifacts['case:alpha|format:sarif'] = ValueCheck::value($sarif);
            $this->observeRecords($run, RecordCheck::create($run), 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
            if ($advice === 'Advice') {
                self::assertSame([], $run->report->raised());
            } else {
                self::assertContains(FailureClass::RECORD_PROJECTION_MISMATCH, $run->report->failureClasses());
            }
        }
    }

    #[Test]
    public function itProjectsTheUncomparedBaselineReasonOnBothPublishersWithoutCallingItAMeasuredBreach(): void
    {
        $record = array_replace(self::finding(), ['acceptedLevel' => ['shape' => 'magnitude', 'describe' => '1', 'count' => 1], 'baselineVerdict' => 'not-compared', 'baselineReason' => 'missing dependency']);
        self::assertSame('M (accepted at 1; not compared: missing dependency)', ReportRecords::projection('format:checkstyle', $record, 'current')['message']);
        self::assertSame('M (accepted at 1; not compared: missing dependency)', ReportRecords::projection('format:checkstyle', $record, 'legacy')['message']);
    }

    #[Test]
    public function itProjectsTheCandidateHtmlSharedRecordWithoutReferenceAliases(): void
    {
        $record = self::finding() + ['baselineVerdict' => null, 'baselineReason' => null];
        self::assertSame($record, ReportRecords::projection('format:html', $record, 'current'));
        self::assertSame('rule', \QmxFindingGate\PublishedVocabulary::spellingOf('format:html', 'rule', 'current'));
        self::assertSame('ruleName', \QmxFindingGate\PublishedVocabulary::spellingOf('format:html', 'rule', 'legacy'));
    }

    #[Test]
    public function itRefusesAnEmptyCheckstylePublicationAsAGateError(): void
    {
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('The checkstyle projection is not a readable checkstyle XML document.');

        ReportRecords::checkstyle('');
    }

    /** @param array<string,mixed> $record */
    #[Test]
    #[DataProvider('schemas')]
    public function itRefusesPartialAndExtraActualRecordsOfEveryReport(string $report, array $record): void
    {
        $fields = ReportRecords::SCHEMAS[$report];
        $key = ReportRecords::ARRAYS[$report];
        self::assertSame([$record], ReportRecords::extract($report, ValueCheck::value([$key => [$record]]), $fields));
        $partial = $record;
        unset($partial[$fields[0]]);
        foreach ([$partial, $record + ['extra' => 'not published']] as $bad) {
            try {
                ReportRecords::extract($report, ValueCheck::value([$key => [$bad]]), $fields);
                self::fail('An incomplete or over-complete actual record was accepted.');
            } catch (GateError $error) {
                self::assertStringContainsString('record fields differ', $error->getMessage());
            }
        }
    }

    /** @return iterable<string,array{string,array<string,mixed>}> */
    public static function schemas(): iterable
    {
        require_once \dirname(__DIR__) . '/classes.php';
        yield 'json' => ['json', self::finding()];
        yield 'suppressed' => ['suppressed', ['mechanism' => 'inline', 'suppressor' => 'src/A.php:1', 'rule' => 'a.b', 'channel' => 'a.b', 'subject' => 'class:App\\A', 'occurrence' => null, 'edge' => null, 'file' => 'src/A.php', 'line' => 1, 'symbol' => 'App\\A', 'severity' => 'error', 'message' => 'M', 'recommendation' => null]];
        yield 'metrics' => ['metrics', ['type' => 'class', 'name' => 'App\\A', 'file' => 'src/A.php', 'line' => 1, 'metrics' => ['ccn' => 2]]];
        yield 'directives' => ['directives', ['file' => 'src/A.php', 'line' => 1, 'form' => 'ignore', 'target' => 'a.b', 'effect' => 'applied', 'reason' => null, 'masked_by' => null, 'boundary_observable' => true, 'refusals' => []]];
    }

    #[Test]
    public function itReadsCurrentDirectiveRecordsOnBothIdentitySidesAndKeepsTheDeclaredLegacyReference(): void
    {
        $record = [
            'file' => 'src/Alpha.php',
            'line' => 1,
            'form' => 'symbol',
            'target' => 'replay.alpha',
            'effect' => 'refused',
            'reason' => null,
            'masked_by' => null,
            'boundary_observable' => true,
            'refusals' => [['channel' => 'annotation.unresolved-directive', 'message' => 'Unknown channel.']],
        ];
        $run = $this->context();
        self::assertSame(0, $run->declarations->fields->count());
        $check = RecordCheck::create($run);
        foreach (['candidate', 'reference'] as $side) {
            $fields = $check->fields('directives', 'directives', $side);
            self::assertSame(array_keys($record), $fields);
            self::assertSame([$record], ReportRecords::extract('directives', ValueCheck::value(['directives' => [$record]]), $fields));
            $accepted = $record;
            $accepted['effect'] = 'effective';
            $accepted['refusals'] = [];
            self::assertSame([$accepted], ReportRecords::extract('directives', ValueCheck::value(['directives' => [$accepted]]), $fields));
        }

        Fs::write($this->root . '/finding-gate/' . DeclaredFields::INDEX, Tsv::render(DeclaredFields::COLUMNS, [
            ['added', 'directives', 'directives', 'refusals', 'Publish explicit refusal details.'],
        ]));
        $check = RecordCheck::create($this->context());
        $legacy = $record;
        unset($legacy['refusals']);
        self::assertSame(array_keys($record), $check->fields('directives', 'directives', 'candidate'));
        self::assertSame(array_keys($legacy), $check->fields('directives', 'directives', 'reference'));
        self::assertSame([$record], ReportRecords::extract('directives', ValueCheck::value(['directives' => [$record]]), $check->fields('directives', 'directives', 'candidate')));
        self::assertSame([$legacy], ReportRecords::extract('directives', ValueCheck::value(['directives' => [$legacy]]), $check->fields('directives', 'directives', 'reference')));
    }

    #[Test]
    public function itAcceptsOnlyTheRecognizedNonemptyMetricSubjectProfile(): void
    {
        $record = iterator_to_array(self::schemas())['metrics'][1];
        $subject = $record + ['subject' => 'class:App\\A'];
        self::assertSame([$subject], ReportRecords::extract('metrics', ValueCheck::value(['symbols' => [$subject]]), ReportRecords::SCHEMAS['metrics']));
        foreach ([[$record + ['subject' => ''], 'nonempty string'], [$record + ['subject' => null], 'nonempty string'], [$subject + ['unknown' => 1], 'record fields differ']] as [$bad, $reason]) {
            try {
                ReportRecords::extract('metrics', ValueCheck::value(['symbols' => [$bad]]), ReportRecords::SCHEMAS['metrics']);
                self::fail('An unsupported metric profile was accepted.');
            } catch (GateError $error) {
                self::assertStringContainsString($reason, $error->getMessage());
            }
        }
    }

    #[Test]
    public function itPreservesDirectionalFieldDeclarationsInsteadOfEnablingTheOptionalSubjectProfile(): void
    {
        $record = iterator_to_array(self::schemas())['metrics'][1];
        foreach (['added', 'removed'] as $change) {
            Fs::write($this->root . '/finding-gate/' . DeclaredFields::INDEX, Tsv::render(DeclaredFields::COLUMNS, [[$change, 'metrics', 'format:metrics', 'subject', 'Publish the exact subject transition.']]));
            $run = $this->context();
            $check = RecordCheck::create($run);
            self::assertFalse($check->optionalSubject('metrics', 'format:metrics'));
            $absentSide = $change === 'added' ? 'reference' : 'candidate';
            $fields = $check->fields('metrics', 'format:metrics', $absentSide);
            try {
                ReportRecords::extract('metrics', ValueCheck::value(['symbols' => [$record + ['subject' => 'class:App\\A']]]), $fields, $check->optionalSubject('metrics', 'format:metrics'));
                self::fail('The directional subject absence was ignored.');
            } catch (GateError $error) {
                self::assertStringContainsString('record fields differ', $error->getMessage());
            }
        }
    }

    #[Test]
    public function itRefusesAnUnannouncedMetricSubjectPresenceChangeInEitherDirection(): void
    {
        foreach (['candidate', 'reference'] as $subjectSide) {
            $run = $this->context();
            $check = RecordCheck::create($run);
            foreach (['candidate', 'reference'] as $side) {
                $record = iterator_to_array(self::schemas())['metrics'][1];
                if ($side === $subjectSide) {
                    $record['subject'] = 'class:App\\A';
                }
                $artifacts = self::artifacts([self::finding()], $side);
                $artifacts['case:alpha|format:metrics'] = ValueCheck::value(['symbols' => [$record]]);
                $this->observeRecords($run, $check, $side, $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
            }
            $check->prepare('alpha');
            self::assertContains(FailureClass::VALUE_MISMATCH, $run->report->failureClasses());
        }
    }

    #[Test]
    public function itRefusesMetricRecordsWithoutTheirTypedIdentityAndNamedMetricObject(): void
    {
        $record = iterator_to_array(self::schemas())['metrics'][1];
        foreach (['type' => '', 'name' => null, 'metrics' => [1, 2]] as $field => $value) {
            $bad = $record;
            $bad[$field] = $value;
            try {
                ReportRecords::extract('metrics', ValueCheck::value(['symbols' => [$bad]]), ReportRecords::SCHEMAS['metrics']);
                self::fail('The typed metric identity or metric object was absent.');
            } catch (GateError $error) {
                self::assertStringContainsString('type, name and a metrics object', $error->getMessage());
            }
        }
    }

    #[Test]
    public function itRefusesAnAbsentObservedListInsteadOfTreatingItAsEmpty(): void
    {
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('observed violations list');
        ReportRecords::extract('json', '{"meta":{}}', ReportRecords::SCHEMAS['json']);
    }

    #[Test]
    public function itPreservesDuplicatePublishedRecordInstances(): void
    {
        $a = self::finding();
        self::assertSame([$a, $a], ReportRecords::extract('json', ValueCheck::value(['violations' => [$a, $a]]), ReportRecords::SCHEMAS['json']));
    }

    #[Test]
    public function itEditsOnlyLicensedValueSpansAndPreservesUnrelatedJsonBytes(): void
    {
        $text = "{\"meta\":1.50, \"violations\":[{\"message\":\"A\\/B\",\"line\":1}], \"tail\": \"unchanged\"}\n";
        $edited = ReportRecords::edit($text, ['["violations",0,"line"]' => '2']);
        self::assertSame(str_replace('"line":1', '"line":2', $text), $edited);
        self::assertStringContainsString('1.50', $edited);
        self::assertStringContainsString('A\\/B', $edited);
    }

    #[Test]
    public function itDeletesAdjacentMultisetInstancesWithoutLeavingInvalidCommas(): void
    {
        self::assertSame(['items' => [['x' => 3]]], ReportRecords::decode(ReportRecords::edit('{"items":[{"x":1},{"x":2},{"x":3}]}', ['["items",0]' => null, '["items",1]' => null])));
        self::assertSame(['items' => []], ReportRecords::decode(ReportRecords::edit('{"items":[{"x":1},{"x":2}]}', ['["items",0]' => null, '["items",1]' => null])));
        self::assertSame('{"items":[{"x":1}]}', ReportRecords::edit('{"items":[{"x":1},{"x":2},{"x":3}]}', ['["items",1]' => null, '["items",2]' => null]));
        self::assertSame('{"items":[]}', ReportRecords::edit('{"items":[{"x":1},{"x":2},{"x":3}]}', ['["items",0]' => null, '["items",1]' => null, '["items",2]' => null]));
        self::assertSame('{"items":[{"x":1},{"x":3}]}', ReportRecords::edit('{"items":[{"x":1},{"x":2},{"x":3}]}', ['["items",1]' => null]));
        self::assertSame('{"items":[{"x":2},{"x":4},{"x":5}]}', ReportRecords::edit('{"items":[{"x":1},{"x":2},{"x":3},{"x":4},{"x":5}]}', ['["items",0]' => null, '["items",2]' => null]));
        self::assertSame('{"items":[{"x":1}]}', ReportRecords::edit('{"items":[{"x":1},{"x":1},{"x":1}]}', ['["items",1]' => null, '["items",2]' => null]));
        self::assertSame('{"outer":{"a":"A\\/B"},"tail":1.50}' . "\r\n", ReportRecords::edit('{"outer":{"a":"A\\/B","b":1.50,"c":3},"tail":1.50}' . "\r\n", ['["outer","b"]' => null, '["outer","c"]' => null]));
        $multiline = "{\r\n  \"items\": [\r\n    {\"x\":1},\r\n    {\"x\":2},\r\n    {\"x\":3}\r\n  ],\r\n  \"tail\": \"A\\/B\"\r\n}\r\n";
        self::assertSame("{\r\n  \"items\": [\r\n    {\"x\":1}\r\n  ],\r\n  \"tail\": \"A\\/B\"\r\n}\r\n", ReportRecords::edit($multiline, ['["items",1]' => null, '["items",2]' => null]));
        self::assertSame(['{"x":1.50}', '{"x":"A\\/B"}'], ReportRecords::rawRecords('{"items":[{"x":1.50},{"x":"A\\/B"}]}', 'items'));
        self::assertSame('{"items":[]}', ReportRecords::edit('{"items":[{"x":1}]}', ['["items",0]' => '', '["items",0,"x"]' => '']));
        foreach ([
            ['{"items":[}', [], 'not JSON'],
            ['{"items":[1]}', ['["items",0]' => 'oops'], 'not JSON'],
            ['{"items":[{"x":1}]}', ['["items",0]' => '{"x":2}', '["items",0,"x"]' => '2'], 'overlap'],
        ] as [$text, $edits, $message]) {
            try {
                ReportRecords::edit($text, $edits);
                self::fail('An invalid JSON edit was accepted.');
            } catch (GateError $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    #[Test]
    public function itRefusesAChangedSarifRuleIndexBeforeCanonicalizingTheCatalog(): void
    {
        $document = self::sarif(self::finding());
        $document['runs'][0]['results'][0]['ruleIndex'] = 1;
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('ruleIndex');
        ReportRecords::projected('format:sarif', ValueCheck::value($document));
    }

    #[Test]
    #[DataProvider('actualCompletenessDefects')]
    public function itReportsIncompletePublishedRecordsOnEitherSideDuringCaseComparison(string $report, string $defect, string $side): void
    {
        $run = $this->context();
        $artifacts = self::artifacts([self::finding()]);
        $record = iterator_to_array(self::schemas())[$report][1];
        if ($defect === 'partial') {
            unset($record[ReportRecords::SCHEMAS[$report][0]]);
        } else {
            $record['extra'] = 1;
        }
        $surface = $report === 'directives' ? 'directives' : 'format:' . $report;
        $artifacts['case:alpha|' . $surface] = ValueCheck::value([ReportRecords::ARRAYS[$report] => [$record], 'exit_code' => 0]);
        $this->observeRecords($run, RecordCheck::create($run), $side, $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
        self::assertContains(FailureClass::RECORD_PROJECTION_MISMATCH, $run->report->failureClasses());
        self::assertSame(1, $run->report->exitCode());
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function actualCompletenessDefects(): iterable
    {
        foreach (['json', 'suppressed', 'metrics', 'directives'] as $report) {
            foreach (['partial', 'extra'] as $defect) {
                foreach (['candidate', 'reference'] as $side) {
                    yield $report . '-' . $defect . '-' . $side => [$report, $defect, $side];
                }
            }
        }
    }

    #[Test]
    public function itChecksSameInputOutputAndParallelRecordsAgainstTheirOwnMainPublication(): void
    {
        $run = $this->context();
        $artifacts = self::artifacts([self::finding()]);
        $other = self::finding();
        $other['message'] = 'Wrong output';
        $artifacts['case:alpha|check:output:file'] = ValueCheck::value(['violations' => [$other]]);
        $artifacts['case:alpha|check:parallel'] = ValueCheck::value(['violations' => [$other]]);
        $this->observeRecords($run, RecordCheck::create($run), 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
        self::assertCount(2, $run->report->raised());
        self::assertSame([FailureClass::RECORD_PROJECTION_MISMATCH], $run->report->failureClasses());
    }

    #[Test]
    public function itRefusesDirectivesJsonWhoseExitDisagreesWithTheProcess(): void
    {
        $run = $this->context();
        $artifacts = self::artifacts([self::finding()]);
        $artifacts['case:alpha|exit:directives'] = '5';
        $this->observeRecords($run, RecordCheck::create($run), 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
        self::assertContains(FailureClass::RECORD_PROJECTION_MISMATCH, $run->report->failureClasses());
    }

    #[Test]
    public function itSuppliesCompleteMeasurementsOnceBeforeRecordAndFieldProjection(): void
    {
        Fs::write($this->root . '/finding-gate/' . DeclaredFields::INDEX, Tsv::render(DeclaredFields::COLUMNS, [['added', 'json', 'format:json', 'fresh', 'A new observation.']]));
        $publisher = $this->root . '/' . explode('::', \QmxFindingGate\EquivalenceTuple::source())[0];
        Fs::write($publisher, str_replace("            'message' => null,", "            'message' => null,\n            'fresh' => null,", Fs::read($publisher)));
        self::assertContains('fresh', \QmxFindingGate\EquivalenceTuple::derive($this->root)->fields);
        $run = $this->context();
        foreach (['candidate', 'reference'] as $side) {
            $run->declarations->fields->requireMeasurements('json', 'alpha', 'format:json', $side);
        }
        $a = self::finding() + ['fresh' => 7];
        $records = RecordCheck::create($run);
        $this->observeRecords($run, $records, 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$a]));
        $this->observeRecords($run, $records, 'reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([self::finding()], 'reference'));
        $source = 'case:alpha|format:json';
        $captures = [];
        foreach (['candidate', 'reference'] as $side) {
            $captures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $run->rankings->of($side, $source)]);
        }
        self::assertTrue(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $captures, $run, $records));
        $records->prepare('alpha');
        $records->prepare('alpha');
        self::assertFalse(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $captures, $run, $records));
        $measurements = $run->declarations->fields->measurements('json');
        self::assertCount(2, $measurements);
        self::assertSame(7, $measurements[0]['records'][0]['fields']['fresh']);
        self::assertArrayNotHasKey('fresh', json_decode($measurements[0]['records'][0]['record'], true));
    }

    #[Test]
    public function itDerivesOnlySelectedResidualsAndRefusesAnUndeclaredNeighbourWithoutWriting(): void
    {
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', '{"channel":"a.b"}', 'Remove exactly this channel.']]));
        $run = $this->context();
        $records = RecordCheck::create($run);
        $derivation = RecordDerivation::create($run);
        $derivation->startDeriving();
        $neighbour = self::finding();
        $neighbour['channel'] = $neighbour['code'] = $neighbour['rule'] = 'c.d';
        $this->observeRecords($run, $records, 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([]));
        $this->observeRecords($run, $records, 'reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([self::finding(), $neighbour], 'reference'));
        $records->prepare('alpha');
        self::assertContains(FailureClass::RECORD_UNDECLARED, $run->report->failureClasses());
        self::assertSame([], $derivation->rewriteDerived());
        self::assertFileDoesNotExist($this->root . '/finding-gate/' . DeclaredRecords::DERIVED);
    }

    #[Test]
    public function itWritesTheCompleteSelectedRecordWithItsDuplicateMultiplicity(): void
    {
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', '{"channel":"a.b"}', 'Remove exactly this channel.']]));
        $run = $this->context();
        $records = RecordCheck::create($run);
        $derivation = RecordDerivation::create($run);
        $derivation->startDeriving();
        $a = self::finding();
        $a['metricValue'] = 3.0;
        $this->observeRecords($run, $records, 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([]));
        $this->observeRecords($run, $records, 'reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$a, $a], 'reference'));
        $source = 'case:alpha|format:json';
        $captures = [];
        foreach (['candidate', 'reference'] as $side) {
            $captures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $run->rankings->of($side, $source)]);
        }
        self::assertTrue(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $captures, $run, $records));
        $records->prepare('alpha');
        self::assertFalse(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $captures, $run, $records));
        self::assertSame([], $run->report->raised());
        self::assertSame([DeclaredRecords::DERIVED], $derivation->rewriteDerived());
        $rows = Tsv::rows($this->root . '/finding-gate/' . DeclaredRecords::DERIVED, DeclaredRecords::DERIVED_COLUMNS);
        self::assertCount(2, $rows);
        self::assertSame(DeclaredRecords::canonical(self::comparativeFinding($a)), $rows[0]['record']);
        self::assertSame($rows[0], $rows[1]);
        $referenceSlot = $captures['reference']->rankings[$source];
        $referenceSlot['ranked']['stdout'] = ReportRecords::edit($referenceSlot['ranked']['stdout'], [ValueCheck::value(['violations', 1, 'metricValue']) => '3.00000000000000001']);
        self::assertSame(ReportRecords::decode($captures['reference']->rankings[$source]['ranked']['stdout']), ReportRecords::decode($referenceSlot['ranked']['stdout']));
        $distinctRaw = ['candidate' => $captures['candidate'], 'reference' => new \QmxFindingGate\CaptureResult([], [$source => $referenceSlot])];
        self::assertFalse(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $distinctRaw, $run, $records));
        $rawOccurrences = ReportRecords::rawRecords($referenceSlot['ranked']['stdout'], 'violations');
        $permutedSlot = $referenceSlot;
        $permutedSlot['ranked']['stdout'] = ReportRecords::edit($referenceSlot['ranked']['stdout'], [
            ValueCheck::value(['violations', 0]) => $rawOccurrences[1],
            ValueCheck::value(['violations', 1]) => $rawOccurrences[0],
        ]);
        self::assertFalse(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, [
            'candidate' => $captures['candidate'],
            'reference' => new \QmxFindingGate\CaptureResult([], [$source => $permutedSlot]),
        ], $run, $records));

        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::DERIVED, Tsv::render(DeclaredRecords::DERIVED_COLUMNS, [array_values($rows[0])]));
        $partialRun = $this->context();
        $partialRecords = RecordCheck::create($partialRun);
        $this->observeRecords($partialRun, $partialRecords, 'candidate', $partialRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([]));
        $this->observeRecords($partialRun, $partialRecords, 'reference', $partialRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$a, $a], 'reference'));
        $partialRecords->prepare('alpha');
        self::assertContains(FailureClass::RECORD_UNDECLARED, $partialRun->report->failureClasses());
        $partialCaptures = [
            'candidate' => new \QmxFindingGate\CaptureResult([], [$source => $partialRun->rankings->of('candidate', $source)]),
            'reference' => new \QmxFindingGate\CaptureResult([], [$source => $referenceSlot]),
        ];
        self::assertTrue(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $partialCaptures, $partialRun, $partialRecords));
    }

    #[Test]
    public function itRemovesOnlyTheDeclaredCompleteProjectionInstance(): void
    {
        $a = self::finding();
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', '{"channel":"a.b"}', 'Remove one observation.']]));
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::DERIVED, Tsv::render(DeclaredRecords::DERIVED_COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', DeclaredRecords::canonical(self::comparativeFinding($a))]]));
        $run = $this->context();
        $check = RecordCheck::create($run);
        $this->observeRecords($run, $check, 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$a]));
        $this->observeRecords($run, $check, 'reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$a, $a], 'reference'));
        $pair = new SurfacePair('case:alpha|format:gitlab', 'format:gitlab', self::artifacts([$a])['case:alpha|format:gitlab'], self::artifacts([$a, $a])['case:alpha|format:gitlab']);
        $check->prepare('alpha');
        RecordStage::create($run)->applyStage($pair);
        self::assertCount(1, ReportRecords::decode((string) $pair->reference));
        self::assertSame([], $run->report->raised());
    }

    #[Test]
    public function itChecksBaselineEntriesAgainstTheirVariantSourceInsteadOfMainJson(): void
    {
        Fs::write($this->root . '/finding-gate/cases/alpha/baseline-src/src/Alpha.php', "<?php\n");
        $main = self::finding();
        $main['metricValue'] = 1;
        $variant = self::finding();
        $run = $this->context();
        $artifacts = self::artifacts([$main]);
        $artifacts['case:alpha|check:baseline-source'] = ValueCheck::value(['violations' => [$variant]]);
        $artifacts['case:alpha|baseline-file'] = self::baselineDocument($variant, [3]);
        $this->observeRecords($run, RecordCheck::create($run), 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
        self::assertSame([], $run->report->raised());
        $bad = $this->context();
        $artifacts['case:alpha|baseline-file'] = self::baselineDocument($variant, [1]);
        $this->observeRecords($bad, RecordCheck::create($bad), 'candidate', $bad->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
        self::assertContains(FailureClass::RECORD_PROJECTION_MISMATCH, $bad->report->failureClasses());
    }

    #[Test]
    public function itRefusesMissingOrInvalidVariantAuthorityAndPreservesObservedEmpty(): void
    {
        Fs::write($this->root . '/finding-gate/cases/alpha/baseline-src/src/Alpha.php', "<?php\n");
        foreach ([null, '{bad', '{"violations":[]}'] as $source) {
            $run = $this->context();
            $artifacts = self::artifacts([]);
            if ($source !== null) {
                $artifacts['case:alpha|check:baseline-source'] = $source;
            }
            $this->observeRecords($run, RecordCheck::create($run), 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
            self::assertSame($source === null ? [FailureClass::RECORD_PROJECTION_MISMATCH] : [], $run->report->failureClasses());
        }
    }

    #[Test]
    public function itDoesNotLicenseVariantResidualsWithAMainViewIntent(): void
    {
        Fs::write($this->root . '/finding-gate/cases/alpha/baseline-src/src/Alpha.php', "<?php\n");
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', '{"channel":"a.b"}', 'Remove the main publication only.']]));
        $run = $this->context();
        $check = RecordCheck::create($run);
        $check->startDeriving();
        $candidate = self::artifacts([]) + ['case:alpha|check:baseline-source' => '{"violations":[]}'];
        $reference = self::artifacts([self::finding()], 'reference') + ['case:alpha|check:baseline-source' => ValueCheck::value(['violations' => [self::finding()]])];
        $this->observeRecords($run, $check, 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $candidate);
        $this->observeRecords($run, $check, 'reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $reference);
        $check->prepare('alpha');
        self::assertContains(FailureClass::RECORD_UNDECLARED, $run->report->failureClasses());
        self::assertCount(1, $check->licensedResiduals('alpha', 'format:json', 'reference'));
        self::assertSame([], $check->licensedResiduals('alpha', 'check:baseline-source', 'reference'));
        self::assertSame([], $check->rewriteDerived());
    }

    #[Test]
    public function itSuppliesIdenticalInstancesSeparatelyForBothAuthorityViews(): void
    {
        Fs::write($this->root . '/finding-gate/cases/alpha/baseline-src/src/Alpha.php', "<?php\n");
        Fs::write($this->root . '/finding-gate/' . DeclaredFields::INDEX, Tsv::render(DeclaredFields::COLUMNS, [
            ['added', 'json', 'format:json', 'fresh', 'Observe the main field.'],
            ['added', 'json', 'check:baseline-source', 'fresh', 'Observe the source field.'],
        ]));
        $run = $this->context();
        foreach (['format:json', 'check:baseline-source'] as $view) {
            foreach (['candidate', 'reference'] as $side) {
                $run->declarations->fields->requireMeasurements('json', 'alpha', $view, $side);
            }
        }
        $candidate = self::artifacts([self::finding() + ['fresh' => 7]]) + ['case:alpha|check:baseline-source' => ValueCheck::value(['violations' => [self::finding() + ['fresh' => 9]]])];
        $reference = self::artifacts([self::finding()], 'reference') + ['case:alpha|check:baseline-source' => ValueCheck::value(['violations' => [self::finding()]])];
        $check = RecordCheck::create($run);
        $this->observeRecords($run, $check, 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $candidate);
        $this->observeRecords($run, $check, 'reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $reference);
        $check->prepare('alpha');
        $measurements = $run->declarations->fields->measurements('json');
        self::assertCount(4, $measurements);
        $values = [];
        foreach ($measurements as $measurement) {
            if ($measurement['side'] === 'candidate') {
                $values[$measurement['view']] = $measurement['records'][0]['fields']['fresh'];
            }
        }
        self::assertSame(['format:json' => 7, 'check:baseline-source' => 9], $values);
    }

    #[Test]
    public function itKeepsFieldValueMeasurementsDistinctAcrossMainAndVariantInvocations(): void
    {
        Fs::write($this->root . '/finding-gate/cases/alpha/baseline-src/src/Alpha.php', "<?php\n");
        Fs::write($this->root . '/finding-gate/' . \QmxFindingGate\DeclaredValues::INDEX, Tsv::render(\QmxFindingGate\DeclaredValues::COLUMNS, [['field', 'metricValue', '*', 'Change measured values in both invocations.']]));
        $run = $this->context();
        $check = RecordCheck::create($run);
        $values = ValueCheck::create($run);
        $values->startDeriving();
        $old = self::finding();
        $old['metricValue'] = 1;
        $main = self::finding();
        $variant = self::finding();
        $variant['metricValue'] = 5;
        $this->observeRecords($run, $check, 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$main]) + ['case:alpha|check:baseline-source' => ValueCheck::value(['violations' => [$variant]])]);
        $this->observeRecords($run, $check, 'reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$old], 'reference') + ['case:alpha|check:baseline-source' => ValueCheck::value(['violations' => [$old]])]);
        $check->prepare('alpha');
        self::assertSame([], $run->report->raised());
        $values->rewriteDerived();
        $rows = Tsv::rows($this->root . '/finding-gate/' . \QmxFindingGate\DeclaredValues::DERIVED, \QmxFindingGate\DeclaredValues::DERIVED_COLUMNS);
        self::assertCount(2, $rows);
        self::assertStringStartsWith('case:alpha|check:baseline-source|record:', $rows[0]['subject']);
        self::assertStringStartsWith('case:alpha|format:json|record:', $rows[1]['subject']);
        self::assertSame(['5', '3'], array_column($rows, 'to'));
    }

    /**
     * @param array<string,mixed> $record
     * @param list<int> $magnitudes
     */
    private static function baselineDocument(array $record, array $magnitudes): string
    {
        return ValueCheck::value(['version' => 14, 'entries' => [$record['subject'] => [['channel' => $record['channel'], 'magnitudes' => $magnitudes]]]]);
    }

    #[Test]
    public function itSharesLiveRolesWithoutRetainingAReleasedRun(): void
    {
        $run = $this->context();
        $records = RecordCheck::create($run);
        $values = ValueCheck::create($run);
        self::assertSame($records, RecordCheck::create($run));
        self::assertSame($values, ValueCheck::create($run));
        $weakRun = WeakReference::create($run);
        $weakRecords = WeakReference::create($records);
        $weakValues = WeakReference::create($values);
        unset($run, $records, $values);
        gc_collect_cycles();
        self::assertNull($weakRun->get());
        self::assertNull($weakRecords->get());
        self::assertNull($weakValues->get());
    }

    #[Test]
    public function itRewritesOnlyLicensedFindingValuesOnEveryReadableProjection(): void
    {
        Fs::write($this->root . '/finding-gate/' . \QmxFindingGate\DeclaredValues::INDEX, Tsv::render(\QmxFindingGate\DeclaredValues::COLUMNS, [
            ['field', 'message', '*', 'Change this finding message.'],
            ['field', 'metricValue', '*', 'Change this finding magnitude.'],
        ]));
        $run = $this->context();
        ValueCheck::create($run)->startDeriving();
        $a = self::finding();
        $a['message'] = 'New (term)';
        $a['metricValue'] = 4;
        $a['threshold'] = 1.0;
        $b = self::finding();
        $b['threshold'] = 1.0;
        $candidate = self::artifacts([$a]);
        $reference = self::artifacts([$b], 'reference');
        foreach (['candidate' => $a, 'reference' => $b] as $side => $record) {
            $extra = [
                'case:alpha|format:text' => 'src/A.php:1: error[a.b]: ' . $record['message'] . " (A)\n",
                'case:alpha|format:github' => '::error file=src/A.php,line=1,title=a.b::' . $record['message'] . "\n",
                'case:alpha|format:checkstyle' => '<checkstyle><file name="src/A.php"><error line="1" severity="error" source="qmx.a.b" message="' . $record['message'] . '"/></file></checkstyle>',
            ];
            if ($side === 'candidate') {
                $candidate += $extra;
            } else {
                $reference += $extra;
            }
        }
        $check = RecordCheck::create($run);
        $this->observeRecords($run, $check, 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $candidate);
        $this->observeRecords($run, $check, 'reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $reference);
        $check->prepare('alpha');
        foreach (['format:json', 'format:gitlab', 'format:sarif', 'format:html', 'format:text', 'format:github', 'format:checkstyle'] as $surface) {
            $aText = $candidate['case:alpha|' . $surface];
            $bText = $reference['case:alpha|' . $surface];
            if ($surface === 'format:html') {
                $aText = \QmxFindingGate\ReportPayload::of($aText, 'case:alpha|format:html', 'candidate');
                $bText = \QmxFindingGate\ReportPayload::of($bText, 'case:alpha|format:html', 'reference');
            }
            $pair = new SurfacePair('case:alpha|' . $surface, $surface, $aText, $bText);
            RecordStage::create($run)->applyStage($pair);
            self::assertSame($surface === 'format:html' ? ValueCheck::value(['tree' => ['violations' => [ReportRecords::projection('format:html', $b, 'current')]]]) : $pair->reference, $pair->candidate, $surface);
        }
        self::assertSame([], $run->report->raised());
        $source = 'case:alpha|format:json';
        $captures = [];
        foreach (['candidate', 'reference'] as $side) {
            $captures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $run->rankings->of($side, $source)]);
        }
        self::assertFalse(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $captures, $run, $check));
        $candidateSlot = $captures['candidate']->rankings[$source];
        $candidateSlot['ranked']['stdout'] = str_replace('"threshold":1.0', '"threshold":1.00000000000000001', $candidateSlot['ranked']['stdout']);
        $captures['candidate'] = new \QmxFindingGate\CaptureResult([], [$source => $candidateSlot]);
        self::assertTrue(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $captures, $run, $check));

        $first = $b;
        $first['techDebtMinutes'] = 15.0;
        $second = $first;
        $second['subject'] = 'declaration:callable:App\\B::run@src/A.php';
        $referenceRows = [$first, $second];
        $candidateRows = array_map(static fn(array $record): array => array_replace($record, ['message' => 'New (term)', 'metricValue' => 4]), $referenceRows);
        $rankedSpelling = static function (array $slots): array {
            $source = 'case:alpha|format:json';
            $slots[$source]['ranked']['stdout'] = ReportRecords::edit($slots[$source]['ranked']['stdout'], [
                ValueCheck::value(['topIssues', 1, 'debtMinutes']) => '15.00000000000000001',
            ]);
            return $slots;
        };
        $cohortRun = $this->context();
        ValueCheck::create($cohortRun)->startDeriving();
        $cohortRecords = RecordCheck::create($cohortRun);
        $this->observeRecords($cohortRun, $cohortRecords, 'candidate', $cohortRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($candidateRows), $rankedSpelling);
        $this->observeRecords($cohortRun, $cohortRecords, 'reference', $cohortRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($referenceRows, 'reference'), $rankedSpelling);
        $cohortRecords->prepare('alpha');
        $cohortCaptures = [];
        foreach (['candidate', 'reference'] as $side) {
            $cohortCaptures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $cohortRun->rankings->of($side, $source)]);
        }
        self::assertFalse(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $cohortCaptures, $cohortRun, $cohortRecords), $cohortRun->report->render());
        $permutedSpelling = static function (array $slots): array {
            $source = 'case:alpha|format:json';
            $slots[$source]['ranked']['stdout'] = ReportRecords::edit($slots[$source]['ranked']['stdout'], [
                ValueCheck::value(['topIssues', 0, 'debtMinutes']) => '15.00000000000000001',
                ValueCheck::value(['topIssues', 1, 'debtMinutes']) => '15.0',
            ]);
            return $slots;
        };
        $permutedRun = $this->context();
        ValueCheck::create($permutedRun)->startDeriving();
        $permutedRecords = RecordCheck::create($permutedRun);
        $this->observeRecords($permutedRun, $permutedRecords, 'candidate', $permutedRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($candidateRows), $permutedSpelling);
        $this->observeRecords($permutedRun, $permutedRecords, 'reference', $permutedRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($referenceRows, 'reference'), $permutedSpelling);
        $permutedRecords->prepare('alpha');
        $permutedCaptures = [];
        foreach (['candidate', 'reference'] as $side) {
            $permutedCaptures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $permutedRun->rankings->of($side, $source)]);
        }
        self::assertSame([], $permutedRun->report->raised());
        self::assertFalse(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $permutedCaptures, $permutedRun, $permutedRecords));
        $competingRows = $candidateRows;
        $competingRows[1]['metricValue'] = 5;
        $competingRun = $this->context();
        ValueCheck::create($competingRun)->startDeriving();
        $competingRecords = RecordCheck::create($competingRun);
        $this->observeRecords($competingRun, $competingRecords, 'candidate', $competingRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($competingRows), $rankedSpelling);
        $this->observeRecords($competingRun, $competingRecords, 'reference', $competingRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($referenceRows, 'reference'), $rankedSpelling);
        $competingRecords->prepare('alpha');
        $competingCaptures = [];
        foreach (['candidate', 'reference'] as $side) {
            $competingCaptures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $competingRun->rankings->of($side, $source)]);
        }
        self::assertSame([], $competingRun->report->raised());
        self::assertTrue(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $competingCaptures, $competingRun, $competingRecords));
        $unspelledCompetingRun = $this->context();
        ValueCheck::create($unspelledCompetingRun)->startDeriving();
        $unspelledCompetingRecords = RecordCheck::create($unspelledCompetingRun);
        $this->observeRecords($unspelledCompetingRun, $unspelledCompetingRecords, 'candidate', $unspelledCompetingRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($competingRows));
        $this->observeRecords($unspelledCompetingRun, $unspelledCompetingRecords, 'reference', $unspelledCompetingRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($referenceRows, 'reference'));
        $unspelledCompetingRecords->prepare('alpha');
        $unspelledCompetingCaptures = [];
        foreach (['candidate', 'reference'] as $side) {
            $unspelledCompetingCaptures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $unspelledCompetingRun->rankings->of($side, $source)]);
        }
        self::assertSame([], $unspelledCompetingRun->report->raised());
        self::assertFalse(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $unspelledCompetingCaptures, $unspelledCompetingRun, $unspelledCompetingRecords));
        $partialReferenceRows = $referenceRows;
        $partialReferenceRows[1] = $candidateRows[1];
        $partialRun = $this->context();
        ValueCheck::create($partialRun)->startDeriving();
        $partialRecords = RecordCheck::create($partialRun);
        $this->observeRecords($partialRun, $partialRecords, 'candidate', $partialRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($candidateRows), $rankedSpelling);
        $this->observeRecords($partialRun, $partialRecords, 'reference', $partialRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($partialReferenceRows, 'reference'), $rankedSpelling);
        $partialRecords->prepare('alpha');
        self::assertSame([], $partialRun->report->raised());
        $partialCaptures = [];
        foreach (['candidate', 'reference'] as $side) {
            $partialCaptures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $partialRun->rankings->of($side, $source)]);
        }
        self::assertTrue(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $partialCaptures, $partialRun, $partialRecords));
        $partialPermutedRun = $this->context();
        ValueCheck::create($partialPermutedRun)->startDeriving();
        $partialPermutedRecords = RecordCheck::create($partialPermutedRun);
        $this->observeRecords($partialPermutedRun, $partialPermutedRecords, 'candidate', $partialPermutedRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($candidateRows), $permutedSpelling);
        $this->observeRecords($partialPermutedRun, $partialPermutedRecords, 'reference', $partialPermutedRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($partialReferenceRows, 'reference'), $permutedSpelling);
        $partialPermutedRecords->prepare('alpha');
        $partialPermutedCaptures = [];
        foreach (['candidate', 'reference'] as $side) {
            $partialPermutedCaptures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $partialPermutedRun->rankings->of($side, $source)]);
        }
        self::assertSame([], $partialPermutedRun->report->raised());
        self::assertTrue(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $partialPermutedCaptures, $partialPermutedRun, $partialPermutedRecords));
        $uniformRun = $this->context();
        ValueCheck::create($uniformRun)->startDeriving();
        $uniformRecords = RecordCheck::create($uniformRun);
        $this->observeRecords($uniformRun, $uniformRecords, 'candidate', $uniformRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($candidateRows));
        $this->observeRecords($uniformRun, $uniformRecords, 'reference', $uniformRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($partialReferenceRows, 'reference'));
        $uniformRecords->prepare('alpha');
        $uniformCaptures = [];
        foreach (['candidate', 'reference'] as $side) {
            $uniformCaptures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $uniformRun->rankings->of($side, $source)]);
        }
        self::assertSame([], $uniformRun->report->raised());
        self::assertFalse(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $uniformCaptures, $uniformRun, $uniformRecords));

        Fs::write($this->root . '/finding-gate/' . DeclaredFields::INDEX, Tsv::render(DeclaredFields::COLUMNS, [
            ['added', 'json', 'ranking', 'probe', 'Publish the measured ranked member.'],
        ]));
        $formatter = $this->root . '/src/Reporting/Formatter/Json/JsonFormatter.php';
        Fs::write($formatter, str_replace("                'rank' => null,", "                'rank' => null,\n                'probe' => null,", Fs::read($formatter)));
        $schemaRun = $this->context();
        ValueCheck::create($schemaRun)->startDeriving();
        $schemaRecords = RecordCheck::create($schemaRun);
        foreach (['candidate', 'reference'] as $side) {
            $schemaRun->declarations->fields->requireMeasurements('json', 'alpha', 'ranking', $side);
        }
        $withProbe = static function (array $slots): array {
            $key = 'case:alpha|format:json';
            $document = ReportRecords::decode($slots[$key]['ranked']['stdout']);
            foreach ($document['topIssues'] as &$issue) {
                $issue['probe'] = 7;
            }
            unset($issue);
            $slots[$key]['ranked']['stdout'] = ValueCheck::value($document);
            return $slots;
        };
        $this->observeRecords($schemaRun, $schemaRecords, 'candidate', $schemaRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$a]), $withProbe);
        $this->observeRecords($schemaRun, $schemaRecords, 'reference', $schemaRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$b], 'reference'));
        $schemaRecords->prepare('alpha');
        $schemaCaptures = [];
        foreach (['candidate', 'reference'] as $side) {
            $schemaCaptures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $schemaRun->rankings->of($side, $source)]);
        }
        self::assertSame([], $schemaRun->report->raised());
        self::assertSame([true, true], array_column($schemaRun->declarations->fields->requiredPublications('alpha'), 'supplied'));
        self::assertFalse(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $schemaCaptures, $schemaRun, $schemaRecords));
        foreach ([
            'identical' => [$candidateRows, $referenceRows, static fn(array $slots): array => $slots, false],
            'uniform' => [$candidateRows, $referenceRows, $rankedSpelling, false],
            'permuted' => [$candidateRows, $referenceRows, $permutedSpelling, false],
            'partial' => [$candidateRows, $partialReferenceRows, $rankedSpelling, true],
            'competing' => [$competingRows, $referenceRows, $rankedSpelling, true],
        ] as $name => [$left, $right, $spelling, $residual]) {
            $jointRun = $this->context();
            ValueCheck::create($jointRun)->startDeriving();
            $jointRecords = RecordCheck::create($jointRun);
            foreach (['candidate', 'reference'] as $side) {
                $jointRun->declarations->fields->requireMeasurements('json', 'alpha', 'ranking', $side);
            }
            $candidateRanking = static fn(array $slots): array => $spelling($withProbe($slots));
            $this->observeRecords($jointRun, $jointRecords, 'candidate', $jointRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($left), $candidateRanking);
            $this->observeRecords($jointRun, $jointRecords, 'reference', $jointRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts($right, 'reference'), $spelling);
            $jointRecords->prepare('alpha');
            $jointCaptures = [];
            foreach (['candidate', 'reference'] as $side) {
                $jointCaptures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $jointRun->rankings->of($side, $source)]);
            }
            self::assertSame([], $jointRun->report->raised(), $name);
            self::assertSame([true, true], array_column($jointRun->declarations->fields->requiredPublications('alpha'), 'supplied'), $name);
            self::assertSame($residual, \QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $jointCaptures, $jointRun, $jointRecords), $name);
        }
        $mixedCandidate = $a;
        $mixedReference = $b;
        $mixedCandidate['techDebtMinutes'] = 15.0;
        $mixedReference['techDebtMinutes'] = 15.0;
        $mixedRun = $this->context();
        ValueCheck::create($mixedRun)->startDeriving();
        $mixedRecords = RecordCheck::create($mixedRun);
        foreach (['candidate', 'reference'] as $side) {
            $mixedRun->declarations->fields->requireMeasurements('json', 'alpha', 'ranking', $side);
        }
        $withUntouchedToken = static function (array $slots) use ($withProbe): array {
            $key = 'case:alpha|format:json';
            $slots = $withProbe($slots);
            $slots[$key]['ranked']['stdout'] = ReportRecords::edit($slots[$key]['ranked']['stdout'], [
                ValueCheck::value(['topIssues', 0, 'debtMinutes']) => '15.00000000000000001',
            ]);
            return $slots;
        };
        $this->observeRecords($mixedRun, $mixedRecords, 'candidate', $mixedRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$mixedCandidate]), $withUntouchedToken);
        $this->observeRecords($mixedRun, $mixedRecords, 'reference', $mixedRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$mixedReference], 'reference'));
        $mixedRecords->prepare('alpha');
        $mixedCaptures = [];
        foreach (['candidate', 'reference'] as $side) {
            $mixedCaptures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $mixedRun->rankings->of($side, $source)]);
        }
        self::assertSame([], $mixedRun->report->raised());
        self::assertSame([true, true], array_column($mixedRun->declarations->fields->requiredPublications('alpha'), 'supplied'));
        self::assertTrue(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $mixedCaptures, $mixedRun, $mixedRecords));

        Fs::write($this->root . '/finding-gate/' . DeclaredFields::INDEX, Tsv::render(DeclaredFields::COLUMNS, [
            ['added', 'json', 'format:json', 'probe', 'Publish the measured physical member.'],
        ]));
        Fs::write($formatter, str_replace("                'probe' => null,\n", '', Fs::read($formatter)));
        $findingSection = $this->root . '/' . explode('::', \QmxFindingGate\EquivalenceTuple::source())[0];
        Fs::write($findingSection, str_replace("            'message' => null,", "            'message' => null,\n            'probe' => null,", Fs::read($findingSection)));
        self::assertContains('probe', \QmxFindingGate\EquivalenceTuple::derive($this->root)->fields);
        $physicalRun = $this->context();
        ValueCheck::create($physicalRun)->startDeriving();
        $physicalRecords = RecordCheck::create($physicalRun);
        foreach (['candidate', 'reference'] as $side) {
            $physicalRun->declarations->fields->requireMeasurements('json', 'alpha', 'format:json', $side);
        }
        $physicalCandidate = $a;
        $physicalCandidate['probe'] = 7;
        $this->observeRecords($physicalRun, $physicalRecords, 'candidate', $physicalRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$physicalCandidate]));
        $this->observeRecords($physicalRun, $physicalRecords, 'reference', $physicalRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$b], 'reference'));
        $physicalRecords->prepare('alpha');
        $physicalCaptures = [];
        foreach (['candidate', 'reference'] as $side) {
            $physicalCaptures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $physicalRun->rankings->of($side, $source)]);
        }
        self::assertSame([], $physicalRun->report->raised());
        self::assertSame([true, true], array_column($physicalRun->declarations->fields->requiredPublications('alpha'), 'supplied'));
        self::assertFalse(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $physicalCaptures, $physicalRun, $physicalRecords));

        Fs::write($this->root . '/finding-gate/' . DeclaredFields::INDEX, Tsv::render(DeclaredFields::COLUMNS, [
            ['removed', 'json', 'format:json', 'probe', 'Withdraw the measured physical member.'],
        ]));
        Fs::write($findingSection, str_replace("            'probe' => null,\n", '', Fs::read($findingSection)));
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [
            ['withdrawn', 'alpha', 'json', 'format:json', '{"channel":"a.b"}', 'Withdraw the complete observation.'],
        ]));
        $wholeRun = $this->context();
        ValueCheck::create($wholeRun)->startDeriving();
        $wholeRecords = RecordCheck::create($wholeRun);
        RecordDerivation::create($wholeRun)->startDeriving();
        foreach (['candidate', 'reference'] as $side) {
            $wholeRun->declarations->fields->requireMeasurements('json', 'alpha', 'format:json', $side);
        }
        $wholeReference = $b;
        $wholeReference['probe'] = 7;
        $this->observeRecords($wholeRun, $wholeRecords, 'candidate', $wholeRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([]));
        $this->observeRecords($wholeRun, $wholeRecords, 'reference', $wholeRun->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$wholeReference], 'reference'));
        $wholeRecords->prepare('alpha');
        $wholeCaptures = [];
        foreach (['candidate', 'reference'] as $side) {
            $wholeCaptures[$side] = new \QmxFindingGate\CaptureResult([], [$source => $wholeRun->rankings->of($side, $source)]);
        }
        self::assertSame([], $wholeRun->report->raised());
        self::assertSame([true, true], array_column($wholeRun->declarations->fields->requiredPublications('alpha'), 'supplied'));
        self::assertFalse(\QmxFindingGate\ExactSurfaceAuthority::rawResidual($source, $wholeCaptures, $wholeRun, $wholeRecords));
    }

    #[Test]
    public function itSkipsRefusalRecordsWithoutInventingAnEmptyPublication(): void
    {
        SyntheticTree::remove($this->root);
        $this->root = SyntheticTree::fixture(\QmxFindingGate\SelfTestOutcomes::fixture());
        $run = $this->context();
        $records = RecordCheck::create($run);
        $case = $run->corpus->cases[0];
        $this->observeRecords($run, $records, 'candidate', $case, CaseOutcome::REFUSAL, []);
        $this->observeRecords($run, $records, 'reference', $case, CaseOutcome::ANALYSIS, self::artifacts([self::finding()], 'reference'));
        $records->prepare('alpha');
        try {
            $records->published('alpha', 'format:json', 'candidate');
            self::fail('A refusal fabricated an empty record publication.');
        } catch (GateError $error) {
            self::assertSame('A required authoritative record publication is unavailable.', $error->getMessage());
        }
        foreach (['format:json' => ValueCheck::value(['violations' => [self::finding()]]), 'baseline-file' => self::baselineDocument(self::finding(), [3])] as $surface => $reference) {
            $pair = new SurfacePair('case:alpha|' . $surface, $surface, 'Refused input', $reference);
            RecordStage::create($run)->applyStage($pair);
            self::assertSame('Refused input', $pair->candidate);
            self::assertSame($reference, $pair->reference);
            self::assertFalse($pair->settled);
        }
        self::assertSame([], $run->report->raised());
        $file = $this->root . '/finding-gate/cases/alpha/case.json';
        $definition = ReportRecords::decode(Fs::read($file));
        Fs::write($this->root . '/finding-gate/' . \QmxFindingGate\DeclaredOutcomes::INDEX, Tsv::render(\QmxFindingGate\DeclaredOutcomes::COLUMNS, []));
        foreach ([CaseOutcome::INCOMPLETE, CaseOutcome::ANALYSIS] as $outcome) {
            if ($outcome === CaseOutcome::INCOMPLETE) {
                $definition['outcome'] = ['kind' => $outcome, 'exit' => 2];
            } else {
                unset($definition['outcome']);
            }
            Fs::write($file, ValueCheck::value($definition));
            $run = $this->context();
            $records = RecordCheck::create($run);
            $this->observeRecords($run, $records, 'candidate', $run->corpus->cases[0], $outcome, self::artifacts([self::finding()]) + ['case:alpha|baseline-file' => 'No baseline was generated']);
            $pair = new SurfacePair('case:alpha|baseline-file', 'baseline-file', 'No baseline was generated', 'No baseline was generated');
            RecordStage::create($run)->applyStage($pair);
            self::assertSame([], $run->report->raised());
            self::assertFalse($pair->settled);
        }
    }

    #[Test]
    public function itRemovesOnlyGroupsEmptiedByAnExactFindingWithdrawal(): void
    {
        $kept = self::finding();
        $removed = array_replace($kept, ['subject' => 'class:App\\B', 'file' => 'src/B.php', 'symbol' => 'App\\B', 'code' => 'c.d', 'rule' => 'c.d', 'channel' => 'c.d']);
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', '{"channel":"c.d"}', 'Retire this exact finding.']]));
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::DERIVED, Tsv::render(DeclaredRecords::DERIVED_COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', DeclaredRecords::canonical(self::comparativeFinding($removed))]]));
        $run = $this->context();
        $records = RecordCheck::create($run);
        $this->observeRecords($run, $records, 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$kept]));
        $this->observeRecords($run, $records, 'reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$kept, $removed], 'reference'));
        $source = 'case:alpha|format:json';
        $run->baselineEligibility->supply('candidate', [$source => array_fill_keys(array_keys(\QmxFindingGate\BaselineEligibility::groups([$kept])), true)]);
        $run->baselineEligibility->supply('reference', [$source => array_fill_keys(array_keys(\QmxFindingGate\BaselineEligibility::groups([$kept, $removed])), true)]);
        $records->prepare('alpha');
        $baseline = '{"version":14,"entries":{"class:App\\\\A":[{"channel":"a.b","magnitudes":[3.000000]}],"class:App\\\\B":[{"channel":"c.d","magnitudes":[3]}]},"neighbour":{"spelling":1.00}}';
        $xml = '<checkstyle><file name="src/A.php"><error line="1" severity="error" source="qmx.a.b" message="M"/></file><file name="src/B.php"><error line="1" severity="error" source="qmx.c.d" message="M"/></file><file name="unused.php"></file></checkstyle>';
        $sarif = self::sarif($kept);
        $sarif['runs'][0]['tool']['driver']['rules'] = [['id' => 'c.d'], ['id' => 'a.b', 'description' => 'Keep exact catalog text.'], ['id' => 'unused.rule']];
        $sarif['runs'][0]['results'][0]['ruleIndex'] = 1;
        $second = self::sarif($removed)['runs'][0]['results'][0];
        $sarif['runs'][0]['results'][] = $second;
        $sarifText = json_encode($sarif, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        $filteredSarif = $sarif;
        $filteredSarif['runs'][0]['tool']['driver']['rules'] = \array_slice($sarif['runs'][0]['tool']['driver']['rules'], 1);
        $filteredSarif['runs'][0]['results'] = [$sarif['runs'][0]['results'][0]];
        $filteredSarif['runs'][0]['results'][0]['ruleIndex'] = 0;
        $filtered = ['baseline-file' => self::baselineDocument($kept, [3]), 'format:checkstyle' => str_replace('<file name="src/B.php"><error line="1" severity="error" source="qmx.c.d" message="M"/></file>', '', $xml), 'format:sarif' => ValueCheck::value($filteredSarif)];
        foreach (['baseline-file' => $baseline, 'format:checkstyle' => $xml, 'format:sarif' => $sarifText] as $surface => $text) {
            $pair = new SurfacePair('case:alpha|' . $surface, $surface, $filtered[$surface], $text);
            RecordStage::create($run)->applyStage($pair);
            $actual = (string) $pair->reference;
            if ($surface === 'baseline-file') {
                $document = ReportRecords::decode($actual);
                self::assertArrayNotHasKey($removed['subject'], $document['entries']);
                self::assertStringContainsString('3.000000', $actual);
                self::assertStringContainsString('"spelling":1.00', $actual);
            } elseif ($surface === 'format:checkstyle') {
                self::assertStringNotContainsString('src/B.php', $actual);
                self::assertStringContainsString('<file name="unused.php"></file>', $actual);
                self::assertStringContainsString('<file name="src/A.php"><error line="1" severity="error" source="qmx.a.b" message="M"/></file>', $actual);
            } else {
                $rules = ReportRecords::decode($actual)['runs'][0]['tool']['driver']['rules'];
                self::assertSame(['a.b', 'unused.rule'], array_column($rules, 'id'));
                self::assertStringContainsString('"description": "Keep exact catalog text."', $actual);
                self::assertSame(0, ReportRecords::decode($actual)['runs'][0]['results'][0]['ruleIndex']);
            }
        }
        self::assertSame([], $run->report->raised());
    }

    #[Test]
    public function itKeepsMissingIdentityLoudWithoutAbortingPreparation(): void
    {
        $record = self::finding();
        self::assertStringContainsString('"edge":null', ReportRecords::identity('json', $record));
        unset($record['edge']);
        try {
            ReportRecords::identity('json', $record);
            self::fail('An absent identity field was silently replaced with null.');
        } catch (GateError $error) {
            self::assertSame('A record identity requires published edge', $error->getMessage());
        }
        [$run, $check] = $this->failedIdentityRun();
        self::prepareWithoutAborting($check);
        self::assertSame(1, $run->report->exitCode());
        $failures = array_values(array_filter($run->report->raised(), static fn(array $failure): bool => $failure['scope'] === 'candidate / case:alpha|check:baseline-source'));
        self::assertCount(2, $failures);
        foreach ($failures as $failure) {
            self::assertSame(FailureClass::RECORD_PROJECTION_MISMATCH, $failure['class']);
            self::assertSame('A record identity requires published edge', $failure['detail']);
        }
    }

    #[Test]
    public function itSuppliesFullDuplicateMeasurementsBeforeStoppingAnUnreadyIdentityView(): void
    {
        [$run, $check] = $this->failedIdentityRun();
        self::prepareWithoutAborting($check);
        self::prepareWithoutAborting($check);
        try {
            $measurements = $run->declarations->fields->measurements('json');
        } catch (GateError $error) {
            self::fail('A complete observed population was not supplied: ' . $error->getMessage());
        }
        self::assertCount(2, $measurements);
        self::assertSame(['candidate', 'reference'], array_column($measurements, 'side'));
        foreach ($measurements as $publication) {
            self::assertSame('check:baseline-source', $publication['view']);
            self::assertCount(2, $publication['records']);
            self::assertSame($publication['records'][0], $publication['records'][1]);
            $expected = self::finding();
            $expected['message'] = $publication['side'] === 'candidate' ? 'Variant new' : 'Variant old';
            if ($publication['side'] === 'candidate') {
                unset($expected['edge']);
            }
            self::assertSame($expected, $publication['records'][0]['fields']);
            self::assertSame(DeclaredRecords::canonical(array_diff_key($expected, ['edge' => true])), $publication['records'][0]['record']);
        }
    }

    #[Test]
    public function itPairsAValidNeighbourWithoutCreditingOrDerivingTheFailedIdentityView(): void
    {
        [$run, $check] = $this->failedIdentityRun();
        $check->startDeriving();
        self::prepareWithoutAborting($check);
        $values = ValueCheck::create($run);
        $rows = array_values((new ReflectionProperty(ValueCheck::class, 'rows'))->getValue($values));
        self::assertCount(1, $rows);
        self::assertStringContainsString('|format:json|record:', $rows[0][2]);
        self::assertSame(['"Main old"', '"Main new"'], \array_slice($rows[0], 3));
        self::assertSame([], $check->licensedResiduals('alpha', 'check:baseline-source', 'candidate'));
        self::assertCount(1, $run->declarations->records->stale());
        self::assertSame([], (new ReflectionProperty(RecordCheck::class, 'derived'))->getValue($check));
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::DERIVED, Tsv::render(DeclaredRecords::DERIVED_COLUMNS, []));
        Fs::write($this->root . '/finding-gate/' . \QmxFindingGate\DeclaredValues::DERIVED, Tsv::render(\QmxFindingGate\DeclaredValues::DERIVED_COLUMNS, []));
        $paths = [DeclaredRecords::DERIVED, \QmxFindingGate\DeclaredValues::DERIVED];
        $before = array_map(fn(string $path): string => Fs::read($this->root . '/finding-gate/' . $path), $paths);
        self::assertSame([], RecordDerivation::create($run)->rewriteDerived());
        self::assertSame([], $values->rewriteDerived());
        self::assertSame($before, array_map(fn(string $path): string => Fs::read($this->root . '/finding-gate/' . $path), $paths));
    }

    #[Test]
    public function itPreservesRequiredSupplierRefusalsForMalformedObservedRecords(): void
    {
        Fs::write($this->root . '/finding-gate/' . DeclaredFields::INDEX, Tsv::render(DeclaredFields::COLUMNS, [['added', 'json', 'format:json', 'fresh', 'Observe a complete added field.']]));
        $run = $this->context();
        foreach (['candidate', 'reference'] as $side) {
            $run->declarations->fields->requireMeasurements('json', 'alpha', 'format:json', $side);
        }
        $bad = self::finding() + ['fresh' => 1];
        unset($bad['message']);
        $check = RecordCheck::create($run);
        foreach (['candidate' => $bad, 'reference' => self::finding()] as $side => $record) {
            $artifacts = self::artifacts([self::finding()]);
            $artifacts['case:alpha|format:json'] = ValueCheck::value(['violations' => [$record]]);
            $this->observeRecords($run, $check, $side, $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
        }
        self::prepareWithoutAborting($check);
        self::assertContains(FailureClass::RECORD_PROJECTION_MISMATCH, $run->report->failureClasses());
        try {
            $run->declarations->fields->measurements('json');
            self::fail('A malformed publication was supplied as an invented empty population.');
        } catch (GateError $error) {
            self::assertStringContainsString('A required record publication was not supplied:', $error->getMessage());
            self::assertStringContainsString('candidate', $error->getMessage());
        }
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('complete fields object');
        $run->declarations->fields->supply('json', 'alpha', 'format:json', 'candidate', [['record' => '{}', 'fields' => []]]);
    }

    #[Test]
    public function itRefusesAnObservedPublicationWithoutAnIdentityReadinessVerdict(): void
    {
        $run = $this->context();
        $check = RecordCheck::create($run);
        $this->observeRecords($run, $check, 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([self::finding()]));
        (new ReflectionProperty(RecordCheck::class, 'identityReady'))->setValue($check, []);
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('An observed publication has no identity readiness verdict.');
        $check->prepare('alpha');
    }

    private static function prepareWithoutAborting(RecordCheck $check): void
    {
        try {
            $check->prepare('alpha');
        } catch (GateError $error) {
            self::fail('An observed identity refusal aborted preparation: ' . $error->getMessage());
        }
    }

    /** @return array{RunContext,RecordCheck} */
    private function failedIdentityRun(): array
    {
        Fs::write($this->root . '/finding-gate/cases/alpha/baseline-src/src/Alpha.php', "<?php\n");
        Fs::write($this->root . '/finding-gate/' . DeclaredFields::INDEX, Tsv::render(DeclaredFields::COLUMNS, [['removed', 'json', 'check:baseline-source', 'edge', 'Observe a removed identity field without assuming comparability.']]));
        Fs::write($this->root . '/finding-gate/' . \QmxFindingGate\DeclaredValues::INDEX, Tsv::render(\QmxFindingGate\DeclaredValues::COLUMNS, [['field', 'message', '*', 'An exact message measurement.']]));
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [['introduced', 'alpha', 'json', 'check:baseline-source', '{"channel":"a.b"}', 'Do not credit an unpaired residual.']]));
        $run = $this->context();
        $check = RecordCheck::create($run);
        foreach (['candidate', 'reference'] as $side) {
            $run->declarations->fields->requireMeasurements('json', 'alpha', 'check:baseline-source', $side);
            $main = self::finding();
            $main['message'] = $side === 'candidate' ? 'Main new' : 'Main old';
            $variant = self::finding();
            $variant['message'] = $side === 'candidate' ? 'Variant new' : 'Variant old';
            if ($side === 'candidate') {
                unset($variant['edge']);
            }
            $artifacts = self::artifacts([$main]);
            $artifacts['case:alpha|check:baseline-source'] = ValueCheck::value(['violations' => [$variant, $variant]]);
            $this->observeRecords($run, $check, $side, $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
        }
        return [$run, $check];
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private static function comparativeFinding(array $record): array
    {
        return $record + ['ranking.impactScore' => 0, 'ranking.coupling.class-rank' => null];
    }

    /** @param array<string,string> $artifacts */
    private function observeRecords(RunContext $run, RecordCheck $check, string $side, \QmxFindingGate\CaseDefinition $case, string $outcome, array $artifacts, ?callable $editRanking = null): void
    {
        if (!CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, $outcome)) {
            $check->checkCase($side, $case, $outcome, $artifacts);
            return;
        }
        $scope = 'case:' . $case->id;
        if ($case->baselineSource() !== null && !isset($artifacts[$scope . '|check:baseline']) && isset($artifacts[$scope . '|format:json'])) {
            $artifacts[$scope . '|check:baseline'] = ValueCheck::value(['violations' => []]);
        }
        $slots = [];
        foreach (\QmxFindingGate\ReportViews::forCase($case) as $view => $report) {
            if ($report !== 'json') {
                continue;
            }
            $key = $scope . '|' . $view;
            if (!isset($artifacts[$key])) {
                continue;
            }
            try {
                $document = ReportRecords::decode($artifacts[$key]);
            } catch (GateError) {
                continue;
            }
            if (!\is_array($document['violations'] ?? null) || !array_is_list($document['violations'])) {
                continue;
            }
            $summary = $view === 'format:json' ? array_values(array_filter(\QmxFindingGate\ProseRecords::extract('format:summary', $artifacts[$scope . '|format:summary'] ?? ''), static fn(array $entry): bool => isset($entry['fields']['rank']))) : [];
            $issues = [];
            $counts = [];
            foreach ($document['violations'] as $index => $record) {
                if (!\is_array($record)) {
                    continue;
                }
                $rule = (string) ($record['rule'] ?? 'unavailable');
                $counts[$rule] = ($counts[$rule] ?? 0) + 1;
                $issues[] = ['rank' => $index + 1, ...array_intersect_key($record, array_flip(\QmxFindingGate\RankingSchema::PROJECTION)), 'impactScore' => isset($summary[$index]) ? (float) $summary[$index]['fields']['score'] : 0, 'coupling.class-rank' => null, 'debtMinutes' => $record['techDebtMinutes'] ?? null];
            }
            $document['topIssues'] = \array_slice($issues, 0, \count($summary));
            $document['violationsMeta'] = ['total' => \count($document['violations']), 'shown' => \count($document['violations']), 'truncated' => false, 'byRule' => $counts];
            $artifacts[$key] = ValueCheck::value($document);
            $document['topIssues'] = $issues;
            $artifacts[$scope . '|exit:' . $view] ??= '0';
            $artifacts[$scope . '|stderr:' . $view] ??= '';
            $slots[$key] = ['ranked' => ['stdout' => ValueCheck::value($document), 'stderr' => $artifacts[$scope . '|stderr:' . $view], 'exit' => (int) $artifacts[$scope . '|exit:' . $view]], 'physical' => null];
        }
        foreach (['check:output:file', 'check:parallel'] as $view) {
            $key = $scope . '|' . $view;
            if (isset($artifacts[$key])) {
                try {
                    $document = ReportRecords::decode($artifacts[$key]);
                    $document['topIssues'] = [];
                    $artifacts[$key] = ValueCheck::value($document);
                } catch (GateError) {
                    continue;
                }
            }
        }
        $artifacts[$scope . '|format:summary'] ??= 'Analysis complete.';
        $run->rankings->supply($side, $editRanking === null ? $slots : $editRanking($slots));
        $check->checkCase($side, $case, $outcome, $artifacts);
    }

    private function context(bool $currentReference = false): RunContext
    {
        $options = Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], $this->root);
        $maps = RenameMaps::fromPairs([]);
        return new RunContext($options, new GateReport(), Corpus::load($this->root), $maps, ChannelSplit::of($maps), MetricVocabulary::ofTree($this->root), Normalization::fromRules([]), Declarations::load($this->root), $this->root, $currentReference ? [] : ['candidate' => 'current', 'reference' => 'legacy']);
    }

    #[Test]
    #[TestWith(['format:suppressed'])]
    #[TestWith(['format:text-verbose'])]
    public function itLeavesWithdrawnCandidatePublicationsToTheirRefusalCheck(string $surface): void
    {
        Fs::write($this->root . '/finding-gate/declared-surfaces/refusal.json', '{"stdout":"","stderr":"Unsupported format.","exit":"3"}');
        Fs::write($this->root . '/finding-gate/' . \QmxFindingGate\DeclaredSurfaces::INDEX, Tsv::render(\QmxFindingGate\DeclaredSurfaces::COLUMNS, [
            ['withdrawn', $surface, 'declared-surfaces/refusal.json', '["alpha"]', 'Withdraw the format and require its exact refusal.'],
        ]));
        foreach (['candidate', 'reference'] as $side) {
            $run = $this->context(true);
            $key = 'case:alpha|' . $surface;
            $artifacts = array_replace(self::artifacts([self::finding()]), [$key => '', 'case:alpha|exit:' . $surface => '3', 'case:alpha|stderr:' . $surface => 'Unsupported format.']);
            $this->observeRecords($run, RecordCheck::create($run), $side, $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
            self::assertSame([], $run->report->raised());
            self::assertFalse($run->report->sourceValid($side, $key, 'records'));
        }
    }

    #[Test]
    public function itRequiresExactCurrentHtmlBaselineFieldsAndPreservesLegacyProjection(): void
    {
        Fs::write($this->root . '/finding-gate/' . DeclaredFields::INDEX, Tsv::render(DeclaredFields::COLUMNS, [
            ['added', 'json', 'format:json', 'baselineVerdict', 'Publish the baseline verdict.'],
            ['added', 'json', 'format:json', 'baselineReason', 'Publish the baseline reason.'],
        ]));
        $legacy = self::finding();
        $projection = ['subject' => $legacy['subject'], 'ruleName' => 'a.b', 'violationCode' => 'a.b', 'message' => 'M', 'recommendation' => null, 'severity' => 'error', 'metricValue' => 3, 'symbolPath' => 'App\\A', 'occurrence' => null, 'file' => 'src/A.php', 'line' => 1];
        self::assertSame($projection, ReportRecords::projection('format:html', $legacy));
        foreach ([
            ['acceptedLevel' => ['shape' => 'magnitude', 'describe' => '3', 'count' => 1], 'baselineVerdict' => 'breached', 'baselineReason' => null],
            ['acceptedLevel' => ['shape' => 'magnitude', 'describe' => '3', 'count' => 1], 'baselineVerdict' => 'not-compared', 'baselineReason' => 'paths-differ'],
            ['acceptedLevel' => null, 'baselineVerdict' => null, 'baselineReason' => null],
        ] as $baseline) {
            $record = array_replace($legacy, $baseline);
            $expected = ReportRecords::projection('format:html', $record, 'current');
            foreach (['valid', 'missing:acceptedLevel', 'missing:baselineVerdict', 'missing:baselineReason', 'changed:acceptedLevel', 'changed:baselineVerdict', 'changed:baselineReason', 'extra', 'duplicate'] as $shape) {
                $node = $expected;
                if (str_starts_with($shape, 'missing:')) {
                    unset($node[substr($shape, 8)]);
                } elseif (str_starts_with($shape, 'changed:')) {
                    $node[substr($shape, 8)] = 'different';
                } elseif ($shape === 'extra') {
                    $node['extra'] = true;
                }
                $run = $this->context();
                $artifacts = self::artifacts([$record]);
                $sarif = ReportRecords::decode($artifacts['case:alpha|format:sarif']);
                $sarif['runs'][0]['results'][0]['message']['text'] = ReportRecords::message($record, codec: 'current');
                $artifacts['case:alpha|format:sarif'] = ValueCheck::value($sarif);
                $artifacts['case:alpha|format:html'] = '<script type="application/json" id="report-data">' . ValueCheck::value(['tree' => ['violations' => $shape === 'duplicate' ? [$node, $node] : [$node]]]) . '</script>';
                $this->observeRecords($run, RecordCheck::create($run), 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
                if ($shape === 'valid') {
                    self::assertSame([], $run->report->raised());
                } else {
                    self::assertContains(FailureClass::RECORD_PROJECTION_MISMATCH, $run->report->failureClasses(), $shape);
                }
            }
        }
    }

    #[Test]
    public function itReadsPublishedHtmlViolationsWithCompleteFieldsAndInstanceBudgets(): void
    {
        $record = self::finding();
        $projection = ReportRecords::projection('format:html', $record, 'current');
        $text = ValueCheck::value(['tree' => ['violations' => [$projection], 'children' => [['violations' => [$projection]]]], 'unrelated' => ['findings' => ['Keep this metadata.']]]);
        $entries = ReportRecords::projected('format:html', $text);
        self::assertSame([['tree', 'violations', 0], ['tree', 'children', 0, 'violations', 0]], array_column($entries, 'path'));
        self::assertSame([$projection, $projection], array_column($entries, 'fields'));
        foreach (['valid', 'partial', 'extra', 'duplicate'] as $shape) {
            $run = $this->context();
            $artifacts = self::artifacts([$record]);
            $node = $projection;
            if ($shape === 'partial') {
                unset($node['recommendation']);
            } elseif ($shape === 'extra') {
                $node['extra'] = true;
            }
            $artifacts['case:alpha|format:html'] = '<script type="application/json" id="report-data">' . ValueCheck::value(['tree' => ['violations' => $shape === 'duplicate' ? [$node, $node] : [$node]]]) . '</script>';
            $this->observeRecords($run, RecordCheck::create($run), 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
            if ($shape === 'valid') {
                self::assertSame([], $run->report->raised());
            } else {
                self::assertContains(FailureClass::RECORD_PROJECTION_MISMATCH, $run->report->failureClasses(), $shape);
            }
        }
    }

    #[Test]
    public function itJoinsBaselineEdgesByExactNamedFieldsAndPreservesTheirPublishedBytes(): void
    {
        $record = self::finding();
        $record['edge'] = ['type' => 'type_hint', 'target' => 'class:App\\B'];
        $entry = ['channel' => 'a.b', 'edge' => ['target' => 'class:App\\B', 'type' => 'type_hint'], 'magnitudes' => [3]];
        $text = str_replace('3]', '3.000000]', ValueCheck::value(['version' => 14, 'entries' => [$record['subject'] => [$entry]], 'neighbour' => 'Keep raw bytes.']));
        self::assertSame(array_replace($entry, ['magnitudes' => [3.0]]), ReportRecords::baselineEntries($text, [$record])[0]['fields']);
        $run = $this->context();
        $check = RecordCheck::create($run);
        foreach (['candidate', 'reference'] as $side) {
            $artifacts = self::artifacts([$record], $side);
            $artifacts['case:alpha|baseline-file'] = $text;
            $this->observeRecords($run, $check, $side, $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
        }
        $check->prepare('alpha');
        $pair = new SurfacePair('case:alpha|baseline-file', 'baseline-file', $text, $text);
        RecordStage::create($run)->applyStage($pair);
        self::assertSame([], $run->report->raised());
        self::assertSame($text, $pair->candidate);
        self::assertSame($text, $pair->reference);
        foreach ([['target' => 'class:App\\C', 'type' => 'type_hint'], ['target' => 'class:App\\B', 'type' => 'new'], ['target' => 'class:App\\B', 'type' => 'unknown'], ['target' => 'class:App\\B', 'extra' => true], ['target' => ''], ['target' => 'class:App\\B', 'type' => null]] as $edge) {
            try {
                ReportRecords::baselineEntries(ValueCheck::value(['version' => 14, 'entries' => [$record['subject'] => [array_replace($entry, ['edge' => $edge])]]]), [$record]);
                self::fail('A different or unsupported dependency edge joined the baseline source.');
            } catch (GateError $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        foreach ([['target' => 'class:App\\B', 'type' => 'unknown'], ['target' => 'class:App\\B', 'type' => null], ['target' => 'class:App\\B', 'extra' => true], ['target' => ''], ['type' => 'new'], 'not an edge'] as $edge) {
            try {
                ReportRecords::identity('json', array_replace($record, ['edge' => $edge]));
                self::fail('An unsupported edge shape acquired a record identity.');
            } catch (GateError $error) {
                self::assertStringContainsString('A record edge requires', $error->getMessage());
            }
        }
        $targetOnly = array_replace($record, ['edge' => ['target' => 'class:App\\B']]);
        self::assertNotSame(ReportRecords::identity('json', $targetOnly), ReportRecords::identity('json', $record));
    }

    #[Test]
    #[DataProvider('currentAndHistoricalTypePositionEdges')]
    public function itKeepsCurrentAndHistoricalTypePositionEdgesInRecordIdentity(string $type): void
    {
        $record = array_replace(self::finding(), [
            'edge' => ['target' => 'class:App\\B', 'type' => $type],
        ]);

        self::assertSame($type, ReportRecords::decode(ReportRecords::identity('json', $record))['edge']['type']);
    }

    /** @return iterable<string, array{string}> */
    public static function currentAndHistoricalTypePositionEdges(): iterable
    {
        yield 'current constant type position' => ['constant_type'];
        yield 'historical union type position' => ['union_type'];
        yield 'historical intersection type position' => ['intersection_type'];
    }

    #[Test]
    public function itMatchesUnprintedProseLinesWhileRefusingPrintedLineAndFindingNeighbours(): void
    {
        $record = self::finding();
        $record['line'] = 17;
        $texts = [
            'format:text' => "src/A.php: error[a.b]: M (A)\n",
            'format:text-detail' => "src/A.php (1 violation)\n  ERROR  A\n    M  [a.b]\n",
            'format:summary' => "  1. [ERR] 20.0  src/A.php  [15min]\n         a.b: M\n",
        ];
        foreach ($texts as $surface => $text) {
            $entries = \QmxFindingGate\ProseRecords::extract($surface, $text);
            self::assertCount(1, $entries, $surface);
            self::assertNull($entries[0]['fields']['line']);
            self::assertTrue(\QmxFindingGate\ProseRecords::matches($surface, $entries[0]['fields'], $record), $surface);
            foreach (['line' => 16, 'code' => 'neighbour', 'message' => 'Different advice', 'file' => 'src/B.php'] as $field => $value) {
                self::assertFalse(\QmxFindingGate\ProseRecords::matches($surface, array_replace($entries[0]['fields'], [$field => $value]), $record), $surface . ' / ' . $field);
            }
            $after = array_replace($record, ['message' => 'New (term)']);
            $changed = \QmxFindingGate\ProseRecords::rewriteProjection($surface, $text, $entries[0], $record, $after);
            self::assertSame(str_replace('M', 'New (term)', $text), $changed);
            $run = $this->context();
            $artifacts = self::artifacts([$record]) + ['case:alpha|' . $surface => $text];
            $this->observeRecords($run, RecordCheck::create($run), 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
            self::assertSame([], $run->report->raised(), $surface);
        }
    }

    #[Test]
    public function itReadsAndRewritesEveryLineOfFiniteMultilineAdviceWithoutNeighbourCredit(): void
    {
        $record = array_replace(self::finding(), ['file' => null, 'line' => null, 'subject' => 'project:', 'symbol' => '', 'severity' => 'warning', 'recommendation' => "Break this cycle.\nCycle data: {\"cycle\":[\"A\",\"B\"]}"]);
        $texts = [
            'format:text-detail' => "[project] (1 violation)\n  WARN\n    Break this cycle.\nCycle data: {\"cycle\":[\"A\",\"B\"]}  [a.b]\n\nTechnical debt by rule:\n  a.b ~15min\n",
            'format:summary' => "Top issues by impact\n  1. [WRN] 20.0  [project]  [15min]\n         a.b: Break this cycle.\nCycle data: {\"cycle\":[\"A\",\"B\"]}\n1 violation (1 warning) | Tech debt: 15min\n",
        ];
        foreach ($texts as $surface => $text) {
            $entries = \QmxFindingGate\ProseRecords::extract($surface, $text);
            self::assertCount(1, $entries);
            self::assertCount(3, $entries[0]['lines']);
            self::assertTrue(\QmxFindingGate\ProseRecords::matches($surface, $entries[0]['fields'], $record));
            self::assertFalse(\QmxFindingGate\ProseRecords::matches($surface, array_replace($entries[0]['fields'], ['message' => "Break this cycle.\nCycle data: {\"cycle\":[\"A\",\"C\"]}"]), $record));
            $after = array_replace($record, ['recommendation' => "Invert this edge.\nCycle data: {\"cycle\":[\"A\",\"B\"]}"]);
            self::assertSame(str_replace('Break this cycle.', 'Invert this edge.', $text), \QmxFindingGate\ProseRecords::rewriteProjection($surface, $text, $entries[0], $record, $after));
            $erased = \QmxFindingGate\ProseRecords::erase($text, $entries[0]['lines']);
            self::assertStringNotContainsString('Cycle data:', $erased);
            self::assertStringNotContainsString('Break this cycle.', $erased);
            self::assertStringContainsString($surface === 'format:summary' ? '1 violation (1 warning)' : 'Technical debt by rule:', $erased);
            $single = \QmxFindingGate\ProseRecords::rewriteProjection($surface, $text, $entries[0], $record, array_replace($record, ['recommendation' => 'A single line.']));
            self::assertStringContainsString('A single line.', $single);
            self::assertStringNotContainsString('Cycle data:', $single);
            self::assertTrue(\QmxFindingGate\ProseRecords::matches($surface, \QmxFindingGate\ProseRecords::extract($surface, $single)[0]['fields'], array_replace($record, ['recommendation' => 'A single line.'])));
        }
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('complete published advice and channel terminator');
        \QmxFindingGate\ProseRecords::extract('format:text-detail', "[project] (1 violation)\n  WARN\n    Break this cycle.\nCycle data: {}\n\n");
    }

    #[Test]
    public function itUsesThePublishedGitlabProjectPathAndRefusesAnEmptyPathNeighbour(): void
    {
        $record = array_replace(self::finding(), ['file' => null, 'line' => null, 'subject' => 'project:', 'symbol' => '']);
        $expected = ['description' => 'M', 'check_name' => 'a.b', 'severity' => 'critical', 'location' => ['path' => '_project', 'lines' => ['begin' => 1]]];
        self::assertSame($expected, ReportRecords::projection('format:gitlab', $record, 'current'));
        foreach (['_project', ''] as $path) {
            $run = $this->context();
            $artifacts = self::artifacts([$record]);
            $artifacts['case:alpha|format:sarif'] = ValueCheck::value(['runs' => [['tool' => ['driver' => ['rules' => [['id' => 'a.b']]]], 'results' => [['ruleId' => 'a.b', 'ruleIndex' => 0, 'level' => 'error', 'message' => ['text' => 'M']]]]]]);
            $artifacts['case:alpha|format:gitlab'] = ValueCheck::value([array_replace($expected, ['location' => ['path' => $path, 'lines' => ['begin' => 1]]])]);
            $this->observeRecords($run, RecordCheck::create($run), 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
            self::assertSame($path === '_project' ? [] : [FailureClass::RECORD_PROJECTION_MISMATCH], $run->report->failureClasses());
        }
    }

    #[Test]
    public function itRewritesBaselineMagnitudesUsingTheSameSemanticEdgeJoin(): void
    {
        Fs::write($this->root . '/finding-gate/' . \QmxFindingGate\DeclaredValues::INDEX, Tsv::render(\QmxFindingGate\DeclaredValues::COLUMNS, [['field', 'metricValue', '*', 'Change this exact edge magnitude.']]));
        $run = $this->context();
        $check = RecordCheck::create($run);
        $record = array_replace(self::finding(), ['edge' => ['type' => 'type_hint', 'target' => 'class:App\\B']]);
        $before = array_replace($record, ['metricValue' => 1]);
        foreach (['candidate' => $record, 'reference' => $before] as $side => $finding) {
            $this->observeRecords($run, $check, $side, $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$finding], $side));
        }
        $check->prepare('alpha');
        $text = ValueCheck::value(['version' => 14, 'entries' => [$record['subject'] => [['channel' => 'a.b', 'edge' => ['target' => 'class:App\\B', 'type' => 'type_hint'], 'magnitudes' => [3]]]], 'neighbour' => ['number' => 1.0]]);
        $pair = new SurfacePair('case:alpha|baseline-file', 'baseline-file', $text, str_replace('"magnitudes":[3]', '"magnitudes":[1]', $text));
        RecordStage::create($run)->applyStage($pair);
        self::assertSame([], $run->report->raised());
        self::assertSame(str_replace('"magnitudes":[3]', '"magnitudes":[1]', $text), $pair->candidate);
        self::assertStringContainsString('"edge":{"target":"class:App\\\\B","type":"type_hint"}', $pair->candidate);
        self::assertStringContainsString('"neighbour":{"number":1.0}', $pair->candidate);
    }

    #[Test]
    public function itChecksWholeMultilineProsePublicationsAndRejectsOnlyTheChangedContinuation(): void
    {
        $record = array_replace(self::finding(), ['line' => 16, 'severity' => 'warning', 'recommendation' => "Move the layer.\nDep data: {\"type\":\"type_hint\",\"target\":\"App\\\\B\"}"]);
        $texts = [
            'format:text-detail' => "src/A.php (1 violation)\n  WARN  A\n    M  [a.b]\n    Recommendation: Move the layer.\nDep data: {\"type\":\"type_hint\",\"target\":\"App\\\\B\"}\n\nTechnical debt by rule:\n",
            'format:summary' => "Top issues by impact\n  1. [WRN] 20.0  src/A.php  [15min]\n         a.b: M\n         Recommendation: Move the layer.\nDep data: {\"type\":\"type_hint\",\"target\":\"App\\\\B\"}\n1 violation (1 warning) | Tech debt: 15min\n",
        ];
        foreach ($texts as $surface => $text) {
            foreach ([false, true] as $neighbour) {
                $run = $this->context();
                $artifacts = self::artifacts([$record]) + ['case:alpha|' . $surface => $neighbour ? str_replace('type_hint', 'new', $text) : $text];
                $this->observeRecords($run, RecordCheck::create($run), 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
                self::assertSame($neighbour ? ($surface === 'format:summary' ? [FailureClass::RANKING_PROJECTION_MISMATCH] : [FailureClass::RECORD_PROJECTION_MISMATCH]) : [], $run->report->failureClasses(), $surface);
                if ($neighbour) {
                    self::assertSame('candidate / case:alpha|' . ($surface === 'format:summary' ? 'format:json' : $surface), $run->report->raised()[0]['scope']);
                }
            }
        }
    }

    #[Test]
    public function itRewritesMultilineAdviceAcrossLineCountsWithoutMovingNeighbourOwnership(): void
    {
        Fs::write($this->root . '/finding-gate/' . \QmxFindingGate\DeclaredValues::INDEX, Tsv::render(\QmxFindingGate\DeclaredValues::COLUMNS, [['field', 'recommendation', '*', 'Change the complete advice block.']]));
        $a = array_replace(self::finding(), ['recommendation' => "New advice.\nDep data: new"]);
        $b = array_replace($a, ['recommendation' => "Old advice.\nDep data: old\nExtra evidence: old"]);
        $keeper = array_replace(self::finding(), ['code' => 'c.d', 'rule' => 'c.d', 'channel' => 'c.d', 'recommendation' => 'Keep this neighbour.']);
        foreach (['format:text-detail', 'format:summary'] as $surface) {
            $run = $this->context();
            $check = RecordCheck::create($run);
            $texts = [];
            foreach (['candidate' => $a, 'reference' => $b] as $side => $record) {
                $publishedKeeper = $side === 'candidate' ? array_replace($keeper, ['recommendation' => 'New neighbour.']) : $keeper;
                $text = $surface === 'format:summary'
                    ? "Top issues by impact\n  1. [ERR] 20.0  src/A.php:1  [15min]\n         a.b: " . $record['recommendation'] . "\n  2. [ERR] 10.0  src/A.php:1  [15min]\n         c.d: " . $publishedKeeper['recommendation'] . "\n2 violations (2 errors) | Tech debt: 30min\n"
                    : "src/A.php (2 violations)\n  ERROR at line 1  A\n    " . $record['recommendation'] . "  [a.b]\n\n  ERROR at line 1  A\n    " . $publishedKeeper['recommendation'] . "  [c.d]\n\nTechnical debt by rule:\n";
                if ($side === 'candidate') {
                    $text = $surface === 'format:summary'
                        ? "Top issues by impact\n  1. [ERR] 20.0  src/A.php:1  [15min]\n         a.b: M\n         Recommendation: " . $record['recommendation'] . "\n  2. [ERR] 10.0  src/A.php:1  [15min]\n         c.d: M\n         Recommendation: " . $publishedKeeper['recommendation'] . "\n2 violations (2 errors) | Tech debt: 30min\n"
                        : "src/A.php (2 violations)\n  ERROR at line 1  A\n    M  [a.b]\n    Recommendation: " . $record['recommendation'] . "\n\n  ERROR at line 1  A\n    M  [c.d]\n    Recommendation: " . $publishedKeeper['recommendation'] . "\n\nTechnical debt by rule:\n";
                }
                $texts[$side] = $text;
                $this->observeRecords($run, $check, $side, $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$record, $publishedKeeper], $side) + ['case:alpha|' . $surface => $text]);
            }
            $check->prepare('alpha');
            $pair = new SurfacePair('case:alpha|' . $surface, $surface, $texts['candidate'], $texts['reference']);
            RecordStage::create($run)->applyStage($pair);
            self::assertSame([], $run->report->raised(), $surface);
            if ($surface === 'format:summary') {
                self::assertIsString($pair->candidate);
                self::assertSame($pair->reference, $pair->candidate);
                self::assertStringNotContainsString('a.b:', $pair->candidate);
                self::assertStringNotContainsString('c.d:', $pair->candidate);
                self::assertStringContainsString('2 violations (2 errors)', $pair->candidate);
            } else {
                self::assertSame(str_replace(["New advice.\nDep data: new", 'New neighbour.'], ["Old advice.\nDep data: old\nExtra evidence: old", 'Keep this neighbour.'], $texts['candidate']), $pair->candidate);
                self::assertSame($texts['reference'], $pair->reference);
                self::assertStringContainsString('Keep this neighbour.', $pair->candidate);
            }
        }
    }

    #[Test]
    public function itPublishesExactAcceptedMagnitudeAndOccurrenceSuffixesWithoutRejudgingTheBreach(): void
    {
        foreach ([
            [['shape' => 'magnitude', 'describe' => '1', 'count' => 1], 3, 'accepted at 1, now 3'],
            [['shape' => 'magnitude', 'describe' => '1', 'count' => 1], 0, 'accepted at 1, now 0'],
            [['shape' => 'magnitude', 'describe' => '1, 2', 'count' => 2], 3.140000, 'accepted at 1, 2, now 3.14'],
            [['shape' => 'magnitude', 'describe' => '1', 'count' => 1], null, 'accepted at 1'],
            [['shape' => 'magnitude', 'describe' => '1', 'count' => 1], \INF, 'accepted at 1'],
            [['shape' => 'magnitude', 'describe' => '1', 'count' => 1], \NAN, 'accepted at 1'],
            [['shape' => 'occurrence', 'describe' => '1 occurrence', 'count' => 1], 3, 'accepted at 1 occurrence'],
            [['shape' => 'occurrence', 'describe' => '3 occurrences', 'count' => 3], null, 'accepted at 3 occurrences'],
        ] as [$accepted, $value, $suffix]) {
            $record = array_replace(self::finding(), ['acceptedLevel' => $accepted, 'metricValue' => $value, 'recommendation' => 'Advice']);
            self::assertSame('M (' . $suffix . ')', ReportRecords::message($record));
            self::assertSame('Advice (' . $suffix . ')', ReportRecords::message($record, true));
        }
        self::assertSame('M', ReportRecords::message(self::finding()));
    }

    #[Test]
    public function itRefusesScalarAndMalformedAcceptedLevelsInsteadOfInventingATextSuffix(): void
    {
        $valid = ['shape' => 'magnitude', 'describe' => '1', 'count' => 1];
        foreach ([1, '1', [], ['magnitude', '1', 1], array_diff_key($valid, ['describe' => true]), $valid + ['extra' => true], array_replace($valid, ['shape' => 'unknown']), array_replace($valid, ['describe' => '']), array_replace($valid, ['describe' => 1]), array_replace($valid, ['count' => 0]), array_replace($valid, ['count' => '1'])] as $accepted) {
            try {
                ReportRecords::message(array_replace(self::finding(), ['acceptedLevel' => $accepted]));
                self::fail('An unsupported accepted-level schema produced a licensed suffix.');
            } catch (GateError $error) {
                self::assertSame('An accepted level requires the exact shape, description, and positive count object.', $error->getMessage());
            }
        }
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('numeric or absent current value');
        ReportRecords::message(array_replace(self::finding(), ['acceptedLevel' => $valid, 'metricValue' => '3']));
    }

    #[Test]
    public function itChecksActualAcceptedSuffixesOnInterchangeAndProsePublicationsWithoutNeighbourCredit(): void
    {
        foreach (['magnitude', 'occurrence'] as $shape) {
            $accepted = ['shape' => $shape, 'describe' => $shape === 'magnitude' ? '1' : '1 occurrence', 'count' => 1];
            $suffix = $shape === 'magnitude' ? ' (accepted at 1, now 3)' : ' (accepted at 1 occurrence)';
            $record = array_replace(self::finding(), ['acceptedLevel' => $accepted, 'recommendation' => 'Advice']);
            $expected = 'M' . $suffix;
            foreach ([false, true] as $neighbour) {
                $run = $this->context();
                $artifacts = self::artifacts([$record]);
                $actual = $neighbour ? 'M (accepted at 2, now 3)' : $expected;
                $sarif = ReportRecords::decode($artifacts['case:alpha|format:sarif']);
                $sarif['runs'][0]['results'][0]['message']['text'] = $actual;
                $artifacts['case:alpha|format:sarif'] = ValueCheck::value($sarif);
                $artifacts['case:alpha|format:gitlab'] = ValueCheck::value([['description' => $expected, 'check_name' => 'a.b', 'severity' => 'critical', 'location' => ['path' => 'src/A.php', 'lines' => ['begin' => 1]]]]);
                $artifacts += [
                    'case:alpha|format:text' => 'src/A.php:1: error[a.b]: ' . $expected . " (A)\n",
                    'case:alpha|format:github' => '::error file=src/A.php,line=1,title=a.b::' . $expected . "\n",
                    'case:alpha|format:text-detail' => "src/A.php (1 violation)\n  ERROR at line 1  A\n    M  [a.b]\n    Recommendation: Advice\n    " . trim($suffix, ' ()') . "\n",
                    'case:alpha|format:summary' => "  1. [ERR] 20.0  src/A.php:1  [15min]\n         a.b: M\n         Recommendation: Advice\n         " . trim($suffix, ' ()') . "\n",
                    'case:alpha|format:checkstyle' => '<checkstyle><file name="src/A.php"><error line="1" severity="error" source="qmx.a.b" message="' . $expected . '"/></file></checkstyle>',
                ];
                $this->observeRecords($run, RecordCheck::create($run), 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
                self::assertSame($neighbour ? [FailureClass::RECORD_PROJECTION_MISMATCH] : [], $run->report->failureClasses(), $shape);
                if ($neighbour) {
                    self::assertSame('candidate / case:alpha|format:sarif', $run->report->raised()[0]['scope']);
                }
            }
        }
    }

    #[Test]
    public function itKeepsNamespaceCaptionsSpecificToTheActualProseComposition(): void
    {
        $record = array_replace(self::finding(), ['subject' => 'ns:App\\Service', 'symbol' => 'App\\Service', 'line' => 3]);
        $texts = [
            'format:text' => "src/A.php: error[a.b]: M (namespace: App\\Service)\n",
            'format:text-detail' => "src/A.php (1 violation)\n  ERROR\n    M  [a.b]\n",
            'format:summary' => "  1. [ERR] 20.0  src/A.php  [15min]\n         a.b: M (namespace: App\\Service)\n",
            'show-suppressed' => "src/A.php (1 violation)\n  ERROR\n    M  [a.b]\n",
        ];
        foreach ($texts as $surface => $text) {
            $entry = \QmxFindingGate\ProseRecords::extract($surface, $text)[0];
            self::assertTrue(\QmxFindingGate\ProseRecords::matches($surface, $entry['fields'], $record), $surface);
            $bad = array_replace($entry['fields'], isset($entry['fields']['symbol']) ? ['symbol' => 'namespace: App\\Neighbour'] : ['message' => 'M (namespace: App\\Neighbour)']);
            self::assertFalse(\QmxFindingGate\ProseRecords::matches($surface, $bad, $record), $surface);
            $after = array_replace($record, ['message' => 'New advice']);
            self::assertSame(str_replace('M', 'New advice', $text), \QmxFindingGate\ProseRecords::rewriteProjection($surface, $text, $entry, $record, $after));
        }
    }

    #[Test]
    public function itOmitsOnlyAnExactLogicalFileCaptionRegardlessOfTheMeasuredCallableSubject(): void
    {
        $record = array_replace(self::finding(), ['subject' => 'declaration:callable:App\\A::run@src/A.php', 'symbol' => 'src/A.php', 'line' => 10]);
        $texts = [
            'format:text' => "src/A.php:10: error[a.b]: M\n",
            'format:text-detail' => "src/A.php (1 violation)\n  ERROR at line 10\n    M  [a.b]\n",
            'format:summary' => "  1. [ERR] 20.0  src/A.php:10  [15min]\n         a.b: M\n",
            'show-suppressed' => "src/A.php (1 violation)\n  ERROR at line 10\n    M  [a.b]\n",
        ];
        foreach ($texts as $surface => $text) {
            $entry = \QmxFindingGate\ProseRecords::extract($surface, $text)[0];
            self::assertTrue(\QmxFindingGate\ProseRecords::matches($surface, $entry['fields'], $record), $surface);
            $bad = array_replace($entry['fields'], isset($entry['fields']['symbol']) ? ['symbol' => 'A.php'] : ['message' => 'M (A.php)']);
            self::assertFalse(\QmxFindingGate\ProseRecords::matches($surface, $bad, $record), $surface);
            foreach (['App\\A::run', 'src/A.phpNeighbour'] as $logicalSymbol) {
                self::assertFalse(\QmxFindingGate\ProseRecords::matches($surface, $entry['fields'], array_replace($record, ['symbol' => $logicalSymbol])), $logicalSymbol);
            }
            self::assertSame(str_replace('M', 'New advice', $text), \QmxFindingGate\ProseRecords::rewriteProjection($surface, $text, $entry, $record, array_replace($record, ['message' => 'New advice'])));
        }
    }

    #[Test]
    #[TestWith(['format:text-detail'])]
    #[TestWith(['format:text-verbose'])]
    public function itKeepsClassAndNamespaceFindingInstancesDistinctDespiteMatchingAdviceAndLocation(string $surface): void
    {
        self::assertTrue(\QmxFindingGate\ReportViews::recordBearingSurface($surface));
        $class = array_replace(self::finding(), ['symbol' => 'App\\A', 'line' => 3]);
        $namespace = array_replace($class, ['subject' => 'ns:App\\Service', 'symbol' => 'App\\Service']);
        $valid = "src/A.php (2 violations)\n  ERROR  A\n    M  [a.b]\n\n  ERROR\n    M  [a.b]\n";
        foreach ([$valid, str_replace('  ERROR  A', '  ERROR', $valid), str_replace("  ERROR\n", "  ERROR  A\n", $valid)] as $text) {
            $run = $this->context();
            $artifacts = self::artifacts([$class, $namespace]) + ['case:alpha|' . $surface => $text];
            $this->observeRecords($run, RecordCheck::create($run), 'candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
            self::assertSame($text === $valid ? [] : [FailureClass::RECORD_PROJECTION_MISMATCH], $run->report->failureClasses());
            if ($text !== $valid) {
                self::assertSame('candidate / case:alpha|' . $surface, $run->report->raised()[0]['scope']);
            }
        }
    }

    #[Test]
    public function itKeepsKnownIncompleteRunDiagnosticsOutsideTheFindingMultiset(): void
    {
        $finding = self::finding();
        $diagnostic = ['description' => 'Analysis failed for src/linked: The subtree was not read.', 'check_name' => 'analysis.directory-symlink', 'fingerprint' => 'run-diagnostic', 'severity' => 'blocker', 'location' => ['path' => 'src/linked', 'lines' => ['begin' => 1]]];
        $text = ValueCheck::value([ReportRecords::projection('format:gitlab', $finding) + ['fingerprint' => 'finding'], $diagnostic]);
        self::assertSame([ReportRecords::projection('format:gitlab', $finding)], array_column(ReportRecords::projected('format:gitlab', $text), 'fields'));
        foreach ([['check_name' => 'analysis.unknown'], ['severity' => 'major']] as $change) {
            self::assertCount(1, ReportRecords::projected('format:gitlab', ValueCheck::value([array_replace($diagnostic, $change)])));
        }
        $xml = '<checkstyle><file name="src/A.php"><error line="1" severity="error" source="qmx.a.b" message="M"/></file><file name="[analysis]"><error line="1" severity="error" source="qmx.analysis.directory-symlink" message="src/linked: The subtree was not read."/></file></checkstyle>';
        self::assertSame([ReportRecords::projection('format:checkstyle', $finding)], ReportRecords::checkstyle($xml));
        foreach (['[analysis]' => 'src/linked', 'qmx.analysis.directory-symlink' => 'qmx.analysis.unknown', 'line="1" severity="error" source="qmx.analysis.' => 'line="2" severity="error" source="qmx.analysis.'] as $from => $to) {
            self::assertCount(2, ReportRecords::checkstyle(str_replace($from, $to, $xml)));
        }
        $run = $this->context();
        $artifacts = self::artifacts([$finding]);
        $artifacts['case:alpha|format:gitlab'] = $text;
        $artifacts['case:alpha|format:checkstyle'] = $xml;
        $this->observeRecords($run, RecordCheck::create($run), 'candidate', $run->corpus->cases[0], CaseOutcome::INCOMPLETE, $artifacts);
        self::assertSame([], $run->report->raised());
    }

    /** @return array<string,mixed> */
    private static function finding(): array
    {
        return array_replace(array_fill_keys(ReportRecords::SCHEMAS['json'], null), ['file' => 'src/A.php', 'line' => 1, 'subject' => 'class:App\\A', 'symbol' => 'App\\A', 'channel' => 'a.b', 'rule' => 'a.b', 'code' => 'a.b', 'severity' => 'error', 'message' => 'M', 'metricValue' => 3, 'threshold' => 1, 'techDebtMinutes' => 15]);
    }

    /**
     * @param list<array<string,mixed>> $findings
     *
     * @return array<string,string>
     */
    private static function artifacts(array $findings, string $side = 'current'): array
    {
        $gitlab = array_map(static fn(array $record): array => ReportRecords::projection('format:gitlab', $record, $side === 'reference' ? 'legacy' : 'current'), $findings);
        $html = array_map(static fn(array $record): array => ReportRecords::projection('format:html', $record, $side === 'reference' ? 'legacy' : 'current'), $findings);
        $sarif = self::sarif($findings[0] ?? self::finding());
        $sarif['runs'][0]['results'] = [];
        $sarif['runs'][0]['tool']['driver']['rules'] = [];
        foreach ($findings as $record) {
            $index = \count($sarif['runs'][0]['tool']['driver']['rules']);
            $sarif['runs'][0]['tool']['driver']['rules'][] = ['id' => $record['code']];
            $result = self::sarif($record)['runs'][0]['results'][0];
            $result['ruleIndex'] = $index;
            $sarif['runs'][0]['results'][] = $result;
        }
        return [
            'case:alpha|format:json' => ValueCheck::value(['violations' => $findings]),
            'case:alpha|format:metrics' => '{"symbols":[]}',
            'case:alpha|format:suppressed' => '{"suppressed":[]}',
            'case:alpha|directives' => '{"directives":[],"exit_code":0}',
            'case:alpha|exit:directives' => '0',
            'case:alpha|format:html' => '<script type="application/json" id="report-data">' . ValueCheck::value(['tree' => ['violations' => $html]]) . '</script>',
            'case:alpha|format:gitlab' => ValueCheck::value($gitlab),
            'case:alpha|format:sarif' => ValueCheck::value($sarif),
        ];
    }

    /**
     * @param array<string,mixed> $finding
     *
     * @return array<string,mixed>
     */
    private static function sarif(array $finding): array
    {
        return ['runs' => [['tool' => ['driver' => ['rules' => [['id' => $finding['code']]]]], 'results' => [['ruleId' => $finding['code'], 'ruleIndex' => 0, 'level' => match ($finding['severity']) {
            'warning' => 'warning', 'error' => 'error', default => 'note',
        }, 'message' => ['text' => $finding['message']], 'locations' => [['physicalLocation' => ['artifactLocation' => ['uri' => $finding['file']], 'region' => ['startLine' => $finding['line']]]]]]]]]];
    }
}
