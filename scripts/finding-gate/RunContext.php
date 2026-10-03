<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** What one comparison run is made of, as a registered form sees it. */
final class RunContext
{
    /** @var array<string,true> */
    private array $exactSurfaces = [];

    public function selectExactSurface(string $key): void
    {
        $this->exactSurfaces[$key] = true;
    }

    public function isExactSurface(string $key): bool
    {
        return isset($this->exactSurfaces[$key]);
    }
    public readonly RankingCaptures $rankings;

    public readonly BaselineEligibility $baselineEligibility;

    public readonly CapturePlan $capturePlan;

    public function __construct(
        public readonly Options $options,
        public readonly GateReport $report,
        public readonly Corpus $corpus,
        public readonly RenameMaps $maps,
        public readonly ChannelSplit $split,
        public readonly MetricVocabulary $vocabulary,
        public readonly Normalization $normalization,
        public readonly Declarations $declarations,
        public readonly string $temporaryDirectory,
    ) {
        $this->rankings = new RankingCaptures();
        $this->baselineEligibility = new BaselineEligibility();
        $this->capturePlan = CapturePlan::forCorpus($corpus, $declarations->surfaces);
    }

    public function withCandidateCapture(CaptureResult $capture): self
    {
        $pass = new self(
            $this->options,
            $this->report,
            $this->corpus,
            $this->maps,
            $this->split,
            $this->vocabulary,
            $this->normalization,
            $this->declarations,
            $this->temporaryDirectory,
        );
        $pass->rankings->supply('candidate', $capture->rankings);
        $pass->baselineEligibility->supply('candidate', $capture->baselineEligibility);
        return $pass;
    }
}
