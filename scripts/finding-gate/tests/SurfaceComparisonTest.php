<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\ChannelSplit;
use QmxFindingGate\Corpus;
use QmxFindingGate\DeclaredDelta;
use QmxFindingGate\DeclaredDeltaCheck;
use QmxFindingGate\DeclaredExactSurfaces;
use QmxFindingGate\DeclaredFieldMoves;
use QmxFindingGate\ExactDiff;
use QmxFindingGate\ExactSurfaceAuthority;
use QmxFindingGate\FailureClass;
use QmxFindingGate\FingerprintCheck;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\GateReport;
use QmxFindingGate\MetricVocabulary;
use QmxFindingGate\Normalization;
use QmxFindingGate\Options;
use QmxFindingGate\RenameMaps;
use QmxFindingGate\RunContext;
use QmxFindingGate\SurfaceComparison;
use QmxFindingGate\SurfacePair;
use QmxFindingGate\SurfaceStage;
use QmxFindingGate\SyntheticTree;
use QmxFindingGate\Tsv;

/**
 * A form's registered stage takes part in every surface's comparison, at the
 * step it names.
 */
final class SurfaceComparisonTest extends TestCase
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

    #[Test]
    public function itRunsARegisteredStageBeforeTheStepItNames(): void
    {
        $report = new GateReport();
        $stage = self::settling('difference');
        $json = (string) json_encode(['violations' => []]);

        $this->comparison($report, [$stage])->compareSurfaces(
            ['case:alpha|format:json' => $json, 'case:alpha|format:text' => 'candidate text'],
            ['case:alpha|format:json' => $json, 'case:alpha|format:text' => 'reference text'],
        );

        self::assertSame(['case:alpha|format:json', 'case:alpha|format:text'], $stage->seen);
        self::assertSame([], $report->raised(), 'a surface the stage settled reaches no later step');
    }

    #[Test]
    public function itKeepsRejectedBaselineSourceLocalWithoutSilencingUnknownAuthority(): void
    {
        $configured = SyntheticTree::clean();
        $configured['declarations']['cases/alpha/baseline-src/src/Alpha.php'] = "<?php\n";
        $configuredRoot = SyntheticTree::fixture($configured);
        try {
            foreach ([$this->root => 'format:json', $configuredRoot => 'check:baseline-source'] as $root => $source) {
                $maps = RenameMaps::load($root . '/finding-gate/maps', MetricVocabulary::ofTree($root));
                $options = Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root);
                foreach ([[false, false], [false, null], [null, null], [true, true]] as [$candidateState, $referenceState]) {
                    $report = new GateReport();
                    $run = new RunContext(
                        $options,
                        $report,
                        Corpus::load($root),
                        $maps,
                        ChannelSplit::of($maps),
                        MetricVocabulary::ofTree($root),
                        Normalization::fromRules([]),
                        \QmxFindingGate\Declarations::load($root),
                        $root,
                    );
                    foreach (['candidate' => $candidateState, 'reference' => $referenceState] as $side => $state) {
                        if ($state !== null) {
                            $report->sourceEvidence($side, 'case:alpha|' . $source, 'records', $state);
                        }
                        if ($state === false) {
                            $report->fail(FailureClass::RECORD_PROJECTION_MISMATCH, $side . ' / case:alpha|' . $source, 'The source was rejected.');
                        }
                    }
                    $pair = new SurfacePair('case:alpha|baseline-file', 'baseline-file', '{}', '{}');
                    \QmxFindingGate\RecordStage::create($run)->applyStage($pair);
                    $scopes = array_column($report->raised(), 'scope');
                    if ($candidateState === false) {
                        self::assertContains('candidate / case:alpha|' . $source, $scopes, $report->render());
                    }
                    self::assertSame(
                        $candidateState === false && $referenceState === false ? [] : [($candidateState === false ? 'reference' : 'candidate') . ' / case:alpha|baseline-file'],
                        array_values(array_filter($scopes, static fn(string $scope): bool => str_ends_with($scope, '|baseline-file'))),
                        $report->render(),
                    );
                    self::assertSame($candidateState === false && $referenceState === false, !$pair->settled);
                }
            }
        } finally {
            SyntheticTree::remove($configuredRoot);
        }
    }

    #[Test]
    public function itComparesFindingCountsOnlyWhenBothDeclaredSidesPublishFindings(): void
    {
        SyntheticTree::remove($this->root);
        $this->root = SyntheticTree::create(\QmxFindingGate\SelfTestOutcomes::fixture());
        foreach ([true, false] as $declared) {
            if (!$declared) {
                Fs::write($this->root . '/finding-gate/' . \QmxFindingGate\DeclaredOutcomes::INDEX, Tsv::render(\QmxFindingGate\DeclaredOutcomes::COLUMNS, []));
            }
            $report = new GateReport();
            $this->comparison($report, [self::settling('difference')])->compareSurfaces(['case:alpha|format:json' => ''], ['case:alpha|format:json' => '{"violations":[{}]}']);
            if ($declared) {
                self::assertNotContains(FailureClass::FINDING_COUNT_MISMATCH, $report->failureClasses());
            } else {
                self::assertContains(FailureClass::FINDING_COUNT_MISMATCH, $report->failureClasses());
            }
        }
    }

    #[Test]
    public function itComparesTheWholeHtmlRefusalWithoutRequiringAnAnalysisPayload(): void
    {
        Fs::write($this->root . '/finding-gate/cases/alpha/case.json', json_encode([
            'id' => 'alpha', 'description' => 'A configuration refusal before report generation.',
            'paths' => ['src'], 'config' => 'qmx.yaml', 'coverage' => 'auxiliary', 'channels' => [],
            'outcome' => ['kind' => \QmxFindingGate\CaseOutcome::REFUSAL, 'exit' => 3],
        ], \JSON_THROW_ON_ERROR));
        foreach ([['', '', []], ['Refused input', 'Refused input', []], ['Changed cause', 'Refused input', [FailureClass::SURFACE_MISMATCH]], [null, '', [FailureClass::SURFACE_MISMATCH]]] as [$candidate, $reference, $failures]) {
            $report = new GateReport();
            $this->comparison($report, [])->compareSurfaces(
                $candidate === null ? [] : ['case:alpha|format:html' => $candidate],
                ['case:alpha|format:html' => $reference],
            );
            self::assertSame($failures, $report->failureClasses());
        }
        $definition = json_decode(Fs::read($this->root . '/finding-gate/cases/alpha/case.json'), true, 512, \JSON_THROW_ON_ERROR);
        unset($definition['outcome']);
        $definition['channels'] = ['replay.alpha@callable'];
        foreach ([null, ['kind' => \QmxFindingGate\CaseOutcome::INCOMPLETE, 'exit' => 4]] as $outcome) {
            if ($outcome !== null) {
                $definition['outcome'] = $outcome;
            }
            Fs::write($this->root . '/finding-gate/cases/alpha/case.json', json_encode($definition, \JSON_THROW_ON_ERROR));
            $report = new GateReport();
            $this->comparison($report, [])->compareSurfaces(['case:alpha|format:html' => ''], ['case:alpha|format:html' => '']);
            self::assertContains(FailureClass::REPORT_PAYLOAD_UNREADABLE, $report->failureClasses());
        }
    }

    #[Test]
    public function itRefusesAStageBeforeAStepThatDoesNotExist(): void
    {
        $this->expectException(GateError::class);

        $this->comparison(new GateReport(), [self::settling('translate')]);
    }

    #[Test]
    public function itComparesOnePositionIndependentDeltaForEveryCaseOfASurfaceClass(): void
    {
        $canonical = "--- candidate\n+++ reference (mapped)\n-a\n+A\n";
        $this->declare('format:text', $canonical);
        $report = new GateReport();
        $check = $this->deltaCheck($report);
        $check->checkDifference('case:alpha|format:text', "a\n", "A\n");
        $check->checkDifference('case:beta|format:text', "padding\na\n", "padding\nA\n");
        self::assertSame([], $report->raised());
        $check->checkStaleDeclaredDelta();
        self::assertSame([], $report->raised());
    }

    #[Test]
    public function itRefusesAnEqualCaseUnderADeclaredSurfaceClass(): void
    {
        $this->declare('format:text', "--- candidate\n+++ reference (mapped)\n-a\n+A\n");
        $report = new GateReport();
        $this->deltaCheck($report)->observeEqual('case:alpha|format:text');
        self::assertContains(FailureClass::DELTA_STALE, $report->failureClasses());
    }

    #[Test]
    public function itRefusesDifferentCaseMeasurementsOfOneSurfaceClassWhileDeriving(): void
    {
        $this->declare('format:text', "--- candidate\n+++ reference (mapped)\n-a\n+A\n");
        $report = new GateReport();
        $check = $this->deltaCheck($report);
        $check->startDeriving();
        $check->checkDifference('case:alpha|format:text', "a\n", "A\n");
        $check->checkDifference('case:beta|format:text', "b\n", "B\n");
        self::assertContains(FailureClass::DELTA_MISMATCH, $report->failureClasses());
        self::assertSame([], $check->rewriteDerived());
    }

    #[Test]
    public function itRefusesAnUnannouncedNeighbourBeforeADerivationWritesAnyFile(): void
    {
        $this->declare('case:alpha|format:text', "--- candidate\n+++ reference (mapped)\n-a\n+A\n");
        $path = $this->root . '/finding-gate/' . DeclaredDelta::INDEX;
        $before = Fs::read($path);
        $report = new GateReport();
        $check = $this->deltaCheck($report);
        $check->startDeriving();
        $this->comparison($report, [])->compareSurfaces(['case:beta|format:text' => "a\n"], ['case:beta|format:text' => "A\n"]);
        self::assertContains(FailureClass::SURFACE_MISMATCH, $report->failureClasses());
        self::assertSame([], $check->rewriteDerived());
        self::assertSame($before, Fs::read($path));
    }

    #[Test]
    public function itChecksOnlyTheChosenOutputMarkerAfterItsNamedNormalization(): void
    {
        $rule = new \QmxFindingGate\NormalizationRule('stderr:check:output', '~^(Report written to ).*()$~m', \QmxFindingGate\NormalizationRule::KIND_LINE_REGEX, 'The validated output destination varies per run.');
        $report = new GateReport();
        $comparison = $this->comparison($report, [], Normalization::fromRules([$rule]));
        $comparison->checkPathLeaks(['case:alpha|stderr:check:output' => 'Report written to ' . $this->root . "/chosen.json\n"], [], '/other-reference');
        self::assertSame([], $report->raised());
        $comparison->checkPathLeaks([
            'case:alpha|stderr:check:output' => 'Report written to ' . $this->root . "/chosen.json\nordinary " . $this->root,
            'case:alpha|stderr:rules' => 'Report written to ' . $this->root . '/chosen.json',
            'case:alpha|check:output' => 'Report written to ' . $this->root . '/chosen.json',
        ], [], '/other-reference');
        self::assertCount(3, $report->raised());
        $without = new GateReport();
        $this->comparison($without, [])->checkPathLeaks(['case:alpha|stderr:check:output' => 'Report written to ' . $this->root . '/chosen.json'], [], '/other-reference');
        self::assertContains(FailureClass::PATH_LEAK, $without->failureClasses());
    }

    #[Test]
    public function itKeepsStructuralMetadataOutsideComparedRecordsAndRefusesRecordOverreach(): void
    {
        $a = '{"meta":{"message":"New schema label"},"violations":[]}';
        $b = '{"meta":{"message":"Old schema label"},"violations":[]}';
        $this->declare('case:alpha|format:json', ExactDiff::between($a, $b, 'candidate', 'reference (mapped)')->render());
        $report = new GateReport();
        $this->deltaCheck($report)->checkDifference('case:alpha|format:json', $a, $b);
        self::assertSame([], $report->raised());
        $a = '{"meta":{},"violations":[{"message":"New message"}]}';
        $b = '{"meta":{},"violations":[{"message":"Old message"}]}';
        $this->declare('case:alpha|format:json', ExactDiff::between($a, $b, 'candidate', 'reference (mapped)')->render());
        $report = new GateReport();
        $this->deltaCheck($report)->checkDifference('case:alpha|format:json', $a, $b);
        self::assertContains(FailureClass::DELTA_OVERREACH, $report->failureClasses());
    }

    #[Test]
    public function itKeepsSarifPathProtectionOutsideItsDeclaredCaptureSourceUri(): void
    {
        $rule = new \QmxFindingGate\NormalizationRule('format:sarif', 'runs.*.originalUriBaseIds.%SRCROOT%.uri', \QmxFindingGate\NormalizationRule::KIND_JSON_PATH, 'The isolated capture directory varies.');
        $document = ['runs' => [['originalUriBaseIds' => ['%SRCROOT%' => ['uri' => 'file://' . $this->root . '/']], 'results' => [['message' => ['text' => 'A populated finding'], 'locations' => [['physicalLocation' => ['artifactLocation' => ['uri' => 'src/A.php']]]]]]]]];
        $artifact = json_encode($document, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $report = new GateReport();
        $comparison = $this->comparison($report, [], Normalization::fromRules([$rule]));
        $comparison->checkPathLeaks(['case:alpha|format:sarif' => $artifact], [], '/other-reference');
        self::assertSame([], $report->raised());
        $document['runs'][0]['results'][0]['message']['text'] = 'An unexpected directory ' . $this->root;
        $comparison->checkPathLeaks(['case:alpha|format:sarif' => json_encode($document, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)], [], '/other-reference');
        self::assertSame([FailureClass::PATH_LEAK], $report->failureClasses());
        self::assertCount(1, $report->raised());
        $without = new GateReport();
        $this->comparison($without, [])->checkPathLeaks(['case:alpha|format:sarif' => $artifact], [], '/other-reference');
        self::assertSame([FailureClass::PATH_LEAK], $without->failureClasses());
    }

    #[Test]
    public function itRefusesOverlappingFullSurfaceAndClassStructuralIntentions(): void
    {
        Fs::write($this->root . '/finding-gate/declared-delta/probe.diff', "a measured structural change\n");
        Fs::write($this->root . '/finding-gate/' . DeclaredDelta::INDEX, Tsv::render(DeclaredDelta::COLUMNS, [
            ['format:json', 'declared-delta/probe.diff', 'Every case changes.'],
            ['case:alpha|format:json', 'declared-delta/probe.diff', 'The same case changes again.'],
        ]));
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('overlaps');
        DeclaredDelta::load($this->root . '/finding-gate');
    }

    #[Test]
    public function itRefusesAFirstOnlyOrSecondOnlySurfaceInEitherDirection(): void
    {
        $key = 'case:alpha|format:health';
        foreach ([[$key => 'first'], []] as $candidate) {
            $reference = $candidate === [] ? [$key => 'second'] : [];
            $report = new GateReport();
            $this->comparison($report, [])->compareSurfaces($candidate, $reference);
            self::assertSame(1, $report->exitCode());
            self::assertCount(1, $report->raised());
            self::assertSame(FailureClass::SURFACE_MISMATCH, $report->raised()[0]['class']);
            self::assertSame($key, $report->raised()[0]['scope']);
            self::assertStringContainsString($candidate === [] ? 'the reference' : 'the candidate', $report->raised()[0]['detail']);
        }
    }

    #[Test]
    public function itRefusesDifferentUnlicensedBytesInBothDirectionsAndAcceptsEquality(): void
    {
        $key = 'case:alpha|format:health';
        foreach ([['first', 'second'], ['second', 'first'], ['same', 'same']] as [$candidate, $reference]) {
            $report = new GateReport();
            $this->comparison($report, [])->compareSurfaces([$key => $candidate], [$key => $reference]);
            if ($candidate === $reference) {
                self::assertSame([], $report->raised());
                self::assertSame(0, $report->exitCode());
            } else {
                self::assertSame(1, $report->exitCode());
                self::assertCount(1, $report->raised());
                self::assertSame(FailureClass::SURFACE_MISMATCH, $report->raised()[0]['class']);
                self::assertSame($key, $report->raised()[0]['scope']);
                self::assertStringContainsString('outside every declared structural intention', $report->raised()[0]['detail']);
            }
        }
    }

    #[Test]
    public function itRefusesDifferentBytesEvenForAnUnknownSurfaceKey(): void
    {
        $report = new GateReport();
        $this->comparison($report, [])->compareSurfaces(['case:alpha|unknown' => 'first'], ['case:alpha|unknown' => 'second']);
        self::assertSame(1, $report->exitCode());
        self::assertCount(1, $report->raised());
        self::assertSame(FailureClass::SURFACE_MISMATCH, $report->raised()[0]['class']);
        self::assertSame('case:alpha|unknown', $report->raised()[0]['scope']);
    }

    #[Test]
    public function itLooksUpAnIntentionWithoutCreditingItAsPerformed(): void
    {
        $this->declare('format:health', "--- candidate\n+++ reference (mapped)\n-a\n+A\n");
        $report = new GateReport();
        $check = $this->deltaCheck($report);
        self::assertTrue($check->hasIntention('case:alpha|format:health'));
        self::assertFalse($check->hasIntention('case:alpha|unknown'));
        $check->checkStaleDeclaredDelta();
        self::assertCount(1, $report->raised());
        self::assertSame(FailureClass::DELTA_STALE, $report->raised()[0]['class']);
        self::assertSame('format:health', $report->raised()[0]['scope']);
    }

    #[Test]
    public function itRefusesADirectDeltaComparisonWithoutAnExplicitIntention(): void
    {
        $report = new GateReport();
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('A structural delta comparison requires an explicit intention: case:alpha|unknown');
        $this->deltaCheck($report)->checkDifference('case:alpha|unknown', 'first', 'second');
    }

    #[Test]
    public function itDeclaresA244LineNonRecordDeltaAndRefusesANeighbouringByte(): void
    {
        $a = implode("\n", array_map(static fn(int $i): string => 'candidate ' . $i, range(1, 122))) . "\n";
        $b = implode("\n", array_map(static fn(int $i): string => 'reference ' . $i, range(1, 122))) . "\n";
        $this->declare('case:alpha|stderr:rules', ExactDiff::between($a, $b, 'candidate', 'reference (mapped)')->render());
        $report = new GateReport();
        $this->deltaCheck($report)->checkDifference('case:alpha|stderr:rules', $a, $b);
        self::assertSame([], $report->raised());
        $report = new GateReport();
        $this->deltaCheck($report)->checkDifference('case:alpha|stderr:rules', $a . "neighbour\n", $b);
        self::assertSame([FailureClass::DELTA_MISMATCH], $report->failureClasses());
    }

    #[Test]
    public function itRetainsTheOrdinaryLimitForMetricAndDirectiveRecords(): void
    {
        $a = implode("\n", array_fill(0, 122, 'candidate')) . "\n";
        $b = implode("\n", array_fill(0, 122, 'reference')) . "\n";
        foreach (['format:metrics', 'directives'] as $surface) {
            $key = 'case:alpha|' . $surface;
            $this->declare($key, ExactDiff::between($a, $b, 'candidate', 'reference (mapped)')->render());
            $report = new GateReport();
            $this->deltaCheck($report)->checkDifference($key, $a, $b);
            self::assertContains(FailureClass::DELTA_TOO_LARGE, $report->failureClasses(), $surface);
        }
    }

    #[Test]
    public function itMeasuresAndChecksOneExactCaseSurface(): void
    {
        $tree = SyntheticTree::clean();
        $tree['candidateAnswers']['case:alpha|rules'] = ['stdout' => "changed rule listing\n"];
        $tree['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
            ['alpha', 'rules', 'declared-exact-surfaces/rules.diff', 'The rule listing intentionally changes.'],
        ]);
        $tree['candidateDeclarations']['declared-exact-surfaces/rules.diff'] = "pending\n";
        $root = SyntheticTree::fixture($tree);
        try {
            [$derived, $written] = RecordedComparison::derive($tree, $root);
            self::assertContains(DeclaredExactSurfaces::INDEX, $written, $derived->render());
            $row = Tsv::rows($root . '/finding-gate/' . DeclaredExactSurfaces::INDEX, DeclaredExactSurfaces::COLUMNS)[0];
            $diff = Fs::read($root . '/finding-gate/' . $row['file']);
            self::assertNotSame("pending\n", $diff);
            self::assertSame([], RecordedComparison::reportAt($tree, $root)->failureClasses());
            $tree['candidateDeclarations']['declared-exact-surfaces/rules.diff'] = $diff;
            $tree['candidateAnswers']['case:alpha|rules'] = ['stdout' => "changed rule listing\nneighbour\n"];
            self::assertContains(FailureClass::DELTA_MISMATCH, RecordedComparison::report($tree)->failureClasses());
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itKeepsAnInvalidExactSupplierUnmeasuredWhileDerivingAValidNeighbour(): void
    {
        $tree = SyntheticTree::clean();
        $answers = json_decode(Fs::read($this->root . '/replay/answers.json'), true, 512, \JSON_THROW_ON_ERROR);
        $answer = $answers['case:alpha|format:json'];
        $visible = \QmxFindingGate\ReportRecords::decode($answer['stdout']);
        $visible['violationsMeta'] = ['total' => 3, 'shown' => 1, 'limit' => 1, 'truncated' => true, 'byRule' => ['replay.alpha' => 3]];
        $visible['announcedNeighbour'] = 1;
        $answer['stdout'] = \QmxFindingGate\ValueCheck::value($visible);
        $ranked = $visible;
        $issue = $ranked['topIssues'][0];
        $ranked['topIssues'] = [$issue, ['rank' => 2, ...array_diff_key($issue, ['rank' => true])], ['rank' => 3, ...array_diff_key($issue, ['rank' => true])]];
        unset($ranked['announcedNeighbour']);
        $answer['ranked']['stdout'] = \QmxFindingGate\ValueCheck::value($ranked);
        $physical = $ranked;
        $physical['violations'] = array_fill(0, 3, $visible['violations'][0]);
        $physical['violationsMeta'] = ['total' => 4, 'shown' => 3, 'limit' => null, 'truncated' => false, 'byRule' => ['replay.alpha' => 4]];
        $answer['physical']['stdout'] = \QmxFindingGate\ValueCheck::value($physical);
        $tree['candidateAnswers']['case:alpha|format:json'] = $answer;
        $tree['candidateAnswers']['case:alpha|check:output'] = ['file' => $answer['stdout']];
        $tree['candidateAnswers']['case:alpha|check:parallel'] = ['stdout' => $answer['stdout']];
        $tree['candidateAnswers']['case:alpha|rules'] = ['stdout' => "Changed rule listing.\n"];
        $tree['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
            ['alpha', 'format:json', 'declared-exact-surfaces/json.diff', 'The JSON surface changes.'],
            ['alpha', 'rules', 'declared-exact-surfaces/rules.diff', 'The rule listing changes independently.'],
        ]);
        $tree['candidateDeclarations']['declared-exact-surfaces/json.diff'] = "pending json\n";
        $tree['candidateDeclarations']['declared-exact-surfaces/rules.diff'] = "pending rules\n";
        $root = SyntheticTree::fixture($tree);
        try {
            [$report, $written] = RecordedComparison::derive($tree, $root);
            self::assertContains(FailureClass::RANKING_PROJECTION_MISMATCH, $report->failureClasses(), $report->render());
            self::assertContains(FailureClass::RUN_FAILED, $report->failureClasses(), $report->render());
            self::assertNotContains('declared-exact-surfaces/' . md5('case:alpha|format:json') . '.diff', $written, $report->render());
            self::assertSame("pending json\n", Fs::read($root . '/finding-gate/declared-exact-surfaces/json.diff'));
            self::assertContains('declared-exact-surfaces/' . md5('case:alpha|rules') . '.diff', $written, $report->render());
            $rows = Tsv::rows($root . '/finding-gate/' . DeclaredExactSurfaces::INDEX, DeclaredExactSurfaces::COLUMNS);
            self::assertSame('declared-exact-surfaces/json.diff', $rows[0]['file']);
            self::assertNotSame("pending rules\n", Fs::read($root . '/finding-gate/' . $rows[1]['file']));
        } finally {
            SyntheticTree::remove($root);
        }
        unset($answer['physical']);
        $tree['candidateAnswers']['case:alpha|format:json'] = $answer;
        $root = SyntheticTree::fixture($tree);
        try {
            [$missing, $written] = RecordedComparison::derive($tree, $root);
            self::assertContains(FailureClass::RUN_FAILED, $missing->failureClasses(), $missing->render());
            self::assertNotContains('declared-exact-surfaces/' . md5('case:alpha|format:json') . '.diff', $written, $missing->render());
            self::assertContains('declared-exact-surfaces/' . md5('case:alpha|rules') . '.diff', $written, $missing->render());
        } finally {
            SyntheticTree::remove($root);
        }
        $unknown = new GateReport();
        $unknown->fail(FailureClass::RUN_FAILED, 'candidate / alpha', 'The source has no validated authority.', [], ['side' => 'candidate', 'key' => 'case:alpha|format:json', 'role' => 'records']);
        self::assertFalse($unknown->canDeriveExact());
        $global = new GateReport();
        $global->fail(FailureClass::RUN_FAILED, 'candidate-2', 'The candidate capture failed.');
        self::assertFalse($global->canDeriveExact());
        $limited = new GateReport();
        $limited->limit('The corpus is incomplete.');
        self::assertFalse($limited->canDeriveExact());
    }

    #[Test]
    public function itUsesAnExactMetricSurfaceWithoutPairingItsChangedRecords(): void
    {
        $tree = SyntheticTree::clean();
        $tree['candidateAnswers']['case:alpha|format:metrics'] = ['stdout' => json_encode([
            'symbols' => [['type' => 'method', 'name' => 'Replay\\Alpha::run', 'file' => 'src/Alpha.php', 'line' => 1, 'metrics' => ['ccn' => 2]]],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n"];
        $tree['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
            ['alpha', 'format:metrics', 'declared-exact-surfaces/metrics.diff', 'The complete metric report intentionally changes.'],
        ]);
        $tree['candidateDeclarations']['declared-exact-surfaces/metrics.diff'] = "pending\n";
        $root = SyntheticTree::fixture($tree);
        try {
            [$derived, $written] = RecordedComparison::derive($tree, $root);
            self::assertContains(DeclaredExactSurfaces::INDEX, $written, $derived->render());
            self::assertSame([], RecordedComparison::reportAt($tree, $root)->failureClasses());
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itLeavesAnExactIntentionStaleWhenAMetricChangeIsSemanticallyExplained(): void
    {
        $tree = SyntheticTree::clean();
        $tree['candidateAnswers']['case:alpha|format:metrics'] = ['stdout' => json_encode([
            'symbols' => [['type' => 'method', 'name' => 'Replay\\Alpha::run', 'file' => 'src/Alpha.php', 'line' => 1, 'metrics' => ['ccn' => 2]]],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n"];
        $tree['candidateDeclarations'][\QmxFindingGate\DeclaredValues::INDEX] = Tsv::render(\QmxFindingGate\DeclaredValues::COLUMNS, [
            [\QmxFindingGate\DeclaredValues::METRIC, 'ccn', '*', 'The metric value changes.'],
        ]);
        $root = SyntheticTree::fixture($tree);
        try {
            [$measured] = RecordedComparison::derive($tree, $root);
            $tree['candidateDeclarations'][\QmxFindingGate\DeclaredValues::DERIVED] = Fs::read($root . '/finding-gate/' . \QmxFindingGate\DeclaredValues::DERIVED);
            self::assertStringContainsString('ccn', $tree['candidateDeclarations'][\QmxFindingGate\DeclaredValues::DERIVED], $measured->render());
        } finally {
            SyntheticTree::remove($root);
        }
        $semanticOnly = RecordedComparison::report($tree);
        self::assertSame([], $semanticOnly->failureClasses(), $semanticOnly->render());
        $tree['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
            ['alpha', 'format:metrics', 'declared-exact-surfaces/metrics.diff', 'The complete metric report changes.'],
        ]);
        $tree['candidateDeclarations']['declared-exact-surfaces/metrics.diff'] = "pending\n";
        $root = SyntheticTree::fixture($tree);
        try {
            [, $written] = RecordedComparison::derive($tree, $root);
            self::assertNotContains(DeclaredExactSurfaces::INDEX, $written);
        } finally {
            SyntheticTree::remove($root);
        }
        self::assertContains(FailureClass::DELTA_STALE, RecordedComparison::report($tree)->failureClasses());
    }

    #[Test]
    public function itDerivesFreshRecordIntentionsBeforeConsideringAnExactMetricSurface(): void
    {
        $tree = SyntheticTree::clean();
        $answers = json_decode(Fs::read($this->root . '/replay/answers.json'), true, 512, \JSON_THROW_ON_ERROR);
        $metrics = json_decode($answers['case:alpha|format:metrics']['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        $oldName = $metrics['symbols'][0]['name'];
        $newName = str_replace('Alpha', 'Beta', $oldName);
        $metrics['symbols'][0]['name'] = $newName;
        $tree['candidateAnswers']['case:alpha|format:metrics'] = ['stdout' => json_encode($metrics, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n"];
        $tree['candidateDeclarations'][\QmxFindingGate\DeclaredRecords::INDEX] = Tsv::render(\QmxFindingGate\DeclaredRecords::COLUMNS, [
            ['introduced', 'alpha', 'metrics', 'format:metrics', \QmxFindingGate\ValueCheck::value(['type' => 'method', 'name' => $newName]), 'The replacement method is introduced.'],
            ['withdrawn', 'alpha', 'metrics', 'format:metrics', \QmxFindingGate\ValueCheck::value(['type' => 'method', 'name' => $oldName]), 'The former method is withdrawn.'],
        ]);
        $root = SyntheticTree::fixture($tree);
        try {
            self::assertFileDoesNotExist($root . '/finding-gate/' . \QmxFindingGate\DeclaredRecords::DERIVED);
            [$semantic, $semanticWritten] = RecordedComparison::derive($tree, $root);
            self::assertSame([], $semantic->failureClasses(), $semantic->render());
            self::assertContains(\QmxFindingGate\DeclaredRecords::DERIVED, $semanticWritten);
            $measured = Fs::read($root . '/finding-gate/' . \QmxFindingGate\DeclaredRecords::DERIVED);
            $tree['candidateDeclarations'][\QmxFindingGate\DeclaredRecords::DERIVED] = $measured;
        } finally {
            SyntheticTree::remove($root);
        }
        $semanticOnly = RecordedComparison::report($tree);
        self::assertSame([], $semanticOnly->failureClasses(), $semanticOnly->render());
        unset($tree['candidateDeclarations'][\QmxFindingGate\DeclaredRecords::DERIVED]);
        self::assertArrayNotHasKey(\QmxFindingGate\DeclaredRecords::DERIVED, $tree['candidateDeclarations']);
        $tree['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
            ['alpha', 'format:metrics', 'declared-exact-surfaces/metrics.diff', 'Measure any remaining metric publication change.'],
        ]);
        $tree['candidateDeclarations']['declared-exact-surfaces/metrics.diff'] = "pending\n";
        $root = SyntheticTree::fixture($tree);
        try {
            [$report, $written] = RecordedComparison::derive($tree, $root);
            self::assertContains(\QmxFindingGate\DeclaredRecords::DERIVED, $written, $report->render());
            self::assertNotContains(DeclaredExactSurfaces::INDEX, $written, $report->render());
            self::assertSame($measured, Fs::read($root . '/finding-gate/' . \QmxFindingGate\DeclaredRecords::DERIVED));
        } finally {
            SyntheticTree::remove($root);
        }
        $tree['candidateDeclarations'][\QmxFindingGate\DeclaredRecords::DERIVED] = $measured;
        self::assertContains(FailureClass::DELTA_STALE, RecordedComparison::report($tree)->failureClasses());
    }

    #[Test]
    public function itSuppliesRequiredSchemaDuringAnExactMetricTrial(): void
    {
        $tree = SyntheticTree::clean();
        $answers = json_decode(Fs::read($this->root . '/replay/answers.json'), true, 512, \JSON_THROW_ON_ERROR);
        $metrics = json_decode($answers['case:alpha|format:metrics']['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        $metrics['symbols'][0]['extra'] = 2;
        $tree['candidateAnswers']['case:alpha|format:metrics'] = ['stdout' => json_encode($metrics, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n"];
        $tree['candidateDeclarations'][\QmxFindingGate\DeclaredFields::INDEX] = Tsv::render(\QmxFindingGate\DeclaredFields::COLUMNS, [
            ['added', 'metrics', 'format:metrics', 'extra', 'The metric record gains one field.'],
        ]);
        $root = SyntheticTree::fixture($tree);
        try {
            self::assertFileDoesNotExist($root . '/finding-gate/' . \QmxFindingGate\DeclaredFields::DERIVED);
            [$semantic, $semanticWritten] = RecordedComparison::derive($tree, $root);
            self::assertSame([], $semantic->failureClasses(), $semantic->render());
            self::assertContains(\QmxFindingGate\DeclaredFields::DERIVED, $semanticWritten);
            $tree['candidateDeclarations'][\QmxFindingGate\DeclaredFields::DERIVED] = Fs::read($root . '/finding-gate/' . \QmxFindingGate\DeclaredFields::DERIVED);
        } finally {
            SyntheticTree::remove($root);
        }
        $semanticOnly = RecordedComparison::report($tree);
        self::assertSame([], $semanticOnly->failureClasses(), $semanticOnly->render());
        $tree['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
            ['alpha', 'format:metrics', 'declared-exact-surfaces/metrics.diff', 'Measure any remaining metric publication change.'],
        ]);
        $tree['candidateDeclarations']['declared-exact-surfaces/metrics.diff'] = "pending\n";
        $root = SyntheticTree::fixture($tree);
        try {
            [$report, $written] = RecordedComparison::derive($tree, $root);
            self::assertContains(\QmxFindingGate\DeclaredFields::DERIVED, $written, $report->render());
            self::assertNotContains(DeclaredExactSurfaces::INDEX, $written, $report->render());
        } finally {
            SyntheticTree::remove($root);
        }
        self::assertContains(FailureClass::DELTA_STALE, RecordedComparison::report($tree)->failureClasses());
        $metrics['symbols'][0]['metrics']['ccn'] = 2;
        $tree['candidateAnswers']['case:alpha|format:metrics'] = ['stdout' => json_encode($metrics, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n"];
        $root = SyntheticTree::fixture($tree);
        try {
            [$report, $written] = RecordedComparison::derive($tree, $root);
            self::assertContains(\QmxFindingGate\DeclaredFields::DERIVED, $written, $report->render());
            self::assertContains(DeclaredExactSurfaces::INDEX, $written, $report->render());
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itKeepsDistinctRawRankingNumbersBeyondFloatPrecision(): void
    {
        $answers = json_decode(Fs::read($this->root . '/replay/answers.json'), true, 512, \JSON_THROW_ON_ERROR);
        $source = $answers['case:alpha|format:json']['ranked']['stdout'];
        $left = str_replace('"impactScore": 45', '"impactScore": 0.12345678901234567891', $source);
        $right = str_replace('"impactScore": 45', '"impactScore": 0.12345678901234567892', $source);
        self::assertNotSame($source, $left);
        self::assertSame(
            json_decode($left, true, 512, \JSON_THROW_ON_ERROR)['topIssues'][0]['impactScore'],
            json_decode($right, true, 512, \JSON_THROW_ON_ERROR)['topIssues'][0]['impactScore'],
        );
        $maps = RenameMaps::load($this->root . '/finding-gate/maps', MetricVocabulary::ofTree($this->root));
        $run = new RunContext(
            Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], $this->root),
            new GateReport(),
            Corpus::load($this->root),
            $maps,
            ChannelSplit::of($maps),
            MetricVocabulary::ofTree($this->root),
            Normalization::fromRules([]),
            \QmxFindingGate\Declarations::load($this->root),
            $this->root,
        );
        $capture = static fn(string $ranking): \QmxFindingGate\CaptureResult => new \QmxFindingGate\CaptureResult([], [
            'case:alpha|format:json' => ['ranked' => ['stdout' => $ranking, 'stderr' => '', 'exit' => 2], 'physical' => null],
        ]);
        [$candidate, $reference] = ExactSurfaceAuthority::pair(
            new SurfacePair('case:alpha|format:json', 'format:json', $source, $source),
            ['candidate' => $capture($left), 'reference' => $capture($right)],
            $run,
        );
        self::assertStringContainsString('0.12345678901234567891', $candidate);
        self::assertStringContainsString('0.12345678901234567892', $reference);
        self::assertNotSame($candidate, $reference);
    }

    private function declare(string $key, string $diff): void
    {
        Fs::write($this->root . '/finding-gate/declared-delta/probe.diff', $diff);
        Fs::write($this->root . '/finding-gate/' . DeclaredDelta::INDEX, Tsv::render(DeclaredDelta::COLUMNS, [[$key, 'declared-delta/probe.diff', 'An explicit structural intention.']]));
    }

    private function deltaCheck(GateReport $report): DeclaredDeltaCheck
    {
        return new DeclaredDeltaCheck(Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], $this->root), $report, DeclaredDelta::load($this->root . '/finding-gate'), DeclaredFieldMoves::load($this->root . '/finding-gate'), ChannelSplit::of(RenameMaps::fromPairs([])));
    }

    /** @param list<SurfaceStage> $stages */
    private function comparison(GateReport $report, array $stages, ?Normalization $normalization = null): SurfaceComparison
    {
        $maps = RenameMaps::load($this->root . '/finding-gate/maps', MetricVocabulary::ofTree($this->root));
        $options = Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], $this->root);
        $fingerprints = new FingerprintCheck($report);

        return new SurfaceComparison(
            $report,
            Corpus::load($this->root),
            $maps,
            $normalization ?? Normalization::fromRules([]),
            $fingerprints,
            new DeclaredDeltaCheck(
                $options,
                $report,
                DeclaredDelta::load($this->root . '/finding-gate'),
                DeclaredFieldMoves::load($this->root . '/finding-gate'),
                ChannelSplit::of($maps),
            ),
            $this->root,
            $stages,
        );
    }

    /**
     * A stage that settles every surface it sees, once both sides have been
     * through every step before the one it names.
     *
     * @return SurfaceStage&object{seen: list<string>}
     */
    private static function settling(string $before): SurfaceStage
    {
        return new class ($before) implements SurfaceStage {
            /** @var list<string> */
            public array $seen = [];

            public function __construct(private readonly string $step) {}

            public static function create(RunContext $run): static
            {
                throw new GateError('Built by the test, not by the gate.');
            }

            public function before(): string
            {
                return $this->step;
            }

            public function applyStage(SurfacePair $pair): void
            {
                $this->seen[] = $pair->key;
                $pair->settle();
            }
        };
    }
}
