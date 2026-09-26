<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use ArrayObject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CaptureCheck;
use QmxFindingGate\CapturePlan;
use QmxFindingGate\ChannelSplit;
use QmxFindingGate\Corpus;
use QmxFindingGate\Declarations;
use QmxFindingGate\DeclaredStructuralMaps;
use QmxFindingGate\DeclaredSurfaces;
use QmxFindingGate\FailureClass;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\GateReport;
use QmxFindingGate\MetricVocabulary;
use QmxFindingGate\Normalization;
use QmxFindingGate\NormalizationCheck;
use QmxFindingGate\NormalizationDeriver;
use QmxFindingGate\NormalizationRule;
use QmxFindingGate\Options;
use QmxFindingGate\RenameMaps;
use QmxFindingGate\RunContext;
use QmxFindingGate\SelfTestCapture;
use QmxFindingGate\SelfTestNormalization;
use QmxFindingGate\SyntheticTree;
use QmxFindingGate\TreeRun;
use WeakReference;

final class CaptureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itSharesLiveCaptureRolesAndReleasesTheirRun(): void
    {
        $root = SyntheticTree::create(SyntheticTree::clean());
        try {
            $maps = RenameMaps::fromPairs([]);
            $run = new RunContext(
                Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root),
                new GateReport(),
                Corpus::load($root),
                $maps,
                ChannelSplit::of($maps),
                MetricVocabulary::none(),
                Normalization::fromRules([]),
                Declarations::load($root),
                $root,
            );
            $check = CaptureCheck::create($run);
            self::assertSame($check, CaptureCheck::create($run));
            $runReference = WeakReference::create($run);
            $checkReference = WeakReference::create($check);
            unset($run);
            self::assertNotNull($runReference->get());
            unset($check);
            gc_collect_cycles();
            self::assertNull($runReference->get());
            self::assertNull($checkReference->get());
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itChecksCaptureThroughPublicCompareAndDerive(): void
    {
        $failures = new ArrayObject();
        $checks = new SelfTestCapture(\dirname(__DIR__, 3), $failures);
        ob_start();
        try {
            $checks->declarations();
            $checks->derivation();
        } finally {
            $output = ob_get_clean();
        }
        self::assertIsString($output);
        self::assertStringContainsString('GREEN', $output);
        self::assertStringContainsString('surface-withdrawal-mismatch', $output);
        self::assertSame([], $failures->getArrayCopy());
    }

    #[Test]
    public function itKeepsTheNormalizationBudgetLocalToEachPublication(): void
    {
        $failures = new ArrayObject();
        (new SelfTestNormalization(\dirname(__DIR__, 3), $failures))->deriver();
        self::assertSame([], $failures->getArrayCopy());
    }

    #[Test]
    public function itRejectsPublicationsMissingFromEitherCandidateRun(): void
    {
        $key = 'case:alpha|stderr:format:summary';
        foreach ([[[$key => 'first'], [], 1], [[], [$key => 'second'], 2]] as [$first, $second, $producedBy]) {
            $report = new GateReport();
            $check = new NormalizationCheck(Options::parse(['gate', '--reference=HEAD'], \dirname(__DIR__, 3)), $report, Normalization::fromRules([]));
            $check->checkDeterminism($first, $second);
            self::assertSame(GateReport::EXIT_RED, $report->exitCode());
            self::assertCount(1, $report->raised());
            self::assertSame(FailureClass::NONDETERMINISM_UNDECLARED, $report->raised()[0]['class']);
            self::assertSame($key, $report->raised()[0]['scope']);
            self::assertSame('Only run ' . $producedBy . ' of the candidate tree produced this surface at all.', $report->raised()[0]['detail']);
        }
    }

    #[Test]
    public function itComparesCandidateBytesAfterNormalizationAndKeepsEqualPublicationsGreen(): void
    {
        $key = 'case:alpha|format:health';
        foreach ([['first', 'second', GateReport::EXIT_RED], ['same', 'same', GateReport::EXIT_GREEN]] as [$first, $second, $exit]) {
            $report = new GateReport();
            $check = new NormalizationCheck(Options::parse(['gate', '--reference=HEAD'], \dirname(__DIR__, 3)), $report, Normalization::fromRules([]));
            $check->checkDeterminism([$key => $first], [$key => $second]);
            self::assertSame($exit, $report->exitCode());
            if ($exit === GateReport::EXIT_RED) {
                self::assertCount(1, $report->raised());
                self::assertSame(FailureClass::NONDETERMINISM_UNDECLARED, $report->raised()[0]['class']);
                self::assertSame($key, $report->raised()[0]['scope']);
                self::assertStringContainsString('- first', $report->render());
                self::assertStringContainsString('+ second', $report->render());
            } else {
                self::assertSame([], $report->raised());
            }
        }
        $report = new GateReport();
        $normalization = Normalization::fromRules([new NormalizationRule('format:health', 'duration', NormalizationRule::KIND_JSON_PATH, 'Measured duration field.')]);
        $check = new NormalizationCheck(Options::parse(['gate', '--reference=HEAD'], \dirname(__DIR__, 3)), $report, $normalization);
        $check->checkDeterminism([$key => '{"duration":1,"value":"kept"}'], [$key => '{"duration":2,"value":"kept"}']);
        self::assertSame(GateReport::EXIT_GREEN, $report->exitCode());
        self::assertSame([], $report->raised());
    }

    #[Test]
    public function itRefusesUnreadableHtmlWithoutItsExactReportedPredecessor(): void
    {
        $key = 'case:alpha|format:html';
        foreach ([null, [FailureClass::REPORT_PAYLOAD_UNREADABLE, 'case:beta|format:html'], [FailureClass::ENV_MISMATCH, $key]] as $predecessor) {
            $report = new GateReport();
            if ($predecessor !== null) {
                $report->fail($predecessor[0], $predecessor[1], 'An unrelated recorded refusal.');
            }
            $check = new NormalizationCheck(Options::parse(['gate', '--reference=HEAD'], \dirname(__DIR__, 3)), $report, Normalization::fromRules([]));
            try {
                $check->checkRun([$key => '<html>no payload</html>'], []);
                self::fail('An unreadable HTML surface had no exact reported predecessor.');
            } catch (GateError $error) {
                self::assertStringContainsString('carries no `report-data` payload', $error->getMessage());
            }
            self::assertCount($predecessor === null ? 0 : 1, $report->raised());
        }
    }

    #[Test]
    public function itKeepsTheExactHtmlRefusalAndJudgesReadableNeighboringArtifacts(): void
    {
        $key = 'case:alpha|format:html';
        $report = new GateReport();
        $report->fail(FailureClass::REPORT_PAYLOAD_UNREADABLE, $key, 'The already reported unreadable publication.');
        $normalization = Normalization::fromRules([new NormalizationRule('format:json', 'violations', NormalizationRule::KIND_JSON_PATH, 'Measured neighboring record deletion.')]);
        $check = new NormalizationCheck(Options::parse(['gate', '--reference=HEAD'], \dirname(__DIR__, 3)), $report, $normalization);
        try {
            $check->checkRun([$key => '<html>no payload</html>', 'case:beta|format:json' => '{"violations":[{"message":"kept"}]}'], []);
        } catch (GateError $error) {
            self::fail('An already reported payload aborted readable neighbors: ' . $error->getMessage());
        }
        self::assertSame(GateReport::EXIT_RED, $report->exitCode());
        self::assertCount(2, $report->raised());
        self::assertSame(FailureClass::REPORT_PAYLOAD_UNREADABLE, $report->raised()[0]['class']);
        self::assertSame($key, $report->raised()[0]['scope']);
        self::assertSame('The already reported unreadable publication.', $report->raised()[0]['detail']);
        self::assertSame(FailureClass::NORMALIZATION_OVERREACH, $report->raised()[1]['class']);
        self::assertSame('candidate / case:beta|format:json', $report->raised()[1]['scope']);
    }

    #[Test]
    public function itNormalizesOnlyTheGuardedOutputDestinationField(): void
    {
        $rules = NormalizationDeriver::derive([
            ['case:alpha|stderr:check:output' => "Report written to /one.json\nKept diagnostic\n"],
            ['case:alpha|stderr:check:output' => "Report written to /two.json\nKept diagnostic\n"],
        ]);
        self::assertCount(1, $rules);
        self::assertSame('stderr:check:output', $rules[0]->surface);
        $normalization = Normalization::fromRules($rules);
        self::assertSame("Report written to <normalized>\nKept diagnostic\n", $normalization->normalize('stderr:check:output', "Report written to /one.json\nKept diagnostic\n"));
        self::assertSame("Report written to /one.json\n", $normalization->normalize('stderr', "Report written to /one.json\n"));
        self::assertSame("Report moved to /one.json\n", $normalization->normalize('stderr:check:output', "Report moved to /one.json\n"));
        self::assertSame('stderr', \QmxFindingGate\Surfaces::surfaceClass('case:alpha|stderr:rules'));
        self::assertSame('stderr:check:output', \QmxFindingGate\Surfaces::surfaceClass('case:alpha|stderr:check:output'));
        try {
            NormalizationDeriver::derive([
                ['case:alpha|stderr:check:output' => "Report written to /one.json\nOther /one.json\n"],
                ['case:alpha|stderr:check:output' => "Report written to /two.json\nOther /two.json\n"],
            ]);
            self::fail('A neighboring unguarded diagnostic field was derived.');
        } catch (GateError $error) {
            self::assertStringContainsString('outside its single guarded destination field', $error->getMessage());
        }
    }

    #[Test]
    public function itCapturesEveryPlannedPublicationAndKeepsEmptyStderr(): void
    {
        $root = SyntheticTree::create(SyntheticTree::captureFixture());
        $temporary = Fs::temporaryDirectory('capture-test-');
        try {
            $corpus = Corpus::load($root);
            $plan = CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($root . '/finding-gate'));
            $maps = RenameMaps::load($root . '/finding-gate/maps', MetricVocabulary::ofTree($root));
            $run = new TreeRun($root, $temporary, 'candidate', $maps, false, $plan, DeclaredStructuralMaps::load($root . '/finding-gate'));
            $artifacts = $run->rules() + $run->forCase($corpus->cases[0]);
            foreach ($plan->invocations() as $descriptor) {
                $key = $descriptor['scope'] . '|' . $descriptor['surface'];
                foreach ($plan->artifactsOf($key) as $artifact) {
                    self::assertArrayHasKey($artifact, $artifacts);
                }
                if ($descriptor['outputFileKind'] !== null) {
                    self::assertNotSame('', $artifacts[$descriptor['scope'] . '|' . $descriptor['outputFileKind']]);
                }
            }
            self::assertSame('', $artifacts['case:alpha|stderr:format:json']);
            self::assertSame('1', $artifacts['tree|exit:graph:export']);
            self::assertNotSame($artifacts['case:alpha|debug:layer-assignment:Replay\Alpha'], $artifacts['case:alpha|debug:layer-assignment:Replay\Beta']);
            self::assertSame($artifacts['case:alpha|format:json'], $artifacts['case:alpha|check:output:file']);
            self::assertSame($artifacts['case:alpha|format:json'], $artifacts['case:alpha|check:parallel']);
            self::assertDirectoryDoesNotExist($corpus->cases[0]->directory . '/.qmx-cache');
            self::assertSame([], glob($temporary . '/capture-candidate-cache-*'));
        } finally {
            SyntheticTree::remove($root);
            Fs::removeRecursively($temporary);
        }
    }

    #[Test]
    public function itUsesOnlyTheSupportedArgumentsOfEachProductCommand(): void
    {
        $tree = SyntheticTree::captureFixture();
        foreach (['directives', 'graph:export', 'rules', 'debug:layer-assignment:Replay\\Alpha', 'check:parallel', 'check:baseline-source', 'baseline-file'] as $surface) {
            $tree['candidateAnswers']['case:alpha|' . $surface] = ['env' => true];
        }
        $root = SyntheticTree::create($tree);
        $temporary = Fs::temporaryDirectory('capture-argv-');
        try {
            $corpus = Corpus::load($root);
            $case = $corpus->cases[0];
            $maps = RenameMaps::load($root . '/finding-gate/maps', MetricVocabulary::ofTree($root));
            $run = new TreeRun($root, $temporary, 'candidate', $maps, false, CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($root . '/finding-gate')), DeclaredStructuralMaps::load($root . '/finding-gate'));
            $artifacts = $run->forCase($case);
            $read = static fn(string $surface): array => json_decode($artifacts['case:alpha|' . $surface], true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame(['graph:export', 'src', '--no-ansi'], $read('graph:export')['argv']);
            self::assertSame(['rules', '--no-ansi'], $read('rules')['argv']);
            $mainDirectory = $read('directives')['cwd'];
            self::assertStringStartsWith($temporary . '/inputs-candidate-', $mainDirectory);
            self::assertNotSame($case->directory, $mainDirectory);
            self::assertFileExists($mainDirectory . '/qmx.yaml');
            self::assertSame(['directives', 'src', '--no-ansi', '-c', $mainDirectory . '/qmx.yaml', '--format=json'], $read('directives')['argv']);
            self::assertSame(['debug:layer-assignment', 'Replay\\Alpha', '-c', $mainDirectory . '/qmx.yaml', '--format=json', '--no-ansi'], $read('debug:layer-assignment:Replay\\Alpha')['argv']);
            self::assertContains('--workers=2', $read('check:parallel')['argv']);
            self::assertNotContains('--workers=0', $read('check:parallel')['argv']);
            self::assertContains('--no-cache', $read('check:parallel')['argv']);
            $baseline = $read('baseline-file');
            self::assertSame('baseline:generate', $baseline['argv'][0]);
            $source = $read('check:baseline-source');
            self::assertSame($baseline['cwd'], $source['cwd']);
            self::assertStringStartsWith($temporary . '/inputs-candidate-', $source['cwd']);
            self::assertNotSame($mainDirectory, $source['cwd']);
            self::assertSame(['check', 'src', '--workers=0', '--no-cache', '--no-ansi', '--fail-on=error', '-c', $source['cwd'] . '/qmx.yaml', '-f', 'json'], $source['argv']);
            self::assertNotSame($case->directory, $baseline['cwd']);
            self::assertFileExists($baseline['cwd'] . '/src/Alpha.php');
            self::assertStringStartsWith($temporary . '/capture-candidate-cache-', $baseline['cache']);
            self::assertStringNotContainsString($case->directory, $baseline['cache']);
            self::assertDirectoryDoesNotExist($baseline['cache']);
        } finally {
            SyntheticTree::remove($root);
            Fs::removeRecursively($temporary);
        }
    }

    #[Test]
    public function itSkipsIntroducedInvocationsOnTheReferenceSide(): void
    {
        $tree = SyntheticTree::clean();
        $tree['candidateDeclarations'][DeclaredSurfaces::INDEX] = "change\tsurface\tfile\treason\nintroduced\tformat:health\t-\tnew publication\n";
        $root = SyntheticTree::create($tree);
        $temporary = Fs::temporaryDirectory('capture-sides-');
        try {
            $corpus = Corpus::load($root);
            $plan = CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($root . '/finding-gate'));
            $maps = RenameMaps::load($root . '/finding-gate/maps', MetricVocabulary::ofTree($root));
            $candidate = (new TreeRun($root, $temporary, 'candidate', $maps, false, $plan, DeclaredStructuralMaps::load($root . '/finding-gate')))->forCase($corpus->cases[0]);
            $reference = (new TreeRun($root, $temporary, 'reference', $maps, true, $plan, DeclaredStructuralMaps::load($root . '/finding-gate')))->forCase($corpus->cases[0]);
            foreach ($plan->artifactsOf('case:alpha|format:health') as $artifact) {
                self::assertArrayHasKey($artifact, $candidate);
                self::assertArrayNotHasKey($artifact, $reference);
            }
            self::assertArrayHasKey('case:alpha|format:json', $reference);
        } finally {
            SyntheticTree::remove($root);
            Fs::removeRecursively($temporary);
        }
    }

    #[Test]
    public function itRemovesBothCacheLocationsWhenTheCacheProofThrows(): void
    {
        $tree = SyntheticTree::captureFixture();
        $tree['candidateAnswers']['case:alpha|baseline-file'] = ['env' => true, 'cache' => false];
        $root = SyntheticTree::create($tree);
        $temporary = Fs::temporaryDirectory('capture-failure-test-');
        try {
            $corpus = Corpus::load($root);
            $maps = RenameMaps::load($root . '/finding-gate/maps', MetricVocabulary::ofTree($root));
            $run = new TreeRun($root, $temporary, 'candidate', $maps, false, CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($root . '/finding-gate')), DeclaredStructuralMaps::load($root . '/finding-gate'));
            try {
                $run->forCase($corpus->cases[0]);
                self::fail('An empty cache directory established no parser cache record.');
            } catch (GateError $error) {
                self::assertStringContainsString('wrote no cache record', $error->getMessage());
            }
            self::assertDirectoryDoesNotExist($corpus->cases[0]->directory . '/.qmx-cache');
            self::assertSame([], glob($temporary . '/capture-candidate-cache-*'));
        } finally {
            SyntheticTree::remove($root);
            Fs::removeRecursively($temporary);
        }
    }
}
