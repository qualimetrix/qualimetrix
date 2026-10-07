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

    private readonly RankingCaptures $rankings;

    private readonly RecordCheck $records;

    private readonly RunContext $context;

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

    private readonly ExactSurfaceDeltaCheck $exactSurfaceDeltaCheck;

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
        $capturePlan = CapturePlan::forCorpus($this->corpus, $this->declarations->surfaces);
        $this->declarations->fields->registerRequired($this->corpus, $capturePlan);
        $witness = new ChannelWitness($this->options->candidateRoot);
        $this->temporaryDirectory = Fs::temporaryDirectory('finding-gate-run-');

        $this->tupleCheck = new TupleCheck($this->options, $this->report);
        $this->normalizationCheck = new NormalizationCheck($this->options, $this->report, $normalization);
        $this->fingerprintCheck = new FingerprintCheck($this->report);
        $this->renameMapCheck = new RenameMapCheck($this->report, $this->corpus, $this->maps, $this->split);
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
        $this->declaredDeltaCheck = new DeclaredDeltaCheck(
            $this->options,
            $this->report,
            $this->declaredDelta,
            $this->declaredFieldMoves,
            $this->split,
            $run,
        );
        $this->rankings = $run->rankings;
        $this->context = $run;
        $this->exactSurfaceDeltaCheck = new ExactSurfaceDeltaCheck($run);
        $this->records = RecordCheck::create($run);
        $this->caseOutcomeCheck = CaseOutcomeCheck::create($run);
        $wiring = Wiring::of(__DIR__);
        $this->caseChecks = self::registered($wiring, 'caseChecks', CaseCheck::class, $run);
        $this->runChecks = self::registered($wiring, 'runChecks', RunCheck::class, $run);
        $this->derivations = [...self::registered($wiring, 'derivations', Derivation::class, $run), $this->exactSurfaceDeltaCheck];

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
            $this->records,
            $this->exactSurfaceDeltaCheck,
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
        $this->report->fact('declared exact surfaces', $this->declarations->exactSurfaces->count());
        $this->report->countExactSurfaces($this->declarations->exactSurfaces->count());

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

        try {
            $firstCapture = $this->runTree($this->options->candidateRoot, 'candidate-1', reverseInput: false);
            $first = $firstCapture->artifacts;
            $secondCapture = $this->runTree($this->options->candidateRoot, 'candidate-2', reverseInput: false);
            $second = $secondCapture->artifacts;
        } catch (GateError) {
            $this->cleanUp();
            return;
        }
        $this->normalizationCheck->checkDeterminism($first, $second);

        $reference = ReferenceTree::create($this->options->candidateRoot, (string) $this->options->reference, $this->maps);

        try {
            $mismatch = $reference->dependencySetMismatch();

            if ($mismatch !== null) {
                $this->report->fail(FailureClass::ENV_MISMATCH, 'reference tree', $mismatch);

                return;
            }

            try {
                $referenceCapture = $this->runTree($reference->root, 'reference', reverseInput: true);
            } catch (GateError) {
                return;
            }
            $referenceArtifacts = $referenceCapture->artifacts;
            $this->rankings->supply('candidate', $firstCapture->rankings);
            $this->rankings->supply('reference', $referenceCapture->rankings);
            $this->context->baselineEligibility->supply('candidate', $firstCapture->baselineEligibility);
            $this->context->baselineEligibility->supply('reference', $referenceCapture->baselineEligibility);

            $this->renameMapCheck->checkReferenceInput($first, $referenceArtifacts);
            if (\in_array(FailureClass::REFERENCE_INPUT_UNTRANSLATED, $this->report->failureClasses(), true)) {
                return;
            }
            $this->checkFindings('candidate', $first, trackObserved: true);
            $this->checkFindings('reference', $referenceArtifacts, trackObserved: false);
            $this->exactSurfaceDeltaCheck->plan(['candidate' => $firstCapture, 'reference' => $referenceCapture], $this->records, $this->fingerprintCheck);
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
            $this->coverageCheck->checkChannelWitnesses();
            $this->normalizationCheck->checkStaleNormalization();
            $this->renameMapCheck->checkStaleMaps();
            $this->declaredDeltaCheck->checkStaleDeclaredDelta();
            $this->exactSurfaceDeltaCheck->checkStale();
            $this->declaredDeltaCheck->checkStaleFieldMoves();
            $this->staleDeclarationCheck->checkStaleDeclarations();
            $secondAuthority = $this->captureAuthority('candidate-2', $secondCapture);
            if ($secondAuthority !== null) {
                RankingCheck::create($this->context)->checkRepeatedCaptures($firstCapture, $secondCapture);
            }
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
            $firstCapture = null;

            for ($pass = 1; $pass <= NormalizationDeriver::passes(); ++$pass) {
                try {
                    $capture = $this->runTree($this->options->candidateRoot, 'derive-' . $pass, reverseInput: false);
                } catch (GateError) {
                    return null;
                }
                $artifacts = $capture->artifacts;
                $authority = $this->captureAuthority('derive-' . $pass, $capture);
                if ($authority === null) {
                    return null;
                }
                $this->caseOutcomeCheck->checkRunsProduced('derive-' . $pass, $artifacts, $authority);
                $firstCapture ??= $capture;
                if ($this->report->exitCode() === GateReport::EXIT_GREEN) {
                    RankingCheck::create($this->context)->checkRepeatedCaptures($firstCapture, $capture);
                }
                if ($this->report->exitCode() !== GateReport::EXIT_GREEN) {
                    return null;
                }
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
     * change no intent covers keeps the run red. Complete measurements of
     * other forms are still written; a form refuses its own invalid measurement.
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

        if (!$this->report->canDerive()) {
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

    /** @return array<string,list<array<string,mixed>>>|null */
    private function captureAuthority(string $label, CaptureResult $capture): ?array
    {
        $pass = $this->context->withCandidateCapture($capture);
        $ranking = RankingCheck::create($pass);
        $records = RecordCheck::create($pass);
        $authority = [];
        foreach ($this->corpus->cases as $case) {
            $outcome = CaseOutcome::of($case, 'candidate');
            try {
                foreach ($pass->capturePlan->rankingInvocations() as $descriptor) {
                    $key = Surfaces::key($descriptor['scope'], $descriptor['surface']);
                    if ($descriptor['scope'] !== 'case:' . $case->id || !$pass->capturePlan->requiredOn($key, 'candidate')) {
                        continue;
                    }
                    if (!$ranking->checkCaptureMetadata('candidate', $key, $capture->artifacts)) {
                        return null;
                    }
                    if (!CaseOutcome::applies(CaseOutcome::CHECK_FINDINGS, $outcome)) {
                        continue;
                    }
                    $published = ReportRecords::extract('json', $capture->artifacts[$key], $records->fields('json', $descriptor['surface'], 'candidate'));
                    $observed = $ranking->observe('candidate', $case, $descriptor['surface'], $published, $capture->artifacts);
                    if ($descriptor['surface'] === 'format:json') {
                        $authority[$case->id] = $observed['rawAuthority'];
                    }
                }
                if (CaseOutcome::applies(CaseOutcome::CHECK_FINDINGS, $outcome) && !\array_key_exists($case->id, $authority)) {
                    throw new GateError('The capture has no validated main physical authority.');
                }
            } catch (GateError $error) {
                $this->report->fail(FailureClass::RUN_FAILED, $label . ' / ' . $case->id, $error->getMessage());
                return null;
            }
        }
        return $authority;
    }

    private function runTree(string $treeRoot, string $label, bool $reverseInput): CaptureResult
    {
        try {
            $this->context->supplyPublicationTree(str_starts_with($label, 'reference') ? 'reference' : 'candidate', $treeRoot);
            $run = new TreeRun(
                $treeRoot,
                $this->temporaryDirectory,
                $label,
                $this->maps,
                $reverseInput,
                CapturePlan::forCorpus($this->corpus, $this->declarations->surfaces),
                $this->declarations->structuralMaps,
            );
            $capture = new CaptureResult($run->rules(), []);

            return $capture->merge((new CaseScheduler(
                $this->options->candidateRoot,
                $treeRoot,
                $this->temporaryDirectory,
                $label,
                $reverseInput,
                $this->options->jobs,
                $this->maps,
                $this->declarations->structuralMaps,
            ))->captureCases($this->corpus->cases));
        } catch (GateError $error) {
            $this->report->fail(FailureClass::RUN_FAILED, $label, $error->getMessage());
            throw $error;
        }
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

            foreach ($this->caseChecks as $check) {
                if (CaseOutcome::applies($check->name(), $outcome)) {
                    $check->checkCase($side, $case, $outcome, $artifacts);
                }
            }

            if (CaseOutcome::applies(CaseOutcome::CHECK_FINDINGS, $outcome)) {
                try {
                    $complete = $this->records->rawAuthority($case->id, 'format:json', $side);
                } catch (GateError) {
                    // The record producer already reported why its authority is unavailable.
                    $complete = null;
                }
                $findings = $this->caseOutcomeCheck->findingsOf($side, $case, $artifacts, $complete);

                if ($findings === null) {
                    continue;
                }

                if (CaseOutcome::applies(CaseOutcome::CHECK_TUPLE, $outcome)) {
                    $this->tupleCheck->checkTupleAgainstFindings($side, $case, $tuple, $findings);
                }

                if (CaseOutcome::applies(CaseOutcome::CHECK_NORMALIZATION_LEAVES_FINDINGS, $outcome)) {
                    $publication = ReportRecords::decode($artifacts[$key]);
                    $published = $publication['violations'] ?? throw new GateError('A readable findings publication lost its physical section.');
                    if (!\is_array($published)) {
                        throw new GateError('A readable findings publication has no physical records.');
                    }
                    /** @var list<array<string,mixed>> $published */
                    $published = array_values($published);
                    $this->normalizationCheck->checkNormalizationLeavesFindings($side, $case, $artifacts[$key], $published);
                }

                if (CaseOutcome::applies(CaseOutcome::CHECK_FINGERPRINTS, $outcome)) {
                    $this->fingerprintCheck->checkFingerprints($side, $case, $findings, $artifacts);
                }

                if ($trackObserved && CaseOutcome::applies(CaseOutcome::CHECK_COVERAGE, $outcome)) {
                    $this->findingsByCase[$case->id] = $findings;
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
