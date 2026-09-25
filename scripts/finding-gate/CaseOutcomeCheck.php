<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Whether each case's run produced what a comparison reads: a findings section that is not truncated, and
 * a baseline file its command wrote.
 */
final class CaseOutcomeCheck
{
    public function __construct(
        private readonly GateReport $report,
        private readonly Corpus $corpus,
    ) {}

    /**
     * Whether every case of one tree's run produced the artifacts a comparison
     * reads, reporting what did not.
     *
     * Split out because a *derivation* needs exactly this much judgement and no
     * more. A derive run rewrites the tracked declaration the next ordinary run
     * is judged against, so a run that failed must write nothing — but the
     * normalization derivation compares nothing, and every verdict lived in
     * compare(). It therefore wrote a measured list from runs that had produced
     * no output at all, under the sentence "Measured ... from repeated runs".
     *
     * @param array<string, string> $artifacts
     */
    public function checkRunsProduced(string $side, array $artifacts): void
    {
        foreach ($this->corpus->cases as $case) {
            $this->findingsOf($side, $case, $artifacts);
        }
    }

    /**
     * One case's findings, or null with the failure already reported.
     *
     * @param array<string, string> $artifacts
     *
     * @return list<array<string, mixed>>|null
     */
    public function findingsOf(string $side, CaseDefinition $case, array $artifacts): ?array
    {
        $key = Surfaces::key('case:' . $case->id, 'format:json');
        $report = json_decode($artifacts[$key] ?? '', true);

        if (!\is_array($report) || !\is_array($report['violations'] ?? null)) {
            $this->report->fail(FailureClass::RUN_FAILED, $side . ' / ' . $case->id, 'The JSON surface carries no findings section.');

            return null;
        }

        if (($report['violationsMeta']['truncated'] ?? false) === true) {
            $this->report->fail(
                FailureClass::RUN_FAILED,
                $side . ' / ' . $case->id,
                'The JSON surface truncated its findings, so the comparison would silently cover a prefix.'
                . ' Add --format-opt=violations=all to the case arguments.',
            );
        }

        $this->checkBaselineSurface($side, $case, $artifacts);

        /** @var list<array<string, mixed>> $findings */
        $findings = array_values($report['violations']);

        return $findings;
    }

    /**
     * An absent surface must not read as a surface that agrees.
     *
     * `baseline-file` is captured as the file the command wrote, and a command
     * that wrote nothing captures as an empty string on both sides — which
     * compares equal, and would silently retire the whole baseline surface from
     * the comparison. So the surface's existence is asserted before it is
     * compared, on each side separately, together with the exit code of the
     * command that was supposed to produce it.
     *
     * @param array<string, string> $artifacts
     */
    private function checkBaselineSurface(string $side, CaseDefinition $case, array $artifacts): void
    {
        $scope = 'case:' . $case->id;
        $exit = $artifacts[Surfaces::key($scope, 'exit:baseline:generate')] ?? null;

        if ($exit !== '0') {
            $this->report->fail(
                FailureClass::RUN_FAILED,
                $side . ' / ' . $case->id . ' / baseline:generate',
                \sprintf('baseline:generate exited %s, so its file is not a surface either side can be held to.', $exit ?? 'nothing'),
            );
        }

        if (trim($artifacts[Surfaces::key($scope, 'baseline-file')] ?? '') === '') {
            $this->report->fail(
                FailureClass::RUN_FAILED,
                $side . ' / ' . $case->id . ' / baseline-file',
                'baseline:generate wrote no baseline. An empty baseline compares equal to an empty baseline, so the'
                . ' whole surface would drop out of the comparison unnoticed.',
            );
        }
    }
}
