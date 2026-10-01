<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Selection;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Analysis\Finding\Contract\Rule\ModeGatedOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\SelectionFilter;

/** Judges contradictory and non-reporting authored selection requests. */
final class SelectionRefusals
{
    public static function conclude(RuleEnablement $final, ResolvedRuleOptions $options): void
    {
        self::refuseEmptyFilter($final, $options);
        self::refuseDeadSelectors($final, $options);
        self::refuseFilteredEnables($final);
        self::refuseMutedEnables($final, $options);
    }

    /** @param list<array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance, specificity: int}> $applicable */
    public static function contradictions(array $applicable, string $producer): void
    {
        $byLayer = [];
        foreach ($applicable as $statement) {
            $byLayer[$statement['provenance']->layerIndex][] = $statement;
        }
        ksort($byLayer);
        foreach ($byLayer as $statements) {
            $highest = max(array_column($statements, 'specificity'));
            $strongest = array_values(array_filter($statements, static fn(array $statement): bool => $statement['specificity'] === $highest));
            $enabled = array_values(array_filter($strongest, static fn(array $statement): bool => $statement['enabled']));
            $disabled = array_values(array_filter($strongest, static fn(array $statement): bool => !$statement['enabled']));
            if ($enabled === [] || $disabled === []) {
                continue;
            }
            $first = $strongest[0]['provenance'];
            $enabledTexts = array_column($enabled, 'text');
            $disabledTexts = array_column($disabled, 'text');
            sort($enabledTexts);
            sort($disabledTexts);
            $texts = [...$enabledTexts, ...$disabledTexts];
            $writers = [$first];
            foreach (\array_slice($strongest, 1) as $statement) {
                $writers[] = $statement['provenance'];
            }
            throw Provenance::refusalOf(
                $writers,
                \sprintf('Layer %s both enables and disables "%s": %s.', SelectionCauses::layer($first), $producer, implode('; ', $texts)),
            );
        }
    }

    private static function refuseEmptyFilter(RuleEnablement $final, ResolvedRuleOptions $options): void
    {
        $filter = $final->filter();
        if ($filter === null) {
            return;
        }
        foreach ($final->decisions() as $cell) {
            if ($cell->live() && $cell->direct) {
                return;
            }
        }
        $reasons = [];
        foreach ($filter->selectors as $selector) {
            $covered = self::coveredCells($final, $selector);
            $reasons[] = \sprintf('"%s": %s', $selector, SelectionCauses::forCells($covered, $options));
        }
        throw Provenance::refusalOf(
            [$filter->provenance],
            'Rule selection is empty: ' . implode('; ', $reasons) . '; only_rules / --only-rule narrows and does not enable.',
        );
    }

    private static function refuseDeadSelectors(RuleEnablement $final, ResolvedRuleOptions $options): void
    {
        $filter = $final->filter();
        if ($filter === null) {
            return;
        }
        foreach ($filter->selectors as $selector) {
            $parsed = ChannelLevelSelector::tryParse($selector);
            if ($parsed === null || $parsed->channel()->selectsDescendantsOnly()) {
                continue;
            }
            $covered = self::coveredCells($final, $selector);
            if (self::canReportOrWasLaterDisabled($covered, $filter)) {
                continue;
            }
            throw Provenance::refusalOf([$filter->provenance], \sprintf(
                'Filter selector "%s" selects nothing that can report: %s.',
                $selector,
                SelectionCauses::forCells($covered, $options),
            ));
        }
    }

    /** @return list<EnablementDecision> */
    private static function coveredCells(RuleEnablement $final, string $selector): array
    {
        return array_values(array_filter(
            $final->decisions(),
            static fn(EnablementDecision $cell): bool => SelectionSpecificity::selector($selector, $cell->producer, $cell->channel, $cell->level) !== null,
        ));
    }

    /** @param list<EnablementDecision> $covered */
    private static function canReportOrWasLaterDisabled(array $covered, SelectionFilter $filter): bool
    {
        if ($covered === [] || array_any($covered, static fn(EnablementDecision $cell): bool => $cell->live())) {
            return true;
        }
        return array_any($covered, static fn(EnablementDecision $cell): bool =>
            ($cell->on ? $cell->activity->rank() : $cell->rank()) > $filter->rank());
    }

    private static function refuseFilteredEnables(RuleEnablement $final): void
    {
        $filter = $final->filter();
        if ($filter === null) {
            return;
        }
        $byProducer = [];
        foreach ($final->decisions() as $cell) {
            $byProducer[$cell->producer][] = $cell;
        }
        foreach ($byProducer as $producer => $cells) {
            if (array_any($cells, static fn(EnablementDecision $cell): bool => $cell->direct || $cell->role !== ChannelSelectionRole::Selectable)) {
                continue;
            }
            self::refuseAuthoredFilteredCells($producer, $cells, $filter);
        }
    }

    /** @param list<EnablementDecision> $cells */
    private static function refuseAuthoredFilteredCells(string $producer, array $cells, SelectionFilter $filter): void
    {
        foreach ($cells as $cell) {
            if ($cell->statement === null || !$cell->on || $cell->rank() < $filter->rank()) {
                continue;
            }
            $writers = [$filter->provenance, $cell->provenance ?? throw new LogicException('An authored enable must have provenance.')];
            throw Provenance::refusalOf($writers, \sprintf(
                '"%s" is enabled by %s (%s) but excluded by the rule filter of %s.',
                $producer,
                $cell->statement,
                SelectionCauses::layer($cell->provenance),
                SelectionCauses::layer($filter->provenance),
            ));
        }
    }

    private static function refuseMutedEnables(RuleEnablement $final, ResolvedRuleOptions $options): void
    {
        foreach ($final->decisions() as $cell) {
            if ($cell->statement === null || !$cell->on || $cell->activity->active) {
                continue;
            }
            $option = $options->for($cell->producer);
            if (!$option instanceof ModeGatedOptionsInterface || !$option->isMuted()) {
                continue;
            }
            $enable = $cell->provenance ?? throw new LogicException('An authored enable must have provenance.');
            $write = $cell->activity->decidedBy;
            $mode = SelectionCauses::mutedMode($cell);
            throw Provenance::refusalOf($write === null ? [$enable] : [$enable, $write], \sprintf(
                '"%s" is enabled by %s (%s) but its mode is ignore: %s.',
                $cell->producer,
                $cell->statement,
                SelectionCauses::layer($enable),
                $mode,
            ));
        }
    }
}
