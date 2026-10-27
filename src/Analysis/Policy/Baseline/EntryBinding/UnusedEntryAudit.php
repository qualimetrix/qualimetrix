<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\EntryBinding;

use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Finding;

use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\RulePublicationInterface;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineAuditChannels;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome;
use Qualimetrix\Analysis\Policy\Baseline\Contract\Entry\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\Contract\Entry\InertEntryReason;
use Qualimetrix\Core\Symbol\SymbolLevel;

final readonly class UnusedEntryAudit
{
    public function __construct(private RulePublicationInterface $publication) {}

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
            $retiredSelectors = self::retiredSelectors($outcome->inertEntries);
            foreach (self::reportedInert($outcome->inertEntries) as [$entry, $reason]) {
                yield ['identity' => PopulationIdentity::occurrence('inert:' . $entry->describe() . ':' . $entry->selector->value, $ordinal++, 'baseline-diagnostic-record'), 'inputs' => []];
                $findings[] = UnusedEntryFinding::of(
                    'inert',
                    $entry->selector->value,
                    $entry->describe(),
                    $reason,
                    $baselinePath,
                    $entry->reason === InertEntryReason::RetiredClosureIdentity ? $retiredSelectors : [],
                );
            }
        })();
        $population = $publication->measure(UnusedEntryRule::NAME, $channel, SymbolLevel::Project, UnusedEntryRule::channelDeclarations()[BaselineAuditChannels::UNUSED_ENTRY], $members);

        return ['findings' => $this->publication->publishable($findings), 'population' => $population];
    }

    /**
     * Each inert entry with its reported reason, contenders of one identity
     * and selector collapsed into the first of them.
     *
     * @param list<InertBaselineEntry> $entries
     *
     * @return list<array{InertBaselineEntry, string}>
     */
    private static function reportedInert(array $entries): array
    {
        $contenders = array_count_values(array_filter(array_map(self::contenderKey(...), $entries), \is_string(...)));
        $reported = [];
        $seen = [];
        foreach ($entries as $entry) {
            $contender = self::contenderKey($entry);
            if ($contender !== null && isset($seen[$contender])) {
                continue;
            }
            $seen[$contender ?? ''] = true;
            $reason = $entry->reason->description() . ': ' . $entry->detail;
            $count = $contender === null ? 1 : $contenders[$contender];
            $reported[] = [$entry, $count > 1 ? $reason . '; ' . $count . ' contenders share this identity' : $reason];
        }

        return $reported;
    }

    private static function contenderKey(InertBaselineEntry $entry): ?string
    {
        return self::contendsForIdentity($entry) && $entry->identity !== null
            ? $entry->identity->key() . "\x1F" . $entry->selector->value
            : null;
    }

    /**
     * A retired closure entry keeps the identity it claimed, so two of them
     * under one key are contenders exactly as duplicates are: one warning,
     * one selector that removes both.
     */
    private static function contendsForIdentity(InertBaselineEntry $entry): bool
    {
        return $entry->reason === InertEntryReason::DuplicateIdentity
            || $entry->reason === InertEntryReason::RetiredClosureIdentity;
    }

    /**
     * Every retired selector once, in entry order: a write is refused while any
     * of them remains, so advice naming one alone would be refused too.
     *
     * @param list<InertBaselineEntry> $entries
     *
     * @return list<string>
     */
    private static function retiredSelectors(array $entries): array
    {
        $selectors = [];
        foreach ($entries as $entry) {
            if ($entry->reason === InertEntryReason::RetiredClosureIdentity) {
                $selectors[] = $entry->selector->value;
            }
        }

        return array_values(array_unique($selectors));
    }

}
