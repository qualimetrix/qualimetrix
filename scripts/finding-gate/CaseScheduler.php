<?php

declare(strict_types=1);

namespace QmxFindingGate;

use JsonException;

/**
 * Runs independent case measurements in a bounded pool.
 *
 * The parent merges only after every worker settles and follows the corpus
 * order, not completion order. That leaves the compared artifact map byte-for-
 * byte ordered as the serial run while letting distinct case directories use
 * their isolated caches concurrently.
 */
final class CaseScheduler
{
    private const HEARTBEAT_SECONDS = 15;

    private const WORKER_DEADLINE_SECONDS = 1200;

    public function __construct(
        private readonly string $candidateRoot,
        private readonly string $treeRoot,
        private readonly string $temporaryDirectory,
        private readonly string $label,
        private readonly bool $reverseInput,
        private readonly int $jobs,
        // The parent's maps, credited with what each worker's own instance
        // translated. Staleness is judged here, and a case's input is
        // translated there.
        private readonly RenameMaps $maps,
        private readonly DeclaredStructuralMaps $structuralMaps,
    ) {}

    /**
     * @param list<CaseDefinition> $cases
     */
    public function captureCases(array $cases): CaptureResult
    {
        /** @var array<int, array{case: CaseDefinition, child: ProcessHandle, output: string}> $inFlight */
        $inFlight = [];
        /** @var array<int,CaptureResult> $completed */
        $completed = [];
        $next = 0;
        $finished = 0;
        $lastHeartbeatAt = (hrtime(true) / 1_000_000_000);
        $total = \count($cases);

        try {
            while ($next < $total || $inFlight !== []) {
                while ($next < $total && \count($inFlight) < $this->jobs) {
                    $case = $cases[$next];
                    $output = \sprintf('%s/case-%s-%s-%d.json', $this->temporaryDirectory, $this->label, $case->id, $next);
                    $inFlight[$next] = [
                        'case' => $case,
                        'child' => ProcessHandle::start($this->workerCommand($case, $output), $this->candidateRoot),
                        'output' => $output,
                    ];
                    ++$next;
                }

                $this->poll($inFlight);

                foreach ($inFlight as $index => $worker) {
                    if (!$worker['child']->settled()) {
                        if ($worker['child']->age() > self::WORKER_DEADLINE_SECONDS) {
                            throw new GateError(\sprintf(
                                'Case worker "%s" in %s exceeded the %d-second deadline.',
                                $worker['case']->id,
                                $this->label,
                                self::WORKER_DEADLINE_SECONDS,
                            ));
                        }

                        continue;
                    }

                    $result = $worker['child']->reap();
                    unset($inFlight[$index]);

                    if ($result['exit'] !== 0) {
                        throw new GateError(\sprintf(
                            'Case worker "%s" in %s failed with exit %d:\n%s',
                            $worker['case']->id,
                            $this->label,
                            $result['exit'],
                            trim($result['stderr']),
                        ));
                    }

                    try {
                        $payload = json_decode(Fs::read($worker['output']), true, 512, \JSON_THROW_ON_ERROR);
                    } catch (JsonException $error) {
                        throw new GateError(\sprintf(
                            'Case worker "%s" in %s wrote unreadable artifacts: %s.',
                            $worker['case']->id,
                            $this->label,
                            $error->getMessage(),
                        ));
                    }

                    $artifacts = \is_array($payload) ? ($payload['artifacts'] ?? null) : null;
                    $rankings = \is_array($payload) ? ($payload['rankings'] ?? null) : null;
                    $baselineEligibility = \is_array($payload) ? ($payload['baselineEligibility'] ?? null) : null;
                    $hits = \is_array($payload) ? ($payload['mapHits'] ?? null) : null;
                    $structuralHits = \is_array($payload) ? ($payload['structuralMapHits'] ?? null) : null;

                    if (!\is_array($artifacts) || !\is_array($rankings) || !\is_array($baselineEligibility) || !\is_array($hits) || !\is_array($structuralHits)
                        || array_filter($hits, static fn(mixed $count): bool => !\is_int($count)) !== []
                    ) {
                        throw new GateError(\sprintf('Case worker "%s" in %s wrote an invalid artifact map.', $worker['case']->id, $this->label));
                    }

                    $capture = new CaptureResult($artifacts, $rankings, $baselineEligibility);
                    /** @var array<string, int> $hits */
                    $this->maps->creditRowsFiredElsewhere($hits);
                    $this->structuralMaps->creditRowsFiredElsewhere($structuralHits);
                    $completed[$index] = $capture;
                    ++$finished;
                    $this->announce($finished, $total, \count($inFlight));
                    $lastHeartbeatAt = (hrtime(true) / 1_000_000_000);
                }

                if ($inFlight !== [] && (hrtime(true) / 1_000_000_000) - $lastHeartbeatAt >= self::HEARTBEAT_SECONDS) {
                    $this->announce($finished, $total, \count($inFlight));
                    $lastHeartbeatAt = (hrtime(true) / 1_000_000_000);
                }
            }
        } finally {
            $terminationError = null;
            foreach ($inFlight as $worker) {
                try {
                    $worker['child']->terminate();
                } catch (GateError $error) {
                    $terminationError ??= $error;
                }
            }

            if ($terminationError !== null) {
                throw $terminationError;
            }
        }

        ksort($completed);
        $capture = new CaptureResult([], []);

        foreach ($completed as $caseCapture) {
            $capture = $capture->merge($caseCapture);
        }

        return $capture;
    }

    /** @param array<int, array{case: CaseDefinition, child: ProcessHandle, output: string}> $inFlight */
    private function poll(array $inFlight): void
    {
        Interruption::raiseIfRequested();

        $read = [];

        foreach ($inFlight as $worker) {
            $read = [...$read, ...$worker['child']->openStreams()];
        }

        if ($read !== []) {
            $write = $except = [];
            @stream_select($read, $write, $except, 0, 200_000);
        } else {
            usleep(200_000);
        }

        foreach ($inFlight as $worker) {
            $worker['child']->drain();
        }
    }

    /** @return list<string> */
    private function workerCommand(CaseDefinition $case, string $output): array
    {
        return [
            \PHP_BINARY,
            \dirname(__DIR__) . '/finding-gate.php',
            '--candidate=' . $this->candidateRoot,
            '--case-worker=' . $case->id,
            '--worker-output=' . $output,
            '--worker-label=' . $this->label,
            '--worker-tree=' . $this->treeRoot,
            ...($this->reverseInput ? ['--worker-reverse-input'] : []),
        ];
    }

    private function announce(int $finished, int $total, int $inFlight): void
    {
        fwrite(\STDERR, \sprintf(
            "finding-gate: %s: %d/%d case(s) complete; %d in flight.\n",
            $this->label,
            $finished,
            $total,
            $inFlight,
        ));
    }
}
