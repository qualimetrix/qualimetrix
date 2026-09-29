<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** Selects an exact route only after an isolated semantic expressibility trial. */
final class ExactSurfaceDeltaCheck implements Derivation
{
    /** @var array{candidate:CaptureResult,reference:CaptureResult}|null */
    private ?array $captures = null;

    /** @var array<string,string>|null */
    private ?array $derived = null;

    public function __construct(private readonly RunContext $run) {}

    public function startDeriving(): void
    {
        $this->derived = [];
    }

    /** @param array{candidate:CaptureResult,reference:CaptureResult} $captures */
    public function plan(array $captures, RecordCheck $verifiedRecords, FingerprintCheck $verifiedFingerprints): void
    {
        $this->captures = $captures;
        $intentions = $this->run->declarations->exactSurfaces->keys();
        if ($intentions === []) {
            return;
        }
        $report = new GateReport();
        $declarations = Declarations::load($this->run->options->candidateRoot);
        $maps = clone $this->run->maps;
        $normalization = clone $this->run->normalization;
        $trial = new RunContext(
            $this->run->options,
            $report,
            $this->run->corpus,
            $maps,
            ChannelSplit::of($maps),
            $this->run->vocabulary,
            $normalization,
            $declarations,
            $this->run->temporaryDirectory,
        );
        $records = $verifiedRecords->trialCopy($trial);
        $fingerprints = $verifiedFingerprints->forkFor($report);
        foreach ($captures as $side => $capture) {
            $trial->rankings->supply($side, $capture->rankings);
            $trial->baselineEligibility->supply($side, $capture->baselineEligibility);
        }
        $stages = [];
        foreach (Wiring::gate()->list('surfaceStages') as $name) {
            $class = 'QmxFindingGate\\' . $name;
            $stages[] = $class::create($trial);
        }
        $comparison = new SurfaceComparison(
            $report,
            $trial->corpus,
            $trial->maps,
            $trial->normalization,
            $fingerprints,
            new DeclaredDeltaCheck($trial->options, $report, $declarations->delta, $declarations->fieldMoves, $trial->split),
            $trial->temporaryDirectory,
            $stages,
            $records,
        );
        foreach ($intentions as $key) {
            $candidate = $captures['candidate']->artifacts[$key] ?? null;
            $reference = $captures['reference']->artifacts[$key] ?? null;
            if ($candidate === null || $reference === null) {
                continue;
            }
            if (!$comparison->trialSurface($key, $candidate, $reference)) {
                $this->run->selectExactSurface($key);
            }
        }
    }

    public function selected(string $key): bool
    {
        return $this->run->isExactSurface($key);
    }

    public function checkExact(SurfacePair $pair): void
    {
        $this->run->declarations->exactSurfaces->claim($pair->key);
        $this->run->report->usedExactSurface();
        $problem = null;
        try {
            [$candidate, $reference] = ExactSurfaceAuthority::pair(
                $pair,
                $this->captures ?? throw new GateError('An exact surface has no capture pair.'),
                $this->run,
            );
            $diff = ExactDiff::between($candidate, $reference, 'candidate', 'reference (mapped)')->render();
        } catch (GateError|BudgetExceeded $error) {
            $problem = 'The complete exact surface cannot be measured: ' . $error->getMessage();
        }
        if ($problem === null) {
            if ($this->derived !== null) {
                $this->derived[$pair->key] = $diff;
            } elseif ($diff !== $this->run->declarations->exactSurfaces->declared($pair->key)) {
                $problem = 'The complete exact surface differs from its measured intention.';
            }
        }
        if ($problem !== null) {
            $this->run->report->fail(FailureClass::DELTA_MISMATCH, $pair->key, $problem);
        }
    }

    public function checkStale(): void
    {
        foreach ($this->run->declarations->exactSurfaces->stale() as $key) {
            $this->run->report->fail(FailureClass::DELTA_STALE, $key, 'The exact surface intention has no unexplained remainder.');
        }
    }

    /** @return list<string> */
    public function rewriteDerived(): array
    {
        if ($this->derived === null || $this->derived === [] || !$this->run->report->canDerive()) {
            return [];
        }
        return $this->run->declarations->exactSurfaces->rewrite($this->derived);
    }
}
