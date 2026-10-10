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
    public function plan(array $captures, RecordCheck $verifiedRecords): void
    {
        $this->captures = $captures;
        foreach ($captures as $side => $capture) {
            $this->run->publicationForms->supply($side, $capture->artifacts);
        }
        $intentions = $this->run->declarations->exactSurfaces->keys();
        if ($intentions === []) {
            return;
        }
        $report = new GateReport();
        $declarations = $this->run->declarations->trialCopy();
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
        $this->run->copyPublicationsTo($trial);
        $records = $verifiedRecords->trialCopy($trial);
        RankingCheck::create($this->run)->trialCopy($trial);
        ValueCheck::create($this->run)->trialCopy($trial);
        FieldValuesCheck::create($this->run)->trialCopy($trial);
        foreach ($captures as $side => $capture) {
            $trial->publicationForms->supply($side, $capture->artifacts);
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
            $trial->publicationForms,
            new DeclaredDeltaCheck($trial->options, $report, $declarations->delta, $declarations->fieldMoves, $trial->split, $trial),
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
            $footprint = ExactSurfaceAuthority::footprint($key, $this->run);
            $result = $comparison->trialSurface($key, $candidate, $reference, $footprint['residualViews']);
            $authorityResidual = $result['authorityResidual'];
            if ($result['valid'] && !$trial->publicationForms->recordsPair($key)) {
                $authorityResidual = ExactSurfaceAuthority::rawResidual($key, $captures, $trial, $records);
            }
            if ($result['valid'] && $footprint['rawSources'] !== []) {
                $authorityResidual = false;
                foreach ($footprint['rawSources'] as $source) {
                    $authorityResidual = ExactSurfaceAuthority::rawResidual($source, $captures, $trial, $records) || $authorityResidual;
                }
            }
            if ($result['valid'] && ($result['visibleResidual'] || $authorityResidual)) {
                $this->run->selectExactSurface($key);
            }
        }
    }

    public function selected(string $key): bool
    {
        if ($this->run->isExactSurface($key)) {
            return true;
        }
        if (!$this->run->publicationForms->recordInvocation($key)) {
            foreach ($this->run->publicationForms->invocationArtifacts($key) as $artifact) {
                if ($this->run->isExactSurface($artifact)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function checkExact(SurfacePair $pair): void
    {
        if (!$this->run->isExactSurface($pair->key)) {
            return;
        }
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
        if ($this->derived === null || $this->derived === [] || !$this->run->report->canDeriveExact()) {
            return [];
        }
        $eligible = [];
        foreach ($this->derived as $key => $diff) {
            $footprint = ExactSurfaceAuthority::footprint($key, $this->run);
            foreach ($footprint['required'] as $source) {
                if (!$this->run->report->sourceValid($source['side'], $source['key'], $source['role'])) {
                    continue 2;
                }
                if ($this->run->report->sourceRejected('*', Surfaces::surfaceClass($source['key']), 'normalization')) {
                    continue 2;
                }
            }
            foreach ($footprint['schemas'] as $schema) {
                if (!$schema['supplied'] || !$this->run->report->sourceValid($schema['side'], $schema['key'], $schema['role'])) {
                    continue 2;
                }
            }
            $eligible[$key] = $diff;
        }
        return $this->run->declarations->exactSurfaces->rewrite($eligible);
    }
}
