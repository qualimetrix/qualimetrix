<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Proves that the candidate and the reference tree produce equivalent findings
 * over the corpus, modulo the declared maps and the declared normalization.
 *
 * Artifacts need not be byte-identical: a declared rename changes their
 * spelling while preserving their observable meaning.
 *
 * This class runs the trees and fixes the order the checks run in, which is the
 * order a report prints their failures in; each subject's checks live in its
 * own class.
 */
final class Gate
{
    private readonly RenameMaps $maps;

    /** What the candidate's own source says a metric name may be. */
    private readonly MetricVocabulary $vocabulary;

    private readonly Corpus $corpus;

    private readonly DeclaredDelta $declaredDelta;

    private readonly DeclaredFieldMoves $declaredFieldMoves;

    private readonly ChannelSplit $split;

    private readonly string $temporaryDirectory;

    private readonly TupleCheck $tupleCheck;

    private readonly NormalizationCheck $normalizationCheck;

    private readonly CaseOutcomeCheck $caseOutcomeCheck;

    private readonly FingerprintCheck $fingerprintCheck;

    private readonly RenameMapCheck $renameMapCheck;

    private readonly DeclaredDeltaCheck $declaredDeltaCheck;

    private readonly SurfaceComparison $surfaceComparison;

    private readonly CoverageCheck $coverageCheck;

    /** @var array<string, list<array<string, mixed>>> */
    private array $findingsByCase = [];

    public function __construct(
        private readonly Options $options,
        private readonly GateReport $report,
    ) {
        $root = $this->options->candidateRoot . '/finding-gate';
        $normalization = Normalization::load($root . '/normalization.tsv');
        $this->vocabulary = MetricVocabulary::ofTree($this->options->candidateRoot);
        $this->maps = RenameMaps::load($root . '/maps', $this->vocabulary);
        $this->declaredDelta = DeclaredDelta::load($root);
        $this->declaredFieldMoves = DeclaredFieldMoves::load($root);
        $this->split = ChannelSplit::of($this->maps);
        $this->corpus = Corpus::load($this->options->candidateRoot, $this->options->cases);
        $witness = new ChannelWitness($this->options->candidateRoot);
        $this->temporaryDirectory = Fs::temporaryDirectory('finding-gate-run-');

        $this->tupleCheck = new TupleCheck($this->options, $this->report);
        $this->normalizationCheck = new NormalizationCheck($this->options, $this->report, $normalization);
        $this->caseOutcomeCheck = new CaseOutcomeCheck($this->report, $this->corpus);
        $this->fingerprintCheck = new FingerprintCheck($this->report);
        $this->renameMapCheck = new RenameMapCheck($this->report, $this->corpus, $this->maps, $this->split);
        $this->declaredDeltaCheck = new DeclaredDeltaCheck(
            $this->options,
            $this->report,
            $this->declaredDelta,
            $this->declaredFieldMoves,
            $this->split,
        );
        $this->surfaceComparison = new SurfaceComparison(
            $this->report,
            $this->corpus,
            $this->maps,
            $normalization,
            $this->fingerprintCheck,
            $this->declaredDeltaCheck,
            $this->temporaryDirectory,
        );
        $this->coverageCheck = new CoverageCheck($this->options, $this->report, $this->corpus, $witness);
    }

    public function compare(): void
    {
        $this->report->fact('candidate', $this->options->candidateRoot);
        $this->report->fact('reference', (string) $this->options->reference);
        $this->report->fact('cases', array_map(static fn(CaseDefinition $case): string => $case->id, $this->corpus->cases));
        $this->report->fact('formats', Surfaces::FORMATS);
        $this->report->fact('auxiliary cases', array_map(
            static fn(CaseDefinition $case): string => $case->id,
            array_values(array_filter($this->corpus->cases, static fn(CaseDefinition $case): bool => $case->isAuxiliary())),
        ));
        $this->report->fact('maps', $this->maps->isIdentity() ? 'empty (identity)' : 'declared');
        $this->report->fact('map rows', \count($this->maps->declaredRows()));
        $this->report->fact('aggregation suffixes', $this->vocabulary->suffixes);
        $this->report->fact('split halves', $this->split->halves());

        // Loud on purpose. A declared delta is the one declaration that lets a
        // surface differ, so how many there are and how big they are is the
        // first thing a reader of a GREEN run has to be able to see.
        $this->report->fact('declared deltas', \sprintf(
            '%d surface(s), %d byte(s) total',
            $this->declaredDelta->count(),
            $this->declaredDelta->totalBytes(),
        ));
        $this->report->countDeclaredDeltas($this->declaredDelta->count());

        if (!$this->declaredDelta->isEmpty()) {
            $this->report->warn(\sprintf(
                '%d surface(s) are compared against a declared delta rather than for equality: %s.',
                $this->declaredDelta->count(),
                implode(', ', $this->declaredDelta->surfaces()),
            ));
        }

        // Loud for the same reason: a declared field move is the only thing that
        // lets a compared field differ inside a declared diff, so how many there
        // are belongs in what a reader of a GREEN run sees.
        $this->report->fact('declared field moves', $this->declaredFieldMoves->count());
        $this->report->countFieldMoves($this->declaredFieldMoves->count());

        if (!$this->declaredFieldMoves->isEmpty()) {
            $this->report->warn(\sprintf(
                '%d move(s) of a compared field are licensed by %s rather than refused.',
                $this->declaredFieldMoves->count(),
                DeclaredFieldMoves::INDEX,
            ));
        }

        if ($this->options->cases !== []) {
            $this->report->limit('the corpus was restricted to ' . implode(', ', $this->options->cases) . ' by --cases');
        }

        $this->tupleCheck->checkTuple();
        $this->normalizationCheck->checkNormalizationScope();

        $first = $this->runTree($this->options->candidateRoot, 'candidate-1', reverseInput: false);
        $second = $this->runTree($this->options->candidateRoot, 'candidate-2', reverseInput: false);
        $this->normalizationCheck->checkDeterminism($first, $second);

        $reference = ReferenceTree::create($this->options->candidateRoot, (string) $this->options->reference);

        try {
            $mismatch = $reference->dependencySetMismatch();

            if ($mismatch !== null) {
                $this->report->fail(FailureClass::ENV_MISMATCH, 'reference tree', $mismatch);

                return;
            }

            $referenceArtifacts = $this->runTree($reference->root, 'reference', reverseInput: true);

            $this->renameMapCheck->checkReferenceInput($first, $referenceArtifacts);
            $this->checkFindings('candidate', $first, trackObserved: true);
            $this->checkFindings('reference', $referenceArtifacts, trackObserved: false);
            $this->renameMapCheck->checkSplitExplanation($first, $referenceArtifacts);
            $this->surfaceComparison->compareSurfaces($first, $referenceArtifacts);

            // Said out loud for the same reason the declared-delta count is: a
            // reader of a GREEN run has to be able to see that one published
            // value was compared as the identity it hashes rather than as the
            // bytes the product wrote.
            $this->report->fact('fingerprints substituted', \sprintf(
                '%d value(s) on %s, both sides',
                $this->fingerprintCheck->substitutedCount(),
                Fingerprints::OPAQUE_SURFACE,
            ));
            $this->surfaceComparison->checkPathLeaks($first, $referenceArtifacts, $reference->root);
            $this->coverageCheck->checkCoverage($this->findingsByCase);
            $this->coverageCheck->checkWitnesses();
            $this->normalizationCheck->checkStaleNormalization();
            $this->renameMapCheck->checkStaleMaps();
            $this->declaredDeltaCheck->checkStaleDeclaredDelta();
            $this->declaredDeltaCheck->checkStaleFieldMoves();
        } finally {
            $reference->remove();
            $this->cleanUp();
        }
    }

    /**
     * Measures the normalization list from repeated candidate runs, or returns
     * null when one of them failed and nothing may be written.
     */
    public function deriveNormalization(): ?string
    {
        try {
            $passes = [];

            for ($pass = 1; $pass <= NormalizationDeriver::passes(); ++$pass) {
                $artifacts = $this->runTree($this->options->candidateRoot, 'derive-' . $pass, reverseInput: false);
                $this->caseOutcomeCheck->checkRunsProduced('derive-' . $pass, $artifacts);
                $passes[] = $artifacts;
            }

            // The same rule the declared-delta derivation obeys, for the same
            // reason and one door along: a run that produced nothing measures
            // nothing, and a list "measured" from it is a claim the next
            // ordinary run will be judged against. Every pass is judged, not
            // just the one the rules are read from, because a list derived from
            // one good pass and one empty one is a difference between a run and
            // a failure rather than between two runs.
            if ($this->report->exitCode() !== GateReport::EXIT_GREEN) {
                return null;
            }

            return $this->normalizationCheck->measured($passes);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Measures every surface that differs and writes it out as the declared
     * delta, so no declaration is a diff somebody typed.
     *
     * A run that failed writes nothing, and this is where that has to be
     * decided. The entry point already refuses to call such a run a write and
     * prints "nothing was written" — but it printed it *after* this method had
     * replaced the index and every diff file on disk, so the sentence was false
     * and the tree was left holding a declaration measured from a breakage for
     * the next ordinary run to be judged against.
     *
     * @return list<string> the files written
     */
    public function deriveDeclaredDelta(): array
    {
        $this->declaredDeltaCheck->startDeriving();
        $this->compare();

        if ($this->report->exitCode() !== GateReport::EXIT_GREEN) {
            return [];
        }

        // Same rule as the normalization list one door along: a signal in the
        // tail of the run reaches no decision point, so without this the tree's
        // declaration would be rewritten by a run the developer stopped.
        if (Interruption::exitCode() !== null) {
            return [];
        }

        return $this->declaredDeltaCheck->rewriteDerived();
    }

    public function cleanUp(): void
    {
        Fs::removeRecursively($this->temporaryDirectory);
    }

    /** @return array<string, string> */
    private function runTree(string $treeRoot, string $label, bool $reverseInput): array
    {
        $run = new TreeRun($treeRoot, $this->temporaryDirectory, $label, $this->maps, $reverseInput);
        $artifacts = $run->rules();

        $artifacts += (new CaseScheduler(
            $this->options->candidateRoot,
            $treeRoot,
            $this->temporaryDirectory,
            $label,
            $reverseInput,
            $this->options->jobs,
            $this->maps,
        ))->run($this->corpus->cases);

        return $artifacts;
    }

    /** @param array<string, string> $artifacts */
    private function checkFindings(string $side, array $artifacts, bool $trackObserved): void
    {
        $tuple = EquivalenceTuple::load($this->options->candidateRoot);

        foreach ($this->corpus->cases as $case) {
            $key = Surfaces::key('case:' . $case->id, 'format:json');
            $findings = $this->caseOutcomeCheck->findingsOf($side, $case, $artifacts);

            if ($findings === null) {
                continue;
            }

            $this->tupleCheck->checkTupleAgainstFindings($side, $case, $tuple, $findings);
            $this->normalizationCheck->checkNormalizationLeavesFindings($side, $case, $artifacts[$key], $findings);
            $this->fingerprintCheck->checkFingerprints($side, $case, $findings, $artifacts);

            if ($trackObserved) {
                $this->findingsByCase[$case->id] = $findings;
            }
        }
    }
}
