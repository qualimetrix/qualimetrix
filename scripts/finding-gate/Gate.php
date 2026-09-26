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
 * own class. A declaration form adds its checks through its wiring file
 * ({@see Wiring}): per case, as a step of a surface's comparison, over the
 * whole run, and to the derive run.
 */
final class Gate
{
    private readonly RenameMaps $maps;

    /** What the candidate's own source says a metric name may be. */
    private readonly MetricVocabulary $vocabulary;

    private readonly Corpus $corpus;

    private readonly Declarations $declarations;

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

    private readonly StaleDeclarationCheck $staleDeclarationCheck;

    /** @var list<CaseCheck> */
    private readonly array $caseChecks;

    /** @var list<RunCheck> */
    private readonly array $runChecks;

    /** @var list<Derivation> */
    private readonly array $derivations;

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
        $this->declarations = Declarations::load($this->options->candidateRoot);
        $this->declaredDelta = $this->declarations->delta;
        $this->declaredFieldMoves = $this->declarations->fieldMoves;
        $this->split = ChannelSplit::of($this->maps);
        $this->corpus = Corpus::load($this->options->candidateRoot, $this->options->cases);
        foreach (DeclaredFields::REPORTS as $fieldReport) {
            if ($this->declarations->fields->changes($fieldReport) === []) {
                continue;
            }
            foreach ($this->corpus->cases as $case) {
                foreach (['candidate', 'reference'] as $side) {
                    $this->declarations->fields->requireMeasurements($fieldReport, $case->id, $side);
                }
            }
        }
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
        $this->coverageCheck = new CoverageCheck($this->options, $this->report, $this->corpus, $witness);
        $this->staleDeclarationCheck = new StaleDeclarationCheck($this->report, $this->declarations);

        $run = new RunContext(
            $this->options,
            $this->report,
            $this->corpus,
            $this->maps,
            $this->split,
            $this->vocabulary,
            $normalization,
            $this->declarations,
            $this->temporaryDirectory,
        );
        $wiring = Wiring::of(__DIR__);
        $this->caseChecks = self::registered($wiring, 'caseChecks', CaseCheck::class, $run);
        $this->runChecks = self::registered($wiring, 'runChecks', RunCheck::class, $run);
        $this->derivations = self::registered($wiring, 'derivations', Derivation::class, $run);

        foreach ($this->caseChecks as $check) {
            CaseOutcome::applies($check->name(), CaseOutcome::ANALYSIS);
        }

        CaseOutcome::assertVerifiable(
            $this->corpus,
            array_map(static fn(CaseCheck $check): string => $check->name(), $this->caseChecks),
        );

        $this->surfaceComparison = new SurfaceComparison(
            $this->report,
            $this->corpus,
            $this->maps,
            $normalization,
            $this->fingerprintCheck,
            $this->declaredDeltaCheck,
            $this->temporaryDirectory,
            self::registered($wiring, 'surfaceStages', SurfaceStage::class, $run),
        );
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

        foreach ($this->declarations->counts() as $reportKey => $count) {
            $this->report->countDeclarations($reportKey, $count);
        }

        $this->report->fact('declared forms', \sprintf(
            '%d record(s), %d value intent(s), %d field change(s), %d outcome(s), %d surface change(s), %d structural'
            . ' map row(s)',
            ...array_values($this->declarations->counts()),
        ));

        if ($this->options->cases !== []) {
            $this->report->limit('the corpus was restricted to ' . implode(', ', $this->options->cases) . ' by --cases');
        }

        $this->tupleCheck->checkTuple();
        $this->normalizationCheck->checkNormalizationScope();

        $first = $this->runTree($this->options->candidateRoot, 'candidate-1', reverseInput: false);
        $second = $this->runTree($this->options->candidateRoot, 'candidate-2', reverseInput: false);
        $this->normalizationCheck->checkDeterminism($first, $second);

        $reference = ReferenceTree::create($this->options->candidateRoot, (string) $this->options->reference, $this->maps);

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

            foreach ($this->runChecks as $check) {
                $check->checkRun($first, $referenceArtifacts);
            }

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
            $this->staleDeclarationCheck->checkStaleDeclarations();
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
     * Measures every declaration a run can measure — the declared delta of
     * every surface that differs, and what each form's registered
     * {@see Derivation} derives under its intents — and writes them out, so no
     * declaration is a diff somebody typed. One pass for every form: each
     * absorbs only what it derives and the run judges everything else, so a
     * change no intent covers keeps the run red and nothing is written.
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
    public function deriveDeclarations(): array
    {
        $derivations = [$this->declaredDeltaCheck, ...$this->derivations];

        foreach ($derivations as $derivation) {
            $derivation->startDeriving();
        }

        $this->staleDeclarationCheck->startDeriving();
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

        $written = [];

        foreach ($derivations as $derivation) {
            $written = [...$written, ...$derivation->rewriteDerived()];
        }

        return $written;
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

    /**
     * Each case of one side, by the checks its outcome leaves something to
     * check ({@see CaseOutcome::CHECKS}); a case whose findings could not be
     * read is reported once and checked no further.
     *
     * @param array<string, string> $artifacts
     */
    private function checkFindings(string $side, array $artifacts, bool $trackObserved): void
    {
        $tuple = EquivalenceTuple::load($this->options->candidateRoot);

        foreach ($this->corpus->cases as $case) {
            $key = Surfaces::key('case:' . $case->id, 'format:json');
            $outcome = CaseOutcome::of($case, $side);

            if (CaseOutcome::applies(CaseOutcome::CHECK_FINDINGS, $outcome)) {
                $findings = $this->caseOutcomeCheck->findingsOf($side, $case, $artifacts);

                if ($findings === null) {
                    continue;
                }

                if (CaseOutcome::applies(CaseOutcome::CHECK_TUPLE, $outcome)) {
                    $this->tupleCheck->checkTupleAgainstFindings($side, $case, $tuple, $findings);
                }

                if (CaseOutcome::applies(CaseOutcome::CHECK_NORMALIZATION_LEAVES_FINDINGS, $outcome)) {
                    $this->normalizationCheck->checkNormalizationLeavesFindings($side, $case, $artifacts[$key], $findings);
                }

                if (CaseOutcome::applies(CaseOutcome::CHECK_FINGERPRINTS, $outcome)) {
                    $this->fingerprintCheck->checkFingerprints($side, $case, $findings, $artifacts);
                }

                if ($trackObserved && CaseOutcome::applies(CaseOutcome::CHECK_COVERAGE, $outcome)) {
                    $this->findingsByCase[$case->id] = $findings;
                }
            }

            foreach ($this->caseChecks as $check) {
                if (CaseOutcome::applies($check->name(), $outcome)) {
                    $check->checkCase($side, $case, $outcome, $artifacts);
                }
            }
        }
    }

    /**
     * The classes the forms registered under one key, built for this run.
     *
     * @template T of object
     *
     * @param class-string<T> $contract
     *
     * @return list<T>
     */
    private static function registered(Wiring $wiring, string $key, string $contract, RunContext $run): array
    {
        $built = [];

        foreach ($wiring->list($key) as $class) {
            $qualified = __NAMESPACE__ . '\\' . $class;

            if (!is_subclass_of($qualified, GateExtension::class) || !is_subclass_of($qualified, $contract)) {
                throw new GateError(\sprintf(
                    '%s is registered under "%s" and does not implement %s and %s.',
                    $class,
                    $key,
                    GateExtension::class,
                    $contract,
                ));
            }

            $instance = $qualified::create($run);
            \assert($instance instanceof $contract);
            $built[] = $instance;
        }

        return $built;
    }
}
