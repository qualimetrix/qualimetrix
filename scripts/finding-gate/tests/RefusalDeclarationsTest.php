<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CapturePlan;
use QmxFindingGate\CaseOutcomeCheck;
use QmxFindingGate\ChannelSplit;
use QmxFindingGate\Corpus;
use QmxFindingGate\DeclaredDelta;
use QmxFindingGate\DeclaredDeltaCheck;
use QmxFindingGate\DeclaredFieldMoves;
use QmxFindingGate\DeclaredStructuralMaps;
use QmxFindingGate\DeclaredSurfaces;
use QmxFindingGate\EquivalenceTuple;
use QmxFindingGate\FailureClass;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\GateReport;
use QmxFindingGate\Options;
use QmxFindingGate\RenameMaps;
use QmxFindingGate\ReportRecords;
use QmxFindingGate\TreeRun;
use QmxFindingGate\Tsv;

final class RefusalDeclarationsTest extends TestCase
{
    private string $root;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->root = Fs::temporaryDirectory('refusal-declarations-test-');
        Fs::write($this->root . '/src/Reporting/Formatter/FindingRecord.php', '<?php class Publisher { function of() {} }');
        Fs::write($this->root . '/' . EquivalenceTuple::TRACKED_PATH, Tsv::render(EquivalenceTuple::COLUMNS, [['message', EquivalenceTuple::source()]]));
    }

    protected function tearDown(): void
    {
        Fs::removeRecursively($this->root);
    }

    #[Test]
    public function itReadsTheRefusalPublishersKeysRatherThanAFrozenEnvelope(): void
    {
        $source = '<?php class Presenter { function writeEnvelope() { return json_encode(["error" => "refused", "exit_code" => 3, "position" => null, "source" => [["kind" => "file"]]]); } }';
        self::assertSame(['error', 'exit_code', 'position', 'source'], CaseOutcomeCheck::deriveRefusalFields($source));
        self::assertSame(['error', 'exit_code', 'source'], CaseOutcomeCheck::deriveRefusalFields(str_replace('"position" => null, ', '', $source)));
    }

    #[Test]
    #[TestWith([true])]
    #[TestWith([false])]
    public function itCanonicalizesAnEmptyDeltaIndexOnlyForACompleteGreenMeasurement(bool $green): void
    {
        $report = new GateReport();
        $check = $this->check($report, []);
        $path = $this->root . '/finding-gate/' . DeclaredDelta::INDEX;
        $header = Fs::read($path);
        $commented = $header . "# planted: an ignored comment\n";
        Fs::write($path, $commented);
        if (!$green) {
            $report->fail(FailureClass::SURFACE_MISMATCH, 'case:refused|format:json', 'An undeclared publication remains.');
        }
        $check->startDeriving();

        self::assertSame($green ? [DeclaredDelta::INDEX] : [], $check->rewriteDerived());
        self::assertSame($green ? $header : $commented, Fs::read($path));
    }

    #[Test]
    public function itDerivesRefusalDeltasOnEveryStructuredRefusalSurface(): void
    {
        foreach (['json', 'sarif', 'gitlab', 'suppressed'] as $format) {
            $key = 'case:refused|format:' . $format;
            $report = new GateReport();
            $check = $this->check($report, [$key]);
            $check->startDeriving();
            $check->checkDifference($key, '{"error":"refused","exit_code":3,"position":null,"source":[]}', '{"error":"refused","exit_code":3,"position":null}');
            self::assertSame([], $report->failureClasses(), $report->render());
            self::assertNotSame([], $check->rewriteDerived());
            $actual = DeclaredDelta::load($this->root . '/finding-gate')->claim($key);
            self::assertIsString($actual);
            self::assertStringContainsString('"source":[]', $actual);
        }
    }

    #[Test]
    public function itDoesNotMisclassifyATupleRefusalAsDeltaOverreach(): void
    {
        $key = 'case:refused|format:json';
        $report = new GateReport();
        $check = $this->check($report, [$key]);
        Fs::write($this->root . '/' . EquivalenceTuple::TRACKED_PATH, "field\tsource\nmessage\tmissing\n");

        $this->expectException(GateError::class);
        $this->expectExceptionMessage('expected "<file>::<method>"');

        $check->checkDifference($key, '{"error":"refused","exit_code":3,"position":null,"source":[]}', '{"error":"refused","exit_code":3,"position":null}');
    }

    #[Test]
    public function itTreatsOnlyARefusingBaselineWithoutRecordsAsDeltaOverreach(): void
    {
        $key = 'case:refused|check:baseline';
        $report = new GateReport();
        $check = $this->check($report, [$key]);
        $check->startDeriving();
        $check->checkDifference($key, '{"error":"refused","exit_code":3,"position":null,"source":[]}', '{"error":"refused","exit_code":3,"position":null}');
        self::assertContains(FailureClass::DELTA_OVERREACH, $report->failureClasses());
        self::assertSame([], $check->rewriteDerived());
    }

    #[Test]
    public function itJudgesMalformedGitLabBytesAsOneWholePublication(): void
    {
        $key = 'case:malformed|format:gitlab';
        $report = new GateReport();
        $this->check($report, [$key])->checkDifference($key, '{"probe":1}', '[]');
        self::assertContains(FailureClass::DELTA_MISMATCH, $report->failureClasses());
    }

    #[Test]
    public function itDoesNotWriteADeltaFromAnIncompleteCapture(): void
    {
        $key = 'case:refused|format:json';
        $report = new GateReport();
        $check = $this->check($report, [$key]);
        $check->startDeriving();
        $check->checkDifference($key, '{"error":"refused","exit_code":3,"position":null,"source":[]}', '{"error":"refused","exit_code":3,"position":null}');
        $report->fail(FailureClass::RUN_FAILED, 'candidate', 'The second capture did not finish.');
        self::assertSame([], $check->rewriteDerived());
        self::assertSame("placeholder\n", DeclaredDelta::load($this->root . '/finding-gate')->claim($key));
    }

    #[Test]
    public function itPassesConfigurationAndAnalysisInputsRelativeToTheMaterializedCase(): void
    {
        $directory = $this->root . '/finding-gate/cases/refused';
        Fs::write($directory . '/case.json', json_encode([
            'id' => 'refused', 'description' => 'A refused input.', 'coverage' => 'auxiliary',
            'paths' => ['src'], 'config' => 'qmx.yaml', 'channels' => [],
            'outcome' => ['kind' => 'refusal', 'exit' => 3],
        ], \JSON_THROW_ON_ERROR));
        Fs::write($directory . '/composer.json', '{}');
        Fs::write($directory . '/qmx.yaml', '{}');
        Fs::write($directory . '/src/Example.php', '<?php');
        Fs::write($this->root . '/bin/qmx', '<?php echo json_encode(["error" => "refused", "exit_code" => 3, "position" => null, "arguments" => array_slice($argv, 1), "cwd" => getcwd()]); exit(3);');
        $corpus = Corpus::load($this->root);
        $run = new TreeRun(
            $this->root,
            $this->root . '/scratch',
            'candidate-1',
            RenameMaps::fromPairs([]),
            false,
            CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($this->root . '/finding-gate')),
            DeclaredStructuralMaps::load($this->root . '/finding-gate'),
        );
        $captured = $run->forCase($corpus->cases[0]);
        $document = json_decode($captured->artifacts['case:refused|format:json'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('src', $document['arguments'][1]);
        $configAt = array_search('-c', $document['arguments'], true);
        self::assertIsInt($configAt);
        self::assertSame('qmx.yaml', $document['arguments'][$configAt + 1]);
        self::assertSame('refused', basename($document['cwd']));
        self::assertSame('{}', Fs::read($document['cwd'] . '/qmx.yaml'));
    }

    #[Test]
    public function itRemovesOnlyTheDeclaredDocumentMemberAndItsOwnLine(): void
    {
        $reference = "{\n    \"scope\": [],\n    \"health\": null\n}\n";
        $candidate = "{\n    \"scope\": [],\n    \"configurationDiagnostics\": [],\n    \"health\": null\n}\n";
        self::assertSame($reference, ReportRecords::edit($candidate, ['["configurationDiagnostics"]' => null], removeWholeLines: true));
        self::assertSame($reference, ReportRecords::edit($reference, ['["configurationDiagnostics"]' => null], removeWholeLines: true));
        self::assertSame(str_replace('"health": null', '"health" : null', $reference), ReportRecords::edit(str_replace('"health": null', '"health" : null', $candidate), ['["configurationDiagnostics"]' => null], removeWholeLines: true));
    }

    /** @param list<string> $keys */
    private function check(GateReport $report, array $keys): DeclaredDeltaCheck
    {
        $rows = [];
        foreach ($keys as $index => $key) {
            $file = 'declared-delta/intention-' . $index . '.diff';
            Fs::write($this->root . '/finding-gate/' . $file, "placeholder\n");
            $rows[] = [$key, $file, 'Publish refusal provenance.'];
        }
        Fs::write($this->root . '/finding-gate/' . DeclaredDelta::INDEX, Tsv::render(DeclaredDelta::COLUMNS, $rows));
        return new DeclaredDeltaCheck(
            Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], $this->root),
            $report,
            DeclaredDelta::load($this->root . '/finding-gate'),
            DeclaredFieldMoves::load($this->root . '/finding-gate'),
            ChannelSplit::of(RenameMaps::fromPairs([])),
        );
    }
}
