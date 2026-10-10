<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * A class a declaration form registers in its wiring file for the gate to
 * build: it is handed the run it takes part in, and nothing else.
 */
interface GateExtension
{
    public static function create(RunContext $run): static;
}
