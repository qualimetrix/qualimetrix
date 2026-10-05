<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\EntryComparability;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\GroupCapture;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\SubjectRegion;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Captures unoccupied comparable identities of explicitly selected channels. */
final readonly class NewIdentityAcceptance
{
    public function __construct(private ChannelDeclarationRegistryInterface $declarations) {}

    /**
     * @param list<Finding> $measured
     * @param list<FindingChannel> $channels
     *
     * @return array{list<BaselineEntry>, list<BaselineEntryUpdateOutcome>, array<string, string>}
     */
    public function accept(Baseline $baseline, array $measured, array $channels, RunCoverage $coverage, RunRuleCoverage $publication): array
    {
        $occupied = self::occupiedIdentities($baseline);
        [$selected, $notes] = $this->selectedChannels($channels, $publication);
        $entries = $baseline->entries;
        $outcomes = [];
        $capture = new GroupCapture($this->declarations);
        foreach (self::groupsByIdentity($measured) as $key => $group) {
            $identity = BaselineIdentity::forFinding($group[0]);
            if (!isset($selected[$identity->channel->code]) || !$selected[$identity->channel->code]) {
                continue;
            }
            $notes[$identity->channel->code] = 'no-comparable-new-identities';
            $captured = $this->captureNewGroup($identity, $group, $occupied[$key] ?? null, $baseline, $coverage, $publication, $capture);
            if ($captured instanceof BaselineEntryUpdateOutcome) {
                $outcomes[] = $captured;
                continue;
            }
            $entries[] = $captured;
            $outcomes[] = BaselineEntryUpdateOutcome::accepted($identity);
        }
        foreach ($outcomes as $outcome) {
            if ($outcome->disposition === BaselineUpdateDisposition::Accepted) {
                unset($notes[$outcome->identity->channel->code]);
            }
        }

        return [$entries, $outcomes, $notes];
    }

    /** @return array<string, string> */
    private static function occupiedIdentities(Baseline $baseline): array
    {
        $occupied = [];
        foreach ($baseline->entries as $entry) {
            $occupied[$entry->identity->key()] = 'existing-entry';
        }
        foreach ($baseline->inertEntries as $entry) {
            if ($entry->identity !== null) {
                $occupied[$entry->identity->key()] = 'inert-holds-identity';
            }
        }

        return $occupied;
    }

    /**
     * @param list<FindingChannel> $channels
     *
     * @return array{array<string, bool>, array<string, string>}
     */
    private function selectedChannels(array $channels, RunRuleCoverage $publication): array
    {
        $selected = $notes = [];
        foreach ($channels as $channel) {
            $declaration = $this->declarations->declarationFor($channel);
            $published = $declaration !== null && array_any(
                $declaration->levels,
                static fn(SymbolLevel $level): bool => $publication->publishes($channel, $level),
            );
            $selected[$channel->code] = $published;
            $notes[$channel->code] = $published ? 'no-finding' : 'not-measured';
        }

        return [$selected, $notes];
    }

    /** @param non-empty-list<Finding> $group */
    private function captureNewGroup(
        BaselineIdentity $identity,
        array $group,
        ?string $occupiedReason,
        Baseline $baseline,
        RunCoverage $coverage,
        RunRuleCoverage $publication,
        GroupCapture $capture,
    ): BaselineEntry|BaselineEntryUpdateOutcome {
        if ($occupiedReason !== null) {
            return BaselineEntryUpdateOutcome::skipped($identity, $occupiedReason);
        }
        $level = MetricSubject::levelOfCanonical($identity->subjectKey);
        if (!\in_array($level, $this->declarations->declarationFor($identity->channel)->levels ?? [], true)
            || !$publication->publishes($identity->channel, $level)) {
            return BaselineEntryUpdateOutcome::skipped($identity, 'not-measured');
        }
        $region = SubjectRegion::forIdentity($identity, $this->declarations->reachAt($identity->channel, $level), $coverage->psr4Roots, $group);
        $comparison = EntryComparability::judge($region, $baseline, $coverage);
        if (!$comparison->canCompare()) {
            return BaselineEntryUpdateOutcome::skipped($identity, $comparison->reason?->value);
        }
        $captured = $capture->capture($identity, $group);

        return $captured instanceof BaselineEntry
            ? $captured
            : BaselineEntryUpdateOutcome::skipped($identity, $captured->value);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, non-empty-list<Finding>>
     */
    private static function groupsByIdentity(array $findings): array
    {
        $groups = [];
        foreach ($findings as $finding) {
            $groups[BaselineIdentity::forFinding($finding)->key()][] = $finding;
        }

        return $groups;
    }
}
