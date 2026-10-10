<?php

declare(strict_types=1);

namespace QmxFindingGate;

use PhpParser\Error;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use WeakMap;
use WeakReference;

/**
 * Whether each case's run produced what a comparison reads: a findings section that is not truncated, and
 * captured baseline content from its command.
 */
final class CaseOutcomeCheck implements CaseCheck, RunCheck
{
    /** @var WeakMap<RunContext,WeakReference<self>>|null */
    private static ?WeakMap $instances = null;

    private ?RunContext $context = null;

    /** @var array<string,list<string>> */
    private array $envelopeFields = [];

    public function __construct(
        private readonly GateReport $report,
        private readonly Corpus $corpus,
        private readonly ?PublicationForms $publicationForms = null,
    ) {}

    public static function create(RunContext $run): static
    {
        self::$instances ??= new WeakMap();
        $instance = (self::$instances[$run] ?? null)?->get();
        if ($instance === null) {
            $instance = new self($run->report, $run->corpus, $run->publicationForms);
            $instance->context = $run;
            self::$instances[$run] = WeakReference::create($instance);
        }
        return $instance;
    }

    public function name(): string
    {
        return CaseOutcome::CHECK_OUTCOME;
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
            $this->mismatch($side . ' / ' . $case->id, 'An incomplete analysis must report exit 4, incomplete coverage and empty captured baseline content.');
        }
    }

    public function checkRun(array $candidate, array $reference): void
    {
        foreach ($this->corpus->cases as $case) {
            foreach (['candidate' => $candidate, 'reference' => $reference] as $side => $artifacts) {
                $scope = 'case:' . $case->id;
                $exit = $artifacts[$scope . '|exit:baseline:generate'] ?? null;
                if ($exit !== null && $exit !== '0' && ($artifacts[$scope . '|baseline-file'] ?? '') !== '') {
                    $this->mismatch($side . ' / ' . $case->id . ' / baseline:generate', 'A refusing baseline invocation must retain empty captured baseline content.');
                }
            }
        }
    }

    private function mismatch(string $scope, string $detail): void
    {
        $this->report->fail(FailureClass::CASE_OUTCOME_MISMATCH, $scope, $detail);
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

    /**
     * Reads one explicit array passed to the native or repairing JSON encoder.
     * Dynamic envelope construction is refused. PHP syntax belongs to php-parser.
     *
     * @return list<string>
     */
    public static function deriveRefusalFields(string $source): array
    {
        if (!class_exists(ParserFactory::class)) {
            require_once \dirname(__DIR__, 2) . '/vendor/autoload.php';
        }
        try {
            $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        } catch (Error $error) {
            throw new GateError('Cannot parse the refusal publisher: ' . $error->getMessage(), 0, $error);
        }
        $finder = new NodeFinder();
        $methods = $finder->find($nodes, static fn($node): bool => $node instanceof ClassMethod && $node->name->toString() === 'writeEnvelope');
        if (\count($methods) !== 1) {
            throw new GateError('The refusal publisher must declare one writeEnvelope method.');
        }
        $calls = $finder->find($methods[0], static fn($node): bool => ($node instanceof FuncCall
            && $node->name instanceof Name && $node->name->toLowerString() === 'json_encode')
            || ($node instanceof StaticCall && $node->class instanceof Name
                && \in_array($node->class->toString(), ['PublishedUtf8', 'Qualimetrix\\Reporting\\Formatter\\PublishedUtf8'], true)
                && $node->name instanceof Identifier && $node->name->toString() === 'encodeJsonObject'));
        if (\count($calls) !== 1 || !(($encoder = $calls[0]) instanceof FuncCall || $encoder instanceof StaticCall)
            || !($argument = $encoder->getArgs()[0] ?? null) instanceof Arg || !$argument->value instanceof Array_) {
            throw new GateError('The refusal envelope must encode one explicit array literal; dynamic construction is not supported.');
        }
        $fields = [];
        foreach ($argument->value->items as $item) {
            if ($item === null || $item->unpack || !$item->key instanceof String_) {
                throw new GateError('The refusal envelope must publish explicit string keys.');
            }
            $fields[] = $item->key->value;
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
        $forms = $this->publicationForms;
        $captureSide = $side === 'reference' ? 'reference' : 'candidate';
        $forms?->supply($captureSide, $artifacts);
        foreach ($this->corpus->cases as $case) {
            $baselineExit = $artifacts['case:' . $case->id . '|exit:baseline:generate'] ?? null;
            if ($baselineExit !== null && $baselineExit !== '0' && ($artifacts['case:' . $case->id . '|baseline-file'] ?? '') !== '') {
                $this->checkBaselineSurface($side, $case, $artifacts);
            }
            $outcome = CaseOutcome::of($case, $side === 'reference' ? 'reference' : 'candidate');
            if ($outcome !== CaseOutcome::ANALYSIS) {
                $this->checkCase($side, $case, $outcome, $artifacts);
            }
            if (CaseOutcome::applies(CaseOutcome::CHECK_FINDINGS, $outcome)) {
                if ($forms?->of($captureSide, 'case:' . $case->id . '|format:json') === PublicationForms::WHOLE_INVOCATION
                    && ($captureSide === 'reference' || $case->isAuxiliary())) {
                    continue;
                }
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
     * An unpopulated surface must not read as a surface that agrees.
     *
     * `baseline-file` captures bytes from the command's output target. No
     * captured content compares equal on both sides and would silently retire
     * the whole surface from comparison. Require nonempty content on each side,
     * together with the exit code of the command that was supposed to produce it.
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
                'baseline:generate produced no usable captured baseline content. Without it, the whole surface'
                . ' could compare equal and drop out of the comparison unnoticed.',
                [],
                ['side' => $side, 'key' => $key, 'role' => 'outcome'],
            );
        }
        if ($exit === '0' && trim($artifacts[$key] ?? '') !== '') {
            $this->report->sourceEvidence($side, $key, 'outcome', true);
        }
    }
}
