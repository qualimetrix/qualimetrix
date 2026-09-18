<?php

declare(strict_types=1);

namespace QmxTautologyControls;

/**
 * The table, and the verdict the exit code carries.
 *
 * Two refusals no single outcome can state, both of them about the set:
 *
 * 1. a case in the run that no control declares is **guarded by nothing** — a
 *    repair whose teeth this bench never tested;
 * 2. a case a control declares that the run never carried is a **stale
 *    declaration**, distinct from "stayed green", because the two are fixed
 *    differently: one follows a rename, the other means the repair lost its
 *    guard.
 */
final readonly class Report
{
    /**
     * @param list<Outcome> $outcomes
     * @param list<string> $ran every case the population carried
     * @param list<string> $mustBeGuarded the repaired cases, which some control has to claim
     */
    private function __construct(
        public array $outcomes,
        public array $ran,
        public array $mustBeGuarded,
    ) {}

    /**
     * @param list<Outcome> $outcomes
     * @param list<string> $ran
     * @param list<string> $mustBeGuarded
     */
    public static function of(array $outcomes, array $ran, array $mustBeGuarded): self
    {
        return new self($outcomes, $ran, $mustBeGuarded);
    }

    /**
     * Repaired cases no control claims to redden.
     *
     * The population is not the denominator: these files carry hundreds of
     * cases this stage never touched, and a bench that demanded a control for
     * each of them would be measuring the project rather than the repairs.
     *
     * @return list<string>
     */
    public function unguarded(): array
    {
        $declared = [];

        foreach ($this->outcomes as $outcome) {
            $declared = [...$declared, ...$outcome->control->declared()];
        }

        return array_values(array_diff($this->mustBeGuarded, $declared));
    }

    /** @return list<string> */
    public function staleDeclarations(): array
    {
        $stale = [];

        foreach ($this->outcomes as $outcome) {
            foreach ($outcome->stale() as $case) {
                $stale[] = \sprintf('%s names "%s"', $outcome->control->id, $case);
            }
        }

        return $stale;
    }

    public function print(): int
    {
        $failed = 0;

        foreach ($this->outcomes as $outcome) {
            $verdict = $outcome->verdict();

            printf(
                "%-34s %-6s %-28s %s\n",
                $outcome->control->id,
                $outcome->control->row,
                $verdict,
                $outcome->control->claim,
            );

            if ($outcome->refusal !== null) {
                printf("  refused: %s\n", $outcome->refusal);
            }

            foreach ($outcome->stale() as $case) {
                printf("  declared a case that never ran: %s\n", $case);
            }

            foreach ($outcome->missing as $case) {
                if (!\in_array($case, $outcome->stale(), true)) {
                    printf("  stayed green: %s\n", $case);
                }
            }

            foreach ($outcome->unexpected as $case) {
                printf("  reddened without being declared: %s\n", $case);
            }

            if (!$outcome->asDeclared()) {
                ++$failed;
            }
        }

        foreach ($this->unguarded() as $case) {
            printf("  guarded by nothing: %s\n", $case);
        }

        foreach ($this->staleDeclarations() as $line) {
            printf("  stale declaration: %s\n", $line);
        }

        $bad = $failed + \count($this->unguarded()) + \count($this->staleDeclarations());

        printf(
            "\n%d control(s), %d case(s) in the population, %d problem(s).\n",
            \count($this->outcomes),
            \count($this->ran),
            $bad,
        );

        return $bad === 0 ? 0 : 1;
    }
}
