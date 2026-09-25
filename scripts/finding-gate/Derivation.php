<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * One form's share of the derive run: from `startDeriving()` on it absorbs
 * what it would have judged — and only what its own declared intents cover,
 * judging everything else as an ordinary run does — and after a GREEN run it
 * writes what it measured.
 */
interface Derivation
{
    public function startDeriving(): void;

    /** @return list<string> the files written, relative to `finding-gate/` or naming it */
    public function rewriteDerived(): array;
}
