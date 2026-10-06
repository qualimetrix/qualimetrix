<?php

declare(strict_types=1);

namespace QmxFindingGate;

use WeakMap;
use WeakReference;

/**
 * Whether each case's run produced what a comparison reads: a findings section that is not truncated, and
 * a baseline file its command wrote.
 */
final class CaseOutcomeCheck implements CaseCheck, RunCheck, SurfaceStage, Derivation
{
    private bool $deriving = false;

    /** @var array<string,string> */
    private array $measured = [];

    /** @var WeakMap<RunContext,WeakReference<self>>|null */
    private static ?WeakMap $instances = null;

    private ?RunContext $context = null;

    /** @var array<string,list<string>> */
    private array $envelopeFields = [];

    public function __construct(
        private readonly GateReport $report,
        private readonly Corpus $corpus,
    ) {}

    public static function create(RunContext $run): static
    {
        self::$instances ??= new WeakMap();
        $instance = (self::$instances[$run] ?? null)?->get();
        if ($instance === null) {
            $instance = new self($run->report, $run->corpus);
            $instance->context = $run;
            self::$instances[$run] = WeakReference::create($instance);
        }
        return $instance;
    }

    public function name(): string
    {
        return CaseOutcome::CHECK_OUTCOME;
    }

    public function before(): string
    {
        return 'presence';
    }

    public function applyStage(SurfacePair $pair): void
    {
        $run = $this->run();
        $plan = CapturePlan::forCorpus($run->corpus, $run->declarations->surfaces);
        try {
            $invocation = $plan->invocationOf($pair->key);
        } catch (GateError) {
            return;
        }
        $descriptor = $plan->descriptorOf($invocation);
        if ($descriptor['commandClass'] === 'check' && str_starts_with($descriptor['scope'], 'case:')
            && $run->declarations->outcomes->of(substr($descriptor['scope'], 5)) !== null) {
            $pair->settle();
        }
    }

    public function checkCase(string $side, CaseDefinition $case, string $outcome, array $artifacts): void
    {
        $scope = 'case:' . $case->id;
        $exit = $artifacts[Surfaces::key($scope, 'exit:format:json')] ?? null;
        $stdout = $artifacts[Surfaces::key($scope, 'format:json')] ?? null;
        $stderr = $artifacts[Surfaces::key($scope, 'stderr:format:json')] ?? null;
        if ($exit === null || $stdout === null || $stderr === null || !ctype_digit($exit)
            || (int) $exit < 1 || (int) $exit > 255 || $exit === '70'
            || ($case->outcomeExit !== null && $exit !== (string) $case->outcomeExit)) {
            if ($outcome === CaseOutcome::REFUSAL) {
                $this->report->sourceEvidence($side, $scope . '|format:json', 'outcome', false);
            }
            $this->mismatch($side . ' / ' . $case->id, 'The case did not end with its exact declared non-analysis exit.');
            return;
        }
        $payload = json_decode($stdout, true);
        if ($outcome === CaseOutcome::REFUSAL) {
            if (!\is_array($payload) || array_keys($payload) !== $this->refusalFields($side)
                || !\is_string($payload['error'] ?? null) || $payload['error'] === '' || ($payload['exit_code'] ?? null) !== (int) $exit
                || (isset($payload['position']) && !\is_array($payload['position']))) {
                $this->report->sourceEvidence($side, $scope . '|format:json', 'outcome', false);
                $this->mismatch($side . ' / ' . $case->id, 'The JSON refusal differs from its publisher or has incoherent error/exit_code/position values.');
            } else {
                $this->report->sourceEvidence($side, $scope . '|format:json', 'outcome', true);
            }
            return;
        }
        if ($outcome !== CaseOutcome::INCOMPLETE || $exit !== '4' || !\is_array($payload)
            || !\is_array($payload['violations'] ?? null) || ($payload['coverage']['complete'] ?? null) !== false
            || ($artifacts[Surfaces::key($scope, 'exit:baseline:generate')] ?? null) !== '4'
            || ($artifacts[Surfaces::key($scope, 'baseline-file')] ?? null) !== '') {
            $this->mismatch($side . ' / ' . $case->id, 'An incomplete analysis must report exit 4, incomplete coverage and no baseline file.');
        }
    }

    public function checkRun(array $candidate, array $reference): void
    {
        $run = $this->run();
        foreach ($this->corpus->cases as $case) {
            $row = $run->declarations->outcomes->of($case->id);
            if ($row === null) {
                continue;
            }
            if ($row['transition'] === DeclaredOutcomes::REFUSAL_TO_ANALYSIS) {
                $presence = Process::run(['git', 'cat-file', '-e', $run->options->reference . ':finding-gate/cases/' . $case->id . '/case.json'], $run->options->candidateRoot);
                if ($presence['exit'] === 0) {
                    $run->report->fail(FailureClass::REFERENCE_INPUT_UNTRANSLATED, 'case:' . $case->id, 'The reference already owns this case; refusal cannot stand in for translating its inputs.');
                }
            }
            $refusalSide = $row['transition'] === DeclaredOutcomes::ANALYSIS_TO_REFUSAL ? 'candidate' : 'reference';
            if ($refusalSide === 'candidate' && !$case->isAuxiliary()) {
                $this->mismatch('case:' . $case->id, 'A refused authoritative case must transfer channel ownership before declaring its refusal.');
            }
            $refused = $refusalSide === 'candidate' ? $candidate : $reference;
            $analysed = $refusalSide === 'candidate' ? $reference : $candidate;
            $analysis = json_decode($analysed['case:' . $case->id . '|format:json'] ?? '', true);
            $analysisExit = $analysed['case:' . $case->id . '|exit:format:json'] ?? null;
            if (!\is_array($analysis) || !\is_array($analysis['violations'] ?? null) || !\in_array($analysisExit, ['0', '1', '2'], true)) {
                $this->mismatch('case:' . $case->id, 'The analysis side of the declared transition did not produce a complete analysis.');
                continue;
            }
            $baselineKey = 'case:' . $case->id . '|baseline-file';
            $needsBaselineRefusal = $run->declarations->exactSurfaces->has($baselineKey);
            $snapshot = $this->refusalSnapshot($case, $refused);
            if ($snapshot === null) {
                if ($needsBaselineRefusal) {
                    $this->report->sourceEvidence($refusalSide, $baselineKey, 'outcome', false);
                }
                continue;
            }
            if (!$this->deriving && $snapshot !== $row['output']) {
                if ($needsBaselineRefusal) {
                    $this->report->sourceEvidence($refusalSide, $baselineKey, 'outcome', false);
                }
                $this->mismatch('case:' . $case->id, 'The refusal stdout, stderr, exit or file differs from its exact declared snapshot.');
                continue;
            }
            if ($needsBaselineRefusal && (!$this->report->sourceValid($refusalSide, 'case:' . $case->id . '|format:json', 'outcome')
                || ($refused[$baselineKey] ?? null) !== ''
                || !\is_string($baselineExit = $refused['case:' . $case->id . '|exit:baseline:generate'] ?? null)
                || !ctype_digit($baselineExit) || (int) $baselineExit < 1 || (int) $baselineExit > 255 || $baselineExit === '70'
                || !\is_string($baselineStderr = $refused['case:' . $case->id . '|stderr:baseline-file'] ?? null)
                || $baselineStderr === '')) {
                $this->report->sourceEvidence($refusalSide, $baselineKey, 'outcome', false);
                $this->mismatch('case:' . $case->id . ' / baseline:generate', 'The declared refusal did not retain an absent baseline, a non-analysis exit and populated stderr.');
                continue;
            }
            if ($needsBaselineRefusal) {
                $this->report->sourceEvidence($refusalSide, $baselineKey, 'outcome', true);
            }
            $this->measured[$case->id] = $snapshot;
            $run->declarations->outcomes->credit($case->id);
        }
    }

    public function startDeriving(): void
    {
        $this->deriving = true;
    }

    public function rewriteDerived(): array
    {
        $run = $this->run();
        $written = [];
        if (!$run->report->canDerive([FailureClass::NONDETERMINISM_UNDECLARED, FailureClass::PATH_LEAK])) {
            return [];
        }
        foreach ($this->measured as $case => $snapshot) {
            foreach ($run->report->raised() as $failure) {
                if ($failure['class'] === FailureClass::CASE_OUTCOME_MISMATCH && str_contains($failure['scope'], $case)) {
                    continue 2;
                }
            }
            $row = $run->declarations->outcomes->of($case);
            if ($row === null) {
                throw new GateError('An unannounced outcome cannot be written.');
            }
            Fs::write($run->options->candidateRoot . '/finding-gate/' . $row['file'], $snapshot);
            $written[] = $row['file'];
        }
        return $written;
    }

    /** @param array<string,string> $artifacts */
    private function refusalSnapshot(CaseDefinition $case, array $artifacts): ?string
    {
        $run = $this->run();
        $plan = CapturePlan::forCorpus($run->corpus, $run->declarations->surfaces);
        $snapshot = [];
        $expectedExit = $artifacts[Surfaces::key('case:' . $case->id, 'exit:format:json')] ?? null;
        foreach ($plan->invocations() as $descriptor) {
            if ($descriptor['scope'] !== 'case:' . $case->id || $descriptor['commandClass'] !== 'check') {
                continue;
            }
            $key = Surfaces::key($descriptor['scope'], $descriptor['surface']);
            if (!$plan->requiredOn($key, CaseOutcome::of($case, 'candidate') === CaseOutcome::REFUSAL ? 'candidate' : 'reference')) {
                continue;
            }
            $stdout = $artifacts[$key] ?? null;
            $stderr = $artifacts[Surfaces::key($descriptor['scope'], 'stderr:' . $descriptor['surface'])] ?? null;
            $exit = $artifacts[Surfaces::key($descriptor['scope'], 'exit:' . $descriptor['surface'])] ?? null;
            if ($stdout === null || $stderr === null || $exit === null || !ctype_digit($exit)
                || (int) $exit < 1 || (int) $exit > 255 || $exit === '70' || $exit !== $expectedExit || $stdout . $stderr === '') {
                $this->mismatch('case:' . $case->id . ' / ' . $descriptor['surface'], 'A declared refusal snapshot lacks its populated stdout/stderr and non-analysis exit.');
                return null;
            }
            $snapshot[$key] = ['stdout' => $run->normalization->normalize($descriptor['surface'], $stdout),
                'stderr' => $run->normalization->normalize(Surfaces::surfaceClass(Surfaces::key($descriptor['scope'], 'stderr:' . $descriptor['surface'])), $stderr), 'exit' => $exit];
            if ($descriptor['outputFileKind'] !== null) {
                $fileKey = Surfaces::key($descriptor['scope'], $descriptor['outputFileKind']);
                if (!\array_key_exists($fileKey, $artifacts)) {
                    $this->mismatch('case:' . $case->id, 'A declared refusal omitted a planned file artifact.');
                    return null;
                }
                if ($artifacts[$fileKey] !== '') {
                    $snapshot[$key]['file'] = $run->normalization->normalize($descriptor['outputFileKind'], $artifacts[$fileKey]);
                }
            }
        }
        if ($snapshot === []) {
            $this->mismatch('case:' . $case->id, 'No check invocation populated the declared refusal.');
            return null;
        }
        return json_encode($snapshot, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
    }

    private function mismatch(string $scope, string $detail): void
    {
        $this->report->fail(FailureClass::CASE_OUTCOME_MISMATCH, $scope, $detail);
    }

    private function run(): RunContext
    {
        return $this->context ?? throw new GateError('An outcome extension has no live run context.');
    }

    /** @return list<string> */
    private function refusalFields(string $side): array
    {
        $side = $side === 'reference' ? 'reference' : 'candidate';
        if (isset($this->envelopeFields[$side])) {
            return $this->envelopeFields[$side];
        }
        $file = 'src/Infrastructure/Console/Refusal/RefusalPresenter.php';
        $root = $this->context?->options->candidateRoot ?? \dirname(__DIR__, 2);
        if ($side === 'reference' && $this->context !== null) {
            $result = Process::run(['git', 'show', $this->context->options->reference . ':' . $file], $root);
            if ($result['exit'] !== 0) {
                throw new GateError('Cannot read the reference refusal publisher: ' . $result['stderr']);
            }
            $source = $result['stdout'];
        } else {
            $source = Fs::read($root . '/' . $file);
        }
        return $this->envelopeFields[$side] = self::deriveRefusalFields($source);
    }

    /** @return list<string> */
    public static function deriveRefusalFields(string $source): array
    {
        $tokens = token_get_all($source);
        $method = false;
        $encoding = false;
        $depth = 0;
        $fields = [];
        foreach ($tokens as $index => $token) {
            if (\is_array($token) && $token[0] === \T_STRING && $token[1] === 'writeEnvelope') {
                $method = true;
            }
            if ($method && \is_array($token) && $token[0] === \T_STRING && $token[1] === 'json_encode') {
                $encoding = true;
            }
            if (!$encoding) {
                continue;
            }
            if ($token === '[' || $token === '(') {
                ++$depth;
            } elseif ($token === ']' || $token === ')') {
                --$depth;
                if ($depth === 0) {
                    break;
                }
            }
            if ($depth !== 2 || !\is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $next = $index + 1;
            while (isset($tokens[$next]) && \is_array($tokens[$next]) && $tokens[$next][0] === \T_WHITESPACE) {
                ++$next;
            }
            if (isset($tokens[$next]) && \is_array($tokens[$next]) && $tokens[$next][0] === \T_DOUBLE_ARROW) {
                $fields[] = substr($token[1], 1, -1);
            }
        }
        if ($fields === [] || \count($fields) !== \count(array_unique($fields))) {
            throw new GateError('The refusal envelope must publish a nonempty unique key set.');
        }
        return $fields;
    }

    /**
     * Whether every case of one tree's run produced the artifacts a comparison
     * reads, reporting what did not.
     *
     * Split out because a *derivation* needs exactly this much judgement and no
     * more. A normalization derive run rewrites the tracked declaration the next ordinary run
     * is judged against, so a failed normalization measurement must write nothing — but the
     * normalization derivation compares nothing, and every verdict lived in
     * compare(). It therefore wrote a measured list from runs that had produced
     * no output at all, under the sentence "Measured ... from repeated runs".
     *
     * @param array<string, string> $artifacts
     * @param array<string,list<array<string,mixed>>> $authority validated complete findings by case
     */
    public function checkRunsProduced(string $side, array $artifacts, array $authority): void
    {
        foreach ($this->corpus->cases as $case) {
            $outcome = CaseOutcome::of($case, $side === 'reference' ? 'reference' : 'candidate');
            if ($outcome !== CaseOutcome::ANALYSIS) {
                $this->checkCase($side, $case, $outcome, $artifacts);
            }
            if (CaseOutcome::applies(CaseOutcome::CHECK_FINDINGS, $outcome)) {
                if (!\array_key_exists($case->id, $authority)) {
                    throw new GateError('A produced analysis has no validated physical authority: ' . $case->id);
                }
                if (CaseOutcome::applies(CaseOutcome::CHECK_BASELINE_FILE, $outcome)) {
                    $this->checkBaselineSurface($side, $case, $artifacts);
                }
            }
        }
    }

    /**
     * One case's findings, or null with the failure already reported.
     *
     * @param array<string, string> $artifacts
     * @param list<array<string, mixed>>|null $complete validated raw physical authority
     *
     * @return list<array<string, mixed>>|null
     */
    public function findingsOf(string $side, CaseDefinition $case, array $artifacts, ?array $complete = null): ?array
    {
        $key = Surfaces::key('case:' . $case->id, 'format:json');
        $report = json_decode($artifacts[$key] ?? '', true);

        if (!\is_array($report) || !\is_array($report['violations'] ?? null)) {
            $this->report->sourceEvidence($side, $key, 'outcome', false);
            $this->report->fail(FailureClass::RUN_FAILED, $side . ' / ' . $case->id, 'The JSON surface carries no findings section.', [], ['side' => $side, 'key' => $key, 'role' => 'outcome']);

            return null;
        }

        if (($report['violationsMeta']['truncated'] ?? false) === true && $complete === null) {
            $this->report->sourceEvidence($side, $key, 'outcome', false);
            $this->report->fail(
                FailureClass::RUN_FAILED,
                $side . ' / ' . $case->id,
                'The JSON surface truncated its findings, so the comparison would silently cover a prefix.'
                . ' Add --format-opt=violations=all to the case arguments.',
                [],
                ['side' => $side, 'key' => $key, 'role' => 'outcome'],
            );
        }

        if (CaseOutcome::applies(CaseOutcome::CHECK_BASELINE_FILE, CaseOutcome::of($case, $side === 'reference' ? 'reference' : 'candidate'))) {
            $this->checkBaselineSurface($side, $case, $artifacts);
        }
        $this->report->sourceEvidence($side, $key, 'outcome', true);

        /** @var list<array<string, mixed>> $findings */
        $findings = $complete ?? array_values($report['violations']);

        return $findings;
    }

    /**
     * An absent surface must not read as a surface that agrees.
     *
     * `baseline-file` is captured as the file the command wrote, and a command
     * that wrote nothing captures as an empty string on both sides — which
     * compares equal, and would silently retire the whole baseline surface from
     * the comparison. So the surface's existence is asserted before it is
     * compared, on each side separately, together with the exit code of the
     * command that was supposed to produce it.
     *
     * @param array<string, string> $artifacts
     */
    private function checkBaselineSurface(string $side, CaseDefinition $case, array $artifacts): void
    {
        $scope = 'case:' . $case->id;
        $key = Surfaces::key($scope, 'baseline-file');
        $exit = $artifacts[Surfaces::key($scope, 'exit:baseline:generate')] ?? null;

        if ($exit !== '0') {
            $this->report->sourceEvidence($side, $key, 'outcome', false);
            $this->report->fail(
                FailureClass::RUN_FAILED,
                $side . ' / ' . $case->id . ' / baseline:generate',
                \sprintf('baseline:generate exited %s, so its file is not a surface either side can be held to.', $exit ?? 'nothing'),
                [],
                ['side' => $side, 'key' => $key, 'role' => 'outcome'],
            );
        }

        if (trim($artifacts[$key] ?? '') === '') {
            $this->report->sourceEvidence($side, $key, 'outcome', false);
            $this->report->fail(
                FailureClass::RUN_FAILED,
                $side . ' / ' . $case->id . ' / baseline-file',
                'baseline:generate wrote no baseline. An empty baseline compares equal to an empty baseline, so the'
                . ' whole surface would drop out of the comparison unnoticed.',
                [],
                ['side' => $side, 'key' => $key, 'role' => 'outcome'],
            );
        }
        if ($exit === '0' && trim($artifacts[$key] ?? '') !== '') {
            $this->report->sourceEvidence($side, $key, 'outcome', true);
        }
    }
}
