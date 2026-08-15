<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * What one capture produced: the baseline, and the groups it declined to
 * record.
 *
 * Returning the baseline alone would make the shortfall invisible — the
 * success line would read "Baseline with 0 entries written" for a run whose
 * every finding was refused, and the user would meet those findings again on
 * the next `check` with nothing anywhere to explain it.
 */
final readonly class BaselineCapture
{
    /**
     * @param list<UncapturedGroup> $uncaptured groups that produced no entry (ADR 0017)
     */
    public function __construct(
        public Baseline $baseline,
        public array $uncaptured = [],
    ) {}

    /**
     * @param list<array{identity: BaselineIdentity, reason: UncapturedReason, memberCount: positive-int}> $rejected
     */
    public static function fromRejectedGroups(Baseline $baseline, array $rejected): self
    {
        return new self(
            $baseline,
            array_map(
                static fn(array $group): UncapturedGroup => new UncapturedGroup(
                    $group['identity'],
                    $group['reason'],
                    $group['memberCount'],
                ),
                $rejected,
            ),
        );
    }

    /**
     * The distinct channels the refused groups named, sorted — what a report
     * shows next to their count.
     *
     * @return list<string>
     */
    public function uncapturedChannels(): array
    {
        $channels = [];

        foreach ($this->uncaptured as $group) {
            $channels[$group->identity->channel->toKey()] = true;
        }

        $keys = array_keys($channels);
        sort($keys, \SORT_STRING);

        return $keys;
    }
}
