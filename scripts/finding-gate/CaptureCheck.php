<?php

declare(strict_types=1);

namespace QmxFindingGate;

use WeakMap;
use WeakReference;

/** The publications every planned invocation owes, including prospective introduced JSON forms. */
final class CaptureCheck implements SurfaceStage, RunCheck
{
    /** @var WeakMap<RunContext,WeakReference<self>>|null */
    private static ?WeakMap $instances = null;

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
        $surface = Surfaces::surfaceClass($key);
        if ($this->plan->changeOf($key) === DeclaredSurfaces::INTRODUCED && \in_array($surface, ['format:json', 'format:metrics', 'format:suppressed'], true)
            && $this->run->publicationForms->of('candidate', $key) === PublicationForms::RECORDS) {
            // A declared surface is judged as one complete invocation, not as unrelated byte diffs.
            $pair->settle();
        }
    }

    public function checkRun(array $candidate, array $reference): void
    {
        $this->run->publicationForms->supply('candidate', $candidate);
        $this->run->publicationForms->supply('reference', $reference);
        foreach ($this->plan->invocations() as $descriptor) {
            $key = Surfaces::key($descriptor['scope'], $descriptor['surface']);
            $change = $this->plan->changeOf($key);
            $case = $this->caseOf($descriptor['scope']);
            $whole = $change === DeclaredSurfaces::INTRODUCED
                ? $this->run->publicationForms->of('candidate', $key) !== PublicationForms::RECORDS
                : !$this->run->publicationForms->recordInvocation($key);
            $population = !$whole || \in_array($descriptor['commandClass'], ['graph:export', 'rules', 'debug:layer-assignment'], true);
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
                if ($population && $analyzing && $file !== null && ($artifacts[Surfaces::key($descriptor['scope'], $file)] ?? '') === '') {
                    $valid = false;
                    $this->publicationFailure($side . ' / ' . $key, 'The planned file publication is missing or empty.');
                }
                if ($population && $analyzing && $key !== 'tree|graph:export' && $descriptor['surface'] !== 'check:output' && ($artifacts[$key] ?? '') === '') {
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
                $populationExit = !$whole && $side === 'candidate' && !\in_array($rawExit, $allowed, true)
                    ? (ValueCheck::create($this->run)->referenceExitFor($descriptor['commandClass'], $key, $rawExit) ?? $rawExit)
                    : $rawExit;
                if ($key === 'tree|graph:export' && $populationExit !== '1') {
                    $valid = false;
                    $this->publicationFailure($side . ' / ' . $key, 'The neutral empty-directory graph must end in its explicit exit-1 outcome.');
                }
                if ($population && $analyzing && !\in_array($populationExit, $allowed, true)) {
                    $valid = false;
                    $this->publicationFailure($side . ' / ' . $key, 'The process outcome cannot establish successful population for this command.');
                }
                if ($rawExit === '70') {
                    $valid = false;
                    $this->publicationFailure($side . ' / ' . $key, 'An unknown replay invocation cannot establish successful population.');
                } elseif ($valid && (!ctype_digit($rawExit) || (int) $rawExit > 255)) {
                    $valid = false;
                    $this->publicationFailure($side . ' / ' . $key, 'An invalid process exit cannot establish successful population.');
                }
                $this->run->report->sourceEvidence($side, $key, 'capture', $valid);
                foreach ($this->plan->artifactsOf($key) as $artifact) {
                    $this->run->report->sourceEvidence($side, $artifact, 'capture', $valid);
                }
            }
            if ($whole || !\in_array($descriptor['surface'], ['format:json', 'format:metrics', 'format:suppressed'], true)) {
                continue;
            }
            if ($change === DeclaredSurfaces::INTRODUCED) {
                $exit = $candidate[Surfaces::key($descriptor['scope'], 'exit:' . $descriptor['surface'])] ?? '';
                if (\in_array($exit, ['0', '1', '2'], true) && ($candidate[$key] ?? '') !== '') {
                    $this->run->declarations->surfaces->credit($descriptor['surface']);
                } else {
                    $this->publicationFailure('candidate / ' . $key, 'An introduced surface must publish a populated successful analysis, not an unsupported command refusal.');
                }
            }
        }
    }

    private function publicationFailure(string $scope, string $detail): void
    {
        $this->run->report->fail(FailureClass::SURFACE_MISMATCH, $scope, $detail);
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

}
