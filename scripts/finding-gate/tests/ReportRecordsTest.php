<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
        $this->root = SyntheticTree::create(SyntheticTree::clean());
    }

    protected function tearDown(): void
    {
        SyntheticTree::remove($this->root);
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
        yield 'directives' => ['directives', ['file' => 'src/A.php', 'line' => 1, 'form' => 'ignore', 'target' => 'a.b', 'effect' => 'applied', 'reason' => null, 'masked_by' => null, 'boundary_observable' => true]];
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
                $artifacts = self::artifacts([self::finding()]);
                $artifacts['case:alpha|format:metrics'] = ValueCheck::value(['symbols' => [$record]]);
                $check->checkCase($side, $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
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
        RecordCheck::create($run)->checkCase($side, $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
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
        RecordCheck::create($run)->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
        self::assertCount(2, $run->report->raised());
        self::assertSame([FailureClass::RECORD_PROJECTION_MISMATCH], $run->report->failureClasses());
    }

    #[Test]
    public function itRefusesDirectivesJsonWhoseExitDisagreesWithTheProcess(): void
    {
        $run = $this->context();
        $artifacts = self::artifacts([self::finding()]);
        $artifacts['case:alpha|exit:directives'] = '5';
        RecordCheck::create($run)->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
        self::assertContains(FailureClass::RECORD_PROJECTION_MISMATCH, $run->report->failureClasses());
    }

    #[Test]
    public function itSuppliesCompleteMeasurementsOnceBeforeRecordAndFieldProjection(): void
    {
        Fs::write($this->root . '/finding-gate/' . DeclaredFields::INDEX, Tsv::render(DeclaredFields::COLUMNS, [['added', 'json', 'format:json', 'fresh', 'A new observation.']]));
        $run = $this->context();
        foreach (['candidate', 'reference'] as $side) {
            $run->declarations->fields->requireMeasurements('json', 'alpha', 'format:json', $side);
        }
        $a = self::finding() + ['fresh' => 7];
        $records = RecordCheck::create($run);
        $records->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$a]));
        $records->checkCase('reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([self::finding()]));
        $records->prepare('alpha');
        $records->prepare('alpha');
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
        $records->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([]));
        $records->checkCase('reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([self::finding(), $neighbour]));
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
        $records->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([]));
        $records->checkCase('reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$a, $a]));
        $records->prepare('alpha');
        self::assertSame([], $run->report->raised());
        self::assertSame([DeclaredRecords::DERIVED], $derivation->rewriteDerived());
        $rows = Tsv::rows($this->root . '/finding-gate/' . DeclaredRecords::DERIVED, DeclaredRecords::DERIVED_COLUMNS);
        self::assertCount(2, $rows);
        self::assertSame(DeclaredRecords::canonical($a), $rows[0]['record']);
        self::assertSame($rows[0], $rows[1]);
    }

    #[Test]
    public function itRemovesOnlyTheDeclaredCompleteProjectionInstance(): void
    {
        $a = self::finding();
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', '{"channel":"a.b"}', 'Remove one observation.']]));
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::DERIVED, Tsv::render(DeclaredRecords::DERIVED_COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', DeclaredRecords::canonical($a)]]));
        $run = $this->context();
        $check = RecordCheck::create($run);
        $check->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$a]));
        $check->checkCase('reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$a, $a]));
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
        RecordCheck::create($run)->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
        self::assertSame([], $run->report->raised());
        $bad = $this->context();
        $artifacts['case:alpha|baseline-file'] = self::baselineDocument($variant, [1]);
        RecordCheck::create($bad)->checkCase('candidate', $bad->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
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
            RecordCheck::create($run)->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
            self::assertSame($source === '{"violations":[]}' ? [] : [FailureClass::RECORD_PROJECTION_MISMATCH], $run->report->failureClasses());
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
        $reference = self::artifacts([self::finding()]) + ['case:alpha|check:baseline-source' => ValueCheck::value(['violations' => [self::finding()]])];
        $check->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $candidate);
        $check->checkCase('reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $reference);
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
        $reference = self::artifacts([self::finding()]) + ['case:alpha|check:baseline-source' => ValueCheck::value(['violations' => [self::finding()]])];
        $check = RecordCheck::create($run);
        $check->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $candidate);
        $check->checkCase('reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $reference);
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
        $check->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$main]) + ['case:alpha|check:baseline-source' => ValueCheck::value(['violations' => [$variant]])]);
        $check->checkCase('reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$old]) + ['case:alpha|check:baseline-source' => ValueCheck::value(['violations' => [$old]])]);
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
        Fs::write($this->root . '/finding-gate/' . \QmxFindingGate\DeclaredValues::INDEX, Tsv::render(\QmxFindingGate\DeclaredValues::COLUMNS, [['field', 'message', '*', 'Change this finding message.']]));
        $run = $this->context();
        ValueCheck::create($run)->startDeriving();
        $a = self::finding();
        $a['message'] = 'New (term)';
        $b = self::finding();
        $candidate = self::artifacts([$a]);
        $reference = self::artifacts([$b]);
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
        $check->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $candidate);
        $check->checkCase('reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, $reference);
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
            self::assertSame($pair->reference, $pair->candidate, $surface);
        }
        self::assertSame([], $run->report->raised());
    }

    #[Test]
    public function itSkipsRefusalRecordsWithoutInventingAnEmptyPublication(): void
    {
        SyntheticTree::remove($this->root);
        $this->root = SyntheticTree::create(\QmxFindingGate\SelfTestOutcomes::fixture());
        $run = $this->context();
        $records = RecordCheck::create($run);
        $case = $run->corpus->cases[0];
        $records->checkCase('candidate', $case, CaseOutcome::REFUSAL, []);
        $records->checkCase('reference', $case, CaseOutcome::ANALYSIS, self::artifacts([self::finding()]));
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
            $records->checkCase('candidate', $run->corpus->cases[0], $outcome, self::artifacts([self::finding()]) + ['case:alpha|baseline-file' => 'No baseline was generated']);
            $pair = new SurfacePair('case:alpha|baseline-file', 'baseline-file', 'No baseline was generated', 'No baseline was generated');
            RecordStage::create($run)->applyStage($pair);
            if ($outcome === CaseOutcome::INCOMPLETE) {
                self::assertSame([], $run->report->raised());
                self::assertFalse($pair->settled);
            } else {
                self::assertContains(FailureClass::RECORD_PROJECTION_MISMATCH, $run->report->failureClasses());
            }
        }
    }

    #[Test]
    public function itRemovesOnlyGroupsEmptiedByAnExactFindingWithdrawal(): void
    {
        $kept = self::finding();
        $removed = array_replace($kept, ['subject' => 'class:App\\B', 'file' => 'src/B.php', 'symbol' => 'App\\B', 'code' => 'c.d', 'rule' => 'c.d', 'channel' => 'c.d']);
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', '{"channel":"c.d"}', 'Retire this exact finding.']]));
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::DERIVED, Tsv::render(DeclaredRecords::DERIVED_COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', DeclaredRecords::canonical($removed)]]));
        $run = $this->context();
        $records = RecordCheck::create($run);
        $records->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$kept]));
        $records->checkCase('reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$kept, $removed]));
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
    public function itPreservesRankedPrefixBytesAndRefusesAnUndeclaredRankedNeighbour(): void
    {
        $a = self::finding();
        $b = self::finding();
        $b['channel'] = $b['code'] = $b['rule'] = 'c.d';
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::INDEX, Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', '{"channel":"c.d"}', 'Retire this ranked finding.']]));
        Fs::write($this->root . '/finding-gate/' . DeclaredRecords::DERIVED, Tsv::render(DeclaredRecords::DERIVED_COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', DeclaredRecords::canonical($b)]]));
        foreach ([false, true] as $neighbour) {
            $run = $this->context();
            $check = RecordCheck::create($run);
            $check->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$a]));
            $check->checkCase('reference', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([$a, $b]));
            $check->prepare('alpha');
            $issueA = array_intersect_key($a, array_flip(['file', 'line', 'symbol', 'rule', 'severity', 'message', 'recommendation'])) + ['rank' => 1, 'impactScore' => 10.5, 'coupling.class-rank' => null, 'debtMinutes' => 15];
            $issueB = array_intersect_key($b, array_flip(['file', 'line', 'symbol', 'rule', 'severity', 'message', 'recommendation'])) + ['rank' => 2, 'impactScore' => 5, 'coupling.class-rank' => null, 'debtMinutes' => 15];
            $candidateIssue = $issueA;
            if ($neighbour) {
                $candidateIssue['message'] = 'An undeclared ranked neighbour';
            }
            $pair = new SurfacePair('case:alpha|format:json', 'format:json', ValueCheck::value(['violations' => [$a], 'topIssues' => [$candidateIssue]]), str_replace('10.5', '10.50', ValueCheck::value(['violations' => [$a, $b], 'topIssues' => [$issueA, $issueB]])));
            RecordStage::create($run)->applyStage($pair);
            if ($neighbour) {
                self::assertContains(FailureClass::TOP_ISSUES_MISMATCH, $run->report->failureClasses());
            } else {
                self::assertSame([], $run->report->raised());
                self::assertStringContainsString('10.50', (string) $pair->reference);
                self::assertStringNotContainsString('10.50', (string) $pair->candidate);
                self::assertNotSame($pair->candidate, $pair->reference);
                self::assertCount(1, ReportRecords::decode((string) $pair->reference)['topIssues']);
            }
        }
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
            $check->checkCase($side, $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
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
        $check->checkCase('candidate', $run->corpus->cases[0], CaseOutcome::ANALYSIS, self::artifacts([self::finding()]));
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
            $check->checkCase($side, $run->corpus->cases[0], CaseOutcome::ANALYSIS, $artifacts);
        }
        return [$run, $check];
    }

    private function context(): RunContext
    {
        $options = Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], $this->root);
        $maps = RenameMaps::fromPairs([]);
        return new RunContext($options, new GateReport(), Corpus::load($this->root), $maps, ChannelSplit::of($maps), MetricVocabulary::ofTree($this->root), Normalization::fromRules([]), Declarations::load($this->root), $this->root);
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
    private static function artifacts(array $findings): array
    {
        $gitlab = array_map(static fn(array $record): array => ReportRecords::projection('format:gitlab', $record), $findings);
        $html = array_map(static fn(array $record): array => ReportRecords::projection('format:html', $record), $findings);
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
            'case:alpha|format:html' => '<script type="application/json" id="report-data">' . ValueCheck::value(['tree' => ['findings' => $html]]) . '</script>',
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
        return ['runs' => [['tool' => ['driver' => ['rules' => [['id' => $finding['code']]]]], 'results' => [['ruleId' => $finding['code'], 'ruleIndex' => 0, 'level' => 'error', 'message' => ['text' => $finding['message']], 'locations' => [['physicalLocation' => ['artifactLocation' => ['uri' => $finding['file']], 'region' => ['startLine' => $finding['line']]]]]]]]]];
    }
}
