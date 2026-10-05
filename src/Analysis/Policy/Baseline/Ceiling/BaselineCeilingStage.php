<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStageInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStageResult;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Analysis\Policy\Baseline\RunCoverageGap;

/** Groups findings and assembles the ceiling outcome for one measured run. */
final readonly class BaselineCeilingStage implements FindingFilterStageInterface
{
    /** @param array<string, RunCoverageGap> $ruleGaps Keyed by identity. */
    public function __construct(
        private Baseline $baseline,
        private ChannelDeclarationRegistryInterface $declarations,
        private RunCoverage $coverage,
        private array $ruleGaps,
    ) {}

    public function stage(): FindingFilterStage
    {
        return FindingFilterStage::Baseline;
    }

    public function apply(array $findings): FindingFilterStageResult
    {
        return $this->judgeAll($findings)->result;
    }

    /** @param list<Finding> $findings */
    public function judgeAll(array $findings): CeilingOutcome
    {
        $groups = self::groupByIdentity($findings);
        $judgement = new EntryJudgement($this->baseline, $this->declarations, $this->coverage, $this->ruleGaps);
        $verdicts = $statuses = $reasons = [];
        foreach ($groups as $key => $group) {
            $entry = $this->baseline->findByIdentity($group['identity']);
            $verdict = $entry === null
                ? GroupCeilingVerdict::reported()
                : $judgement->judgePresent($entry, $group['identity'], $group['findings']);
            $verdicts[$key] = $verdict;
            $statuses[$key] = $verdict->status();
            if ($verdict->uncomparedReason !== null) {
                $reasons[$key] = $verdict->uncomparedReason->value;
            }
        }

        $result = self::selectFindings($findings, $verdicts);

        $absent = ['stale' => [], 'unmeasured' => [], 'outside-coverage' => [], 'not-compared' => []];
        foreach ($this->baseline->entries as $entry) {
            $key = $entry->identity->key();
            if (isset($groups[$key])) {
                continue;
            }
            $absence = $judgement->classifyAbsent($entry);
            $statuses[$key] = $absence->status;
            if ($absence->reason !== null) {
                $reasons[$key] = $absence->reason->value;
            }
            $absent[$absence->status][] = $entry;
        }

        return new CeilingOutcome(
            result: $result,
            staleEntries: $absent['stale'],
            inertEntries: [...$this->baseline->inertEntries, ...$this->configurationErrorEntries()],
            unmeasuredEntries: $absent['unmeasured'],
            outsideCoverageEntries: $absent['outside-coverage'],
            notComparedEntries: $absent['not-compared'],
            statuses: $statuses,
            reasons: $reasons,
        );
    }

    /**
     * @param list<Finding> $findings
     * @param array<string, GroupCeilingVerdict> $verdicts
     */
    private static function selectFindings(array $findings, array $verdicts): FindingFilterStageResult
    {
        $kept = $removed = [];
        foreach ($findings as $finding) {
            $verdict = $verdicts[BaselineIdentity::forFinding($finding)->key()];
            if ($verdict->suppresses()) {
                $removed[] = $finding;

                continue;
            }
            $kept[] = match (true) {
                $verdict->breachedLevel !== null => $finding->reportedAsBreach($verdict->breachedLevel),
                $verdict->uncomparedLevel !== null && $verdict->uncomparedReason !== null
                    => $finding->reportedUncompared($verdict->uncomparedLevel, $verdict->uncomparedReason->value),
                default => $finding,
            };
        }

        return new FindingFilterStageResult(FindingFilterStage::Baseline, $kept, $removed);
    }

    /** @return list<string> */
    public function baselineScope(): array
    {
        return $this->baseline->scope;
    }

    /** @return list<InertBaselineEntry> */
    private function configurationErrorEntries(): array
    {
        $inert = [];
        foreach ($this->baseline->entries as $entry) {
            $declaration = $this->declarations->declarationFor($entry->identity->channel);
            if ($declaration?->isConfigurationError() !== true) {
                continue;
            }
            $inert[] = InertBaselineEntry::forIdentity(
                $entry->identity,
                InertEntryReason::ConfigurationErrorChannel,
                \sprintf('the channel "%s" reports a configuration error, which cannot be accepted as debt', $entry->identity->channel->code),
                raw: null,
            );
        }

        return $inert;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, array{identity: BaselineIdentity, findings: non-empty-list<Finding>}>
     */
    private static function groupByIdentity(array $findings): array
    {
        $groups = [];
        foreach ($findings as $finding) {
            $identity = BaselineIdentity::forFinding($finding);
            $groups[$identity->key()] ??= ['identity' => $identity, 'findings' => []];
            $groups[$identity->key()]['findings'][] = $finding;
        }

        return $groups;
    }
}
