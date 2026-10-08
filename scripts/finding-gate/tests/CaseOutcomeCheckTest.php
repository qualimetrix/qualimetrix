<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CaseOutcome;
use QmxFindingGate\CaseOutcomeCheck;
use QmxFindingGate\ChannelSplit;
use QmxFindingGate\Corpus;
use QmxFindingGate\CorpusInvalid;
use QmxFindingGate\Declarations;
use QmxFindingGate\FailureClass;
use QmxFindingGate\Fs;
use QmxFindingGate\GateModes;
use QmxFindingGate\GateReport;
use QmxFindingGate\MetricVocabulary;
use QmxFindingGate\Normalization;
use QmxFindingGate\Options;
use QmxFindingGate\Process;
use QmxFindingGate\RenameMaps;
use QmxFindingGate\RunContext;
use QmxFindingGate\SelfTestOutcomes;
use QmxFindingGate\SyntheticTree;
use ReflectionMethod;
use WeakReference;

/**
 * A case's outcome decides which of the run checks apply to it, as `CaseOutcome::CHECKS` states.
 */
final class CaseOutcomeCheckTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itReadsTheExplicitEnvelopeFromTheRepairingEncoder(): void
    {
        $source = <<<'PHP'
<?php
final class RefusalPresenter {
    private function writeEnvelope() {
        return PublishedUtf8::encodeJsonObject([
            'error' => 'refused',
            'exit_code' => 3,
            'position' => null,
            'source' => null,
        ], JSON_PRETTY_PRINT);
    }
}
PHP;
        self::assertSame(['error', 'exit_code', 'position', 'source'], CaseOutcomeCheck::deriveRefusalFields($source));
    }

    #[Test]
    public function itCreditsOnlyTheDeclaredEmptyCapturedBaselineRefusal(): void
    {
        $tree = SelfTestOutcomes::fixture();
        $tree['candidateDeclarations'][\QmxFindingGate\DeclaredExactSurfaces::INDEX] = \QmxFindingGate\Tsv::render(\QmxFindingGate\DeclaredExactSurfaces::COLUMNS, [
            ['alpha', 'baseline-file', 'declared-exact-surfaces/baseline.diff', 'The baseline refusal changes this complete document.'],
        ]);
        $tree['candidateDeclarations']['declared-exact-surfaces/baseline.diff'] = "pending\n";
        $root = SyntheticTree::fixture($tree);
        try {
            $maps = RenameMaps::load($root . '/finding-gate/maps', MetricVocabulary::ofTree($root));
            $corpus = Corpus::load($root);
            $case = $corpus->cases[0];
            $refusal = json_encode(['error' => 'Refused input', 'exit_code' => 3, 'position' => null], \JSON_THROW_ON_ERROR) . "\n";
            $base = [];
            $plan = \QmxFindingGate\CapturePlan::forCorpus($corpus, Declarations::load($root)->surfaces);
            foreach ($plan->invocations() as $descriptor) {
                if ($descriptor['scope'] !== 'case:alpha' || $descriptor['commandClass'] !== 'check') {
                    continue;
                }
                $surface = $descriptor['surface'];
                $base['case:alpha|' . $surface] = $surface === 'format:json' ? $refusal : "Refused input\n";
                $base['case:alpha|stderr:' . $surface] = "Refused input\n";
                $base['case:alpha|exit:' . $surface] = '3';
                if ($descriptor['outputFileKind'] !== null) {
                    $base['case:alpha|' . $descriptor['outputFileKind']] = '';
                }
            }
            $base['case:alpha|baseline-file'] = '';
            $base['case:alpha|exit:baseline:generate'] = '3';
            $base['case:alpha|stderr:baseline-file'] = "Refused input\n";
            foreach ([
                'valid whole invocation' => [[], false],
                'nonempty captured baseline' => [['case:alpha|baseline-file' => '{}'], true],
                'successful baseline exit' => [['case:alpha|exit:baseline:generate' => '0'], false],
                'silent baseline refusal' => [['case:alpha|stderr:baseline-file' => ''], false],
            ] as $label => [$changes, $productFailure]) {
                $report = new GateReport();
                $run = new RunContext(
                    Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root),
                    $report,
                    $corpus,
                    $maps,
                    ChannelSplit::of($maps),
                    MetricVocabulary::ofTree($root),
                    Normalization::fromRules([]),
                    Declarations::load($root),
                    $root,
                );
                $check = CaseOutcomeCheck::create($run);
                $check->startDeriving();
                $candidate = array_replace($base, $changes);
                $check->checkCase('candidate', $case, CaseOutcome::REFUSAL, $candidate);
                $check->checkRun($candidate, [
                    'case:alpha|format:json' => '{"violations":[]}',
                    'case:alpha|exit:format:json' => '0',
                ]);
                self::assertFalse($report->sourceValid('candidate', 'case:alpha|baseline-file', 'outcome'), $label . ': ' . $report->render());
                self::assertSame($productFailure, \in_array(FailureClass::CASE_OUTCOME_MISMATCH, $report->failureClasses(), true), $label . ': ' . $report->render());
                if ($productFailure) {
                    self::assertContains(
                        'A refusing baseline invocation must retain empty captured baseline content.',
                        array_column($report->raised(), 'detail'),
                        $label,
                    );
                }
            }
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itHoldsAnAnalysisToItsBaselineFile(): void
    {
        self::assertSame([FailureClass::RUN_FAILED, FailureClass::RUN_FAILED], $this->failuresWithoutABaselineFile(null));
    }

    #[Test]
    public function itDoesNotHoldAnIncompleteAnalysisToABaselineFileItCannotWrite(): void
    {
        self::assertSame([], $this->failuresWithoutABaselineFile(['kind' => 'incomplete', 'exit' => 4]));
    }

    #[Test]
    public function itSharesOutcomeRolesWhileLiveAndReleasesTheirRun(): void
    {
        $root = SyntheticTree::fixture(SyntheticTree::clean());
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
            $check = CaseOutcomeCheck::create($run);
            self::assertSame($check, CaseOutcomeCheck::create($run));
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

    /** @return iterable<string,array{array<string,string>,int}> */
    public static function provideIncompletePublications(): iterable
    {
        yield 'the exact incomplete ending' => [[], 0];
        yield 'missing JSON exit' => [['case:alpha|exit:format:json' => ''], 1];
        yield 'wrong declared exit' => [['case:alpha|exit:format:json' => '2'], 1];
        yield 'unknown invocation' => [['case:alpha|exit:format:json' => '70'], 1];
        yield 'missing findings' => [['case:alpha|format:json' => '{"coverage":{"complete":false}}'], 1];
        yield 'coverage claims completeness' => [['case:alpha|format:json' => '{"violations":[],"coverage":{"complete":true}}'], 1];
        yield 'baseline succeeds' => [['case:alpha|exit:baseline:generate' => '0'], 1];
        yield 'baseline exists' => [['case:alpha|baseline-file' => '{}'], 1];
    }

    /** @param array<string,string> $changes */
    #[Test]
    #[DataProvider('provideIncompletePublications')]
    public function itRequiresTheCompleteIncompleteEnding(array $changes, int $failures): void
    {
        $tree = SyntheticTree::clean();
        $tree['declarations']['cases/alpha/case.json'] = json_encode([
            'id' => 'alpha', 'description' => 'A declared incomplete analysis.', 'paths' => ['src'],
            'config' => 'qmx.yaml', 'channels' => ['replay.alpha@callable'],
            'outcome' => ['kind' => CaseOutcome::INCOMPLETE, 'exit' => 4],
        ], \JSON_THROW_ON_ERROR);
        $root = SyntheticTree::fixture($tree);
        try {
            $corpus = Corpus::load($root);
            $report = new GateReport();
            (new CaseOutcomeCheck($report, $corpus))->checkCase('candidate', $corpus->cases[0], CaseOutcome::INCOMPLETE, array_replace([
                'case:alpha|exit:format:json' => '4', 'case:alpha|stderr:format:json' => '',
                'case:alpha|format:json' => '{"violations":[],"coverage":{"complete":false}}',
                'case:alpha|exit:baseline:generate' => '4', 'case:alpha|baseline-file' => '',
            ], $changes));
            self::assertSame(array_fill(0, $failures, FailureClass::CASE_OUTCOME_MISMATCH), array_column($report->raised(), 'class'));
        } finally {
            SyntheticTree::remove($root);
        }
    }

    /** @return iterable<string,array{string,int}> */
    public static function provideRefusalEnvelopes(): iterable
    {
        $envelope = ['error' => 'Refused input', 'exit_code' => 3, 'position' => null,
            'source' => [['kind' => 'resolved', 'name' => null, 'imported_by' => null]]];
        yield 'the coherent envelope' => [json_encode($envelope, \JSON_THROW_ON_ERROR), 0];
        yield 'a different exit' => [json_encode(array_replace($envelope, ['exit_code' => 2]), \JSON_THROW_ON_ERROR), 0];
        yield 'no exact error keys' => ['{"error":"Refused input","exit_code":3}', 0];
        yield 'the envelope before source publication' => ['{"error":"Refused input","exit_code":3,"position":null}', 0];
        yield 'a malformed position' => [json_encode(array_replace($envelope, ['position' => 'unknown']), \JSON_THROW_ON_ERROR), 0];
        yield 'an empty error' => [json_encode(array_replace($envelope, ['error' => '']), \JSON_THROW_ON_ERROR), 0];
        yield 'an analysis envelope' => ['{"violations":[]}', 1];
        yield 'malformed JSON' => ['not JSON', 0];
    }

    #[Test]
    #[DataProvider('provideRefusalEnvelopes')]
    public function itJudgesOnlyNativeRecordsAgainstADeclaredRefusalEnvelope(string $json, int $failures): void
    {
        $tree = SyntheticTree::clean();
        $tree['declarations']['cases/alpha/case.json'] = json_encode([
            'id' => 'alpha', 'description' => 'A declared input refusal.', 'paths' => ['src'],
            'config' => 'qmx.yaml', 'channels' => ['replay.alpha@callable'],
            'outcome' => ['kind' => CaseOutcome::REFUSAL, 'exit' => 3],
        ], \JSON_THROW_ON_ERROR);
        $root = SyntheticTree::fixture($tree);
        try {
            $corpus = Corpus::load($root);
            $report = new GateReport();
            (new CaseOutcomeCheck($report, $corpus))->checkCase('candidate', $corpus->cases[0], CaseOutcome::REFUSAL, [
                'case:alpha|exit:format:json' => '3', 'case:alpha|stderr:format:json' => 'Refused input',
                'case:alpha|format:json' => $json,
            ]);
            self::assertSame(array_fill(0, $failures, FailureClass::CASE_OUTCOME_MISMATCH), array_column($report->raised(), 'class'));
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    #[Group('finding-gate-e2e')]
    public function itJudgesNormalizationMeasurementsByTheirCanonicalOutcomeSide(): void
    {
        foreach ([false, true] as $malformed) {
            $tree = SelfTestOutcomes::fixture();
            if ($malformed) {
                $publication = (new ReflectionMethod(SyntheticTree::class, 'findingPublication'))->invoke(null, []);
                $stdout = json_encode($publication, \JSON_THROW_ON_ERROR);
                $tree['candidateAnswers']['case:alpha|format:json']['stdout'] = $stdout;
                $tree['candidateAnswers']['case:alpha|format:json']['ranked'] = ['stdout' => $stdout];
                $tree['candidateAnswers']['case:alpha|format:json']['physical'] = ['stdout' => $stdout];
                self::assertSame('records', \QmxFindingGate\PublicationForms::classify('case:alpha|format:json', $stdout));
            }
            $root = SyntheticTree::create($tree);
            try {
                $before = Fs::read($root . '/finding-gate/normalization.tsv');
                $path = $root . '/normalization-report.json';
                $result = Process::run([
                    \PHP_BINARY, \dirname(__DIR__, 2) . '/finding-gate.php',
                    '--candidate=' . $root, '--reference=HEAD', '--derive-normalization', '--report=' . $path,
                ], \dirname(__DIR__, 3));
                self::assertSame($malformed ? 5 : 4, $result['exit'], $result['stderr']);
                $report = json_decode(Fs::read($path), true, 512, \JSON_THROW_ON_ERROR);
                if ($malformed) {
                    self::assertContains(FailureClass::CASE_OUTCOME_MISMATCH, array_column($report['failures'], 'class'));
                    self::assertSame($before, Fs::read($root . '/finding-gate/normalization.tsv'));
                } else {
                    self::assertSame([], $report['failures']);
                }
            } finally {
                SyntheticTree::remove($root);
            }
        }
    }

    #[Test]
    #[Group('finding-gate-e2e')]
    public function itRefusesInvalidCorpusMetadataBeforeEveryCorpusReadingModeWrites(): void
    {
        $tree = SyntheticTree::clean();
        $tree['declarations']['cases/alpha/case.json'] = '{"id":"alpha"}';
        $root = SyntheticTree::fixture($tree);
        try {
            foreach ([[], ['--derive-declarations'], ['--derive-normalization']] as $flags) {
                $before = Fs::read($root . '/finding-gate/normalization.tsv');
                $report = new GateReport();
                ob_start();
                try {
                    $exit = GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD', ...$flags], $root), $report);
                } finally {
                    ob_end_clean();
                }
                self::assertSame($flags === [] ? 1 : 5, $exit);
                self::assertSame([FailureClass::CORPUS_INVALID], array_column($report->raised(), 'class'));
                self::assertSame($before, Fs::read($root . '/finding-gate/normalization.tsv'));
            }
            $this->expectException(CorpusInvalid::class);
            Corpus::load($root);
        } finally {
            SyntheticTree::remove($root);
        }
    }

    /**
     * @param array{kind: string, exit: int}|null $outcome
     *
     * @return list<string>
     */
    private function failuresWithoutABaselineFile(?array $outcome): array
    {
        $tree = SyntheticTree::clean();

        if ($outcome !== null) {
            $tree['declarations']['cases/alpha/case.json'] = json_encode([
                'id' => 'alpha',
                'description' => 'A case whose analysis ends incomplete.',
                'paths' => ['src'],
                'config' => 'qmx.yaml',
                'channels' => ['replay.alpha@callable'],
                'outcome' => $outcome,
            ], \JSON_THROW_ON_ERROR);
        }

        $root = SyntheticTree::fixture($tree);

        try {
            $corpus = Corpus::load($root);
            $report = new GateReport();
            $findings = (new CaseOutcomeCheck($report, $corpus))->findingsOf('candidate', $corpus->cases[0], [
                'case:alpha|format:json' => '{"violations": []}',
                'case:alpha|exit:baseline:generate' => '4',
            ]);

            self::assertSame([], $findings);

            return array_column($report->raised(), 'class');
        } finally {
            SyntheticTree::remove($root);
        }
    }
}
