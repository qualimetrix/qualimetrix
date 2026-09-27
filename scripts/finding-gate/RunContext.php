<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** What one comparison run is made of, as a registered form sees it. */
final class RunContext
{
    public readonly RankingCaptures $rankings;

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
        return $pass;
    }
}
