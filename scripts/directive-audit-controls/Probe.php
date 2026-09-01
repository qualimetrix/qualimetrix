<?php

declare(strict_types=1);

namespace QmxDirectiveAuditControls;

use QmxFindingGateControls\Mutation;

/**
 * One control: a breakage to plant, and the cases that must go red under it.
 *
 * The declared cases are the point of the control, not a summary of it. A
 * mutation that reddens *something* proves only that the suite notices damage;
 * a mutation that reddens the case written for the claim it breaks proves the
 * claim is guarded. The harness checks the declaration, and separately checks
 * that no mutation reddens everything — a breakage that fails the whole suite
 * says nothing about which claim it broke.
 */
final readonly class Probe
{
    /** @param list<string> $reddens case names, matched as substrings */
    private function __construct(
        public string $id,
        public string $claim,
        public Mutation $mutation,
        public array $reddens,
    ) {}

    /**
     * The unmutated run: every case green.
     *
     * Without it, eighteen reds could all be red for an environmental reason —
     * a missing vendor directory, a broken clone, a PHP that cannot boot the
     * container — and the table would look like a thorough day's work.
     */
    public static function positive(): self
    {
        return new self(
            'positive',
            'the suite is green on an unmutated clone',
            Mutation::none(),
            [],
        );
    }

    /**
     * @param array<string, string> $replacement the source fragment to break => what replaces it
     * @param list<string> $reddens
     */
    public static function breaking(
        string $id,
        string $claim,
        string $file,
        array $replacement,
        array $reddens,
    ): self {
        return new self($id, $claim, Mutation::edit($file, $replacement, $claim), $reddens);
    }

    public function isPositive(): bool
    {
        return $this->mutation->isEmpty();
    }
}
