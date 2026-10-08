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
use ReflectionMethod;
use ReflectionProperty;

/**
 * A form's registered stage takes part in every surface's comparison, at the
 * step it names.
 */
final class SurfaceComparisonTest extends TestCase
{
    private string $root;

    #[Test]
    public function itClassifiesBothPublicationFormsBeforeReadingRecordAuthority(): void
    {
        $nativePublications = [
            ['format:html', '', 'whole-invocation', '1', "Malformed UTF-8 characters\n"],
            ['format:checkstyle', '', 'whole-invocation', '1', "Malformed UTF-8 characters\n"],
            ['format:sarif', '', 'whole-invocation', '1', "Malformed UTF-8 characters\n"],
            ['format:gitlab', '', 'whole-invocation', '1', "Malformed UTF-8 characters\n"],
            ['format:json', '{"violations":[]}', 'records', '1', ''],
            ['format:json', '{"violations":{}}', 'whole-invocation', '1', ''],
            ['format:json', '{"violations":[],"topIssues":{}}', 'whole-invocation', '0', ''],
            ['format:json', '{"violations":[[]]}', 'whole-invocation', '0', ''],
            ['format:metrics', '{"symbols":[]}', 'records', '0', ''],
            ['format:metrics', '{"symbols":{}}', 'whole-invocation', '0', ''],
            ['format:suppressed', '{"suppressed":[]}', 'records', '0', ''],
            ['format:suppressed', '{"suppressed":{}}', 'whole-invocation', '0', ''],
            ['directives', '{"directives":[]}', 'records', '0', ''],
            ['directives', '{"directives":{}}', 'whole-invocation', '0', ''],
            ['format:gitlab', '[]', 'records', '0', ''],
            ['format:gitlab', '{}', 'whole-invocation', '0', ''],
            ['format:sarif', '{"runs":[]}', 'records', '0', ''],
            ['format:sarif', '{"runs":{}}', 'whole-invocation', '0', ''],
            ['format:sarif', '{"runs":[{"results":[],"tool":{"driver":{"rules":[]}}}]}', 'records', '0', ''],
            ['format:sarif', '{"runs":[{"results":{},"tool":{"driver":{"rules":[]}}}]}', 'whole-invocation', '0', ''],
            ['format:sarif', '{"runs":[{"results":[],"tool":{"driver":{"rules":{}}}}]}', 'whole-invocation', '0', ''],
            ['format:html', '<script type="application/json" id="report-data">{"violations":[]}</script>', 'records', '0', ''],
            ['format:html', '<script type="application/json" id="report-data">{"violations":{}}</script>', 'whole-invocation', '0', ''],
            ['format:html', '<script type="application/json" id="report-data">{}</script>', 'whole-invocation', '0', ''],
            ['format:html', '<script type="application/json" id="report-data">{"metrics":{"violations":[]}}</script>', 'whole-invocation', '0', ''],
            ['format:html', '<script type="application/json" id="report-data">{"tree":{"violations":[],"children":[{"violations":[]}]},"metrics":{"violations":{}}}</script>', 'records', '0', ''],
            ['format:checkstyle', '<checkstyle/>', 'records', '0', ''],
            ['baseline-file', '{"version":14,"entries":{}}', 'records', '0', ''],
            ['baseline-file', '{"version":14,"entries":[]}', 'whole-invocation', '0', ''],
            ['baseline-file', '{"version":14,"entries":{"subject":[]}}', 'records', '0', ''],
            ['baseline-file', '{"version":14,"entries":{"subject":{}}}', 'whole-invocation', '0', ''],
        ];
        foreach ($nativePublications as [$view, $text, $expected, $exit, $stderr]) {
            $report = new GateReport();
            $run = new RunContext(
                Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], $this->root),
                $report,
                Corpus::load($this->root),
                RenameMaps::load($this->root . '/finding-gate/maps', MetricVocabulary::ofTree($this->root)),
                ChannelSplit::of(RenameMaps::fromPairs([])),
                MetricVocabulary::ofTree($this->root),
                Normalization::fromRules([]),
                \QmxFindingGate\Declarations::load($this->root),
                $this->root,
            );
            $key = 'case:alpha|' . $view;
            $artifacts = [$key => $text, 'case:alpha|stderr:' . $view => $stderr, 'case:alpha|exit:' . ($view === 'baseline-file' ? 'baseline:generate' : $view) => $exit];
            foreach (['candidate', 'reference'] as $side) {
                $run->publicationForms->supply($side, $artifacts);
                self::assertSame($expected, $run->publicationForms->of($side, $key), $side . ' / ' . $view . ' / ' . $text);
            }
            self::assertSame($expected === 'records', $run->publicationForms->recordsPair($key), $view . ' / ' . $text);
        }
        $publications = [
            ['directives', '{"directives":[],"exit_code":0}', 'directives'],
            ['format:json', '{"violations":[],"topIssues":[]}', 'violations'],
            ['format:json', '{"violations":[],"topIssues":[]}', 'topIssues'],
            ['format:metrics', '{"symbols":[]}', 'symbols'],
            ['format:suppressed', '{"suppressed":[]}', 'suppressed'],
            ['baseline-file', '{"version":14,"entries":{}}', 'entries'],
        ];
        $maps = RenameMaps::load($this->root . '/finding-gate/maps', MetricVocabulary::ofTree($this->root));
        foreach ($publications as [$view, $publication, $member]) {
            foreach ([['records', 'refusal'], ['refusal', 'records'], ['refusal', 'refusal'], ['records', 'records']] as [$candidateForm, $referenceForm]) {
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
                $key = 'case:alpha|' . $view;
                $captures = [];
                foreach (['candidate' => $candidateForm, 'reference' => $referenceForm] as $side => $form) {
                    $text = $form === 'records' ? $publication : ($view === 'baseline-file' ? '' : '{"error":"Malformed UTF-8","exit_code":3}');
                    $exit = $form === 'records' ? '0' : '3';
                    $artifacts = [$key => $text, 'case:alpha|stderr:' . $view => $form === 'records' ? '' : "Refused input\n", 'case:alpha|exit:' . ($view === 'baseline-file' ? 'baseline:generate' : $view) => $exit];
                    $slot = ['ranked' => ['stdout' => $text, 'stderr' => '', 'exit' => (int) $exit], 'physical' => null];
                    $captures[$side] = new \QmxFindingGate\CaptureResult($artifacts, [$key => $slot]);
                }
                $label = $view . ' / ' . $member . ' / ' . $candidateForm . ' / ' . $referenceForm;
                self::assertSame($candidateForm !== $referenceForm, ExactSurfaceAuthority::rawResidual($key, $captures, $run, \QmxFindingGate\RecordCheck::create($run)), $label);
                if ($candidateForm === 'records' && $referenceForm === 'records') {
                    continue;
                }
                $pair = new SurfacePair($key, $view, $captures['candidate']->artifacts[$key], $captures['reference']->artifacts[$key]);
                [$candidate, $reference] = ExactSurfaceAuthority::pair($pair, $captures, $run);
                $this->comparison($run->report, [\QmxFindingGate\RecordStage::create($run), \QmxFindingGate\ValueStage::create($run)])->compareSurfaces($captures['candidate']->artifacts, $captures['reference']->artifacts);
                self::assertSame($candidateForm === $referenceForm ? [] : [FailureClass::SURFACE_MISMATCH], $run->report->failureClasses(), $label);
                self::assertSame($candidateForm === $referenceForm, $candidate === $reference, $label);
                self::assertStringContainsString('invocation-exit ', $candidate, $label);
                self::assertStringContainsString('invocation-stderr ', $reference, $label);
                self::assertSame([], ExactSurfaceAuthority::footprint($key, $run)['rawSources'], $label);
                self::assertSame([], ExactSurfaceAuthority::footprint($key, $run)['schemas'], $label);
                foreach (['stdout', 'stderr', 'exit'] as $changed) {
                    $artifacts = $captures['reference']->artifacts;
                    $changedKey = match ($changed) {
                        'stdout' => $key,
                        'stderr' => 'case:alpha|stderr:' . $view,
                        default => 'case:alpha|exit:' . ($view === 'baseline-file' ? 'baseline:generate' : $view),
                    };
                    $artifacts[$changedKey] .= $changed === 'exit' ? '1' : " \n";
                    $changedCapture = new \QmxFindingGate\CaptureResult($artifacts, []);
                    if ($view === 'baseline-file' && (($changed === 'stdout' && $referenceForm === 'refusal') || ($changed === 'exit' && $referenceForm === 'records'))) {
                        try {
                            ExactSurfaceAuthority::pair($pair, ['candidate' => $captures['candidate'], 'reference' => $changedCapture], $run);
                            self::fail('A nonempty refusing baseline publication was accepted.');
                        } catch (GateError $error) {
                            self::assertSame('A refusing baseline invocation must retain empty captured baseline content.', $error->getMessage(), $label);
                        }
                    } else {
                        self::assertNotSame($reference, ExactSurfaceAuthority::pair($pair, ['candidate' => $captures['candidate'], 'reference' => $changedCapture], $run)[1], $label . ' / ' . $changed);
                    }
                }
            }
        }
        foreach ([['candidate', 'records'], ['reference', 'records'], ['candidate', 'refusal'], ['reference', 'refusal']] as [$side, $summaryForm]) {
            $report = new GateReport();
            $gate = new \QmxFindingGate\Gate(Options::parse(['gate', '--candidate=' . $this->root, '--reference=HEAD'], $this->root), $report);
            try {
                $run = (new ReflectionProperty($gate, 'context'))->getValue($gate);
                $capture = (new ReflectionMethod(RecordedComparison::class, 'capture'))->invoke(null, $this->root, $run);
                $key = 'case:alpha|format:json';
                $complete = json_decode($capture->artifacts[$key], true, 512, \JSON_THROW_ON_ERROR);
                $truncated = $complete;
                $truncated['violations'] = [];
                $truncated['violationsMeta']['shown'] = 0;
                $truncated['violationsMeta']['limit'] = 0;
                $truncated['violationsMeta']['truncated'] = true;
                $publication = \QmxFindingGate\ValueCheck::value($truncated);
                $artifacts = array_replace($capture->artifacts, [$key => $publication, 'case:alpha|check:output:file' => $publication]);
                if ($summaryForm === 'refusal') {
                    $artifacts['case:alpha|format:summary'] = '{"error":"Refused summary","exit_code":3}';
                    $artifacts['case:alpha|exit:format:summary'] = '3';
                    $artifacts['case:alpha|stderr:format:summary'] = "Refused summary\n";
                }
                $rankings = $capture->rankings;
                $rankings[$key]['ranked']['stdout'] = $publication;
                $rankings[$key]['physical'] = ['stdout' => \QmxFindingGate\ValueCheck::value($complete), 'stderr' => $artifacts['case:alpha|stderr:format:json'], 'exit' => (int) $artifacts['case:alpha|exit:format:json']];
                $ownCapture = new \QmxFindingGate\CaptureResult($artifacts, $rankings, $capture->baselineEligibility);
                $run->publicationForms->supply($side, $artifacts);
                $run->publicationForms->supply($side === 'candidate' ? 'reference' : 'candidate', array_replace($capture->artifacts, [$key => '{"error":"Refused input","exit_code":3}']));
                $run->rankings->supply($side, $rankings);
                $run->baselineEligibility->supply($side, $capture->baselineEligibility);
                (new ReflectionMethod($gate, 'checkFindings'))->invoke($gate, $side, $artifacts, true, $ownCapture);
                self::assertSame($complete['violations'], (new ReflectionProperty($gate, 'findingsByCase'))->getValue($gate)['alpha'] ?? null, $side . ' / summary ' . $summaryForm . ' / independent complete claims: ' . $report->render());
                self::assertFalse($report->sourceValid($side, $key, 'ranking'), $side . ' mixed claims are not comparative ranking authority');
                self::assertNotContains(FailureClass::RUN_FAILED, $report->failureClasses(), $report->render());
                $invalid = array_replace($artifacts, ['case:alpha|format:gitlab' => '{"error":"Unrelated refusal","exit_code":3}', 'case:alpha|exit:format:gitlab' => '3']);
                \QmxFindingGate\RecordCheck::create($run)->checkCase($side, $run->corpus->cases[0], \QmxFindingGate\CaseOutcome::ANALYSIS, $invalid);
                self::assertNotContains('The partial-view refusal does not match this invocation selector and native diagnostic.', array_column($report->raised(), 'detail'), $side . ' an undeclared whole publication does not invoke a partial-view form guard');
            } finally {
                $gate->cleanUp();
            }
        }
    }

    #[Test]
    public function itComparesUnchangedPartialViewRefusalsWithoutInventingFindingPublications(): void
    {
        $tree = SyntheticTree::clean();
        foreach (['gitlab', 'checkstyle'] as $format) {
            $message = 'Configuration error: Format "' . $format . '" has no place to say the report is a partial view: its consumer reads every entry as a finding. Drop --namespace, or use a format that says what the selection left out, such as json, sarif or github.';
            $tree['answers']['case:alpha|format:' . $format] = [
                'stdout' => $format === 'gitlab' ? json_encode(['error' => $message, 'exit_code' => 3, 'position' => null, 'source' => [['kind' => 'cli', 'name' => '--namespace', 'imported_by' => null]]], \JSON_THROW_ON_ERROR) : '',
                'stderr' => $format === 'checkstyle' ? $message . "\nSource: option --namespace.\nDocs: https://qualimetrix.dev · AI agents: https://qualimetrix.dev/llms.txt\n" : '',
                'exit' => 3,
            ];
        }
        $prepare = static function (string $root): void {
            $path = $root . '/finding-gate/cases/alpha/case.json';
            $case = json_decode(Fs::read($path), true, 512, \JSON_THROW_ON_ERROR);
            $case['args'] = ['--namespace=subtree:App'];
            Fs::write($path, json_encode($case, \JSON_THROW_ON_ERROR));
        };
        self::assertSame([], RecordedComparison::report($tree, $prepare)->raised());
        $tree['candidateAnswers']['case:alpha|format:gitlab'] = array_replace($tree['answers']['case:alpha|format:gitlab'], ['stdout' => '{"error":"Different failure","exit_code":3,"position":null,"source":null}']);
        self::assertContains(FailureClass::SURFACE_MISMATCH, RecordedComparison::report($tree, $prepare)->failureClasses());
        $tree['candidateAnswers'] = [];
        self::assertSame([], RecordedComparison::report($tree)->raised());
    }

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
    public function itFramesOnlyAnEmptyCapturedDeclaredBaselineRefusalAndKeepsAnalysisAuthority(): void
    {
        $tree = \QmxFindingGate\SelfTestOutcomes::fixture();
        $tree['candidateDeclarations'][\QmxFindingGate\DeclaredOutcomes::INDEX] = Tsv::render(\QmxFindingGate\DeclaredOutcomes::COLUMNS, [
            ['alpha', \QmxFindingGate\DeclaredOutcomes::REFUSAL_TO_ANALYSIS, 'declared-outcomes/alpha.json', 'The reference refuses the new input.'],
        ]);
        $tree['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
            ['alpha', 'baseline-file', 'declared-exact-surfaces/baseline.diff', 'The baseline refusal changes this complete document.'],
        ]);
        $tree['candidateDeclarations']['declared-exact-surfaces/baseline.diff'] = "pending\n";
        $root = SyntheticTree::fixture($tree);
        try {
            $maps = RenameMaps::load($root . '/finding-gate/maps', MetricVocabulary::ofTree($root));
            $run = new RunContext(
                Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root),
                new GateReport(),
                Corpus::load($root),
                $maps,
                ChannelSplit::of($maps),
                MetricVocabulary::ofTree($root),
                Normalization::fromRules([]),
                \QmxFindingGate\Declarations::load($root),
                $root,
            );
            $key = 'case:alpha|baseline-file';
            $required = ExactSurfaceAuthority::footprint($key, $run)['required'];
            self::assertContains(['side' => 'candidate', 'key' => $key, 'role' => 'records'], $required);
            self::assertContains(['side' => 'candidate', 'key' => 'case:alpha|format:json', 'role' => 'ranking'], $required);
            self::assertContains(['side' => 'reference', 'key' => $key, 'role' => 'outcome'], $required);
            self::assertNotContains(['side' => 'reference', 'key' => $key, 'role' => 'records'], $required);
            self::assertNotContains(['side' => 'reference', 'key' => 'case:alpha|format:json', 'role' => 'ranking'], $required);
            self::assertNotContains(['side' => 'reference', 'key' => 'case:alpha|format:json', 'role' => 'tuple'], $required);

            $baseline = "{\n  \"version\": 14,\n  \"entries\": {}\n" . str_repeat("\n", 240) . "}\n";
            $refusal = "{\"error\":\"Refused input\",\"exit_code\":3,\"position\":null}\n";
            $candidate = new \QmxFindingGate\CaptureResult([$key => $baseline], []);
            $reference = new \QmxFindingGate\CaptureResult([
                $key => '', 'case:alpha|exit:baseline:generate' => '3',
                'case:alpha|stderr:baseline-file' => "Refused input\n",
                'case:alpha|format:json' => $refusal,
            ], []);
            $pair = new SurfacePair($key, 'baseline-file', $baseline, '');
            [$candidateFrame, $referenceFrame] = ExactSurfaceAuthority::pair($pair, ['candidate' => $candidate, 'reference' => $reference], $run);
            self::assertStringContainsString('invocation-baseline-file ', $candidateFrame);
            self::assertStringContainsString('missing-invocation-artifact ', $candidateFrame);
            self::assertStringStartsWith("visible 0\n\n", $referenceFrame);
            self::assertStringContainsString('invocation-exit 1' . "\n" . '3', $referenceFrame);
            self::assertStringContainsString('invocation-stderr', $referenceFrame);
            self::assertStringContainsString('invocation-baseline-file 0', $referenceFrame);
            try {
                ExactSurfaceAuthority::pair(
                    $pair,
                    ['candidate' => $candidate, 'reference' => new \QmxFindingGate\CaptureResult(array_replace($reference->artifacts, [$key => '{}']), [])],
                    $run,
                );
                self::fail('A nonempty captured baseline was accepted as a refusal.');
            } catch (GateError $error) {
                self::assertSame(
                    'A refusing baseline invocation must retain empty captured baseline content.',
                    $error->getMessage(),
                );
            }
            $changedReference = new \QmxFindingGate\CaptureResult([
                $key => '', 'case:alpha|exit:baseline:generate' => '2',
                'case:alpha|stderr:baseline-file' => "Different refusal\n",
                'case:alpha|format:json' => $refusal,
            ], []);
            self::assertNotSame($referenceFrame, ExactSurfaceAuthority::pair($pair, ['candidate' => $candidate, 'reference' => $changedReference], $run)[1]);
            $changedBaseline = str_replace('"version": 14', '"version": 13', $baseline);
            self::assertNotSame($candidateFrame, ExactSurfaceAuthority::pair(
                new SurfacePair($key, 'baseline-file', $changedBaseline, ''),
                ['candidate' => new \QmxFindingGate\CaptureResult([$key => $changedBaseline], []), 'reference' => $reference],
                $run,
            )[0]);
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itRunsARegisteredStageBeforeTheStepItNames(): void
    {
        $report = new GateReport();
        $stage = self::settling('difference');
        $json = (string) json_encode(['violations' => []]);

        $this->comparison($report, [$stage])->compareSurfaces(
            ['case:alpha|format:json' => $json, 'case:alpha|format:text' => 'No findings'],
            ['case:alpha|format:json' => $json, 'case:alpha|format:text' => 'No findings'],
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
            Fs::write($configuredRoot . '/finding-gate/' . \QmxFindingGate\DeclaredFields::INDEX, Tsv::render(\QmxFindingGate\DeclaredFields::COLUMNS, [
                ['added', 'json', 'format:json', 'extra', 'The physical source adds this field.'],
                ['added', 'json', 'ranking', 'extra', 'The ranking source adds this field.'],
                ['added', 'json-document', 'format:json', 'extra', 'The JSON document adds this field.'],
                ['added', 'json', 'check:baseline-source', 'extra', 'The configured source adds this field.'],
                ['added', 'json-document', 'check:baseline-source', 'extra', 'The configured document adds this field.'],
            ]));
            $maps = RenameMaps::load($configuredRoot . '/finding-gate/maps', MetricVocabulary::ofTree($configuredRoot));
            $run = new RunContext(
                Options::parse(['gate', '--candidate=' . $configuredRoot, '--reference=HEAD'], $configuredRoot),
                new GateReport(),
                Corpus::load($configuredRoot),
                $maps,
                ChannelSplit::of($maps),
                MetricVocabulary::ofTree($configuredRoot),
                Normalization::fromRules([]),
                \QmxFindingGate\Declarations::load($configuredRoot),
                $configuredRoot,
            );
            foreach (['json' => ['format:json', 'ranking', 'check:baseline-source'], 'json-document' => ['format:json', 'check:baseline-source']] as $report => $views) {
                foreach ($views as $view) {
                    $run->declarations->fields->requireMeasurements($report, 'alpha', $view, 'candidate');
                }
            }
            $legacy = ['side' => 'candidate', 'key' => 'case:alpha|format:json', 'role' => 'schema', 'supplied' => false];
            $ranked = ['side' => 'candidate', 'key' => 'case:alpha|ranking', 'role' => 'schema', 'supplied' => false];
            $configured = ['side' => 'candidate', 'key' => 'case:alpha|check:baseline-source', 'role' => 'schema', 'supplied' => false];
            $lifecycleSchemas = ExactSurfaceAuthority::footprint('case:alpha|baseline:cleanup:file', $run)['schemas'];
            self::assertContains($legacy, $lifecycleSchemas);
            self::assertContains($ranked, $lifecycleSchemas);
            self::assertNotContains($configured, $lifecycleSchemas);
            self::assertCount(2, $lifecycleSchemas);
            $baselineSchemas = ExactSurfaceAuthority::footprint('case:alpha|baseline-file', $run)['schemas'];
            self::assertContains($legacy, $baselineSchemas);
            self::assertContains($ranked, $baselineSchemas);
            self::assertContains($configured, $baselineSchemas);
            self::assertCount(4, $baselineSchemas);
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
            $this->comparison($report, [self::settling('difference')])->compareSurfaces(['case:alpha|format:json' => '{"violations":[]}'], ['case:alpha|format:json' => '{"violations":[{}]}']);
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
            self::assertSame([], $report->failureClasses());
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
        $rawOnly = SyntheticTree::clean();
        $rawOnlyAnswer = $answer;
        $rawOnlyAnswer['stdout'] = $answers['case:alpha|format:json']['stdout'];
        self::assertNotSame($answers['case:alpha|format:json']['physical']['stdout'], $rawOnlyAnswer['physical']['stdout']);
        self::assertSame($answers['case:alpha|format:json']['stdout'], $rawOnlyAnswer['stdout']);
        $rawOnly['candidateAnswers']['case:alpha|format:json'] = $rawOnlyAnswer;
        $rawOnly['candidateAnswers']['case:alpha|rules'] = ['stdout' => "Changed rule listing.\n"];
        $rawOnly['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
            ['alpha', 'format:json', 'declared-exact-surfaces/json.diff', 'The complete physical authority changes.'],
            ['alpha', 'rules', 'declared-exact-surfaces/rules.diff', 'Measure the independent rule listing.'],
        ]);
        $rawOnly['candidateDeclarations']['declared-exact-surfaces/json.diff'] = "pending json\n";
        $rawOnly['candidateDeclarations']['declared-exact-surfaces/rules.diff'] = "pending rules\n";
        $rawOnlyRoot = SyntheticTree::fixture($rawOnly);
        try {
            [$rawOnlyReport, $rawOnlyWritten] = RecordedComparison::derive($rawOnly, $rawOnlyRoot);
            self::assertContains(FailureClass::RANKING_PROJECTION_MISMATCH, $rawOnlyReport->failureClasses(), $rawOnlyReport->render());
            self::assertNotContains('declared-exact-surfaces/' . md5('case:alpha|format:json') . '.diff', $rawOnlyWritten, $rawOnlyReport->render());
            self::assertSame("pending json\n", Fs::read($rawOnlyRoot . '/finding-gate/declared-exact-surfaces/json.diff'));
            self::assertContains('declared-exact-surfaces/' . md5('case:alpha|rules') . '.diff', $rawOnlyWritten, $rawOnlyReport->render());
            self::assertSame(
                ['case' => 'alpha', 'surface' => 'format:json', 'file' => 'declared-exact-surfaces/json.diff', 'reason' => 'The complete physical authority changes.'],
                Tsv::rows($rawOnlyRoot . '/finding-gate/' . DeclaredExactSurfaces::INDEX, DeclaredExactSurfaces::COLUMNS)[0],
            );
        } finally {
            SyntheticTree::remove($rawOnlyRoot);
        }
        $documentTree = SyntheticTree::clean();
        $document = $answers['case:alpha|format:json'];
        $document['stdout'] = substr_replace($document['stdout'], '"unrelatedResidual":1,', (int) strpos($document['stdout'], '{') + 1, 0);
        $documentTree['candidateAnswers']['case:alpha|format:json'] = $document;
        $documentTree['candidateAnswers']['case:alpha|check:output'] = ['file' => $document['stdout']];
        $documentTree['candidateAnswers']['case:alpha|check:parallel'] = ['stdout' => $document['stdout']];
        $documentTree['candidateAnswers']['case:alpha|rules'] = ['stdout' => "Changed rule listing.\n"];
        $documentTree['candidateDeclarations'][\QmxFindingGate\DeclaredFields::INDEX] = Tsv::render(\QmxFindingGate\DeclaredFields::COLUMNS, [
            ['added', 'json-document', 'format:json', 'extra', 'The JSON document requires this member.'],
        ]);
        $documentTree['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
            ['alpha', 'format:json', 'declared-exact-surfaces/json.diff', 'Measure the remaining JSON document change.'],
            ['alpha', 'rules', 'declared-exact-surfaces/rules.diff', 'Measure the independent rule listing.'],
        ]);
        $documentTree['candidateDeclarations']['declared-exact-surfaces/json.diff'] = "pending json\n";
        $documentTree['candidateDeclarations']['declared-exact-surfaces/rules.diff'] = "pending rules\n";
        $documentRoot = SyntheticTree::fixture($documentTree);
        try {
            [$documentReport, $documentWritten] = RecordedComparison::derive($documentTree, $documentRoot);
            self::assertTrue($documentReport->sourceRejected('candidate', 'case:alpha|format:json', 'schema'), $documentReport->render());
            self::assertContains(FailureClass::FIELD_VALUES_MISMATCH, $documentReport->failureClasses(), $documentReport->render());
            self::assertNotContains('declared-exact-surfaces/' . md5('case:alpha|format:json') . '.diff', $documentWritten, $documentReport->render());
            self::assertSame("pending json\n", Fs::read($documentRoot . '/finding-gate/declared-exact-surfaces/json.diff'));
            self::assertContains('declared-exact-surfaces/' . md5('case:alpha|rules') . '.diff', $documentWritten, $documentReport->render());
            $documentRows = Tsv::rows($documentRoot . '/finding-gate/' . DeclaredExactSurfaces::INDEX, DeclaredExactSurfaces::COLUMNS);
            self::assertSame('declared-exact-surfaces/json.diff', $documentRows[0]['file']);
        } finally {
            SyntheticTree::remove($documentRoot);
        }
        $missingSupplier = $documentTree;
        $missingSupplier['candidateDeclarations'][\QmxFindingGate\DeclaredFields::INDEX] = Tsv::render(\QmxFindingGate\DeclaredFields::COLUMNS, [
            ['added', 'json', 'format:json', 'extra', 'The complete physical report requires this field.'],
        ]);
        $missingRoot = SyntheticTree::fixture($missingSupplier);
        try {
            $refusal = null;
            try {
                RecordedComparison::derive($missingSupplier, $missingRoot);
            } catch (GateError $error) {
                $refusal = $error;
            }
            self::assertInstanceOf(GateError::class, $refusal);
            self::assertStringContainsString('A required record publication was not supplied', $refusal->getMessage());
            self::assertSame("pending json\n", Fs::read($missingRoot . '/finding-gate/declared-exact-surfaces/json.diff'));
            self::assertSame($missingSupplier['candidateDeclarations'][DeclaredExactSurfaces::INDEX], Fs::read($missingRoot . '/finding-gate/' . DeclaredExactSurfaces::INDEX));
            self::assertSame('declared-exact-surfaces/json.diff', Tsv::rows($missingRoot . '/finding-gate/' . DeclaredExactSurfaces::INDEX, DeclaredExactSurfaces::COLUMNS)[0]['file']);
        } finally {
            SyntheticTree::remove($missingRoot);
        }
        foreach (['check:output:file' => 'check:output', 'check:parallel' => 'check:parallel'] as $view => $invocation) {
            $aliasTree = SyntheticTree::clean();
            $aliasDocument = $answers['case:alpha|format:json'];
            $aliasDocument['stdout'] = substr_replace($aliasDocument['stdout'], '"unrelatedResidual":1,', (int) strpos($aliasDocument['stdout'], '{') + 1, 0);
            $aliasTree['candidateAnswers']['case:alpha|format:json'] = $aliasDocument;
            $aliasTree['candidateAnswers']['case:alpha|' . $invocation] = $view === 'check:output:file'
                ? ['file' => $aliasDocument['stdout']]
                : ['stdout' => $aliasDocument['stdout']];
            if ($view === 'check:parallel') {
                for ($index = 0; $index < 101; ++$index) {
                    $aliasTree['declarations']['cases/alpha/src/Shard' . $index . '.php'] = "<?php\n";
                }
            }
            $aliasTree['candidateAnswers']['case:alpha|rules'] = ['stdout' => "Changed rule listing.\n"];
            $aliasTree['candidateDeclarations'][\QmxFindingGate\DeclaredFields::INDEX] = Tsv::render(\QmxFindingGate\DeclaredFields::COLUMNS, [
                ['added', 'json-document', $view, 'extra', 'The alias document requires this member.'],
            ]);
            $aliasTree['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
                ['alpha', $view, 'declared-exact-surfaces/alias.diff', 'Measure the remaining alias document change.'],
                ['alpha', 'rules', 'declared-exact-surfaces/rules.diff', 'Measure the independent rule listing.'],
            ]);
            $aliasTree['candidateDeclarations']['declared-exact-surfaces/alias.diff'] = "pending alias\n";
            $aliasTree['candidateDeclarations']['declared-exact-surfaces/rules.diff'] = "pending rules\n";
            $aliasRoot = SyntheticTree::fixture($aliasTree);
            try {
                [$aliasReport, $aliasWritten] = RecordedComparison::derive($aliasTree, $aliasRoot);
                self::assertTrue($aliasReport->sourceRejected('candidate', 'case:alpha|' . $view, 'schema'), $view . ': ' . $aliasReport->render());
                self::assertContains(FailureClass::FIELD_VALUES_MISMATCH, $aliasReport->failureClasses(), $view . ': ' . $aliasReport->render());
                self::assertNotContains('declared-exact-surfaces/' . md5('case:alpha|' . $view) . '.diff', $aliasWritten, $view . ': ' . $aliasReport->render());
                self::assertSame("pending alias\n", Fs::read($aliasRoot . '/finding-gate/declared-exact-surfaces/alias.diff'));
                self::assertContains('declared-exact-surfaces/' . md5('case:alpha|rules') . '.diff', $aliasWritten, $view . ': ' . $aliasReport->render());
            } finally {
                SyntheticTree::remove($aliasRoot);
            }
        }
        $lifecycle = SyntheticTree::clean();
        $lifecycle['declarations']['cases/alpha/baseline-src/src/Alpha.php'] = "<?php\n";
        $baseline = \QmxFindingGate\ReportRecords::decode($answers['case:alpha|baseline-file']['file']);
        $baseline['version'] = 14;
        $lifecycle['candidateAnswers']['case:alpha|baseline:cleanup'] = ['file' => \QmxFindingGate\ValueCheck::value($baseline)];
        $lifecycle['candidateDeclarations'][\QmxFindingGate\DeclaredFields::INDEX] = Tsv::render(\QmxFindingGate\DeclaredFields::COLUMNS, [
            ['added', 'json-document', 'format:json', 'extra', 'An unrelated JSON document requires this field.'],
        ]);
        $lifecycle['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
            ['alpha', 'baseline:cleanup:file', 'declared-exact-surfaces/cleanup.diff', 'Measure the independent cleanup document.'],
        ]);
        $lifecycle['candidateDeclarations']['declared-exact-surfaces/cleanup.diff'] = "pending cleanup\n";
        $lifecycleRoot = SyntheticTree::fixture($lifecycle);
        try {
            [$lifecycleReport, $lifecycleWritten] = RecordedComparison::derive($lifecycle, $lifecycleRoot);
            self::assertTrue($lifecycleReport->sourceRejected('candidate', 'case:alpha|format:json', 'schema'), $lifecycleReport->render());
            self::assertContains(FailureClass::FIELD_VALUES_MISMATCH, $lifecycleReport->failureClasses(), $lifecycleReport->render());
            self::assertContains(DeclaredExactSurfaces::INDEX, $lifecycleWritten, $lifecycleReport->render());
            self::assertNotSame("pending cleanup\n", Fs::read($lifecycleRoot . '/finding-gate/' . Tsv::rows($lifecycleRoot . '/finding-gate/' . DeclaredExactSurfaces::INDEX, DeclaredExactSurfaces::COLUMNS)[0]['file']));
        } finally {
            SyntheticTree::remove($lifecycleRoot);
        }
        $baselineSource = SyntheticTree::clean();
        $baselineSource['declarations']['cases/alpha/baseline-src/src/Alpha.php'] = "<?php\n";
        $configured = $answers['case:alpha|check:baseline-source'];
        $configured['stdout'] = substr_replace($configured['stdout'], '"unrelatedResidual":1,', (int) strpos($configured['stdout'], '{') + 1, 0);
        $baselineSource['candidateAnswers']['case:alpha|check:baseline-source'] = $configured;
        $baselineSource['candidateAnswers']['case:alpha|baseline-file'] = ['file' => \QmxFindingGate\ValueCheck::value($baseline)];
        $baselineSource['candidateAnswers']['case:alpha|rules'] = ['stdout' => "Changed rule listing.\n"];
        $baselineSource['candidateDeclarations'][\QmxFindingGate\DeclaredFields::INDEX] = Tsv::render(\QmxFindingGate\DeclaredFields::COLUMNS, [
            ['added', 'json-document', 'check:baseline-source', 'extra', 'The configured source requires this member.'],
        ]);
        $baselineSource['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
            ['alpha', 'baseline-file', 'declared-exact-surfaces/baseline.diff', 'Measure the baseline document.'],
            ['alpha', 'rules', 'declared-exact-surfaces/rules.diff', 'Measure the independent rule listing.'],
        ]);
        $baselineSource['candidateDeclarations']['declared-exact-surfaces/baseline.diff'] = "pending baseline\n";
        $baselineSource['candidateDeclarations']['declared-exact-surfaces/rules.diff'] = "pending rules\n";
        $baselineRoot = SyntheticTree::fixture($baselineSource);
        try {
            [$baselineReport, $baselineWritten] = RecordedComparison::derive($baselineSource, $baselineRoot);
            self::assertTrue($baselineReport->sourceRejected('candidate', 'case:alpha|check:baseline-source', 'schema'), $baselineReport->render());
            self::assertContains(FailureClass::FIELD_VALUES_MISMATCH, $baselineReport->failureClasses(), $baselineReport->render());
            self::assertNotContains('declared-exact-surfaces/' . md5('case:alpha|baseline-file') . '.diff', $baselineWritten, $baselineReport->render());
            self::assertSame("pending baseline\n", Fs::read($baselineRoot . '/finding-gate/declared-exact-surfaces/baseline.diff'));
            self::assertContains('declared-exact-surfaces/' . md5('case:alpha|rules') . '.diff', $baselineWritten, $baselineReport->render());
        } finally {
            SyntheticTree::remove($baselineRoot);
        }
        foreach ([
            ['baseline-file', 'check:baseline-source'],
            ['baseline:cleanup:file', 'format:json'],
        ] as [$surface, $sourceView]) {
            $residualOnly = SyntheticTree::clean();
            if ($sourceView === 'check:baseline-source') {
                $residualOnly['declarations']['cases/alpha/baseline-src/src/Alpha.php'] = "<?php\n";
                $sourceAnswer = $answers['case:alpha|' . $sourceView];
                foreach (['stdout', 'ranked', 'physical'] as $slot) {
                    $text = $slot === 'stdout' ? $sourceAnswer['stdout'] : $sourceAnswer[$slot]['stdout'];
                    $document = \QmxFindingGate\ReportRecords::decode($text);
                    $document['violations'][0]['message'] = 'changed source message';
                    $document['topIssues'][0]['message'] = 'changed source message';
                    if ($slot === 'stdout') {
                        $sourceAnswer['stdout'] = \QmxFindingGate\ValueCheck::value($document);
                    } else {
                        $sourceAnswer[$slot]['stdout'] = \QmxFindingGate\ValueCheck::value($document);
                    }
                }
                $residualOnly['candidateAnswers']['case:alpha|' . $sourceView] = $sourceAnswer;
            } else {
                $residualOnly['candidateFindings']['alpha'] = $residualOnly['findings']['alpha'];
                $residualOnly['candidateFindings']['alpha'][0]['message'] = 'changed source message';
            }
            $residualOnly['candidateAnswers']['case:alpha|rules'] = ['stdout' => "Changed rule listing.\n"];
            $residualOnly['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
                ['alpha', $surface, 'declared-exact-surfaces/source-only.diff', 'Measure only a changed baseline document.'],
                ['alpha', 'rules', 'declared-exact-surfaces/rules.diff', 'Measure the independent rule listing.'],
            ]);
            $residualOnly['candidateDeclarations']['declared-exact-surfaces/source-only.diff'] = "pending source-only\n";
            $residualOnly['candidateDeclarations']['declared-exact-surfaces/rules.diff'] = "pending rules\n";
            $residualRoot = SyntheticTree::fixture($residualOnly);
            try {
                $capturedAnswers = json_decode(Fs::read($residualRoot . '/replay/answers.json'), true, 512, \JSON_THROW_ON_ERROR);
                $ownInvocation = $surface === 'baseline-file' ? 'baseline-file' : 'baseline:cleanup';
                self::assertSame($answers['case:alpha|' . $ownInvocation]['file'], $capturedAnswers['case:alpha|' . $ownInvocation]['file']);
                [$sourceReport, $sourceWritten] = RecordedComparison::derive($residualOnly, $residualRoot);
                self::assertTrue($sourceReport->hasSemanticResidual('case:alpha|' . $sourceView), $surface . ': ' . $sourceReport->render());
                self::assertFalse($sourceReport->hasSemanticResidual('case:alpha|' . $surface), $surface . ': ' . $sourceReport->render());
                self::assertContains(FailureClass::VALUE_MISMATCH, $sourceReport->failureClasses(), $surface . ': ' . $sourceReport->render());
                self::assertNotContains('declared-exact-surfaces/' . md5('case:alpha|' . $surface) . '.diff', $sourceWritten, $surface . ': ' . $sourceReport->render());
                self::assertSame("pending source-only\n", Fs::read($residualRoot . '/finding-gate/declared-exact-surfaces/source-only.diff'));
                self::assertSame(
                    ['case' => 'alpha', 'surface' => $surface, 'file' => 'declared-exact-surfaces/source-only.diff', 'reason' => 'Measure only a changed baseline document.'],
                    Tsv::rows($residualRoot . '/finding-gate/' . DeclaredExactSurfaces::INDEX, DeclaredExactSurfaces::COLUMNS)[0],
                );
                self::assertContains('declared-exact-surfaces/' . md5('case:alpha|rules') . '.diff', $sourceWritten, $surface . ': ' . $sourceReport->render());
                self::assertContains(FailureClass::DELTA_STALE, RecordedComparison::reportAt($residualOnly, $residualRoot)->failureClasses(), $surface);
            } finally {
                SyntheticTree::remove($residualRoot);
            }
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
        $answers = json_decode(Fs::read($this->root . '/replay/answers.json'), true, 512, \JSON_THROW_ON_ERROR);
        foreach (['format:metrics', 'directives', 'format:suppressed'] as $surface) {
            $independent = SyntheticTree::clean();
            $publication = \QmxFindingGate\ReportRecords::decode($answers['case:alpha|' . $surface]['stdout']);
            if ($surface === 'format:metrics') {
                $publication['symbols'][0]['metrics']['ccn'] = 2;
            } elseif ($surface === 'directives') {
                $publication['directives'][0]['reason'] = 'changed';
            } else {
                $publication['byMechanism'] = ['probe' => 1];
            }
            $independent['candidateAnswers']['case:alpha|' . $surface] = ['stdout' => \QmxFindingGate\ValueCheck::value($publication)];
            $independent['candidateDeclarations'][\QmxFindingGate\DeclaredFields::INDEX] = Tsv::render(\QmxFindingGate\DeclaredFields::COLUMNS, [
                ['added', 'json-document', 'format:json', 'extra', 'The unrelated finding document requires this member.'],
            ]);
            $independent['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
                ['alpha', $surface, 'declared-exact-surfaces/independent.diff', 'Measure only this own publication.'],
            ]);
            $independent['candidateDeclarations']['declared-exact-surfaces/independent.diff'] = "pending\n";
            $independentRoot = SyntheticTree::fixture($independent);
            try {
                [$independentReport, $independentWritten] = RecordedComparison::derive($independent, $independentRoot);
                self::assertTrue($independentReport->sourceRejected('candidate', 'case:alpha|format:json', 'schema'), $surface . ': ' . $independentReport->render());
                self::assertContains(FailureClass::FIELD_VALUES_MISMATCH, $independentReport->failureClasses(), $surface . ': ' . $independentReport->render());
                self::assertContains(DeclaredExactSurfaces::INDEX, $independentWritten, $surface . ': ' . $independentReport->render());
            } finally {
                SyntheticTree::remove($independentRoot);
            }
        }
    }

    #[Test]
    public function itLeavesAnExactIntentionStaleWhenAMetricChangeIsSemanticallyExplained(): void
    {
        $tree = SyntheticTree::clean();
        $tree['candidateAnswers']['case:alpha|format:metrics'] = ['stdout' => json_encode([
            'symbols' => [['type' => 'method', 'name' => 'Replay\\Alpha::run', 'file' => 'src/Alpha.php', 'line' => 1, 'metrics' => ['ccn' => 2]]],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n"];
        $tree['candidateAnswers']['case:alpha|format:metrics']['stdout'] = str_replace(
            '"ccn": 2',
            '"ccn": 2.00000000000000001',
            $tree['candidateAnswers']['case:alpha|format:metrics']['stdout'],
        );
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
        $split = SyntheticTree::clean();
        $channels = ['health.complexity', 'health.cohesion'];
        sort($channels);
        $split['cases']['alpha'] = array_map(static fn(string $channel): string => $channel . '@callable', $channels);
        $split['static'] = $split['fixture'] = array_fill_keys($channels, ['callable']);
        $split['findings']['alpha'] = $split['candidateFindings']['alpha'] = [];
        foreach ($channels as $index => $channel) {
            $reference = SyntheticTree::finding($split['tuple'], $channel, 'declaration:callable:Replay\\Alpha::run' . $index . '@src/Alpha.php');
            $reference['rule'] = 'computed.health';
            $candidate = $reference;
            $candidate['rule'] = $channel;
            $split['findings']['alpha'][] = $reference;
            $split['candidateFindings']['alpha'][] = $candidate;
            $split['maps']['channels'][] = 'computed.health#' . $channel . "\t" . $channel . '#' . $channel . "\tA declared producer movement.";
        }
        $split['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
            ['alpha', 'format:json', 'declared-exact-surfaces/split.diff', 'Measure any remaining finding authority.'],
        ]);
        $split['candidateDeclarations']['declared-exact-surfaces/split.diff'] = "pending split\n";
        $splitRoot = SyntheticTree::fixture($split);
        try {
            [$splitReport, $splitWritten] = RecordedComparison::derive($split, $splitRoot);
            self::assertNotContains(DeclaredExactSurfaces::INDEX, $splitWritten, $splitReport->render());
            self::assertSame("pending split\n", Fs::read($splitRoot . '/finding-gate/declared-exact-surfaces/split.diff'));
        } finally {
            SyntheticTree::remove($splitRoot);
        }
        $splitOrdinary = RecordedComparison::report($split);
        self::assertContains(FailureClass::DELTA_STALE, $splitOrdinary->failureClasses(), $splitOrdinary->render());
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
        foreach (['check:baseline-source', 'check:baseline'] as $view) {
            $filtered = SyntheticTree::clean();
            $filtered['declarations']['cases/alpha/baseline-src/src/Alpha.php'] = "<?php\n";
            $answer = $answers['case:alpha|' . $view];
            $answer['stdout'] = substr_replace($answer['stdout'], '"unrelatedResidual":1,', (int) strpos($answer['stdout'], '{') + 1, 0);
            $filtered['candidateAnswers']['case:alpha|' . $view] = $answer;
            $filtered['candidateAnswers']['case:alpha|rules'] = ['stdout' => "Changed rule listing.\n"];
            $filtered['candidateDeclarations'][\QmxFindingGate\DeclaredFields::INDEX] = Tsv::render(\QmxFindingGate\DeclaredFields::COLUMNS, [
                ['added', 'json-document', $view, 'extra', 'The filtered document requires this member.'],
            ]);
            $filtered['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
                ['alpha', $view, 'declared-exact-surfaces/filtered.diff', 'Measure the remaining filtered document change.'],
                ['alpha', 'rules', 'declared-exact-surfaces/rules.diff', 'Measure the independent rule listing.'],
            ]);
            $filtered['candidateDeclarations']['declared-exact-surfaces/filtered.diff'] = "pending filtered\n";
            $filtered['candidateDeclarations']['declared-exact-surfaces/rules.diff'] = "pending rules\n";
            $filteredRoot = SyntheticTree::fixture($filtered);
            try {
                [$filteredReport, $filteredWritten] = RecordedComparison::derive($filtered, $filteredRoot);
                self::assertTrue($filteredReport->sourceRejected('candidate', 'case:alpha|' . $view, 'schema'), $view . ': ' . $filteredReport->render());
                self::assertContains(FailureClass::FIELD_VALUES_MISMATCH, $filteredReport->failureClasses(), $view . ': ' . $filteredReport->render());
                self::assertNotContains('declared-exact-surfaces/' . md5('case:alpha|' . $view) . '.diff', $filteredWritten, $view . ': ' . $filteredReport->render());
                self::assertSame("pending filtered\n", Fs::read($filteredRoot . '/finding-gate/declared-exact-surfaces/filtered.diff'));
                self::assertContains('declared-exact-surfaces/' . md5('case:alpha|rules') . '.diff', $filteredWritten, $view . ': ' . $filteredReport->render());
            } finally {
                SyntheticTree::remove($filteredRoot);
            }
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
