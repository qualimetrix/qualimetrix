<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\ChannelSplit;
use QmxFindingGate\Corpus;
use QmxFindingGate\Declarations;
use QmxFindingGate\DeclaredFields;
use QmxFindingGate\DeclaredOutcomes;
use QmxFindingGate\FailureClass;
use QmxFindingGate\FieldValuesCheck;
use QmxFindingGate\Fs;
use QmxFindingGate\Gate;
use QmxFindingGate\GateError;
use QmxFindingGate\GateModes;
use QmxFindingGate\GateReport;
use QmxFindingGate\MetricVocabulary;
use QmxFindingGate\Normalization;
use QmxFindingGate\Options;
use QmxFindingGate\RenameMaps;
use QmxFindingGate\RunContext;
use QmxFindingGate\SelfTestOutcomes;
use QmxFindingGate\SyntheticTree;
use QmxFindingGate\Tsv;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use WeakReference;

/** Field licenses preserve exact report, invocation, record and instance provenance. */
final class FieldValuesCheckTest extends TestCase
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
    public function itUsesOneInstanceAndOneWriterForTheCompleteReportAndViewUnion(): void
    {
        $addresses = [['json', 'format:json'], ['json', 'check:baseline-source'], ['metrics', 'format:metrics'], ['directives', 'directives'], ['json', 'ranking']];
        $this->intents($addresses);
        $run = $this->context();
        $writer = FieldValuesCheck::create($run);
        self::assertSame($writer, FieldValuesCheck::create($run));
        $writer->startDeriving();
        $rows = [];
        foreach ($addresses as $index => [$report, $view]) {
            $copies = $index === 0 ? 2 : 1;
            $this->publish($run, $report, $view, $index + 1, $copies);
            for ($instance = 0; $instance < $copies; ++$instance) {
                $rows[] = [$report, $view, 'probe', 'alpha', '{"name":"A"}', (string) ($index + 1)];
            }
        }
        FieldValuesCheck::create($run)->checkRun([], []);
        self::assertSame(GateReport::EXIT_GREEN, $run->report->exitCode(), $run->report->render());
        self::assertSame([], $run->declarations->fields->stale());
        self::assertSame([DeclaredFields::DERIVED], $writer->rewriteDerived());
        usort($rows, static fn(array $a, array $b): int => $a <=> $b);
        self::assertSame(Tsv::render(DeclaredFields::DERIVED_COLUMNS, $rows), Fs::read($this->root . '/finding-gate/' . DeclaredFields::DERIVED));
        self::assertSame(6, \count(DeclaredFields::load($this->root . '/finding-gate')->derived('json', 'format:json')) + \count(DeclaredFields::load($this->root . '/finding-gate')->derived('json', 'check:baseline-source')) + \count(DeclaredFields::load($this->root . '/finding-gate')->derived('metrics', 'format:metrics')) + \count(DeclaredFields::load($this->root . '/finding-gate')->derived('directives', 'directives')) + \count(DeclaredFields::load($this->root . '/finding-gate')->derived('json', 'ranking')));
    }

    #[Test]
    public function itRefusesAnExtraReportThroughThePublicCliWithoutWritingDeclarations(): void
    {
        $this->intents([['sarif', 'format:sarif']]);
        $snapshot = function (): array {
            $bytes = [];
            $directory = $this->root . '/finding-gate';
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $bytes[substr($file->getPathname(), \strlen($directory) + 1)] = Fs::read($file->getPathname());
                }
            }
            ksort($bytes);
            return $bytes;
        };
        $before = $snapshot();
        $result = \QmxFindingGate\Process::run([\PHP_BINARY, \dirname(__DIR__, 2) . '/finding-gate.php', '--candidate=' . $this->root, '--reference=HEAD'], $this->root);
        self::assertSame(3, $result['exit'], $result['stderr']);
        self::assertSame("finding-gate: Unknown field report: sarif\n", $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertSame($before, $snapshot());
    }

    #[Test]
    public function itSharesLiveRolesAndReleasesTheRunAfterItsLastRoleIsDropped(): void
    {
        $run = $this->context();
        $check = FieldValuesCheck::create($run);
        self::assertSame($check, FieldValuesCheck::create($run));
        $runReference = WeakReference::create($run);
        $checkReference = WeakReference::create($check);
        unset($check, $run);
        gc_collect_cycles();
        self::assertNull($runReference->get());
        self::assertNull($checkReference->get());
    }

    /** @return iterable<string,array{string}> */
    public static function neighbours(): iterable
    {
        foreach (['report', 'view', 'record', 'instance'] as $dimension) {
            yield $dimension => [$dimension];
        }
    }

    #[Test]
    #[DataProvider('neighbours')]
    public function itRefusesEveryNeighbourAndLeavesTheWholeDerivedUnionUntouched(string $dimension): void
    {
        $addresses = [['json', 'format:json'], ['json', 'check:baseline-source'], ['metrics', 'format:metrics']];
        $this->intents($addresses);
        $expected = [];
        foreach ($addresses as [$report, $view]) {
            $expected[] = [$report, $view, 'probe', 'alpha', '{"name":"A"}', '1'];
        }
        usort($expected, static fn(array $a, array $b): int => $a <=> $b);
        $before = Tsv::render(DeclaredFields::DERIVED_COLUMNS, $expected);
        Fs::write($this->root . '/finding-gate/' . DeclaredFields::DERIVED, $before);
        $run = $this->context();
        foreach ($addresses as [$report, $view]) {
            $value = ($dimension === 'report' && $report === 'metrics') || ($dimension === 'view' && $view === 'check:baseline-source') ? 2 : 1;
            $record = $dimension === 'record' && $view === 'format:json' ? '{"name":"B"}' : '{"name":"A"}';
            $this->publish($run, $report, $view, $value, $dimension === 'instance' && $view === 'format:json' ? 2 : 1, $record);
        }
        $check = FieldValuesCheck::create($run);
        $check->checkRun([], []);
        self::assertContains(FailureClass::FIELD_VALUES_MISMATCH, $run->report->failureClasses(), $run->report->render());
        self::assertSame([], $check->rewriteDerived());
        self::assertSame($before, Fs::read($this->root . '/finding-gate/' . DeclaredFields::DERIVED));
    }

    #[Test]
    public function itRefusesWrongFieldPresenceEvenDuringDerivation(): void
    {
        $this->intents([['json', 'format:json']]);
        $run = $this->context();
        foreach (['candidate', 'reference'] as $side) {
            $run->declarations->fields->requireMeasurements('json', 'alpha', 'format:json', $side);
            $run->declarations->fields->supply('json', 'alpha', 'format:json', $side, [['record' => '{}', 'fields' => ['probe' => 1]]]);
        }
        $check = FieldValuesCheck::create($run);
        $check->startDeriving();
        $check->checkRun([], []);
        self::assertContains(FailureClass::FIELD_VALUES_MISMATCH, $run->report->failureClasses());
        self::assertSame([], $check->rewriteDerived());
        self::assertFileDoesNotExist($this->root . '/finding-gate/' . DeclaredFields::DERIVED);
    }

    #[Test]
    public function itAcceptsAnObservedEmptyReportBesideANonemptyReportWithoutInventingFieldCredit(): void
    {
        $this->intents([['json', 'format:json']]);
        $run = $this->context();
        $this->publish($run, 'json', 'format:json', 1);
        $run->declarations->fields->requireMeasurements('metrics', 'alpha', 'format:metrics', 'candidate');
        $run->declarations->fields->supply('metrics', 'alpha', 'format:metrics', 'candidate', []);
        $check = FieldValuesCheck::create($run);
        $check->startDeriving();
        $check->checkRun([], []);
        self::assertSame(GateReport::EXIT_GREEN, $run->report->exitCode());
        self::assertSame([DeclaredFields::DERIVED], $check->rewriteDerived());
        self::assertCount(1, DeclaredFields::load($this->root . '/finding-gate')->derived('json', 'format:json'));
        unlink($this->root . '/finding-gate/' . DeclaredFields::DERIVED);
        $this->intents([['metrics', 'format:metrics']]);
        $empty = $this->context();
        foreach (['candidate', 'reference'] as $side) {
            $empty->declarations->fields->requireMeasurements('metrics', 'alpha', 'format:metrics', $side);
            $empty->declarations->fields->supply('metrics', 'alpha', 'format:metrics', $side, []);
        }
        $emptyCheck = FieldValuesCheck::create($empty);
        $emptyCheck->startDeriving();
        $emptyCheck->checkRun([], []);
        self::assertCount(1, $empty->declarations->fields->stale());
        self::assertSame([], $emptyCheck->rewriteDerived());
    }

    #[Test]
    public function itRequiresTheActualSupplierAndRefusesAWritingCallBeforeMeasurement(): void
    {
        $this->intents([['json', 'format:json']]);
        $run = $this->context();
        $run->declarations->fields->requireMeasurements('json', 'alpha', 'format:json', 'candidate');
        $check = FieldValuesCheck::create($run);
        try {
            $check->rewriteDerived();
            self::fail('An unmeasured union was written.');
        } catch (GateError $error) {
            self::assertStringContainsString('before every required publication', $error->getMessage());
        }
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('not supplied');
        $check->checkRun([], []);
    }

    #[Test]
    public function itDerivesAnAddedDirectiveFieldFromRecordedReportsAndKeepsANeighbourRed(): void
    {
        SyntheticTree::remove($this->root);
        $tree = SyntheticTree::clean();
        $base = ['file' => 'src/Alpha.php', 'line' => 1, 'form' => 'symbol', 'target' => 'replay.alpha', 'effect' => 'applied', 'reason' => 'replayed', 'masked_by' => null, 'boundary_observable' => true, 'refusals' => []];
        $record = $base + ['probe' => 1];
        $tree['candidateAnswers']['case:alpha|directives'] = ['stdout' => json_encode(['directives' => [$record], 'exit_code' => 0], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n"];
        $tree['declarations'][DeclaredFields::INDEX] = Tsv::render(DeclaredFields::COLUMNS, [['added', 'directives', 'directives', 'probe', 'a new observation']]);
        $derived = Tsv::render(DeclaredFields::DERIVED_COLUMNS, [['directives', 'directives', 'probe', 'alpha', json_encode($base, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), '1']]);
        $tree['declarations'][DeclaredFields::DERIVED] = $derived;
        $this->root = SyntheticTree::fixture($tree);
        $options = Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD', '--jobs=4'], $this->root);
        $green = RecordedComparison::reportAt($tree, $this->root);
        self::assertSame(GateReport::EXIT_GREEN, $green->exitCode(), $green->render());
        Fs::write($this->root . '/finding-gate/' . DeclaredFields::DERIVED, str_replace("\t1\n", "\t2\n", $derived));
        $red = RecordedComparison::reportAt($tree, $this->root);
        self::assertContains(FailureClass::FIELD_VALUES_MISMATCH, $red->failureClasses(), $red->render());
        [$measurement, $written] = RecordedComparison::derive($tree, $this->root);
        self::assertSame(GateReport::EXIT_GREEN, $measurement->exitCode(), $measurement->render());
        self::assertSame(1, array_count_values($written)[DeclaredFields::DERIVED] ?? 0);
        self::assertSame($derived, Fs::read($this->root . '/finding-gate/' . DeclaredFields::DERIVED));
        $answers = json_decode(Fs::read($this->root . '/replay/answers.json'), true, 512, \JSON_THROW_ON_ERROR);
        $record['boundary_observable'] = false;
        $answers['case:alpha|directives']['stdout'] = json_encode(['directives' => [$record], 'exit_code' => 0], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
        Fs::write($this->root . '/replay/answers.json', json_encode($answers, \JSON_THROW_ON_ERROR));
        $before = Fs::read($this->root . '/finding-gate/' . DeclaredFields::DERIVED);
        [$derive, $written] = RecordedComparison::derive($tree, $this->root);
        self::assertSame([DeclaredFields::DERIVED], $written);
        self::assertSame(GateReport::EXIT_RED, $derive->exitCode());
        self::assertSame(str_replace('"boundary_observable":true', '"boundary_observable":false', $before), Fs::read($this->root . '/finding-gate/' . DeclaredFields::DERIVED));
        self::assertContains(FailureClass::VALUE_MISMATCH, $derive->failureClasses(), $derive->render());
    }

    #[Test]
    #[Group('finding-gate-e2e')]
    public function itObservesTheRegisteredFieldValueWitnessFromItsActualProducer(): void
    {
        $witnesses = \QmxFindingGate\SelfTestFindingShape::fieldValuesWitnesses();
        self::assertCount(1, $witnesses);
        self::assertSame('field-values-publication', $witnesses[0]['id']);
        SyntheticTree::remove($this->root);
        $this->root = SyntheticTree::create($witnesses[0]['plant'](SyntheticTree::clean()));
        $report = new GateReport();
        (new Gate(Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD', '--jobs=4'], $this->root), $report))->compare();
        self::assertSame(GateReport::EXIT_RED, $report->exitCode(), $report->render());
        self::assertCount(1, $report->raised());
        $raised = $report->raised()[0];
        $sites = \QmxFindingGate\RaiseSites::of(\dirname(__DIR__), \QmxFindingGate\RaiseSites::DECLARED_NAMES);
        $identity = null;
        foreach ($sites->sites as $site) {
            if ($site['file'] === $raised['file'] && $site['line'] === $raised['line']) {
                $identity = $sites->identityOf($site['site'], $raised['chain']);
            }
        }
        self::assertSame($witnesses[0]['expect'][0], [$raised['class'], $raised['scope'], $identity]);
        self::assertSame('FieldValuesCheck::checkRun <- Gate::compare', $identity);
    }

    /** @return iterable<string,array{string}> */
    public static function duplicateRuns(): iterable
    {
        foreach (['exact', 'instance', 'value'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[Test]
    #[DataProvider('duplicateRuns')]
    public function itJudgesCompleteDuplicateJsonRecordsFromRecordedReports(string $mode): void
    {
        $this->compareDuplicateFields($mode, false);
    }

    private function compareDuplicateFields(string $mode, bool $public): void
    {
        SyntheticTree::remove($this->root);
        $tree = SyntheticTree::clean();
        $base = $tree['findings']['alpha'][0];
        $copies = $mode === 'instance' ? 3 : 2;
        $tree['findings']['alpha'] = array_fill(0, $copies, $base);
        $tree['candidateFindings']['alpha'] = array_fill(0, $copies, $base + ['probe' => $mode === 'value' ? 2 : 1]);
        $tree['tuple'][] = 'probe';
        $tree['published'][] = 'probe';
        $tree['declarations'][DeclaredFields::INDEX] = Tsv::render(DeclaredFields::COLUMNS, [['added', 'json', 'format:json', 'probe', 'a new member on every full record']]);
        $row = ['json', 'format:json', 'probe', 'alpha', json_encode($base, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), '1'];
        $derived = Tsv::render(DeclaredFields::DERIVED_COLUMNS, [$row, $row]);
        $tree['declarations'][DeclaredFields::DERIVED] = $derived;
        $baseline = json_encode(['version' => 13, 'scope' => ['src'], 'entries' => [$base['subject'] => [['channel' => $base['channel'], 'magnitudes' => array_fill(0, $copies, 1)]]]], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
        $tree['answers']['case:alpha|baseline-file'] = ['stdout' => $baseline, 'file' => $baseline];
        $this->root = $public ? SyntheticTree::create($tree) : SyntheticTree::fixture($tree);
        $publisher = 'src/Reporting/Formatter/FindingRecord.php';
        $candidate = Fs::read($this->root . '/' . $publisher);
        $reference = preg_replace("~^ {12}'probe' => .*\\n~m", '', $candidate);
        self::assertIsString($reference);
        self::assertNotSame($candidate, $reference);
        Fs::write($this->root . '/' . $publisher, $reference);
        if ($public) {
            foreach ([['git', 'add', '--', $publisher], ['git', '-c', 'user.name=fixture', '-c', 'user.email=fixture@qmx', 'commit', '--quiet', '-m', 'Reference publisher']] as $command) {
                self::assertSame(0, \QmxFindingGate\Process::run($command, $this->root)['exit']);
            }
        }
        Fs::write($this->root . '/' . $publisher, $candidate);
        $options = Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD', '--jobs=4'], $this->root);
        $report = $public ? new GateReport() : RecordedComparison::reportAt($tree, $this->root);
        if ($public) {
            (new Gate($options, $report))->compare();
        }
        self::assertSame($mode === 'exact' ? GateReport::EXIT_GREEN : GateReport::EXIT_RED, $report->exitCode(), $report->render());
        if ($mode !== 'exact') {
            self::assertContains(FailureClass::FIELD_VALUES_MISMATCH, $report->failureClasses(), $report->render());
        }
        if ($mode === 'instance') {
            $before = Fs::read($this->root . '/finding-gate/' . DeclaredFields::DERIVED);
            $answers = json_decode(Fs::read($this->root . '/replay/answers.json'), true, 512, \JSON_THROW_ON_ERROR);
            $document = json_decode($answers['case:alpha|format:json']['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            $document['violations'][0]['threshold'] = 9;
            $answers['case:alpha|format:json']['stdout'] = json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
            Fs::write($this->root . '/replay/answers.json', json_encode($answers, \JSON_THROW_ON_ERROR));
            $derive = new GateReport();
            if ($public) {
                $written = (new Gate($options, $derive))->deriveDeclarations();
            } else {
                [$derive, $written] = RecordedComparison::derive($tree, $this->root);
            }
            self::assertSame([], $written);
            self::assertSame(GateReport::EXIT_RED, $derive->exitCode());
            self::assertSame($before, Fs::read($this->root . '/finding-gate/' . DeclaredFields::DERIVED));
        }
    }

    /** @return iterable<string,array{string}> */
    public static function viewRuns(): iterable
    {
        foreach (['exact', 'swap', 'main-only', 'derived-other-view'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[Test]
    #[DataProvider('viewRuns')]
    public function itKeepsMainAndBaselineSourceFieldLicensesSeparateFromRecordedReports(string $mode): void
    {
        $this->compareViews($mode, false);
    }

    #[Test]
    #[Group('finding-gate-e2e')]
    public function itComparesAndDerivesTheFieldPublicationUnionThroughThePublicGate(): void
    {
        $this->compareViews('exact', true);
    }

    private function compareViews(string $mode, bool $public): void
    {
        SyntheticTree::remove($this->root);
        $tree = SyntheticTree::clean();
        $base = $tree['findings']['alpha'][0];
        $tree['tuple'][] = 'probe';
        $tree['published'][] = 'probe';
        $tree['candidateFindings']['alpha'] = [$base + ['probe' => $mode === 'swap' ? 2 : 1]];
        $tree['declarations']['cases/alpha/baseline-src/src/Alpha.php'] = "<?php\n";
        $encode = static fn(mixed $value): string => json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
        $tree['answers']['case:alpha|check:baseline-source'] = self::rankedAnswer([$base, $base, $base]);
        $variant = $base + ['probe' => $mode === 'swap' ? 1 : 2];
        $tree['candidateAnswers']['case:alpha|check:baseline-source'] = self::rankedAnswer([$variant, $variant, $variant]);
        $tree['answers']['case:alpha|check:baseline'] = self::rankedAnswer([]);
        $baseline = $encode(['version' => 13, 'scope' => ['src'], 'entries' => [$base['subject'] => [['channel' => $base['channel'], 'magnitudes' => [1, 1, 1]]]]]);
        $tree['answers']['case:alpha|baseline-file'] = ['stdout' => $baseline, 'file' => $baseline];
        $intents = [['added', 'json', 'format:json', 'probe', 'the main field publication']];
        if ($mode !== 'main-only') {
            $intents[] = ['added', 'json', 'check:baseline-source', 'probe', 'the independent baseline source publication'];
        }
        $tree['declarations'][DeclaredFields::INDEX] = Tsv::render(DeclaredFields::COLUMNS, $intents);
        $key = json_encode($base, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $rows = [['json', 'format:json', 'probe', 'alpha', $key, '1']];
        if ($mode !== 'main-only') {
            for ($instance = 0; $instance < 3; ++$instance) {
                $rows[] = ['json', $mode === 'derived-other-view' ? 'format:json' : 'check:baseline-source', 'probe', 'alpha', $key, '2'];
            }
        }
        if ($mode === 'exact') {
            $metric = ['type' => 'method', 'name' => 'Replay\\Alpha::run', 'file' => 'src/Alpha.php', 'line' => 1, 'metrics' => ['ccn' => 1]];
            $directive = ['file' => 'src/Alpha.php', 'line' => 1, 'form' => 'symbol', 'target' => 'replay.alpha', 'effect' => 'applied', 'reason' => 'replayed', 'masked_by' => null, 'boundary_observable' => true, 'refusals' => []];
            $tree['candidateAnswers']['case:alpha|format:metrics'] = ['stdout' => $encode(['symbols' => [$metric + ['probe' => 3]]])];
            $tree['candidateAnswers']['case:alpha|directives'] = ['stdout' => $encode(['directives' => [$directive + ['probe' => 4]], 'exit_code' => 0])];
            $intents[] = ['added', 'metrics', 'format:metrics', 'probe', 'the metric field publication'];
            $intents[] = ['added', 'directives', 'directives', 'probe', 'the directive field publication'];
            $tree['declarations'][DeclaredFields::INDEX] = Tsv::render(DeclaredFields::COLUMNS, $intents);
            $rows[] = ['metrics', 'format:metrics', 'probe', 'alpha', json_encode(['type' => $metric['type'], 'name' => $metric['name']], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), '3'];
            $rows[] = ['directives', 'directives', 'probe', 'alpha', json_encode($directive, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), '4'];
        }
        usort($rows, static fn(array $a, array $b): int => $a <=> $b);
        $tree['declarations'][DeclaredFields::DERIVED] = Tsv::render(DeclaredFields::DERIVED_COLUMNS, $rows);
        $this->root = $public ? SyntheticTree::create($tree) : SyntheticTree::fixture($tree);
        $publisher = 'src/Reporting/Formatter/FindingRecord.php';
        $candidate = Fs::read($this->root . '/' . $publisher);
        $reference = preg_replace("~^ {12}'probe' => .*\\n~m", '', $candidate);
        self::assertIsString($reference);
        Fs::write($this->root . '/' . $publisher, $reference);
        if ($public) {
            foreach ([['git', 'add', '--', $publisher], ['git', '-c', 'user.name=fixture', '-c', 'user.email=fixture@qmx', 'commit', '--quiet', '-m', 'Reference publisher']] as $command) {
                self::assertSame(0, \QmxFindingGate\Process::run($command, $this->root)['exit']);
            }
        }
        Fs::write($this->root . '/' . $publisher, $candidate);
        $options = Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD', '--jobs=4'], $this->root);
        $report = $public ? new GateReport() : RecordedComparison::reportAt($tree, $this->root);
        $before = Fs::read($this->root . '/finding-gate/' . DeclaredFields::DERIVED);
        if ($public) {
            (new Gate($options, $report))->compare();
        }
        self::assertSame($mode === 'exact' ? GateReport::EXIT_GREEN : GateReport::EXIT_RED, $report->exitCode(), $report->render());
        if (\in_array($mode, ['swap', 'derived-other-view'], true)) {
            self::assertContains(FailureClass::FIELD_VALUES_MISMATCH, $report->failureClasses(), $report->render());
        }
        if ($mode === 'main-only') {
            self::assertContains(FailureClass::RECORD_PROJECTION_MISMATCH, $report->failureClasses(), $report->render());
            $derive = new GateReport();
            if ($public) {
                $written = (new Gate($options, $derive))->deriveDeclarations();
            } else {
                [$derive, $written] = RecordedComparison::derive($tree, $this->root);
            }
            self::assertSame([], $written);
            self::assertSame(GateReport::EXIT_RED, $derive->exitCode(), $derive->render());
        }
        self::assertSame($before, Fs::read($this->root . '/finding-gate/' . DeclaredFields::DERIVED));
        if ($mode === 'exact') {
            Fs::write($this->root . '/finding-gate/' . DeclaredFields::DERIVED, Tsv::render(DeclaredFields::DERIVED_COLUMNS, []));
            $derive = new GateReport();
            $written = [];
            if ($public) {
                $written = (new Gate($options, $derive))->deriveDeclarations();
            } else {
                [$derive, $written] = RecordedComparison::derive($tree, $this->root);
            }
            self::assertSame(GateReport::EXIT_GREEN, $derive->exitCode(), $derive->render());
            self::assertSame(1, array_count_values($written)[DeclaredFields::DERIVED] ?? 0);
            self::assertSame($before, Fs::read($this->root . '/finding-gate/' . DeclaredFields::DERIVED));
            self::assertCount(6, $rows);
        }
    }

    #[Test]
    #[Group('finding-gate-e2e')]
    public function itMeasuresApplicableFieldsBesideADeclaredRefusalAndKeepsAnUnexpectedRefusalRed(): void
    {
        SyntheticTree::remove($this->root);
        $tree = SelfTestOutcomes::fixture();
        $tree['candidateDeclarations'][DeclaredFields::INDEX] = Tsv::render(DeclaredFields::COLUMNS, [['added', 'metrics', 'format:metrics', 'probe', 'An independently measured metric field.']]);
        $metric = ['type' => 'method', 'name' => 'Replay\Keeper::run', 'file' => 'src/Keeper.php', 'line' => 1, 'metrics' => ['ccn' => 1], 'probe' => 7];
        $tree['candidateAnswers']['case:keeper|format:metrics'] = ['stdout' => json_encode(['symbols' => [$metric]], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n"];
        $this->root = SyntheticTree::create($tree);
        $arguments = ['gate', '--candidate=' . $this->root, '--reference=HEAD', '--jobs=4'];
        $derivedReport = new GateReport();
        ob_start();
        try {
            self::assertSame(GateModes::WROTE, GateModes::run(Options::parse([...$arguments, '--derive-declarations'], $this->root), $derivedReport));
        } catch (GateError $error) {
            self::fail($error->getMessage());
        } finally {
            ob_end_clean();
        }
        self::assertSame(GateReport::EXIT_GREEN, $derivedReport->exitCode(), $derivedReport->render());
        $fields = DeclaredFields::load($this->root . '/finding-gate');
        $derived = $fields->derived('metrics', 'format:metrics');
        self::assertCount(1, $derived);
        self::assertSame(['metrics', 'format:metrics', 'probe', 'keeper', '7'], [$derived[0]['report'], $derived[0]['view'], $derived[0]['field'], $derived[0]['case'], $derived[0]['value']]);
        self::assertNotSame('', $derived[0]['record']);
        $green = new GateReport();
        $gate = new Gate(Options::parse($arguments, $this->root), $green);
        $gate->compare();
        self::assertSame(GateReport::EXIT_GREEN, $green->exitCode(), $green->render());
        // Inspect captured provenance without adding a runtime inspection API.
        $declarations = (new ReflectionProperty(Gate::class, 'declarations'))->getValue($gate);
        self::assertInstanceOf(Declarations::class, $declarations);
        $measurements = $declarations->fields->measurements('metrics');
        $alphaReference = array_values(array_filter($measurements, static fn(array $row): bool => $row['case'] === 'alpha' && $row['side'] === 'reference'));
        self::assertCount(1, $alphaReference);
        self::assertNotSame([], $alphaReference[0]['records']);
        self::assertSame([], array_values(array_filter($measurements, static fn(array $row): bool => $row['case'] === 'alpha' && $row['side'] === 'candidate')));
        $keeperCandidate = array_values(array_filter($measurements, static fn(array $row): bool => $row['case'] === 'keeper' && $row['side'] === 'candidate'));
        self::assertCount(1, $keeperCandidate);
        self::assertSame(7, $keeperCandidate[0]['records'][0]['fields']['probe']);
        Fs::write($this->root . '/finding-gate/' . DeclaredOutcomes::INDEX, Tsv::render(DeclaredOutcomes::COLUMNS, []));
        $snapshot = function (): array {
            $bytes = [];
            $directory = $this->root . '/finding-gate';
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $bytes[substr($file->getPathname(), \strlen($directory) + 1)] = Fs::read($file->getPathname());
                }
            }
            ksort($bytes);
            return $bytes;
        };
        $before = $snapshot();
        $red = new GateReport();
        try {
            self::assertSame([], (new Gate(Options::parse($arguments, $this->root), $red))->deriveDeclarations());
        } catch (GateError $error) {
            self::assertStringContainsString('Complete ranking requires nonnegative total and boolean truncation metadata', $error->getMessage());
        }
        self::assertSame(GateReport::EXIT_RED, $red->exitCode(), $red->render());
        self::assertContains(FailureClass::RUN_FAILED, $red->failureClasses(), $red->render());
        self::assertSame($before, $snapshot());
    }

    /** @param list<array<string,mixed>> $records
     * @return array{stdout:string,ranked:array{stdout:string}}
     */
    private static function rankedAnswer(array $records): array
    {
        $counts = [];
        $issues = [];
        foreach ($records as $index => $record) {
            $rule = (string) $record['rule'];
            $counts[$rule] = ($counts[$rule] ?? 0) + 1;
            $issues[] = ['rank' => $index + 1, ...array_intersect_key($record, array_flip(\QmxFindingGate\RankingSchema::PROJECTION)), 'impactScore' => 0, 'coupling.class-rank' => null, 'debtMinutes' => $record['techDebtMinutes']];
        }
        $document = ['violations' => $records, 'topIssues' => [], 'violationsMeta' => ['total' => \count($records), 'shown' => \count($records), 'truncated' => false, 'byRule' => $counts]];
        $original = \QmxFindingGate\ValueCheck::value($document);
        $document['topIssues'] = $issues;
        return ['stdout' => $original, 'ranked' => ['stdout' => \QmxFindingGate\ValueCheck::value($document)]];
    }

    /** @param list<array{string,string}> $addresses */
    private function intents(array $addresses): void
    {
        Fs::write($this->root . '/finding-gate/' . DeclaredFields::INDEX, Tsv::render(DeclaredFields::COLUMNS, array_map(static fn(array $address): array => ['added', ...$address, 'probe', 'a measured new field'], $addresses)));
    }

    #[Test]
    public function itMeasuresAnAddedDocumentMemberWithoutTurningItIntoAFindingField(): void
    {
        Fs::write($this->root . '/finding-gate/' . DeclaredFields::INDEX, Tsv::render(DeclaredFields::COLUMNS, [
            ['added', 'json-document', 'format:json', 'configurationDiagnostics', 'Publish configuration diagnostics.'],
            ['added', 'json-document', 'check:output:file', 'configurationDiagnostics', 'Publish configuration diagnostics in files.'],
        ]));
        $run = $this->context();
        $candidate = [];
        $reference = [];
        foreach (['format:json', 'check:output:file'] as $view) {
            foreach (['candidate', 'reference'] as $side) {
                $run->declarations->fields->requireMeasurements('json-document', 'alpha', $view, $side);
            }
            $candidate['case:alpha|' . $view] = '{"violations":[],"configurationDiagnostics":[]}';
            $reference['case:alpha|' . $view] = '{"violations":[]}';
        }
        $writer = FieldValuesCheck::create($run);
        $writer->startDeriving();
        $writer->checkRun($candidate, $reference);
        self::assertSame([], $run->report->failureClasses(), $run->report->render());
        self::assertSame([DeclaredFields::DERIVED], $writer->rewriteDerived());
        $fields = DeclaredFields::load($this->root . '/finding-gate');
        self::assertSame('[]', $fields->derived('json-document', 'format:json')[0]['value']);
        self::assertSame('$', $fields->derived('json-document', 'format:json')[0]['record']);
        self::assertSame([], $fields->changes('json', 'format:json'));
        $changed = $this->context();
        foreach (['format:json', 'check:output:file'] as $view) {
            foreach (['candidate', 'reference'] as $side) {
                $changed->declarations->fields->requireMeasurements('json-document', 'alpha', $view, $side);
            }
        }
        $candidate['case:alpha|format:json'] = '{"violations":[],"configurationDiagnostics":["warning"]}';
        FieldValuesCheck::create($changed)->checkRun($candidate, $reference);
        self::assertContains(FailureClass::FIELD_VALUES_MISMATCH, $changed->report->failureClasses());
    }

    private function context(): RunContext
    {
        $maps = RenameMaps::fromPairs([]);
        return new RunContext(Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], $this->root), new GateReport(), Corpus::load($this->root), $maps, ChannelSplit::of($maps), MetricVocabulary::none(), Normalization::fromRules([]), Declarations::load($this->root), $this->root);
    }

    private function publish(RunContext $run, string $report, string $view, int $value, int $copies = 1, string $key = '{"name":"A"}'): void
    {
        foreach (['candidate', 'reference'] as $side) {
            $run->declarations->fields->requireMeasurements($report, 'alpha', $view, $side);
            $fields = $side === 'candidate' ? ['name' => 'A', 'probe' => $value] : ['name' => 'A'];
            $run->declarations->fields->supply($report, 'alpha', $view, $side, array_fill(0, $copies, ['record' => $key, 'fields' => $fields]));
        }
    }
}
