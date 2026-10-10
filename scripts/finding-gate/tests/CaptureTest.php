<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use ArrayObject;
use FilesystemIterator;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CaptureCheck;
use QmxFindingGate\CapturePlan;
use QmxFindingGate\ChannelSplit;
use QmxFindingGate\Corpus;
use QmxFindingGate\Declarations;
use QmxFindingGate\DeclaredStructuralMaps;
use QmxFindingGate\DeclaredSurfaces;
use QmxFindingGate\DeclaredValues;
use QmxFindingGate\FailureClass;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\GateModes;
use QmxFindingGate\GateReport;
use QmxFindingGate\MetricVocabulary;
use QmxFindingGate\Normalization;
use QmxFindingGate\NormalizationCheck;
use QmxFindingGate\NormalizationDeriver;
use QmxFindingGate\NormalizationRule;
use QmxFindingGate\Options;
use QmxFindingGate\RenameMaps;
use QmxFindingGate\RunContext;
use QmxFindingGate\SelfTestNormalization;
use QmxFindingGate\SelfTestOutcomes;
use QmxFindingGate\SurfacePair;
use QmxFindingGate\SyntheticTree;
use QmxFindingGate\TreeRun;
use QmxFindingGate\Tsv;
use QmxFindingGate\ValueCheck;
use QmxFindingGate\ValueStage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use WeakReference;

final class CaptureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itCapturesDetailedTextThroughTheExistingTextFormat(): void
    {
        $tree = SyntheticTree::clean();
        $tree['candidateAnswers']['case:alpha|format:text-detail'] = ['env' => true];
        $root = SyntheticTree::create($tree);
        $temporary = Fs::temporaryDirectory('detailed-text-capture-test-');
        try {
            $corpus = Corpus::load($root);
            $plan = CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($root . '/finding-gate'));
            $capture = (new TreeRun($root, $temporary, 'candidate', RenameMaps::fromPairs([]), false, $plan, DeclaredStructuralMaps::load($root . '/finding-gate')))->forCase($corpus->cases[0]);
            $document = json_decode($capture->artifacts['case:alpha|format:text-detail'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($document);
            self::assertSame(['-f', 'text', '--detail=all'], \array_slice($document['argv'], -3));
            self::assertSame(0, DeclaredSurfaces::load($root . '/finding-gate')->count());
        } finally {
            SyntheticTree::remove($root);
            Fs::removeRecursively($temporary);
        }
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
    public function itKeepsTheNormalizationBudgetLocalToEachPublication(): void
    {
        $failures = new ArrayObject();
        (new SelfTestNormalization(\dirname(__DIR__, 3), $failures))->deriver();
        self::assertSame([], $failures->getArrayCopy());
    }

    #[Test]
    #[Group('finding-gate-e2e')]
    public function itRefusesPrivateEvidenceThatChangesOnlyOnTheSecondCandidatePass(): void
    {
        $root = SyntheticTree::create(SyntheticTree::clean());
        try {
            $binary = $root . '/bin/qmx';
            $source = Fs::read($binary);
            $anchor = '$stderr = (string) ($answer[\'stderr\'] ?? \'\');';
            $fault = $anchor . "\n" . <<<'PHP'
                if ($capture === 'ranked') {
                    $marker = $tree . '/replay/second-private-' . md5($key);
                    $seen = is_file($marker) ? (int) file_get_contents($marker) : 0;
                    file_put_contents($marker, (string) ($seen + 1));
                    if ($seen === 1) {
                        $stderr = 'A warning only in the second candidate ranking capture.';
                    }
                }
                PHP;
            self::assertSame(1, substr_count($source, $anchor));
            Fs::write($binary, str_replace($anchor, $fault, $source));
            $report = new GateReport();
            ob_start();
            try {
                $exit = GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report);
            } finally {
                ob_end_clean();
            }
            self::assertSame(GateReport::EXIT_RED, $exit, $report->render());
            self::assertContains(FailureClass::RANKING_PROJECTION_MISMATCH, $report->failureClasses(), $report->render());
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    #[Group('finding-gate-e2e')]
    public function itDerivesNormalizationOnlyAfterValidatingFullPhysicalAndRefusalCaptures(): void
    {
        foreach (['healthy', 'physical-count', 'whole-private-slot'] as $fault) {
            if ($fault === 'whole-private-slot') {
                $tree = SelfTestOutcomes::fixture();
                $tree['candidateAnswers']['case:alpha|format:json'] = [
                    ...$tree['answers']['case:alpha|format:json'],
                    'ranked' => ['exit' => 2],
                ];
            } else {
                $tree = SyntheticTree::clean();
                $tree['findings']['alpha'][] = SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\\Beta::run@src/Beta.php');
                $tree['truncated'] = ['alpha'];
                if ($fault === 'physical-count') {
                    $answer = SyntheticTree::caseAnswers('alpha', $tree['findings']['alpha'], true, [])['case:alpha|format:json'];
                    $physical = json_decode($answer['physical']['stdout'] ?? throw new GateError('The full synthetic physical publication is missing.'), true, 512, \JSON_THROW_ON_ERROR);
                    $physical['violationsMeta']['byRule']['replay.alpha'] = 1;
                    $tree['candidateAnswers']['case:alpha|format:json'] = ['physical' => ['stdout' => json_encode($physical, \JSON_THROW_ON_ERROR)]];
                }
            }
            $root = SyntheticTree::create($tree);
            try {
                $path = $root . '/finding-gate/normalization.tsv';
                $before = Fs::read($path);
                $report = new GateReport();
                ob_start();
                try {
                    $exit = GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD', '--derive-normalization'], $root), $report);
                } finally {
                    $output = ob_get_clean();
                }
                self::assertIsString($output);
                if ($fault !== 'physical-count') {
                    self::assertSame(GateModes::WROTE, $exit, $output);
                    self::assertSame(GateReport::VERDICT_GREEN, $report->verdict(), $report->render());
                    self::assertNotSame($before, Fs::read($path));
                } else {
                    self::assertSame(GateModes::MEASUREMENT_FAILED, $exit, $output);
                    self::assertSame(GateReport::VERDICT_RED, $report->verdict(), $report->render());
                    self::assertSame($before, Fs::read($path));
                    self::assertContains(FailureClass::RANKING_PROJECTION_MISMATCH, $report->failureClasses());
                }
            } finally {
                SyntheticTree::remove($root);
            }
        }
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
    public function itKeepsUnreadableHtmlAsWholeBytesWithoutAReportedPredecessor(): void
    {
        $key = 'case:alpha|format:html';
        foreach ([null, [FailureClass::SURFACE_MISMATCH, 'case:beta|format:html'], [FailureClass::ENV_MISMATCH, $key]] as $predecessor) {
            $report = new GateReport();
            if ($predecessor !== null) {
                $report->fail($predecessor[0], $predecessor[1], 'An unrelated recorded refusal.');
            }
            $check = new NormalizationCheck(Options::parse(['gate', '--reference=HEAD'], \dirname(__DIR__, 3)), $report, Normalization::fromRules([]));
            $check->checkRun([$key => '<html>no payload</html>'], []);
            self::assertCount($predecessor === null ? 0 : 1, $report->raised());
        }
    }

    #[Test]
    public function itKeepsTheExactHtmlRefusalAndJudgesReadableNeighboringArtifacts(): void
    {
        $key = 'case:alpha|format:html';
        $report = new GateReport();
        $report->fail(FailureClass::SURFACE_MISMATCH, $key, 'The already reported whole publication difference.');
        $normalization = Normalization::fromRules([new NormalizationRule('format:json', 'violations', NormalizationRule::KIND_JSON_PATH, 'Measured neighboring record deletion.')]);
        $check = new NormalizationCheck(Options::parse(['gate', '--reference=HEAD'], \dirname(__DIR__, 3)), $report, $normalization);
        try {
            $check->checkRun([$key => '<html>no payload</html>', 'case:beta|format:json' => '{"violations":[{"message":"kept"}]}'], []);
        } catch (GateError $error) {
            self::fail('An already reported payload aborted readable neighbors: ' . $error->getMessage());
        }
        self::assertSame(GateReport::EXIT_RED, $report->exitCode());
        self::assertCount(2, $report->raised());
        self::assertSame(FailureClass::SURFACE_MISMATCH, $report->raised()[0]['class']);
        self::assertSame($key, $report->raised()[0]['scope']);
        self::assertSame('The already reported whole publication difference.', $report->raised()[0]['detail']);
        self::assertSame(FailureClass::NORMALIZATION_OVERREACH, $report->raised()[1]['class']);
        self::assertSame('candidate / case:beta|format:json', $report->raised()[1]['scope']);
    }

    #[Test]
    public function itNormalizesOnlyDeclaredSarifCaptureUrisWithoutChangingRecordBytes(): void
    {
        $content = self::sarifCapturePublication();
        foreach (['runs.*.originalUriBaseIds.%SRCROOT%.uri', 'runs.0.originalUriBaseIds.%SRCROOT%.uri'] as $locator) {
            $rule = new NormalizationRule('format:sarif', $locator, NormalizationRule::KIND_JSON_PATH, 'The measured isolated capture directory.');
            $normalization = Normalization::fromRules([$rule]);
            $expected = str_replace('file:///capture/first/', Normalization::REDACTED, $content);
            if (str_contains($locator, '*')) {
                $expected = str_replace('file:///capture/second/', Normalization::REDACTED, $expected);
            }
            self::assertSame($expected, $normalization->normalizeCaptureMetadata('format:sarif', $content));
            self::assertSame($content, $normalization->normalizeCaptureMetadata('format:json', $content));
            self::assertSame([$rule], $normalization->activeRules());
            $before = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            $after = json_decode($expected, true, 512, \JSON_THROW_ON_ERROR);
            foreach ($before['runs'] as $index => $run) {
                self::assertCount(3, $run['results']);
                self::assertSame($run['results'], $after['runs'][$index]['results']);
                self::assertSame($run['tool'], $after['runs'][$index]['tool']);
                self::assertSame($run['originalUriBaseIds']['%NEIGHBOR%'], $after['runs'][$index]['originalUriBaseIds']['%NEIGHBOR%']);
            }
            $report = new GateReport();
            $check = new NormalizationCheck(Options::parse(['gate', '--reference=HEAD'], \dirname(__DIR__, 3)), $report, $normalization);
            $check->checkRun(['case:alpha|format:sarif' => $content], ['case:alpha|format:sarif' => $content]);
            self::assertSame([], $report->raised());
        }
        self::assertSame($content, Normalization::fromRules([])->normalizeCaptureMetadata('format:sarif', $content));
    }

    #[Test]
    public function itKeepsSarifResultsAndNeighboringMetadataProtectedFromNormalization(): void
    {
        $content = self::sarifCapturePublication();
        foreach ([
            ['format:sarif', 'runs', NormalizationRule::KIND_JSON_PATH],
            ['format:sarif', 'runs.0', NormalizationRule::KIND_JSON_PATH],
            ['format:sarif', 'runs.0.tool.driver', NormalizationRule::KIND_JSON_PATH],
            ['format:sarif', 'runs.0.results.0.message.text', NormalizationRule::KIND_JSON_PATH],
            ['format:sarif', 'runs.0.results.0.locations.0.physicalLocation.artifactLocation.uri', NormalizationRule::KIND_JSON_PATH],
            ['format:sarif', 'runs.0.originalUriBaseIds.%NEIGHBOR%.uri', NormalizationRule::KIND_JSON_PATH],
            ['format:sarif', 'runs.*.originalUriBaseIds.*.uri', NormalizationRule::KIND_JSON_PATH],
            ['format:sarif', 'runs.1.originalUriBaseIds.%SRCROOT%.uri', NormalizationRule::KIND_JSON_PATH],
            ['format:sarif', 'runs.0.originalUriBaseIds.%SRCROOT%.uri', NormalizationRule::KIND_HTML_REPORT_DATA_PATH],
            ['format:json', 'runs.0.originalUriBaseIds.%SRCROOT%.uri', NormalizationRule::KIND_JSON_PATH],
        ] as [$surface, $locator, $kind]) {
            $normalization = Normalization::fromRules([new NormalizationRule($surface, $locator, $kind, 'A neighboring published field stays protected.')]);
            self::assertSame($content, $normalization->normalizeCaptureMetadata($surface, $content));
            $report = new GateReport();
            $check = new NormalizationCheck(Options::parse(['gate', '--reference=HEAD'], \dirname(__DIR__, 3)), $report, $normalization);
            $check->checkRun(['case:alpha|' . $surface => $content], []);
            self::assertCount(1, $report->raised(), $locator);
            self::assertSame(FailureClass::NORMALIZATION_OVERREACH, $report->raised()[0]['class']);
            self::assertSame('candidate / case:alpha|' . $surface, $report->raised()[0]['scope']);
        }
        $normalization = Normalization::fromRules([
            new NormalizationRule('format:sarif', 'runs.*.originalUriBaseIds.%SRCROOT%.uri', NormalizationRule::KIND_JSON_PATH, 'The measured capture directory.'),
            new NormalizationRule('format:sarif', 'runs.0.results.0.message.text', NormalizationRule::KIND_JSON_PATH, 'A neighboring result cannot leave comparison.'),
        ]);
        $report = new GateReport();
        (new NormalizationCheck(Options::parse(['gate', '--reference=HEAD'], \dirname(__DIR__, 3)), $report, $normalization))->checkRun(['case:alpha|format:sarif' => $content], []);
        self::assertSame([FailureClass::NORMALIZATION_OVERREACH], $report->failureClasses());
    }

    #[Test]
    public function itKeepsMalformedSarifUriShapesOutsideCaptureMetadataNormalization(): void
    {
        foreach ([null, 42, ['uri' => 'file:///capture/first/'], ['file:///capture/first/']] as $uri) {
            $document = json_decode(self::sarifCapturePublication(), true, 512, \JSON_THROW_ON_ERROR);
            $document['runs'][0]['originalUriBaseIds']['%SRCROOT%']['uri'] = $uri;
            $content = json_encode($document, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            $normalization = Normalization::fromRules([new NormalizationRule('format:sarif', 'runs.0.originalUriBaseIds.%SRCROOT%.uri', NormalizationRule::KIND_JSON_PATH, 'Only a string capture URI is excluded.')]);
            self::assertSame($content, $normalization->normalizeCaptureMetadata('format:sarif', $content));
            $report = new GateReport();
            (new NormalizationCheck(Options::parse(['gate', '--reference=HEAD'], \dirname(__DIR__, 3)), $report, $normalization))->checkRun(['case:alpha|format:sarif' => $content], []);
            self::assertSame([FailureClass::NORMALIZATION_OVERREACH], $report->failureClasses());
        }
    }

    #[Test]
    public function itKeepsWholeGraphExitOutsideRecordValueDeclarations(): void
    {
        [$root, $temporary, $artifacts] = self::stagePublicationFixture();
        try {
            foreach ([false, true] as $deriving) {
                self::declareCaptureExits($root, $deriving ? [] : [['exit', 'graph:export', 'tree|graph:export', '1', '0']]);
                $run = self::captureContext($root, $temporary);
                $values = ValueCheck::create($run);
                if ($deriving) {
                    $values->startDeriving();
                }
                $pair = new SurfacePair('tree|exit:graph:export', 'exit:graph:export', '0', '1');
                ValueStage::create($run)->applyStage($pair);
                self::assertSame('0', $pair->candidate);
                $candidate = $artifacts;
                $candidate['tree|exit:graph:export'] = '0';
                CaptureCheck::create($run)->checkRun($candidate, $artifacts);
                self::assertSame([FailureClass::SURFACE_MISMATCH], $run->report->failureClasses());
                self::assertSame(['candidate / tree|graph:export', 'candidate / tree|graph:export'], array_column($run->report->raised(), 'scope'));
                self::assertNull($values->referenceExitFor('graph:export', 'tree|graph:export', '0'));
            }
        } finally {
            SyntheticTree::remove($root);
            Fs::removeRecursively($temporary);
        }
    }

    #[Test]
    public function itRefusesIntentOnlyAndInexactCaptureExitMeasurements(): void
    {
        [$root, $temporary, $artifacts] = self::stagePublicationFixture();
        try {
            foreach (['intent-only', 'unlicensed', 'wrong-invocation', 'wrong-command', 'wrong-derived', 'incomplete-multiset', 'candidate-mismatch'] as $fault) {
                $command = $fault === 'wrong-command' ? 'rules' : 'graph:export';
                $invocation = $fault === 'wrong-invocation' ? 'case:alpha|graph:export' : 'tree|graph:export';
                $from = $fault === 'wrong-derived' ? '2' : ($fault === 'wrong-invocation' ? '0' : '1');
                $to = $fault === 'candidate-mismatch' ? '2' : ($fault === 'wrong-invocation' ? '1' : '0');
                $rows = [['exit', $command, $invocation, $from, $to]];
                if ($fault === 'incomplete-multiset') {
                    $rows[] = ['exit', 'graph:export', 'case:alpha|graph:export', '0', '1'];
                }
                self::declareCaptureExits($root, $fault === 'intent-only' || $fault === 'unlicensed' ? [] : $rows, $fault === 'unlicensed' ? [] : [$command]);
                $run = self::captureContext($root, $temporary);
                $values = ValueCheck::create($run);
                if ($fault !== 'intent-only' && $fault !== 'unlicensed') {
                    self::assertTrue($values->measure('exit', $command, $invocation, '*', $fault === 'wrong-invocation' ? 0 : 1, (int) $to));
                }
                $candidate = $artifacts;
                $candidate['tree|exit:graph:export'] = '0';
                if ($fault === 'wrong-invocation') {
                    $candidate['case:alpha|exit:graph:export'] = '1';
                }
                CaptureCheck::create($run)->checkRun($candidate, $artifacts);
                self::assertSame([FailureClass::SURFACE_MISMATCH], $run->report->failureClasses(), $fault);
                $expectedScopes = ['candidate / tree|graph:export', 'candidate / tree|graph:export'];
                if ($fault === 'wrong-invocation') {
                    $expectedScopes[] = 'candidate / case:alpha|graph:export';
                }
                self::assertSame($expectedScopes, array_column($run->report->raised(), 'scope'), $fault);
            }
        } finally {
            SyntheticTree::remove($root);
            Fs::removeRecursively($temporary);
        }
    }

    #[Test]
    public function itKeepsRawUnknownAndIncompleteCapturePopulationsRefused(): void
    {
        [$root, $temporary, $artifacts] = self::stagePublicationFixture();
        try {
            foreach (['unknown-replay', 'empty-exit', 'missing-exit', 'empty-publication', 'reference-exit', 'reference-unknown', 'reference-missing', 'reference-empty-publication'] as $fault) {
                $to = $fault === 'unknown-replay' ? '70' : '0';
                self::declareCaptureExits($root, [['exit', 'graph:export', 'tree|graph:export', '1', $to]]);
                $run = self::captureContext($root, $temporary);
                $values = ValueCheck::create($run);
                self::assertTrue($values->measure('exit', 'graph:export', 'tree|graph:export', '*', 1, (int) $to));
                $candidate = $artifacts;
                $candidate['tree|exit:graph:export'] = $to;
                $reference = $artifacts;
                $expectedScope = 'candidate / tree|graph:export';
                if ($fault === 'empty-exit') {
                    $candidate['tree|exit:graph:export'] = '';
                } elseif ($fault === 'missing-exit') {
                    unset($candidate['tree|exit:graph:export']);
                } elseif ($fault === 'empty-publication') {
                    $candidate['case:alpha|graph:export'] = '';
                    $expectedScope = 'candidate / case:alpha|graph:export';
                } elseif ($fault === 'reference-empty-publication') {
                    $reference['case:alpha|graph:export'] = '';
                    $expectedScope = 'reference / case:alpha|graph:export';
                } elseif (str_starts_with($fault, 'reference-')) {
                    $reference['tree|exit:graph:export'] = $fault === 'reference-unknown' ? '70' : '0';
                    if ($fault === 'reference-missing') {
                        unset($reference['tree|exit:graph:export']);
                    }
                    $expectedScope = 'reference / tree|graph:export';
                }
                CaptureCheck::create($run)->checkRun($candidate, $reference);
                self::assertSame([FailureClass::SURFACE_MISMATCH], $run->report->failureClasses(), $fault);
                self::assertContains($expectedScope, array_column($run->report->raised(), 'scope'), $fault);
                $expectedCount = match ($fault) {
                    'unknown-replay', 'empty-publication', 'reference-empty-publication', 'missing-exit' => 3,
                    'reference-missing', 'reference-unknown' => 5,
                    'reference-exit' => 4,
                    default => 2,
                };
                self::assertCount($expectedCount, $run->report->raised(), $fault);
                if ($fault === 'missing-exit' || $fault === 'reference-missing') {
                    self::assertContains(($fault === 'missing-exit' ? 'candidate' : 'reference') . ' / tree|exit:graph:export', array_column($run->report->raised(), 'scope'));
                }
                if ($fault === 'unknown-replay') {
                    self::assertContains('An unknown replay invocation cannot establish successful population.', array_column($run->report->raised(), 'detail'));
                }
                self::assertSame([], array_filter($run->report->raised(), static fn(array $failure): bool => $failure['class'] !== FailureClass::SURFACE_MISMATCH));
            }
        } finally {
            SyntheticTree::remove($root);
            Fs::removeRecursively($temporary);
        }
    }

    #[Test]
    #[Group('finding-gate-e2e')]
    public function itRefusesAMissingReplayKeyThroughThePublicGateWithoutWriting(): void
    {
        $root = SyntheticTree::create(SyntheticTree::clean());
        try {
            $path = $root . '/replay/answers.json';
            $answers = json_decode(Fs::read($path), true, 512, \JSON_THROW_ON_ERROR);
            unset($answers['case:alpha|format:text']);
            Fs::write($path, json_encode($answers, \JSON_THROW_ON_ERROR));
            $snapshot = static function () use ($root): array {
                $bytes = [];
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/finding-gate', FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile()) {
                        $bytes[$file->getPathname()] = Fs::read($file->getPathname());
                    }
                }
                ksort($bytes);
                return $bytes;
            };
            $before = $snapshot();
            $report = new GateReport();
            ob_start();
            try {
                $exit = GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report);
            } finally {
                ob_end_clean();
            }
            self::assertSame(GateReport::EXIT_RED, $exit, $report->render());
            self::assertContains(FailureClass::SURFACE_MISMATCH, $report->failureClasses(), $report->render());
            self::assertStringContainsString('unknown replay invocation', strtolower($report->render()));
            self::assertSame($before, $snapshot());
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itAcceptsObservableDirectivesAndTheirExactMeasuredExitTransition(): void
    {
        [$root, $temporary, $artifacts] = self::stagePublicationFixture();
        try {
            $directives = json_decode($artifacts['case:alpha|directives'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertNotEmpty($directives['directives']);
            $directives['exit_code'] = 2;
            $reference = $artifacts;
            $reference['case:alpha|directives'] = json_encode($directives, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            $reference['case:alpha|exit:directives'] = '2';
            $reference['case:alpha|exit:format:json'] = '2';
            self::declareCaptureExits($root, [], []);
            $run = self::captureContext($root, $temporary);
            CaptureCheck::create($run)->checkRun($reference, $reference);
            self::assertSame([], $run->report->raised());

            self::declareCaptureExits($root, [['exit', 'directives', 'case:alpha|directives', '2', '5']], ['directives']);
            $run = self::captureContext($root, $temporary);
            $values = ValueCheck::create($run);
            $run->publicationForms->supply('candidate', $reference);
            $run->publicationForms->supply('reference', $reference);
            $pair = new SurfacePair('case:alpha|exit:directives', 'exit:directives', '5', '2');
            ValueStage::create($run)->applyStage($pair);
            self::assertSame('2', $pair->candidate);
            $candidate = $reference;
            $candidate['case:alpha|exit:directives'] = '5';
            $directives['exit_code'] = 5;
            $candidate['case:alpha|directives'] = json_encode($directives, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            CaptureCheck::create($run)->checkRun($candidate, $reference);
            $values->checkRun($candidate, $reference);
            self::assertSame('2', $values->referenceExitFor('directives', 'case:alpha|directives', '5'));
            self::assertSame([], $run->report->raised());
        } finally {
            SyntheticTree::remove($root);
            Fs::removeRecursively($temporary);
        }
    }

    #[Test]
    public function itRefusesIncompleteOrUnrecognizedDirectivesAndNeighboringCommandExits(): void
    {
        [$root, $temporary, $artifacts] = self::stagePublicationFixture();
        try {
            foreach ([['directives', '1'], ['directives', '4'], ['graph:export', '2'], ['rules', '2']] as [$surface, $exit]) {
                self::declareCaptureExits($root, [], []);
                $run = self::captureContext($root, $temporary);
                $populated = $artifacts;
                self::assertNotSame('', $populated['case:alpha|' . $surface]);
                $populated['case:alpha|exit:' . $surface] = $exit;
                if ($surface === 'directives') {
                    $payload = json_decode($populated['case:alpha|directives'], true, 512, \JSON_THROW_ON_ERROR);
                    self::assertNotEmpty($payload['directives']);
                    $payload['exit_code'] = (int) $exit;
                    $populated['case:alpha|directives'] = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
                }
                CaptureCheck::create($run)->checkRun($populated, $populated);
                self::assertSame([FailureClass::SURFACE_MISMATCH], $run->report->failureClasses());
                self::assertCount(2, $run->report->raised(), $surface . '/' . $exit);
                self::assertSame(['candidate / case:alpha|' . $surface, 'reference / case:alpha|' . $surface], array_column($run->report->raised(), 'scope'));
                foreach ($run->report->raised() as $failure) {
                    self::assertSame('The process outcome cannot establish successful population for this command.', $failure['detail']);
                }
            }
        } finally {
            SyntheticTree::remove($root);
            Fs::removeRecursively($temporary);
        }
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
            self::assertSame('The stderr diagnostic changed outside its guarded clock and output destination fields.', $error->getMessage());
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
            $artifacts = $run->rules() + $run->forCase($corpus->cases[0])->artifacts;
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
    public function itCapturesCompleteAuthorityWithoutChangingSemanticArguments(): void
    {
        $tree = SyntheticTree::clean();
        $tree['findings']['alpha'] = [];
        for ($index = 0; $index < 12; ++$index) {
            $tree['findings']['alpha'][] = SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\\Alpha::run' . $index . '@src/Alpha.php');
        }
        $definition = ['id' => 'alpha', 'description' => 'A capped publication.', 'paths' => ['src'], 'config' => 'qmx.yaml', 'channels' => ['replay.alpha@callable'],
            'args' => ['--detail=1', '--format-opt=violations=2', '--format-opt=top=7', '--rule-opt=complexity.ccn:threshold=99', '--top=0']];
        $tree['declarations']['cases/alpha/case.json'] = json_encode($definition, \JSON_THROW_ON_ERROR);
        $tree['candidateAnswers']['case:alpha|format:json'] = ['env' => true];
        $root = SyntheticTree::create($tree);
        $temporary = Fs::temporaryDirectory('complete-capture-test-');
        try {
            $corpus = Corpus::load($root);
            $plan = CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($root . '/finding-gate'));
            $capture = (new TreeRun($root, $temporary, 'candidate', RenameMaps::fromPairs([]), false, $plan, DeclaredStructuralMaps::load($root . '/finding-gate')))->forCase($corpus->cases[0]);
            self::assertSame(['case:alpha|format:json'], array_keys($capture->rankings));
            $source = json_decode($capture->artifacts['case:alpha|format:json'], true, 512, \JSON_THROW_ON_ERROR);
            $ranked = json_decode($capture->rankings['case:alpha|format:json']['ranked']['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            $physicalCapture = $capture->rankings['case:alpha|format:json']['physical'];
            self::assertNotNull($physicalCapture);
            $physical = json_decode($physicalCapture['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertCount(2, $source['violations']);
            self::assertSame([], $source['topIssues']);
            self::assertSame($source['violations'], $ranked['violations']);
            self::assertCount(12, $ranked['topIssues']);
            self::assertCount(12, $physical['violations']);
            self::assertFalse($physical['violationsMeta']['truncated']);
            self::assertSame($ranked['topIssues'], $physical['topIssues']);
            self::assertSame([...$source['argv'], '--top=13'], $ranked['argv']);
            self::assertSame($source['cwd'], $ranked['cwd']);
            self::assertSame($source['cwd'], $physical['cwd']);
            self::assertNotContains('--detail=1', $physical['argv']);
            self::assertNotContains('--format-opt=violations=2', $physical['argv']);
            foreach (['--format-opt=top=7', '--rule-opt=complexity.ccn:threshold=99', '--detail=all', '--format-opt=violations=all'] as $argument) {
                self::assertContains($argument, $physical['argv']);
            }
            self::assertSame('--top=13', $physical['argv'][\count($physical['argv']) - 1]);
            self::assertSame([], glob($temporary . '/capture-candidate-cache-*'));
            $expected = [];
            foreach ($plan->invocations() as $descriptor) {
                if ($descriptor['scope'] === 'case:alpha') {
                    $expected = [...$expected, ...$plan->artifactsOf($descriptor['scope'] . '|' . $descriptor['surface'])];
                }
            }
            $actual = array_keys($capture->artifacts);
            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual);
        } finally {
            Fs::removeRecursively($temporary);
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itUsesItsSourceBaselineAndCapturesRefusalsWithoutInventingAuthority(): void
    {
        $tree = SyntheticTree::captureFixture();
        $tree['candidateAnswers']['case:alpha|check:baseline-source'] = ['env' => true];
        $tree['candidateAnswers']['case:alpha|check:baseline'] = ['env' => true];
        $root = SyntheticTree::create($tree);
        $temporary = Fs::temporaryDirectory('baseline-ranking-capture-');
        try {
            $corpus = Corpus::load($root);
            $plan = CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($root . '/finding-gate'));
            $capture = (new TreeRun($root, $temporary, 'candidate', RenameMaps::fromPairs([]), false, $plan, DeclaredStructuralMaps::load($root . '/finding-gate')))->forCase($corpus->cases[0]);
            self::assertSame(['case:alpha|format:json', 'case:alpha|check:baseline-source', 'case:alpha|check:baseline'], array_keys($capture->rankings));
            foreach (['check:baseline-source', 'check:baseline'] as $view) {
                $key = 'case:alpha|' . $view;
                $source = json_decode($capture->artifacts[$key], true, 512, \JSON_THROW_ON_ERROR);
                $ranked = json_decode($capture->rankings[$key]['ranked']['stdout'], true, 512, \JSON_THROW_ON_ERROR);
                self::assertSame([...$source['argv'], '--top=' . ($source['violationsMeta']['total'] + 1)], $ranked['argv']);
                self::assertSame($source['cwd'], $ranked['cwd']);
                self::assertNull($capture->rankings[$key]['physical']);
            }
            $baseline = json_decode($capture->rankings['case:alpha|check:baseline']['ranked']['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertCount(1, array_filter($baseline['argv'], static fn(string $argument): bool => str_starts_with($argument, '--baseline=')));
        } finally {
            Fs::removeRecursively($temporary);
            SyntheticTree::remove($root);
        }

        foreach (['candidate', 'reference'] as $side) {
            foreach (['missing file', 'empty file'] as $defect) {
                $tree = SyntheticTree::captureFixture();
                $tree['answers']['case:alpha|check:output'] = $defect === 'missing file'
                    ? ['stdout' => '', 'missingFile' => true]
                    : ['stdout' => '', 'file' => ''];
                $root = SyntheticTree::create($tree);
                $temporary = Fs::temporaryDirectory('successful-output-destination-');
                try {
                    $corpus = Corpus::load($root);
                    try {
                        $plan = self::outputIdentityPlan(CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($root . '/finding-gate')), $corpus->cases[0]->id, false);
                        (new TreeRun($root, $temporary, $side, RenameMaps::fromPairs([]), false, $plan, DeclaredStructuralMaps::load($root . '/finding-gate')))->forCase($corpus->cases[0]);
                        self::fail('A successful output publication with a ' . $defect . ' was accepted for ' . $side . '.');
                    } catch (GateError $error) {
                        self::assertSame('The output publication is missing, empty, or does not name exactly the chosen file for case:alpha|check:output.', $error->getMessage());
                    }
                } finally {
                    Fs::removeRecursively($temporary);
                    SyntheticTree::remove($root);
                }
            }

            foreach (['missing file', 'empty file'] as $defect) {
                $tree = SyntheticTree::captureFixture();
                $refusal = '{"error":"Refused input","exit_code":1}';
                $tree['answers']['case:alpha|format:json'] = ['stdout' => $refusal, 'stderr' => 'input refused', 'exit' => 1];
                $tree['answers']['case:alpha|check:output'] = $defect === 'missing file'
                    ? ['stdout' => '', 'stderr' => 'input refused', 'exit' => 1, 'missingFile' => true]
                    : ['stdout' => '', 'stderr' => 'input refused', 'exit' => 1, 'file' => ''];
                $tree['answers']['case:alpha|baseline-file'] = ['stdout' => '', 'stderr' => 'input refused', 'exit' => 1, 'missingFile' => true];
                foreach (['baseline:update', 'baseline:cleanup', 'baseline:rename-channels'] as $command) {
                    $tree['answers']['case:alpha|' . $command] = ['stdout' => 'input refused', 'stderr' => 'input refused', 'exit' => 1, 'missingFile' => true];
                }
                $root = SyntheticTree::create($tree);
                $temporary = Fs::temporaryDirectory('whole-ranking-capture-');
                try {
                    $corpus = Corpus::load($root);
                    $plan = self::outputIdentityPlan(CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($root . '/finding-gate')), $corpus->cases[0]->id, true);
                    $capture = (new TreeRun($root, $temporary, $side, RenameMaps::fromPairs([]), false, $plan, DeclaredStructuralMaps::load($root . '/finding-gate')))->forCase($corpus->cases[0]);
                    self::assertArrayNotHasKey('case:alpha|format:json', $capture->rankings);
                    self::assertArrayNotHasKey('case:alpha|format:json', $capture->baselineEligibility);
                    self::assertSame($refusal, $capture->artifacts['case:alpha|format:json']);
                    self::assertSame('1', $capture->artifacts['case:alpha|exit:format:json']);
                    self::assertSame('input refused', $capture->artifacts['case:alpha|stderr:format:json']);
                    self::assertSame('', $capture->artifacts['case:alpha|check:output']);
                    self::assertSame('1', $capture->artifacts['case:alpha|exit:check:output']);
                    self::assertSame('input refused', $capture->artifacts['case:alpha|stderr:check:output']);
                    self::assertSame('', $capture->artifacts['case:alpha|check:output:file']);
                    self::assertSame('', $capture->artifacts['case:alpha|baseline-file']);
                    foreach (['baseline:update', 'baseline:cleanup', 'baseline:rename-channels'] as $command) {
                        self::assertSame('1', $capture->artifacts['case:alpha|exit:' . $command]);
                        self::assertSame('', $capture->artifacts['case:alpha|' . $command . ':file']);
                    }
                } finally {
                    Fs::removeRecursively($temporary);
                    SyntheticTree::remove($root);
                }
            }
        }
    }

    #[Test]
    public function itRemovesOnlyPresentationCapsFromAttachedAndSeparatedArguments(): void
    {
        $method = new ReflectionMethod(TreeRun::class, 'withoutPresentationCaps');
        $retained = ['check', 'src', '--format-opt', 'top=7', '--rule-opt=complexity.ccn:threshold=99', '-f', 'json'];
        foreach ([['--detail=1', '--format-opt=violations=2'], ['--detail', '1', '--format-opt', 'violations=2'], ['--all', '--format-opt=limit=2'], ['--detail=all', '--format-opt', 'limit=2']] as $caps) {
            self::assertSame($retained, $method->invoke(null, [...$retained, ...$caps]));
        }
    }

    #[Test]
    public function itRefusesUnsizedRankingMetadataRatherThanInventingACompleteCapture(): void
    {
        foreach ([[-1, false], [\PHP_INT_MAX, false], ['1', false], [1, 0]] as [$total, $truncated]) {
            $tree = SyntheticTree::clean();
            $tree['candidateAnswers']['case:alpha|format:json'] = ['stdout' => json_encode(['violations' => $tree['findings']['alpha'], 'violationsMeta' => ['total' => $total, 'shown' => 1, 'truncated' => $truncated]], \JSON_THROW_ON_ERROR)];
            $root = SyntheticTree::create($tree);
            $temporary = Fs::temporaryDirectory('ranking-size-refusal-');
            try {
                $corpus = Corpus::load($root);
                try {
                    (new TreeRun($root, $temporary, 'candidate', RenameMaps::fromPairs([]), false, CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($root . '/finding-gate')), DeclaredStructuralMaps::load($root . '/finding-gate')))->forCase($corpus->cases[0]);
                    self::fail('An unknown or overflowing ranking population was accepted.');
                } catch (GateError $error) {
                    self::assertStringContainsString('requires nonnegative total and boolean truncation metadata', $error->getMessage());
                }
            } finally {
                Fs::removeRecursively($temporary);
                SyntheticTree::remove($root);
            }
        }
    }

    #[Test]
    public function itUsesOnlyTheSupportedArgumentsOfEachProductCommand(): void
    {
        $tree = SyntheticTree::captureFixture();
        $measuredArguments = ['--preset=strict', '--disable-rule=code-smell.debug', '--rule-opt=complexity.ccn:callable.warning=3'];
        $definition = json_decode($tree['declarations']['cases/alpha/case.json'], true, 512, \JSON_THROW_ON_ERROR);
        $definition['args'] = ['--namespace=subtree:Replay', '--show-suppressed', ...$measuredArguments];
        $tree['declarations']['cases/alpha/case.json'] = json_encode($definition, \JSON_THROW_ON_ERROR) . "\n";
        foreach (['directives', 'graph:export', 'rules', 'debug:layer-assignment:Replay\\Alpha', 'check:parallel', 'check:baseline-source', 'baseline-file', 'explain:file:src/Alpha.php', 'check:baseline'] as $surface) {
            $tree['candidateAnswers']['case:alpha|' . $surface] = ['env' => true];
        }
        $root = SyntheticTree::create($tree);
        $temporary = Fs::temporaryDirectory('capture-argv-');
        try {
            $corpus = Corpus::load($root);
            $case = $corpus->cases[0];
            $maps = RenameMaps::load($root . '/finding-gate/maps', MetricVocabulary::ofTree($root));
            $run = new TreeRun($root, $temporary, 'candidate', $maps, false, CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($root . '/finding-gate')), DeclaredStructuralMaps::load($root . '/finding-gate'));
            $artifacts = $run->forCase($case)->artifacts;
            $read = static fn(string $surface): array => json_decode($artifacts['case:alpha|' . $surface], true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame(['graph:export', 'src', '--no-ansi'], $read('graph:export')['argv']);
            self::assertSame(['rules', '--no-ansi'], $read('rules')['argv']);
            $mainDirectory = $read('directives')['cwd'];
            self::assertStringStartsWith($temporary . '/inputs-candidate-', $mainDirectory);
            self::assertNotSame($case->directory, $mainDirectory);
            self::assertFileExists($mainDirectory . '/qmx.yaml');
            self::assertSame(['directives', 'src', '--no-ansi', '-c', 'qmx.yaml', ...$measuredArguments, '--format=json'], $read('directives')['argv']);
            self::assertSame(['debug:layer-assignment', 'Replay\\Alpha', '-c', 'qmx.yaml', '--format=json', '--no-ansi'], $read('debug:layer-assignment:Replay\\Alpha')['argv']);
            self::assertContains('--workers=2', $read('check:parallel')['argv']);
            self::assertNotContains('--workers=0', $read('check:parallel')['argv']);
            self::assertContains('--no-cache', $read('check:parallel')['argv']);
            foreach (['--namespace=subtree:Replay', '--show-suppressed', ...$measuredArguments] as $argument) {
                self::assertContains($argument, $read('check:parallel')['argv']);
                self::assertContains($argument, $read('check:baseline')['argv']);
            }
            self::assertSame(['baseline:explain', 'file:src/Alpha.php', 'src', '--no-ansi', '-c', 'qmx.yaml', ...$measuredArguments], $read('explain:file:src/Alpha.php')['argv']);
            $baseline = $read('baseline-file');
            self::assertSame('baseline:generate', $baseline['argv'][0]);
            self::assertSame(['src', '--no-ansi', '-c', 'qmx.yaml', ...$measuredArguments], \array_slice($baseline['argv'], 2));
            $source = $read('check:baseline-source');
            self::assertSame($baseline['cwd'], $source['cwd']);
            self::assertStringStartsWith($temporary . '/inputs-candidate-', $source['cwd']);
            self::assertNotSame($mainDirectory, $source['cwd']);
            self::assertSame(['check', 'src', '--workers=0', '--no-cache', '--no-ansi', '--fail-on=error', '-c', 'qmx.yaml', ...$measuredArguments, '-f', 'json'], $source['argv']);
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
        $tree['candidateDeclarations'][DeclaredSurfaces::INDEX] = "change\tsurface\tfile\tcases\treason\nintroduced\tformat:health\t-\t*\tnew publication\n";
        $root = SyntheticTree::create($tree);
        $temporary = Fs::temporaryDirectory('capture-sides-');
        try {
            $corpus = Corpus::load($root);
            $plan = CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($root . '/finding-gate'));
            $maps = RenameMaps::load($root . '/finding-gate/maps', MetricVocabulary::ofTree($root));
            $candidate = (new TreeRun($root, $temporary, 'candidate', $maps, false, $plan, DeclaredStructuralMaps::load($root . '/finding-gate')))->forCase($corpus->cases[0])->artifacts;
            $reference = (new TreeRun($root, $temporary, 'reference', $maps, true, $plan, DeclaredStructuralMaps::load($root . '/finding-gate')))->forCase($corpus->cases[0])->artifacts;
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
                $run->forCase($corpus->cases[0])->artifacts;
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
    private static function outputIdentityPlan(CapturePlan $full, string $caseId, bool $refusal): CapturePlan
    {
        $surfaces = $refusal
            ? ['format:json', 'check:baseline-source', 'baseline-file', 'check:output', 'check:baseline', 'baseline:update', 'baseline:cleanup', 'baseline:rename-channels']
            : ['format:json', 'check:output'];
        $descriptors = [];
        $artifacts = [];
        $changes = [];
        foreach ($full->invocations() as $descriptor) {
            if ($descriptor['scope'] !== 'case:' . $caseId || !\in_array($descriptor['surface'], $surfaces, true)) {
                continue;
            }
            $key = $descriptor['scope'] . '|' . $descriptor['surface'];
            $descriptors[$key] = $descriptor;
            foreach ($full->artifactsOf($key) as $artifact) {
                $artifacts[$artifact] = $key;
            }
            $change = $full->changeOf($key);
            if ($change !== null) {
                $changes[$key] = $change;
            }
        }
        // The private constructor bypasses validation, so retain only bindings from the validated plan.
        $reflection = new ReflectionClass(CapturePlan::class);
        $plan = $reflection->newInstanceWithoutConstructor();
        $reflection->getMethod('__construct')->invoke($plan, $descriptors, $artifacts, $changes);

        return $plan;
    }

    private static function sarifCapturePublication(): string
    {
        $tree = SyntheticTree::clean();
        $finding = $tree['findings']['alpha'][0];
        $answer = SyntheticTree::caseAnswers('alpha', [$finding, $finding, $finding], false, [])['case:alpha|format:sarif']['stdout'] ?? null;
        self::assertIsString($answer);
        $document = json_decode($answer, true, 512, \JSON_THROW_ON_ERROR);
        $document['runs'][0]['originalUriBaseIds'] = ['%SRCROOT%' => ['uri' => 'file:///capture/first/'], '%NEIGHBOR%' => ['uri' => 'file:///kept/']];
        $document['runs'][1] = $document['runs'][0];
        $document['runs'][1]['originalUriBaseIds']['%SRCROOT%']['uri'] = 'file:///capture/second/';

        return json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
    }

    private static function captureContext(string $root, string $temporary): RunContext
    {
        $maps = RenameMaps::fromPairs([]);

        return new RunContext(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), new GateReport(), Corpus::load($root), $maps, ChannelSplit::of($maps), MetricVocabulary::none(), Normalization::fromRules([]), Declarations::load($root), $temporary);
    }

    /** @return array{0:string,1:string,2:array<string,string>} */
    private static function stagePublicationFixture(): array
    {
        $root = SyntheticTree::create(SyntheticTree::captureFixture());
        $temporary = Fs::temporaryDirectory('capture-population-test-');
        try {
            $corpus = Corpus::load($root);
            $plan = CapturePlan::forCorpus($corpus, DeclaredSurfaces::load($root . '/finding-gate'));
            $answers = json_decode(Fs::read($root . '/replay/answers.json'), true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($answers)) {
                throw new LogicException('Synthetic answers must be an object.');
            }
            $artifacts = [];
            foreach ($plan->invocations() as $descriptor) {
                $key = $descriptor['scope'] . '|' . $descriptor['surface'];
                $answer = $answers[$key] ?? null;
                if (!\is_array($answer)) {
                    throw new LogicException('No synthetic answer for ' . $key);
                }
                $publication = $descriptor['surface'] === 'baseline-file' ? ($answer['file'] ?? null) : ($answer['stdout'] ?? null);
                if (!\is_string($publication)) {
                    throw new LogicException('No synthetic publication for ' . $key);
                }
                if ($descriptor['surface'] === 'format:summary') {
                    $issues = $answer['summaryIssues'] ?? null;
                    if (!\is_array($issues) || array_filter($issues, static fn(mixed $issue): bool => !\is_string($issue)) !== []) {
                        throw new LogicException('No synthetic summary issues for ' . $key);
                    }
                    $publication = "Analysis complete\n\nTop issues by impact\n" . implode('', $issues);
                }
                $artifacts[$key] = $publication;
                $exit = $descriptor['surface'] === 'baseline-file' ? 'baseline:generate' : $descriptor['surface'];
                $artifacts[$descriptor['scope'] . '|exit:' . $exit] = (string) ($answer['exit'] ?? 0);
                $stderr = $answer['stderr'] ?? '';
                if ($descriptor['surface'] === 'check:output') {
                    $stderr = str_replace('{{output}}', $temporary . '/output.json', $stderr);
                }
                $artifacts[$descriptor['scope'] . '|stderr:' . $descriptor['surface']] = $stderr;
                if ($descriptor['outputFileKind'] !== null) {
                    $artifacts[$descriptor['scope'] . '|' . $descriptor['outputFileKind']] = $answer['file'] ?? '';
                }
            }

            return [$root, $temporary, $artifacts];
        } catch (Throwable $error) {
            SyntheticTree::remove($root);
            Fs::removeRecursively($temporary);
            throw $error;
        }
    }

    /**
     * @param list<list<string>> $rows
     * @param list<string> $commands
     */
    private static function declareCaptureExits(string $root, array $rows, array $commands = ['graph:export']): void
    {
        Fs::write($root . '/finding-gate/' . DeclaredValues::INDEX, Tsv::render(DeclaredValues::COLUMNS, array_map(static fn(string $command): array => ['exit', $command, '*', 'An exact measured capture outcome.'], $commands)));
        if ($rows === []) {
            Fs::removeRecursively($root . '/finding-gate/' . DeclaredValues::DERIVED);
        } else {
            Fs::write($root . '/finding-gate/' . DeclaredValues::DERIVED, Tsv::render(DeclaredValues::DERIVED_COLUMNS, $rows));
        }
    }

}
