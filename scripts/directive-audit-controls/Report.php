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
 *
 * A third disagreement gets its own line rather than folding into either:
 * a declared name the run never executed at all — renamed, removed, or a
 * dataset that moved without its declaration — is not a case that "stayed
 * green", it is a name with nothing behind it.
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

        $universe = $this->universe();
        $stale = $this->staleDeclarations($universe);

        foreach ($this->outcomes as $outcome) {
            printf(
                "%-{$width}s  %-20s %s\n",
                $outcome->probe->id,
                $outcome->verdict(),
                $outcome->refusal ?? \sprintf('%d of %d cases red', \count($outcome->red), \count($outcome->cases)),
            );

            foreach ($outcome->missing as $declared) {
                $line = \in_array($declared, $universe, true)
                    ? 'stayed green: ' . $declared
                    : 'declared a case that never ran: ' . $declared;

                printf("%-{$width}s  %-20s %s\n", '', '', $line);
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
        $unguarded = $this->unguarded($universe);

        printf(
            "\n%d probes, %d not as declared, %d cases guarded by nothing.\n",
            \count($this->outcomes),
            \count($failed),
            \count($unguarded),
        );

        foreach ($unguarded as $case) {
            printf("  guarded by nothing: %s\n", $case);
        }

        // A stale name already fails its probe through `missing`, printed
        // above — this line is what makes the failure a distinct refusal
        // rather than a message that reads like an ordinary red case: the
        // name was never in the run at all, not a claim the run left green.
        foreach ($stale as $probeId => $names) {
            foreach ($names as $name) {
                printf("  stale declaration: %s names \"%s\", which no case in this run carries\n", $probeId, $name);
            }
        }

        if ($this->narrowed) {
            print("\nNarrowed by --only, so the coverage condition is not evidence: it is checked over the whole list.\n");

            return $failed === [] && $stale === [] ? 0 : 1;
        }

        return $failed === [] && $unguarded === [] && $stale === [] ? 0 : 1;
    }

    /**
     * Every case name the run actually executed, across every clone.
     *
     * @return list<string>
     */
    private function universe(): array
    {
        $cases = [];

        foreach ($this->outcomes as $outcome) {
            $cases = [...$cases, ...$outcome->cases];
        }

        return array_values(array_unique($cases));
    }

    /**
     * Declared case names — from either half of {@see Probe::declared()} —
     * that name nothing the run executed: a rename, a removal, or a dataset
     * that moved on without the declaration following it.
     *
     * @param list<string> $universe
     *
     * @return array<string, list<string>> probe id => the stale names it declared
     */
    private function staleDeclarations(array $universe): array
    {
        $stale = [];

        foreach ($this->outcomes as $outcome) {
            // A refused probe already carries its own reason — the mutation
            // did not apply, or PHPUnit could not be read — and every one of
            // its declared names is reported missing as a side effect of that
            // refusal, not evidence that the name itself has gone stale.
            if ($outcome->refusal !== null) {
                continue;
            }

            $names = array_values(array_diff($outcome->probe->declared(), $universe));

            if ($names !== []) {
                $stale[$outcome->probe->id] = $names;
            }
        }

        return $stale;
    }

    /**
     * The cases no probe *declares* it reddens, counting neither the positive
     * probe nor the blanket ones.
     *
     * Built from `$probe->reddens` rather than from what actually turned red:
     * a case a probe reaches only through {@see Probe::alsoReddens()} is
     * guarded by the claim that cascade belongs to, not by a claim of its
     * own, and counting it here is how MISDECLARED cases read as covered
     * while nothing denied them directly (measured in
     * `enumeration-unguarded-cases.tsv`).
     *
     * The rule for what counts as coverage is the same one
     * {@see staleDeclarations()} already applies: an outcome that measured
     * nothing cannot prove anything measured its declaration, so a refused
     * probe's `reddens` is excluded here exactly as its declared names are
     * excluded from staleness there.
     *
     * @param list<string> $universe
     *
     * @return list<string>
     */
    private function unguarded(array $universe): array
    {
        $reddened = [];

        foreach ($this->outcomes as $outcome) {
            // The positive probe reddens nothing by design; a blanket one
            // reddens nearly everything, also by design. Counting either as the
            // thing that guards a case is how eleven of the fifteen field cases
            // came to look guarded while no breakage denied their claim. A
            // refused probe reddened nothing either, for a third reason: its
            // mutation never ran, so its declaration is not a measurement.
            if ($outcome->probe->isPositive() || $outcome->probe->blanket || $outcome->refusal !== null) {
                continue;
            }

            $reddened = [...$reddened, ...$outcome->probe->reddens];
        }

        $unguarded = array_values(array_unique(array_diff($universe, $reddened)));
        sort($unguarded);

        return $unguarded;
    }
}
