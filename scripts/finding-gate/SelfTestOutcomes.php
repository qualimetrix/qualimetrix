<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Exact declared endings observed through public compare and derive.
 *
 * @phpstan-import-type Specification from SyntheticTree
 * @phpstan-import-type Witness from CheckWitnesses
 */
final class SelfTestOutcomes extends SelfTestGroup
{
    public function outcomes(): void
    {
        $root = SyntheticTree::create(self::fixture());
        try {
            $before = Fs::read($root . '/finding-gate/declared-outcomes/alpha.json');
            $report = new GateReport();
            GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD', '--derive-declarations'], $root), $report);
            $this->same(GateReport::VERDICT_RED, $report->verdict(), 'a whole-invocation refusal cannot derive an outcome snapshot');
            $this->assert(self::has($report, FailureClass::SURFACE_MISMATCH), 'the refusal remains an exact whole-invocation comparison');
            $this->same($before, Fs::read($root . '/finding-gate/declared-outcomes/alpha.json'), 'a refused derive run writes no outcome snapshot');
            $report = new GateReport();
            GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report);
            $this->same(GateReport::VERDICT_RED, $report->verdict(), 'a whole-invocation refusal cannot compare through an outcome snapshot');
            $this->assert(self::has($report, FailureClass::OUTCOME_DECLARATION_STALE), 'the uncredited outcome declaration remains stale');
            $this->same($before, Fs::read($root . '/finding-gate/declared-outcomes/alpha.json'), 'ordinary compare writes no outcome snapshot');
        } finally {
            SyntheticTree::remove($root);
        }
        foreach (['stderr', 'stdout', 'exit'] as $field) {
            $tree = self::fixture();
            $tree['candidateAnswers'] = [];
            if ($field === 'exit') {
                $tree['candidateAnswers']['case:alpha|format:text']['exit'] = 2;
            } elseif ($field === 'stdout') {
                $tree['candidateAnswers']['case:alpha|format:text']['stdout'] = 'A different refusal cause';
            } else {
                $tree['candidateAnswers']['case:alpha|format:text']['stderr'] = 'A different refusal cause';
            }
            $scope = 'case:alpha|' . ($field === 'stdout' ? 'format:text' : $field . ':format:text');
            $root = SyntheticTree::create($tree);
            try {
                $before = Fs::read($root . '/finding-gate/declared-outcomes/alpha.json');
                $report = new GateReport();
                GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report);
                $this->same(GateReport::VERDICT_RED, $report->verdict(), 'a neighboring ' . $field . ' change remains RED');
                $this->assert(\in_array([FailureClass::SURFACE_MISMATCH, $scope], array_map(
                    static fn(array $failure): array => [$failure['class'], $failure['scope']],
                    $report->raised(),
                ), true), 'the neighboring ' . $field . ' change has its exact whole-invocation scope');
                $this->same($before, Fs::read($root . '/finding-gate/declared-outcomes/alpha.json'), 'ordinary compare writes no outcome snapshot');
            } finally {
                SyntheticTree::remove($root);
            }
        }
        $tree = self::fixture();
        $tree['candidateAnswers'] = [];
        $tree['candidateAnswers']['case:alpha|rules'] = ['stdout' => 'An unrelated catalog change.'];
        $root = SyntheticTree::create($tree);
        try {
            $before = Fs::read($root . '/finding-gate/declared-outcomes/alpha.json');
            $report = new GateReport();
            GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD', '--derive-declarations'], $root), $report);
            $this->same(GateReport::VERDICT_RED, $report->verdict(), 'a non-check publication remains RED');
            $this->assert(\in_array([FailureClass::SURFACE_MISMATCH, 'case:alpha|rules'], array_map(
                static fn(array $failure): array => [$failure['class'], $failure['scope']],
                $report->raised(),
            ), true), 'a non-check publication remains under its ordinary comparison');
            $this->same($before, Fs::read($root . '/finding-gate/declared-outcomes/alpha.json'), 'a RED derive run writes no outcome snapshot');
        } finally {
            SyntheticTree::remove($root);
        }
    }

    public function outputPublication(): void
    {
        $tree = self::fixture();
        $tree['candidateAnswers']['case:alpha|check:output'] = [
            'stdout' => '', 'stderr' => "Refused input\nReport written to {{output}}\n", 'exit' => 3,
            'file' => json_encode(['error' => 'Refused input', 'exit_code' => 3, 'position' => null], \JSON_THROW_ON_ERROR) . "\n",
        ];
        $root = SyntheticTree::create($tree);
        try {
            $snapshot = Fs::read($root . '/finding-gate/declared-outcomes/alpha.json');
            $report = new GateReport();
            GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD', '--derive-declarations'], $root), $report);
            $this->same(GateReport::VERDICT_RED, $report->verdict(), 'a real refusal file cannot license a whole-invocation outcome snapshot');
            $this->assert(self::has($report, FailureClass::SURFACE_MISMATCH), 'the refusal file remains in the whole-invocation comparison');
            $this->assert(!self::has($report, FailureClass::RUN_FAILED), 'the valid selected destination passes its product guard');
            $this->same($snapshot, Fs::read($root . '/finding-gate/declared-outcomes/alpha.json'), 'a RED derive run writes no refusal snapshot');
            $report = new GateReport();
            GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report);
            $this->same(GateReport::VERDICT_RED, $report->verdict(), 'a refusal file compares through its whole invocation');
            $this->assert(self::has($report, FailureClass::OUTCOME_DECLARATION_STALE), 'a whole refusal file does not credit the outcome declaration');
            $this->same($snapshot, Fs::read($root . '/finding-gate/declared-outcomes/alpha.json'), 'ordinary compare writes no refusal snapshot');
        } finally {
            SyntheticTree::remove($root);
        }
        foreach (["Refused input\n", "Report written to /wrong.json\n", "Report written to {{output}}\nReport written to {{output}}\n"] as $stderr) {
            $tree['candidateDeclarations']['declared-outcomes/alpha.json'] = $snapshot;
            $tree['candidateAnswers']['case:alpha|check:output']['stderr'] = $stderr;
            $root = SyntheticTree::create($tree);
            try {
                $before = Fs::read($root . '/finding-gate/declared-outcomes/alpha.json');
                $result = Process::run([\PHP_BINARY, $this->candidateRoot . '/scripts/finding-gate.php', '--candidate=' . $root, '--reference=HEAD'], $this->candidateRoot);
                $this->same(GateReport::EXIT_RED, $result['exit'], 'a refusal file cannot bypass the selected-destination guard');
                $this->assert(str_contains($result['stdout'], 'FAIL [run-failed]'), 'the refusal is preserved in the public failed verdict');
                $this->assert(str_contains($result['stdout'], 'does not name exactly the chosen file'), 'the refusal is the real destination guard');
                $this->same($before, Fs::read($root . '/finding-gate/declared-outcomes/alpha.json'), 'an invalid refusal publication writes no snapshot');
            } finally {
                SyntheticTree::remove($root);
            }
        }
    }

    /** @return Specification */
    public static function fixture(string $snapshot = "placeholder\n"): array
    {
        $tree = SyntheticTree::clean();
        $tree['cases']['keeper'] = ['replay.alpha@callable'];
        $tree['findings']['keeper'] = [SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\\Keeper::run@src/Keeper.php')];
        $tree['declarations']['cases/alpha/case.json'] = json_encode([
            'id' => 'alpha', 'description' => 'A check input whose refusal is declared.', 'coverage' => 'auxiliary',
            'paths' => ['src'], 'config' => 'qmx.yaml', 'channels' => ['replay.alpha@callable'],
        ], \JSON_THROW_ON_ERROR);
        $tree['candidateDeclarations'][DeclaredOutcomes::INDEX] = Tsv::render(DeclaredOutcomes::COLUMNS, [
            ['alpha', DeclaredOutcomes::ANALYSIS_TO_REFUSAL, 'declared-outcomes/alpha.json', 'The check input is now refused.'],
        ]);
        $tree['candidateDeclarations']['declared-outcomes/alpha.json'] = $snapshot;
        foreach ([...array_map(static fn(string $format): string => 'format:' . $format, Surfaces::FORMATS), 'show-suppressed', 'check:output'] as $surface) {
            $tree['candidateAnswers']['case:alpha|' . $surface] = ['stdout' => '', 'stderr' => "Refused input\n", 'exit' => 3];
        }
        $tree['candidateAnswers']['case:alpha|format:json']['stdout'] = json_encode(['error' => 'Refused input', 'exit_code' => 3, 'position' => null], \JSON_THROW_ON_ERROR) . "\n";
        $tree['candidateAnswers']['case:alpha|check:output']['missingFile'] = true;
        return $tree;
    }

    /** @return list<Witness> */
    public static function witnesses(): array
    {
        return [CheckWitnesses::witness(
            'case-outcome-exact-exit',
            CheckWitnesses::WHOLE_RUN,
            static function (array $tree): array {
                $tree['declarations']['cases/alpha/case.json'] = json_encode([
                    'id' => 'alpha', 'description' => 'A declared incomplete analysis.', 'paths' => ['src'],
                    'config' => 'qmx.yaml', 'channels' => ['replay.alpha@callable'],
                    'outcome' => ['kind' => CaseOutcome::INCOMPLETE, 'exit' => 4],
                ], \JSON_THROW_ON_ERROR);
                return $tree;
            },
            [[FailureClass::CASE_OUTCOME_MISMATCH, '* / alpha', 'CaseOutcomeCheck::mismatch <- Gate::checkFindings']],
        ), CheckWitnesses::witness(
            'candidate-channel-input-refusal',
            CheckWitnesses::WHOLE_RUN,
            static function (array $tree): array {
                $tree['static']['replay.alpha'] = [];
                return $tree;
            },
            [[FailureClass::CANDIDATE_INPUT_REFUSED, 'corpus', 'CoverageCheck::inputRefused <- Gate::compare']],
        ), CheckWitnesses::witness(
            'corpus-invalid-metadata',
            CheckWitnesses::WHOLE_RUN,
            static function (array $tree): array {
                $tree['declarations']['cases/alpha/case.json'] = '{"id":"alpha"}';
                return $tree;
            },
            [[FailureClass::CORPUS_INVALID, 'corpus', 'GateModes::run']],
        )];
    }

    private static function has(GateReport $report, string $class): bool
    {
        return \in_array($class, array_column($report->raised(), 'class'), true);
    }
}
