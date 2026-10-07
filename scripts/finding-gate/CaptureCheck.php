<?php

declare(strict_types=1);

namespace QmxFindingGate;

use WeakMap;
use WeakReference;

/** The publications every planned invocation owes, including declared withdrawals. */
final class CaptureCheck implements SurfaceStage, RunCheck, Derivation
{
    /** @var WeakMap<RunContext,WeakReference<self>>|null */
    private static ?WeakMap $instances = null;

    private bool $deriving = false;

    /** @var array<string,string> surface => normalized refusal envelope */
    private array $measured = [];

    private readonly CapturePlan $plan;

    private function __construct(private readonly RunContext $run)
    {
        $this->plan = CapturePlan::forCorpus($run->corpus, $run->declarations->surfaces);
    }

    public static function create(RunContext $run): static
    {
        self::$instances ??= new WeakMap();

        $instance = (self::$instances[$run] ?? null)?->get();
        if ($instance === null) {
            $instance = new self($run);
            self::$instances[$run] = WeakReference::create($instance);
        }
        return $instance;
    }

    public function before(): string
    {
        return 'presence';
    }

    public function applyStage(SurfacePair $pair): void
    {
        try {
            $key = $this->plan->invocationOf($pair->key);
        } catch (GateError) {
            return;
        }
        if ($this->plan->changeOf($key) !== null) {
            // A declared surface is judged as one complete invocation, not as unrelated byte diffs.
            $pair->settle();
        }
    }

    public function checkRun(array $candidate, array $reference): void
    {
        foreach ($this->plan->invocations() as $descriptor) {
            $key = Surfaces::key($descriptor['scope'], $descriptor['surface']);
            $change = $this->plan->changeOf($key);
            $case = $this->caseOf($descriptor['scope']);
            foreach (['candidate' => $candidate, 'reference' => $reference] as $side => $artifacts) {
                if ($side === 'reference' && $change === DeclaredSurfaces::INTRODUCED) {
                    continue;
                }
                $valid = true;
                foreach ($this->plan->artifactsOf($key) as $artifact) {
                    if (!\array_key_exists($artifact, $artifacts)) {
                        $valid = false;
                        $this->publicationFailure($side . ' / ' . $artifact, 'A planned invocation omitted this publication.');
                    }
                }
                $analyzing = $case === null || CaseOutcome::of($case, $side) === CaseOutcome::ANALYSIS;
                $file = $descriptor['outputFileKind'];
                if ($analyzing && $file !== null && ($artifacts[Surfaces::key($descriptor['scope'], $file)] ?? '') === '') {
                    $valid = false;
                    $this->publicationFailure($side . ' / ' . $key, 'The planned file publication is missing or empty.');
                }
                if ($analyzing && !($change === DeclaredSurfaces::WITHDRAWN && $side === 'candidate') && $key !== 'tree|graph:export' && $descriptor['surface'] !== 'check:output' && ($artifacts[$key] ?? '') === '') {
                    $valid = false;
                    $this->publicationFailure($side . ' / ' . $key, 'The invocation has no populated publication.');
                }
                $exitKey = Surfaces::key($descriptor['scope'], 'exit:' . ($descriptor['surface'] === 'baseline-file' ? 'baseline:generate' : $descriptor['surface']));
                $rawExit = $artifacts[$exitKey] ?? '';
                $allowed = $key === 'tree|graph:export' ? ['1'] : match ($descriptor['commandClass']) {
                    'check' => ['0', '1', '2'],
                    'directives' => ['0', '2'],
                    default => ['0'],
                };
                $populationExit = $side === 'candidate' && !\in_array($rawExit, $allowed, true)
                    ? (ValueCheck::create($this->run)->referenceExitFor($descriptor['commandClass'], $key, $rawExit) ?? $rawExit)
                    : $rawExit;
                if ($key === 'tree|graph:export' && $populationExit !== '1') {
                    $valid = false;
                    $this->publicationFailure($side . ' / ' . $key, 'The neutral empty-directory graph must end in its explicit exit-1 outcome.');
                }
                if ($analyzing && !($change === DeclaredSurfaces::WITHDRAWN && $side === 'candidate') && !\in_array($populationExit, $allowed, true)) {
                    $valid = false;
                    $this->publicationFailure($side . ' / ' . $key, 'The process outcome cannot establish successful population for this command.');
                }
                if ($rawExit === '70') {
                    $valid = false;
                    $this->publicationFailure($side . ' / ' . $key, 'An unknown replay invocation cannot establish successful population.');
                }
                $this->run->report->sourceEvidence($side, $key, 'capture', $valid);
                foreach ($this->plan->artifactsOf($key) as $artifact) {
                    $this->run->report->sourceEvidence($side, $artifact, 'capture', $valid);
                }
            }
            if ($change === DeclaredSurfaces::INTRODUCED) {
                $exit = $candidate[Surfaces::key($descriptor['scope'], 'exit:' . $descriptor['surface'])] ?? '';
                if (\in_array($exit, ['0', '1', '2'], true) && ($candidate[$key] ?? '') !== '') {
                    $this->run->declarations->surfaces->credit($descriptor['surface']);
                } else {
                    $this->publicationFailure('candidate / ' . $key, 'An introduced surface must publish a populated successful analysis, not an unsupported command refusal.');
                }
            } elseif ($change === DeclaredSurfaces::WITHDRAWN) {
                $this->withdrawal($descriptor, $candidate, $reference);
            }
        }
    }

    private function publicationFailure(string $scope, string $detail): void
    {
        $this->run->report->fail(FailureClass::SURFACE_MISMATCH, $scope, $detail);
    }

    /**
     * @param array{scope:string,surface:string,commandClass:string,outputFileKind:?string} $descriptor
     * @param array<string,string> $candidate
     * @param array<string,string> $reference
     */
    private function withdrawal(array $descriptor, array $candidate, array $reference): void
    {
        $surface = $descriptor['surface'];
        $key = Surfaces::key($descriptor['scope'], $surface);
        $exitKey = Surfaces::key($descriptor['scope'], 'exit:' . $surface);
        $stderrKey = Surfaces::key($descriptor['scope'], 'stderr:' . $surface);
        $envelope = json_encode([
            'stdout' => $this->run->normalization->normalize($surface, $candidate[$key] ?? ''),
            'stderr' => $this->run->normalization->normalize('stderr', $candidate[$stderrKey] ?? ''),
            'exit' => $candidate[$exitKey] ?? '',
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
        $valid = ($candidate[$exitKey] ?? '0') !== '0' && ($candidate[$exitKey] ?? '70') !== '70'
            && (($candidate[$key] ?? '') !== '' || ($candidate[$stderrKey] ?? '') !== '');
        $case = $this->caseOf($descriptor['scope']);
        if ($case !== null && CaseOutcome::of($case, 'reference') === CaseOutcome::ANALYSIS) {
            $valid = $valid && \in_array($reference[$exitKey] ?? '', ['0', '1', '2'], true) && ($reference[$key] ?? '') !== '';
        }
        if ($this->deriving) {
            $expected = $this->measured[$surface] ?? $envelope;
            $this->measured[$surface] = $expected;
        } else {
            $expected = $this->run->declarations->surfaces->refusalOf($surface);
        }
        if (!$valid || $expected !== $envelope) {
            $this->run->report->fail(FailureClass::SURFACE_WITHDRAWAL_MISMATCH, $key, 'A withdrawn surface must produce the exact declared normalized stdout, stderr and exit; an analyzing reference must still publish it.');

            return;
        }
        $this->run->declarations->surfaces->credit($surface);
    }

    private function caseOf(string $scope): ?CaseDefinition
    {
        foreach ($this->run->corpus->cases as $case) {
            if ('case:' . $case->id === $scope) {
                return $case;
            }
        }

        return null;
    }

    public function startDeriving(): void
    {
        $this->deriving = true;
    }

    public function rewriteDerived(): array
    {
        $written = [];
        if (!$this->run->report->canDerive([FailureClass::SURFACE_WITHDRAWAL_MISMATCH, FailureClass::SURFACE_DECLARATION_STALE,
            FailureClass::NONDETERMINISM_UNDECLARED, FailureClass::PATH_LEAK])) {
            return [];
        }
        foreach (DeclarationTable::rows($this->run->options->candidateRoot . '/finding-gate', DeclaredSurfaces::INDEX, DeclaredSurfaces::COLUMNS) as $row) {
            $envelope = $this->measured[$row['surface']] ?? null;
            if ($row['change'] !== DeclaredSurfaces::WITHDRAWN || $envelope === null) {
                continue;
            }
            Fs::write($this->run->options->candidateRoot . '/finding-gate/' . $row['file'], $envelope);
            $written[] = 'finding-gate/' . $row['file'];
        }

        return $written;
    }
}
