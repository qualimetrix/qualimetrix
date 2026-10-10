<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\EntryBinding;

use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;

use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineAuditChannels;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Core\Symbol\SymbolLevel;

final readonly class UnusedEntryAudit
{
    public function __construct(private RuleExecutionInterface $execution) {}

    /** @return array{findings: list<Finding>, population: JudgedPopulation} */
    public function auditResult(CeilingOutcome $outcome, string $baselinePath, ChannelPublication $publication): array
    {
        $channel = new FindingChannel(BaselineAuditChannels::UNUSED_ENTRY);
        if (!$publication->publishes(UnusedEntryRule::NAME, $channel, SymbolLevel::Project)) {
            return ['findings' => [], 'population' => JudgedPopulation::empty()];
        }
        $findings = [];
        $members = (static function () use ($outcome, $baselinePath, &$findings): iterable {
            $ordinal = 0;
            foreach ($outcome->staleEntries as $entry) {
                yield ['identity' => PopulationIdentity::occurrence('stale:' . $entry->identity->key() . ':' . $entry->selector()->value, $ordinal++, 'baseline-diagnostic-record'), 'inputs' => []];
                $findings[] = UnusedEntryFinding::of(
                    'stale',
                    $entry->selector()->value,
                    $entry->identity->describe(),
                    'its identity did not appear in the complete comparable measured set',
                    $baselinePath,
                );
            }
            $duplicateCounts = self::duplicateCounts($outcome->inertEntries);
            $reportedDuplicates = [];
            foreach ($outcome->inertEntries as $entry) {
                $reason = $entry->reason->description() . ': ' . $entry->detail;
                if ($entry->reason === InertEntryReason::DuplicateIdentity && $entry->identity !== null) {
                    $key = $entry->identity->key();
                    $selector = $entry->selector->value;
                    if (isset($reportedDuplicates[$key][$selector])) {
                        continue;
                    }
                    $reportedDuplicates[$key][$selector] = true;
                    $reason .= '; ' . $duplicateCounts[$key][$selector] . ' contenders share this identity';
                }
                yield ['identity' => PopulationIdentity::occurrence('inert:' . $entry->describe() . ':' . $entry->selector->value, $ordinal++, 'baseline-diagnostic-record'), 'inputs' => []];
                $findings[] = UnusedEntryFinding::of(
                    'inert',
                    $entry->selector->value,
                    $entry->describe(),
                    $reason,
                    $baselinePath,
                );
            }
        })();
        $population = $publication->measure(UnusedEntryRule::NAME, $channel, SymbolLevel::Project, UnusedEntryRule::channelDeclarations()[BaselineAuditChannels::UNUSED_ENTRY], $members);

        return ['findings' => $this->execution->publishable($findings), 'population' => $population];
    }

    /**
     * @param list<InertBaselineEntry> $entries
     *
     * @return array<string, array<string, int>>
     */
    private static function duplicateCounts(array $entries): array
    {
        $duplicateCounts = [];
        foreach ($entries as $entry) {
            if ($entry->reason === InertEntryReason::DuplicateIdentity && $entry->identity !== null) {
                $key = $entry->identity->key();
                $selector = $entry->selector->value;
                $duplicateCounts[$key][$selector] = ($duplicateCounts[$key][$selector] ?? 0) + 1;
            }
        }
        return $duplicateCounts;
    }

}
