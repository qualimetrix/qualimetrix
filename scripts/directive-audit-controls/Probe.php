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
    /**
     * @param list<string> $reddens case names, matched as substrings
     * @param bool $blanket whether this breakage short-circuits the whole comparison rather than
     *                      denying one claim. Such a probe reddens most of the suite by design, and
     *                      is deliberately not allowed to count as the thing that guards a case:
     *                      review measured eleven of the fifteen field cases resting on nothing else
     */
    private function __construct(
        public string $id,
        public string $claim,
        public Mutation $mutation,
        public array $reddens,
        public bool $blanket = false,
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

    /**
     * A breakage that denies nothing in particular by denying everything: the
     * comparison of two runs, short-circuited.
     *
     * Kept because it is the one probe that proves the suite notices damage at
     * all, and kept out of the coverage count for the same reason.
     *
     * @param array<string, string> $replacement
     * @param list<string> $reddens
     */
    public static function blanket(
        string $id,
        string $claim,
        string $file,
        array $replacement,
        array $reddens,
    ): self {
        return new self($id, $claim, Mutation::edit($file, $replacement, $claim), $reddens, true);
    }

    /**
     * A breakage that plants a file rather than editing one.
     *
     * Some claims are about what is *not* in the tree, and no edit states them:
     * the seeded directive fixture proves nothing about the enumeration over
     * `src/` until a copy of it appears there.
     *
     * @param array<string, string> $files path => the whole file to write
     * @param list<string> $reddens
     */
    public static function planting(
        string $id,
        string $claim,
        array $files,
        array $reddens,
    ): self {
        return new self($id, $claim, Mutation::create($files, $claim), $reddens);
    }

    public function isPositive(): bool
    {
        return $this->mutation->isEmpty();
    }
}
