<?php

declare(strict_types=1);

namespace QmxFindingGate;

final class ValueDerivation implements GateExtension, Derivation
{
    private function __construct(private readonly ValueCheck $values) {}

    public static function create(RunContext $run): static
    {
        return new self(ValueCheck::create($run));
    }

    public function startDeriving(): void
    {
        $this->values->startDeriving();
    }

    public function rewriteDerived(): array
    {
        return $this->values->rewriteDerived();
    }
}
