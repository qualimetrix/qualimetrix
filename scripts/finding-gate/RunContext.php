<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** What one comparison run is made of, as a registered form sees it. */
final class RunContext
{
    /** @var array<string,true> */
    private array $exactSurfaces = [];

    /** @var array<string,string> */
    private array $publicationTrees = [];

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
    public readonly PublicationForms $publicationForms;

    /** @param array<string,string> $publicationCodecs */
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
        private array $publicationCodecs = [],
    ) {
        $this->rankings = new RankingCaptures();
        $this->baselineEligibility = new BaselineEligibility();
        $this->capturePlan = CapturePlan::forCorpus($corpus, $declarations->surfaces);
        $this->publicationForms = new PublicationForms($this->capturePlan, $report);
    }

    public function supplyPublicationTree(string $side, string $treeRoot): void
    {
        $codec = ReportRecords::codecOf($treeRoot);
        $this->publicationTrees[$side] = $treeRoot;
        $this->publicationCodecs[$side] ??= $codec;
    }

    public function publicationTree(string $side): string
    {
        return $this->publicationTrees[$side] ?? throw new GateError('The ranking publication source tree is missing for ' . $side . '.');
    }

    /** Unsupplied sides belong to the single-root fixture; real comparisons supply both trees. */
    public function publicationCodec(string $side): string
    {
        return $this->publicationCodecs[$side] ??= ReportRecords::codecOf($this->options->candidateRoot);
    }

    public function copyPublicationsTo(self $target): void
    {
        $target->publicationTrees = $this->publicationTrees;
        $target->publicationCodecs = $this->publicationCodecs;
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
        $this->copyPublicationsTo($pass);
        $pass->rankings->supply('candidate', $capture->rankings);
        $pass->baselineEligibility->supply('candidate', $capture->baselineEligibility);
        return $pass;
    }
}
