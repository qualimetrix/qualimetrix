<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use Qualimetrix\Core\Time\ClockInterface;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Violation\Violation;

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
     * selector. A valid entry is offered for one of two reasons — stale, or
     * its channel is no longer declared — and every inert entry is offered
     * too, since it already has a selector and the user is entitled to
     * delete an unreadable line (ADR 0017).
     *
     * A valid entry whose channel is no longer declared is reported as
     * {@see BaselineCleanupReason::ChannelNotDeclared} rather than
     * {@see BaselineCleanupReason::Stale}, even though such an entry is
     * always stale too — a channel no rule declares can never produce a
     * measured finding. The two reasons are not independent for that entry,
     * and the more specific, more permanent cause is the more useful answer.
     *
     * @param list<Violation> $measured the run's measured set (ADR 0017)
     *
     * @return list<BaselineCleanupCandidate>
     */
    public function candidates(
        Baseline $baseline,
        array $measured,
        ChannelDeclarationRegistryInterface $declarations,
    ): array {
        $measuredKeys = [];
        foreach ($measured as $violation) {
            $measuredKeys[] = BaselineIdentity::forViolation($violation)->key();
        }

        $staleKeys = [];
        foreach ($baseline->staleEntries($measuredKeys) as $stale) {
            $staleKeys[$stale->identity->key()] = true;
        }

        $candidates = [];

        foreach ($baseline->entries as $entry) {
            if ($declarations->declarationFor($entry->identity->channel) === null) {
                $candidates[] = new BaselineCleanupCandidate(
                    $entry->selector(),
                    $entry->identity->describe(),
                    BaselineCleanupReason::ChannelNotDeclared,
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
            $matches = $baseline->findBySelector($selector);

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
}
