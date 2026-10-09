<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\EntryBinding;

use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;

use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineAuditChannels;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

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
        $members = [];
        foreach ($outcome->staleEntries as $entry) {
            $members[] = ['identity' => PopulationIdentity::occurrence('stale:' . $entry->identity->key() . ':' . $entry->selector()->value, \count($members), 'baseline-diagnostic-record'), 'inputs' => []];
            $findings[] = self::finding(
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
            $members[] = ['identity' => PopulationIdentity::occurrence('inert:' . $entry->describe() . ':' . $entry->selector->value, \count($members), 'baseline-diagnostic-record'), 'inputs' => []];
            $findings[] = self::finding(
                'inert',
                $entry->selector->value,
                $entry->describe(),
                $reason,
                $baselinePath,
            );
        }

        return ['findings' => $this->execution->publishable($findings), 'population' => JudgedPopulation::measure($publication, UnusedEntryRule::NAME, $channel, SymbolLevel::Project, UnusedEntryRule::channelDeclarations()[BaselineAuditChannels::UNUSED_ENTRY], $members)];
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

    private static function finding(string $cause, string $selector, string $entry, string $reason, string $baselinePath): Finding
    {
        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: BaselineAuditChannels::UNUSED_ENTRY,
            code: BaselineAuditChannels::UNUSED_ENTRY,
            message: \sprintf('Baseline entry %s [%s] is %s: %s.', $entry, $selector, $cause, $reason),
            severity: Severity::Warning,
            recommendation: \sprintf(
                'Remove baseline entry [%s] with qmx baseline:cleanup %s --remove=%s.%s',
                $selector,
                escapeshellarg($baselinePath),
                $selector,
                $cause === 'stale' ? ' Or run baseline:update after an intentional change to accepted debt.' : '',
            ),
            occurrenceKey: OccurrenceKey::semantic('baseline-unused-entry', ['cause' => $cause, 'selector' => $selector]),
        );
    }
}
