<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * A check of one side's run of one case, registered by a declaration form.
 *
 * Which outcomes it applies to is not its own choice: its name is a row of
 * {@see CaseOutcome::CHECKS}, the one place every check asks.
 */
interface CaseCheck extends GateExtension
{
    /** A key of {@see CaseOutcome::CHECKS}. */
    public function name(): string;

    /** @param array<string, string> $artifacts the side's whole run */
    public function checkCase(string $side, CaseDefinition $case, string $outcome, array $artifacts): void;
}
