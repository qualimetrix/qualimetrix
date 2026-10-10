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
    public function outputPublication(): void
    {
        $tree = self::fixture();
        $tree['candidateAnswers']['case:alpha|check:output'] = [
            'stdout' => '', 'stderr' => "Another refusal cause\nReport written to {{output}}\n", 'exit' => 3,
            'file' => json_encode(['error' => 'Another refusal cause', 'exit_code' => 3, 'position' => null], \JSON_THROW_ON_ERROR) . "\n",
        ];
        $root = SyntheticTree::create($tree);
        try {
            foreach ([[], ['--derive-declarations']] as $flags) {
                $report = new GateReport();
                GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD', ...$flags], $root), $report);
                $this->same(GateReport::VERDICT_RED, $report->verdict(), 'a changed refusal file remains under its whole-invocation comparison');
                $this->assert(self::has($report, FailureClass::SURFACE_MISMATCH), 'the refusal file differs from its reference publication');
                $this->assert(!self::has($report, FailureClass::RUN_FAILED), 'the valid selected destination passes its product guard');
            }
        } finally {
            SyntheticTree::remove($root);
        }
        foreach (["Refused input\n", "Report written to /wrong.json\n", "Report written to {{output}}\nReport written to {{output}}\n"] as $stderr) {
            $tree['candidateAnswers']['case:alpha|check:output']['stderr'] = $stderr;
            $root = SyntheticTree::create($tree);
            try {
                $result = Process::run([\PHP_BINARY, $this->candidateRoot . '/scripts/finding-gate.php', '--candidate=' . $root, '--reference=HEAD'], $this->candidateRoot);
                $this->same(GateReport::EXIT_RED, $result['exit'], 'a refusal file cannot bypass the selected-destination guard');
                $this->assert(str_contains($result['stdout'], 'FAIL [run-failed]'), 'the refusal is preserved in the public failed verdict');
                $this->assert(str_contains($result['stdout'], 'does not name exactly the chosen file'), 'the refusal is the real destination guard');
            } finally {
                SyntheticTree::remove($root);
            }
        }
    }

    /** @return Specification */
    public static function fixture(): array
    {
        $tree = SyntheticTree::clean();
        $tree['cases']['keeper'] = ['replay.alpha@callable'];
        $tree['findings']['keeper'] = [SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\\Keeper::run@src/Keeper.php')];
        $tree['declarations']['cases/alpha/case.json'] = json_encode([
            'id' => 'alpha', 'description' => 'A declared input refusal.', 'coverage' => 'auxiliary',
            'paths' => ['src'], 'config' => 'qmx.yaml', 'channels' => ['replay.alpha@callable'],
            'outcome' => ['kind' => CaseOutcome::REFUSAL, 'exit' => 3],
        ], \JSON_THROW_ON_ERROR);
        $envelope = json_encode(['error' => 'Refused input', 'exit_code' => 3, 'position' => null], \JSON_THROW_ON_ERROR) . "\n";
        foreach ([...array_map(static fn(string $format): string => 'format:' . $format, Surfaces::FORMATS), 'show-suppressed', 'check:output', 'check:baseline', 'check:baseline-source', 'check:parallel', 'baseline:generate'] as $surface) {
            $tree['answers']['case:alpha|' . $surface] = ['stdout' => '', 'stderr' => "Refused input\n", 'exit' => 3];
        }
        $tree['answers']['case:alpha|format:json']['stdout'] = $envelope;
        $tree['answers']['case:alpha|baseline:generate']['file'] = '';
        $tree['answers']['case:alpha|check:output'] = ['stdout' => '', 'stderr' => "Refused input\nReport written to {{output}}\n", 'exit' => 3, 'file' => $envelope];
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
            [[FailureClass::CASE_OUTCOME_MISMATCH, '* / alpha']],
        ), CheckWitnesses::witness(
            'candidate-channel-input-refusal',
            CheckWitnesses::WHOLE_RUN,
            static function (array $tree): array {
                $tree['static']['replay.alpha'] = [];
                return $tree;
            },
            [[FailureClass::CANDIDATE_INPUT_REFUSED, 'corpus']],
        ), CheckWitnesses::witness(
            'corpus-invalid-metadata',
            CheckWitnesses::WHOLE_RUN,
            static function (array $tree): array {
                $tree['declarations']['cases/alpha/case.json'] = '{"id":"alpha"}';
                return $tree;
            },
            [[FailureClass::CORPUS_INVALID, 'corpus']],
        )];
    }

    private static function has(GateReport $report, string $class): bool
    {
        return \in_array($class, array_column($report->raised(), 'class'), true);
    }
}
