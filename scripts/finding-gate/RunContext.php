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
}
