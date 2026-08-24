<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Time\ClockInterface;

/**
 * `baseline:cleanup`'s two halves: listing removal candidates, and removing
 * exactly the ones a user named (ADR 0017).
 *
 * **`cleanup` never removes on its own.** {@see candidates()} only reports;
 * calling it changes nothing. Only {@see remove()}, given an explicit,
 * non-empty list of selectors, writes anything — and it removes precisely
 * those selectors and nothing else. There is deliberately no "remove
 * everything listed" method. ADR 0017 rejects that withdrawn `--all-listed`
 * shape as the same inference-by-absence the
 * whole command exists to forbid, because the list {@see candidates()}
 * returns is recomputed inside the same invocation that would consume it —
 * `baseline:cleanup file.json src/ --all-listed` run in CI after a threshold
 * loosened would delete the very entries recording the now-invisible debt.
 */
final readonly class BaselineCleaner
{
    public function __construct(
        private ClockInterface $clock,
    ) {}

    /**
     * Every entry `cleanup` would offer to remove, with its reason and
     * selector. A valid entry is offered for one of three reasons — stale,
     * its channel is no longer declared, or its channel reports a
     * configuration error and may never be accepted — and every inert entry
     * is offered too, since it already has a selector and the user is
     * entitled to delete an unreadable line (ADR 0017).
     *
     * A valid entry whose channel is no longer declared is reported as
     * {@see BaselineCleanupReason::ChannelNotDeclared} rather than
     * {@see BaselineCleanupReason::Stale}, even though such an entry is
     * always stale too — a channel no rule declares can never produce a
     * measured finding. The two reasons are not independent for that entry,
     * and the more specific, more permanent cause is the more useful answer.
     *
     * @param list<Finding> $measured the run's measured set (ADR 0017)
     *
     * @return list<BaselineCleanupCandidate>
     */
    public function candidates(
        Baseline $baseline,
        array $measured,
        ChannelDeclarationRegistryInterface $declarations,
    ): array {
        $measuredKeys = [];
        foreach ($measured as $finding) {
            $measuredKeys[] = BaselineIdentity::forFinding($finding)->key();
        }

        $staleKeys = [];
        foreach ($baseline->staleEntries($measuredKeys) as $stale) {
            $staleKeys[$stale->identity->key()] = true;
        }

        $candidates = [];

        foreach ($baseline->entries as $entry) {
            $declaration = $declarations->declarationFor($entry->identity->channel);

            if ($declaration === null) {
                $candidates[] = new BaselineCleanupCandidate(
                    $entry->selector(),
                    $entry->identity->describe(),
                    BaselineCleanupReason::ChannelNotDeclared,
                );

                continue;
            }

            // Listed on its own cause, ahead of staleness, for the same
            // reason an undeclared channel is: the entry can never be
            // applied, and "stale" would send the user looking for a symbol
            // that moved rather than at a channel that may not be accepted
            // at all. Unlike staleness, this holds even while the finding is
            // still being measured.
            if ($declaration->isConfigurationError()) {
                $candidates[] = new BaselineCleanupCandidate(
                    $entry->selector(),
                    $entry->identity->describe(),
                    BaselineCleanupReason::ChannelIsConfigurationError,
                );

                continue;
            }

            if (isset($staleKeys[$entry->identity->key()])) {
                $candidates[] = new BaselineCleanupCandidate(
                    $entry->selector(),
                    $entry->identity->describe(),
                    BaselineCleanupReason::Stale,
                );
            }
        }

        foreach ($baseline->inertEntries as $inert) {
            $candidates[] = new BaselineCleanupCandidate(
                $inert->selector,
                $inert->describe(),
                BaselineCleanupReason::Inert,
                $inert->reason,
            );
        }

        return $candidates;
    }

    /**
     * Removes exactly the named entries — valid or inert — and reports which
     * selectors matched exactly one entry, which matched none, and which
     * matched more than one and were therefore left alone (ADR 0017). An empty
     * `$selectors` list changes nothing but still stamps a fresh `generated`
     * — callers implementing "no `--remove` given" as "report and change
     * nothing" (ADR 0017) do so by not calling this method at all, not by calling
     * it with an empty list.
     *
     * @param list<EntrySelector> $selectors
     */
    public function remove(Baseline $baseline, array $selectors): BaselineCleanupRemoval
    {
        $bySelector = $this->indexBySelector($baseline);

        $toRemoveEntries = [];
        $toRemoveInert = [];
        $removed = [];
        $notFound = [];
        $ambiguous = [];
        /** @var array<string, true> $seenSelectors */
        $seenSelectors = [];

        foreach ($selectors as $selector) {
            if (isset($seenSelectors[$selector->value])) {
                continue;
            }

            $seenSelectors[$selector->value] = true;
            $matches = $bySelector[$selector->value] ?? [];

            if ($matches === []) {
                $notFound[] = $selector;

                continue;
            }

            if (\count($matches) > 1) {
                $ambiguous[] = $selector;

                continue;
            }

            $match = $matches[0];

            if ($match instanceof BaselineEntry) {
                $toRemoveEntries[] = $match;
            } else {
                $toRemoveInert[] = $match;
            }

            $removed[] = $selector;
        }

        $entries = array_values(array_filter(
            $baseline->entries,
            static fn(BaselineEntry $entry): bool => !\in_array($entry, $toRemoveEntries, true),
        ));

        $inertEntries = array_values(array_filter(
            $baseline->inertEntries,
            static fn(InertBaselineEntry $entry): bool => !\in_array($entry, $toRemoveInert, true),
        ));

        $updated = new Baseline(
            generated: $this->clock->now(),
            scope: $baseline->scope,
            entries: $entries,
            inertEntries: $inertEntries,
            sourceContentHash: $baseline->sourceContentHash,
        );

        return new BaselineCleanupRemoval($updated, $removed, $notFound, $ambiguous);
    }

    /**
     * Every entry — valid or inert — keyed by its selector, built on demand
     * for this call only. `Baseline` itself no longer carries this index: on
     * the `check` path nothing reads it, yet building it still hashes every
     * entry's identity, so it lived as pure per-run cost for callers that
     * never removed anything.
     *
     * Returns lists, not single entries, because the digest, however unlikely
     * to collide, is not a proof of uniqueness (see {@see EntrySelector}) —
     * {@see remove()} reports a selector matching more than one entry as
     * ambiguous instead of picking one.
     *
     * @return array<string, list<BaselineEntry|InertBaselineEntry>>
     */
    private function indexBySelector(Baseline $baseline): array
    {
        $bySelector = [];

        foreach ($baseline->entries as $entry) {
            $bySelector[$entry->selector()->value][] = $entry;
        }

        foreach ($baseline->inertEntries as $inert) {
            $bySelector[$inert->selector->value][] = $inert;
        }

        return $bySelector;
    }
}
