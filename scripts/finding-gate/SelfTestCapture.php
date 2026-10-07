<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Capture declarations observed through the gate's public comparison.
 *
 * @phpstan-import-type Specification from SyntheticTree
 * @phpstan-import-type Witness from CheckWitnesses
 */
final class SelfTestCapture extends SelfTestGroup
{
    public function declarations(): void
    {
        $tree = self::withdrawnFixture();
        $report = $this->reportFor($tree);
        $this->same(GateReport::VERDICT_GREEN, $report->verdict(), 'a declared withdrawal compares its complete refusal and remains GREEN');
        $tree['candidateAnswers']['case:alpha|format:retired']['stderr'] = 'another cause';
        $report = $this->reportFor($tree);
        $this->assert(self::contains($report, FailureClass::SURFACE_WITHDRAWAL_MISMATCH, 'case:alpha|format:retired'), 'a neighboring undeclared refusal cause raises surface-withdrawal-mismatch');

    }

    public function outputDestination(): void
    {
        $tree = SyntheticTree::clean();
        $file = SyntheticTree::caseAnswers('alpha', $tree['findings']['alpha'], false, [])['case:alpha|format:json']['stdout'] ?? throw new GateError('The synthetic finding source has no publication.');
        $tree['answers']['case:alpha|check:output'] = ['stdout' => '', 'file' => $file, 'stderr' => "Report written to {{output}}\n"];
        $report = $this->reportFor($tree);
        $this->same(GateReport::VERDICT_GREEN, $report->verdict(), 'the output diagnostic is validated against the actual chosen file before normalization');
        foreach ([
            'wrong destination' => ['stdout' => '', 'file' => $file, 'stderr' => "Report written to /wrong.json\n"],
            'missing marker' => ['stdout' => '', 'file' => $file, 'stderr' => ''],
            'duplicate marker' => ['stdout' => '', 'file' => $file, 'stderr' => "Report written to {{output}}\nReport written to {{output}}\n"],
            'missing file' => ['stdout' => '', 'missingFile' => true],
            'empty file' => ['stdout' => '', 'file' => ''],
        ] as $defect => $answer) {
            $tree['candidateAnswers']['case:alpha|check:output'] = $answer;
            $root = SyntheticTree::create($tree);
            try {
                $result = Process::run([\PHP_BINARY, __DIR__ . '/../finding-gate.php', '--candidate=' . $root, '--reference=HEAD'], $this->candidateRoot);
                $this->same(GateReport::EXIT_RED, $result['exit'], 'the public CLI refuses an output publication with ' . $defect);
                $this->assert(str_contains($result['stdout'], 'FAIL [' . FailureClass::RUN_FAILED . ']'), 'the public CLI retains run-failed for ' . $defect);
                $this->assert(str_contains($result['stdout'], 'output publication is missing, empty, or does not name exactly the chosen file'), 'the public CLI names the destination guard for ' . $defect);
            } finally {
                SyntheticTree::remove($root);
            }
        }
    }

    public function population(): void
    {
        $tree = SyntheticTree::clean();
        $tree['answers']['tree|rules'] = ['stdout' => 'unsupported command', 'exit' => 3];
        $report = $this->reportFor($tree);
        $this->assert(self::contains($report, FailureClass::SURFACE_MISMATCH, 'candidate / tree|rules'), 'two equal exit-3 refusals do not establish successful rules population');
        $tree['answers']['tree|rules']['exit'] = 70;
        $report = $this->reportFor($tree);
        $this->assert(self::contains($report, FailureClass::SURFACE_MISMATCH, 'candidate / tree|rules'), 'two equal unknown exit-70 invocations cannot be GREEN');
    }

    public function derivation(): void
    {
        $tree = self::withdrawnFixture();
        $tree['candidateDeclarations']['declared-surfaces/retired.json'] = "wrong declaration\n";
        $root = SyntheticTree::create($tree);
        try {
            $report = new GateReport();
            GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD', '--derive-declarations'], $root), $report);
            $this->same(GateReport::VERDICT_GREEN, $report->verdict(), 'a derive run measures only the named withdrawn surface');
            $expected = json_encode(['stdout' => '', 'stderr' => 'unsupported format', 'exit' => '3'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES) . "\n";
            $this->same($expected, Fs::read($root . '/finding-gate/declared-surfaces/retired.json'), 'withdrawal derive writes the measured complete envelope at the declared file address');
            $report = new GateReport();
            GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report);
            $this->same(GateReport::VERDICT_GREEN, $report->verdict(), 'the declaration measured by derive is accepted by public compare');
        } finally {
            SyntheticTree::remove($root);
        }
        $tree = self::withdrawnFixture();
        $tree['candidateAnswers']['case:alpha|rules'] = ['stdout' => 'an unrelated catalog change'];
        $root = SyntheticTree::create($tree);
        try {
            $before = Fs::read($root . '/finding-gate/declared-surfaces/retired.json');
            $report = new GateReport();
            GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD', '--derive-declarations'], $root), $report);
            $this->assert(self::contains($report, FailureClass::SURFACE_MISMATCH, 'case:alpha|rules'), 'derive leaves an unrelated catalog change RED');
            $this->same($before, Fs::read($root . '/finding-gate/declared-surfaces/retired.json'), 'a RED derive run does not rewrite the measured declaration');
        } finally {
            SyntheticTree::remove($root);
        }
    }

    /** @return list<Witness> */
    public static function witnesses(): array
    {
        return [CheckWitnesses::witness(
            'surface-withdrawal-cause',
            CheckWitnesses::WHOLE_RUN,
            static function (array $tree): array {
                $fixture = self::withdrawnFixture();
                $tree['declarations'] += $fixture['declarations'];
                $tree['candidateDeclarations'] = [...($tree['candidateDeclarations'] ?? []), ...($fixture['candidateDeclarations'] ?? [])];
                $tree['answers'] += $fixture['answers'];
                $tree['candidateAnswers'] += $fixture['candidateAnswers'];
                $tree['candidateAnswers']['case:alpha|format:retired']['stderr'] = 'another cause';

                return $tree;
            },
            [[FailureClass::SURFACE_WITHDRAWAL_MISMATCH, 'case:alpha|format:retired', 'CaptureCheck::withdrawal <- Gate::compare']],
            [[FailureClass::SURFACE_DECLARATION_STALE, 'format:retired']],
        ), CheckWitnesses::witness(
            'capture-neutral-outcome',
            CheckWitnesses::WHOLE_RUN,
            static function (array $tree): array {
                $tree['answers']['tree|graph:export'] = ['stdout' => "No files found to analyze\n", 'exit' => 0];

                return $tree;
            },
            [[FailureClass::SURFACE_MISMATCH, '* / tree|graph:export', 'CaptureCheck::publicationFailure <- Gate::compare']],
        ), CheckWitnesses::witness(
            'normalization-readable-records',
            CheckWitnesses::WHOLE_RUN,
            static function (array $tree): array {
                $tree['normalization'][] = ['directives', 'directives.*.boundary_observable', NormalizationRule::KIND_JSON_PATH];

                return $tree;
            },
            [[FailureClass::NORMALIZATION_OVERREACH, '* / case:*|directives', 'NormalizationCheck::checkRun <- Gate::compare']],
            [[FailureClass::RECORD_PROJECTION_MISMATCH, '* / case:alpha|directives']],
        )];
    }

    /** @return Specification */
    public static function withdrawnFixture(): array
    {
        $tree = SyntheticTree::clean();
        $tree['answers']['case:alpha|format:retired'] = ['stdout' => 'old publication'];
        $tree['candidateAnswers']['case:alpha|format:retired'] = ['stdout' => '', 'stderr' => 'unsupported format', 'exit' => 3];
        $tree['candidateDeclarations'][DeclaredSurfaces::INDEX] = Tsv::render(DeclaredSurfaces::COLUMNS, [[DeclaredSurfaces::WITHDRAWN, 'format:retired', 'declared-surfaces/retired.json', '*', 'removed format']]);
        $tree['candidateDeclarations']['declared-surfaces/retired.json'] = json_encode(['stdout' => '', 'stderr' => 'unsupported format', 'exit' => '3'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES) . "\n";

        return $tree;
    }

    /** @param Specification $tree */
    private function reportFor(array $tree): GateReport
    {
        $root = SyntheticTree::create($tree);
        $report = new GateReport();
        try {
            GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report);
        } finally {
            SyntheticTree::remove($root);
        }

        return $report;
    }

    private static function contains(GateReport $report, string $class, string $scope): bool
    {
        foreach ($report->raised() as $failure) {
            if ($failure['class'] === $class && $failure['scope'] === $scope) {
                return true;
            }
        }

        return false;
    }
}
