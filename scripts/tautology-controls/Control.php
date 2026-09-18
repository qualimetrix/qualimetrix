<?php

declare(strict_types=1);

namespace QmxTautologyControls;

use QmxFindingGateControls\Mutation;

/**
 * One repaired tautology, as a breakage and the cases that must notice it.
 *
 * Stage 05 replaced fourteen assertions that could not fail. "Could not fail"
 * is a claim about the *new* assertion as much as the old one, and a report
 * saying so is not re-runnable: the next edit to the production code it reads
 * can quietly take the teeth back out. So each repair is declared here with
 * the production edit it is supposed to reject, and the harness plants that
 * edit and reads which cases went red.
 *
 * The declaration is an equality over exact case names, borrowed from
 * {@see \QmxDirectiveAuditControls\Probe} together with the reason it is an
 * equality: a subset comparison leaves every case reddened beyond the
 * declaration unchecked, and a substring one credits a method's declaration
 * with any single data set of it. A cascade the breakage genuinely causes is
 * written down through {@see alsoReddens()}, with its reason, rather than
 * tolerated.
 */
final readonly class Control
{
    /**
     * @param string $row the ledger row this repair closes
     * @param list<string> $reddens exact case names, as PHPUnit writes them —
     *                              a method with data sets is named per data set
     * @param array<string, string> $alsoReddens exact case name => why this breakage reaches it too
     * @param array<string, string> $fragments the production text the mutation replaces => its replacement,
     *                                         kept beside the Mutation so the declaration can be read
     *                                         without planting anything
     */
    private function __construct(
        public string $id,
        public string $row,
        public string $claim,
        public Mutation $mutation,
        public array $reddens,
        public array $alsoReddens = [],
        public array $fragments = [],
    ) {}

    /**
     * The unmutated run: every case green.
     *
     * Without it a table of reds proves only that something in the clone is
     * broken — a missing vendor directory, a tree that did not copy — and
     * every control below would read as a thorough day's work.
     */
    public static function positive(): self
    {
        return new self(
            'positive',
            '—',
            'the suite is green on an unmutated clone',
            Mutation::none(),
            [],
        );
    }

    /**
     * @param array<string, string> $replacement the production fragment to break => what replaces it
     * @param list<string> $reddens
     */
    public static function breaking(
        string $id,
        string $row,
        string $claim,
        string $file,
        array $replacement,
        array $reddens,
    ): self {
        return new self($id, $row, $claim, Mutation::edit($file, $replacement, $claim), $reddens, [], $replacement);
    }

    /**
     * The cases this breakage reaches beyond the repair it denies, each with
     * the reason it does.
     *
     * Not a weaker declaration: the harness compares the union of both lists
     * to the red set as an equality, so an undeclared red is still a failed
     * control. What the tail records is a judgement — this edit sits under
     * cases that read the same code — and a judgement has to be written where
     * a reader can dispute it.
     *
     * @param list<string> $cases
     */
    public function alsoReddens(string $because, array $cases): self
    {
        $tail = $this->alsoReddens;

        foreach ($cases as $case) {
            $tail[$case] = $because;
        }

        return new self($this->id, $this->row, $this->claim, $this->mutation, $this->reddens, $tail, $this->fragments);
    }

    /**
     * Every case this control promises to redden: the repair's own, plus the tail.
     *
     * @return list<string>
     */
    public function declared(): array
    {
        return array_values(array_unique([...$this->reddens, ...array_keys($this->alsoReddens)]));
    }

    public function isPositive(): bool
    {
        return $this->mutation->isEmpty();
    }
}
