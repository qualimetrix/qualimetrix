<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** Process exits are values of an exact invocation, never of a surface prefix. */
final class ValueStage implements SurfaceStage
{
    private readonly CapturePlan $plan;

    private function __construct(private readonly ValueCheck $values, RunContext $run)
    {
        $this->plan = CapturePlan::forCorpus($run->corpus, $run->declarations->surfaces);
    }

    public static function create(RunContext $run): static
    {
        return new self(ValueCheck::create($run), $run);
    }

    public function before(): string
    {
        return 'difference';
    }

    public function applyStage(SurfacePair $pair): void
    {
        if (!str_contains($pair->key, '|exit:') || $pair->candidate === null || $pair->reference === null || $pair->candidate === $pair->reference) {
            return;
        }
        $invocation = $this->plan->invocationOf($pair->key);
        $command = $this->plan->commandClassOf($invocation);
        if (preg_match('~^-?[0-9]+$~D', $pair->candidate) !== 1 || preg_match('~^-?[0-9]+$~D', $pair->reference) !== 1) {
            throw new GateError('A process exit artifact must publish an integer.');
        }
        if ($this->values->measure(DeclaredValues::EXIT, $command, $invocation, '*', (int) $pair->reference, (int) $pair->candidate)) {
            $pair->candidate = $pair->reference;
        }
    }
}
