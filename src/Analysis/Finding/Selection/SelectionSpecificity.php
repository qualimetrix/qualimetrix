<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Selection;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch;
use Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress;
use Qualimetrix\Analysis\Finding\Contract\SelectionFilter;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Decides authored membership and rank for every declared producer/channel cell. */
final class SelectionSpecificity
{
    public static function producerEnable(string $written, string $producer): ?int
    {
        return $written === $producer ? 3 : null;
    }

    public static function selector(string $raw, string $producer, FindingChannel $channel, ?SymbolLevel $level): ?int
    {
        $selector = ChannelLevelSelector::tryParse($raw);
        if ($selector === null) {
            return null;
        }
        if (!$selector->matches($channel->code, $level)) {
            return self::producerMatch($selector, $producer);
        }
        if ($selector->channel()->selectsDescendantsOnly()) {
            return $selector->level() === null ? 1 : 2;
        }
        if ($selector->level() !== null) {
            return 5;
        }
        return $channel->code === $producer ? 3 : 4;
    }

    private static function producerMatch(ChannelLevelSelector $selector, string $producer): ?int
    {
        if ($selector->level() !== null || !$selector->channel()->matches($producer)) {
            return null;
        }
        return $selector->channel()->selectsDescendantsOnly() ? 1 : 3;
    }

    public static function admission(?SelectionFilter $filter, SelectionCellAddress $address): CellAdmission
    {
        if ($filter === null) {
            return CellAdmission::Direct;
        }
        foreach ($filter->selectors as $selector) {
            if (self::selector($selector, $address->producer, $address->channel, $address->level) !== null) {
                return CellAdmission::Direct;
            }
        }
        return CellAdmission::Filtered;
    }

    /** @return list<SelectionCellAddress> */
    public static function addresses(ChannelUniverseInterface $channels): array
    {
        $addresses = [];
        foreach ($channels->ruleNames() as $producer) {
            array_push($addresses, ...self::producerAddresses($producer, $channels));
        }
        return $addresses;
    }

    /** @return list<SelectionCellAddress> */
    private static function producerAddresses(string $producer, ChannelUniverseInterface $channels): array
    {
        $produced = $channels->channelsProducedBy($producer);
        if ($produced === []) {
            $produced = [new FindingChannel($producer)];
        }
        $addresses = [];
        foreach ($produced as $channel) {
            array_push($addresses, ...self::channelAddresses($producer, $channel, $channels));
        }
        return $addresses;
    }

    /** @return list<SelectionCellAddress> */
    private static function channelAddresses(string $producer, FindingChannel $channel, ChannelUniverseInterface $channels): array
    {
        $levels = $channels->levelsOf($channel->code);
        $role = $channels->declarationFor($channel)->selectionRole ?? ChannelSelectionRole::Selectable;
        $addresses = [];
        foreach ($levels === [] ? [null] : $levels as $level) {
            $addresses[] = new SelectionCellAddress($producer, $channel, $level, $role);
        }
        return $addresses;
    }

    /**
     * @param list<array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance}> $statements
     *
     * @return list<array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance, specificity: int}>
     */
    public static function applicableStatements(SelectionCellAddress $address, array $statements): array
    {
        $applicable = [];
        foreach ($statements as $statement) {
            $specificity = $statement['exactEnable']
                ? self::producerEnable($statement['selector'], $address->producer)
                : self::selector($statement['selector'], $address->producer, $address->channel, $address->level);
            if ($specificity !== null) {
                $applicable[] = [...$statement, 'specificity' => $specificity];
            }
        }
        return $applicable;
    }

    /** @param list<array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance, specificity: int}> $applicable */
    public static function decision(SelectionCellAddress $address, array $applicable, ?SelectionFilter $filter): EnablementDecision
    {
        $decisive = SelectionCauses::decisive($applicable);
        return new EnablementDecision($address, new AuthoredCellDecision(
            ($decisive[0]['enabled'] ?? true) ? CellSwitch::On : CellSwitch::Off,
            self::admission($filter, $address),
            array_map(static fn(array $statement): array => [
                'text' => $statement['text'], 'provenance' => $statement['provenance'],
            ], $decisive),
        ));
    }
}
