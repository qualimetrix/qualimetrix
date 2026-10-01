<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Selection;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\ModeGatedOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\SelectionFilter;

/** Retains complete deciding writers and their deterministic display. */
final class SelectionCauses
{
    /**
     * @param list<array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance, specificity: int}> $applicable
     *
     * @return list<array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance, specificity: int}>
     */
    public static function decisive(array $applicable): array
    {
        $winner = self::winner($applicable);
        if ($winner === null) {
            return [];
        }
        $decisive = array_values(array_filter($applicable, static fn(array $statement): bool =>
            $statement['provenance']->layerIndex === $winner['provenance']->layerIndex
            && $statement['specificity'] === $winner['specificity']));
        usort($decisive, static fn(array $left, array $right): int => strcmp($left['text'], $right['text']));
        return $decisive;
    }

    /** @param list<array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance, specificity: int}> $applicable
     * @return ?array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance, specificity: int}
     */
    private static function winner(array $applicable): ?array
    {
        usort($applicable, static fn(array $left, array $right): int =>
            [$right['provenance']->layerIndex, $right['specificity']] <=> [$left['provenance']->layerIndex, $left['specificity']]);
        return $applicable[0] ?? null;
    }

    /** @param list<EnablementDecision> $cells */
    public static function forCells(array $cells, ResolvedRuleOptions $options): string
    {
        $reasons = [];
        foreach ($cells as $cell) {
            if (!$cell->on && $cell->decisiveStatements !== []) {
                foreach ($cell->decisiveStatements as $statement) {
                    $reasons[] = [
                        'rank' => $statement['provenance']->layerIndex,
                        'text' => \sprintf('%s (%s)', $statement['text'], self::layer($statement['provenance'])),
                    ];
                }
                continue;
            }
            $reasons[] = [
                'rank' => $cell->on ? $cell->activity->rank() : $cell->rank(),
                'text' => self::forCell($cell, $options),
            ];
        }
        usort($reasons, static fn(array $left, array $right): int => [$left['rank'], $left['text']] <=> [$right['rank'], $right['text']]);
        return implode(', ', array_values(array_unique(array_column($reasons, 'text'))));
    }

    private static function forCell(EnablementDecision $cell, ResolvedRuleOptions $options): string
    {
        if (!$cell->on) {
            return $cell->statement === null ? \sprintf('"%s" is disabled by default', $cell->producer)
                : \sprintf('%s (%s)', $cell->statement, self::layer($cell->provenance));
        }
        if (!$cell->activity->active) {
            return self::inactiveCell($cell, $options);
        }
        return 'filtered';
    }

    private static function inactiveCell(EnablementDecision $cell, ResolvedRuleOptions $options): string
    {
        $mode = $options->for($cell->producer) instanceof ModeGatedOptionsInterface;
        if ($cell->activity->decidedBy !== null) {
            return \sprintf('%s: %s (%s)', $cell->activity->decidedBy->displayPath(), $mode ? 'ignore' : 'false', self::layer($cell->activity->decidedBy));
        }
        if ($mode) {
            return \sprintf('mode of "%s" is ignore by default', $cell->producer);
        }
        return \sprintf('level %s of "%s" is inactive by default', $cell->level === null ? 'project' : $cell->level->value, $cell->producer);
    }

    public static function mutedMode(EnablementDecision $cell): string
    {
        $write = $cell->activity->decidedBy;
        if ($write === null) {
            return \sprintf('mode of "%s" is ignore by default', $cell->producer);
        }
        return \sprintf('%s: ignore (%s)', $write->path === null
            ? ($write->origin->locator() ?? '--rule-opt') : $write->displayPath(), self::layer($write));
    }

    public static function layer(?Provenance $provenance): string
    {
        if ($provenance === null) {
            return 'default';
        }
        return $provenance->origin->source() === ConfigurationSource::CommandLine
            ? 'the command line'
            : $provenance->origin->describe();
    }

    /** @return array{string, ?Provenance} */
    public static function authoredCell(EnablementDecision $decision): array
    {
        if (!$decision->on) {
            $statements = $decision->decisiveStatements;
            return [$statements === [] ? ($decision->statement ?? 'default')
                : implode('; ', array_column($statements, 'text')), $decision->provenance];
        }
        return [$decision->activity->written ?? 'inactive by default', $decision->activity->decidedBy];
    }

    /** @return array{string, ?Provenance} */
    public static function filter(?SelectionFilter $filter): array
    {
        $filter = $filter ?? throw new LogicException('A filtered cell requires a written rule filter.');
        $selectors = '[' . implode(', ', $filter->selectors) . ']';
        $writer = $filter->provenance;
        $statement = $writer->path === null
            ? ($writer->origin->locator() ?? '--only-rule') . '=' . $selectors
            : $writer->displayPath() . ': ' . $selectors;
        return [$statement, $writer];
    }
}
