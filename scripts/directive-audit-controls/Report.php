<?php

declare(strict_types=1);

namespace QmxDirectiveAuditControls;

/**
 * The run, as a table and an exit code.
 *
 * The table is the point of the harness and not decoration: a control bench
 * that answers only "0" cannot be read for *which* claim is now unguarded, and
 * the first thing anyone does after a red run is ask that.
 *
 * The coverage line at the end is the condition no single probe can carry — a
 * case that no breakage reddens is a case that proves nothing, and it is
 * invisible while every probe passes its own declaration.
 *
 * Cases reddened past the declaration are printed beside the ones that stayed
 * green because the two are the same kind of answer: a declaration and a
 * measurement that disagree, in one direction or the other.
 */
final readonly class Report
{
    /** @param list<Outcome> $outcomes */
    private function __construct(
        private array $outcomes,
        private bool $narrowed,
    ) {}

    /** @param list<Outcome> $outcomes */
    public static function of(array $outcomes, bool $narrowed): self
    {
        return new self($outcomes, $narrowed);
    }

    public function print(): int
    {
        // The list is never empty — the harness refuses an empty selection
        // before constructing this — but `max()` of nothing is a fatal, and a
        // control bench that dies while reporting is worse than one that
        // reports a useless width.
        $width = max(1, ...array_map(
            static fn(Outcome $outcome): int => \strlen($outcome->probe->id),
            $this->outcomes,
        ));

        foreach ($this->outcomes as $outcome) {
            printf(
                "%-{$width}s  %-20s %s\n",
                $outcome->probe->id,
                $outcome->verdict(),
                $outcome->refusal ?? \sprintf('%d of %d cases red', \count($outcome->red), \count($outcome->cases)),
            );

            foreach ($outcome->missing as $declared) {
                printf("%-{$width}s  %-20s %s\n", '', '', 'stayed green: ' . $declared);
            }

            // Printed exactly like the cases that stayed green, and for the
            // same reason: the run has to be readable as "here is what to
            // declare or narrow", not as a count nobody can act on.
            foreach ($outcome->unexpected as $case) {
                printf("%-{$width}s  %-20s %s\n", '', '', 'reddened undeclared: ' . $case);
            }
        }

        $failed = array_values(array_filter(
            $this->outcomes,
            static fn(Outcome $outcome): bool => !$outcome->asDeclared(),
        ));
        $unguarded = $this->unguarded();

        printf(
            "\n%d probes, %d not as declared, %d cases guarded by nothing.\n",
            \count($this->outcomes),
            \count($failed),
            \count($unguarded),
        );

        foreach ($unguarded as $case) {
            printf("  guarded by nothing: %s\n", $case);
        }

        if ($this->narrowed) {
            print("\nNarrowed by --only, so the coverage condition is not evidence: it is checked over the whole list.\n");

            return $failed === [] ? 0 : 1;
        }

        return $failed === [] && $unguarded === [] ? 0 : 1;
    }

    /**
     * The cases no probe reddens, counting neither the positive probe nor the
     * blanket ones.
     *
     * @return list<string>
     */
    private function unguarded(): array
    {
        $cases = [];
        $reddened = [];

        foreach ($this->outcomes as $outcome) {
            $cases = [...$cases, ...$outcome->cases];

            // The positive probe reddens nothing by design; a blanket one
            // reddens nearly everything, also by design. Counting either as the
            // thing that guards a case is how eleven of the fifteen field cases
            // came to look guarded while no breakage denied their claim.
            if ($outcome->probe->isPositive() || $outcome->probe->blanket) {
                continue;
            }

            $reddened = [...$reddened, ...$outcome->red];
        }

        $unguarded = array_values(array_unique(array_diff($cases, $reddened)));
        sort($unguarded);

        return $unguarded;
    }
}
