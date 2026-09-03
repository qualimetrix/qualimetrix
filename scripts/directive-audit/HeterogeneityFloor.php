<?php

declare(strict_types=1);

namespace QmxDirectiveAudit;

/**
 * What a population has to contain before an agreement between the two sweeps
 * is evidence about the sweeps rather than about the tree.
 *
 * `--sweep=narrow` and `--sweep=full` agreeing over `src/` is a measurement of
 * `src/`: every one of its verdicts is `Effective`, so the comparison would
 * redden for a defect that turns verdicts into `Inert` or `Unmeasured` and stay
 * green for a defect that turns everything into `Effective` — and the second is
 * the outcome it watches as normal. Measured, not reasoned: collapsing the
 * narrowed baseline onto the full one flips four of this fixture's eight
 * verdicts and none of `src/`'s.
 *
 * Three requirements, and the third is the one prose could not hold:
 *
 * - **measured threshold verdicts**, at least as many as the caller names.
 *   `--sweep` changes how a threshold verdict is produced and nothing else, so
 *   these are the only verdicts a difference between the scopes can move at
 *   all. {@see VerdictReport::measuredThresholdCount()} already answers this
 *   for the CI floor; what is new here is a caller-set minimum above one.
 * - **every verdict of the vocabulary**, so the population can express a
 *   disagreement in any direction rather than in the one direction its tree
 *   happens to have.
 * - **every reason of the vocabulary**. Coalition masking — the branch this
 *   whole control was asked for — carries no verdict of its own: it is
 *   `Unmeasured`, exactly like a directive naming a channel nobody owns. A
 *   floor written over verdicts alone is satisfied by a population that never
 *   executes the coalition branch, and no reader would notice.
 *
 * Both tables are frozen here rather than derived from `DirectiveEffect` and
 * `DirectiveUnmeasurableReason`, for the reason {@see MeasuredEffects} is:
 * enrolling a fifth case silently would decide on the author's behalf that a
 * fixture must now produce it. Two tests hold each table against its enum in
 * both directions, so the decision is refused rather than skipped.
 */
final class HeterogeneityFloor
{
    /** @var list<string> every `DirectiveEffect` a population must contain */
    public const array REQUIRED_EFFECTS = ['effective', 'overrun', 'inert', 'unmeasured'];

    /** @var list<string> every `DirectiveUnmeasurableReason` a population must contain */
    public const array REQUIRED_REASONS = [
        'producer-disabled',
        'already-refused',
        'addresses-every-channel',
        'masked',
    ];

    /**
     * What the population contains, as the line a reader checks the floor
     * against.
     *
     * Printed on every run and not only on a failing one: a floor that is met
     * silently is a floor nobody can tell from a floor that is not applied.
     *
     * @throws AuditReportError
     */
    public static function describe(VerdictReport $report): string
    {
        return \sprintf(
            "  verdicts: %s\n  reasons:  %s\n  measured threshold verdicts: %d\n",
            self::tally(self::effectCounts($report), self::REQUIRED_EFFECTS),
            self::tally(self::reasonCounts($report), self::REQUIRED_REASONS),
            $report->measuredThresholdCount(),
        );
    }

    /**
     * Everything the floor asks for and this population does not have, in one
     * pass.
     *
     * A list rather than the first failure: an author fixing a fixture wants
     * the whole shortfall, and reporting one requirement at a time turns three
     * edits into three runs of a comparison that pays for two sweeps.
     *
     * @throws AuditReportError
     *
     * @return list<string>
     */
    public static function shortfalls(VerdictReport $report, int $minimumMeasured): array
    {
        $shortfalls = [];

        $measured = $report->measuredThresholdCount();
        if ($measured < $minimumMeasured) {
            $shortfalls[] = \sprintf(
                'measured threshold verdicts: %d, and --min-measured asks for %d. Only these can move when'
                    . ' the sweep width changes, so a population short of them agrees for free.',
                $measured,
                $minimumMeasured,
            );
        }

        $effects = self::effectCounts($report);
        foreach (self::REQUIRED_EFFECTS as $effect) {
            if (($effects[$effect] ?? 0) === 0) {
                $shortfalls[] = \sprintf('no directive was judged "%s".', $effect);
            }
        }

        $reasons = self::reasonCounts($report);
        foreach (self::REQUIRED_REASONS as $reason) {
            if (($reasons[$reason] ?? 0) === 0) {
                $shortfalls[] = \sprintf('no directive was refused for "%s".', $reason);
            }
        }

        return $shortfalls;
    }

    /**
     * @throws AuditReportError
     *
     * @return array<string, int>
     */
    private static function effectCounts(VerdictReport $report): array
    {
        $counts = [];

        foreach ($report->verdicts() as $verdict) {
            $counts[$verdict->effect] = ($counts[$verdict->effect] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @throws AuditReportError
     *
     * @return array<string, int>
     */
    private static function reasonCounts(VerdictReport $report): array
    {
        $counts = [];

        foreach ($report->verdicts() as $verdict) {
            if ($verdict->reason === null) {
                continue;
            }

            $counts[$verdict->reason] = ($counts[$verdict->reason] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The required names first and in the table's order, then whatever else the
     * population carried: a name the table does not know is news, and sorting
     * it in among the rest is how it stops being.
     *
     * @param array<string, int> $counts
     * @param list<string> $required
     */
    private static function tally(array $counts, array $required): string
    {
        $parts = [];

        foreach ($required as $name) {
            $parts[] = \sprintf('%s=%d', $name, $counts[$name] ?? 0);
        }

        foreach ($counts as $name => $count) {
            if (!\in_array($name, $required, true)) {
                $parts[] = \sprintf('%s=%d (unnamed by the floor)', $name, $count);
            }
        }

        return implode(' ', $parts);
    }
}
