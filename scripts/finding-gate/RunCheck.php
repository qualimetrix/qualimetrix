<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * A check of the whole comparison, registered by a declaration form: it runs
 * once both sides' artifacts are compared and before any declaration is judged
 * stale, so a row it consumes is credited in time.
 */
interface RunCheck extends GateExtension
{
    /**
     * @param array<string, string> $candidate
     * @param array<string, string> $reference
     */
    public function checkRun(array $candidate, array $reference): void;
}
