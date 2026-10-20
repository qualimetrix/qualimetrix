<?php

declare(strict_types=1);

namespace QmxFindingGate;

final class RecordDerivation implements GateExtension, Derivation
{
    private function __construct(private readonly RecordCheck $records) {}

    public static function create(RunContext $run): static
    {
        return new self(RecordCheck::create($run));
    }

    public function startDeriving(): void
    {
        $this->records->startDeriving();
    }

    public function rewriteDerived(): array
    {
        return $this->records->rewriteDerived();
    }
}
