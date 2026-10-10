<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusal;

/** Keeps authored winning halves and default halves distinct in a band refusal. */
final class ThresholdBandRefusal
{
    public static function refuse(FindingConfiguration $configuration, string $producer, RuleOptionRefusal $refusal): never
    {
        $writers = [];
        $descriptions = [];
        foreach ($refusal->bandValues as $role => $half) {
            if ($half['path'] === null) {
                $descriptions[] = \sprintf('%s: default %s', $role, $half['value']);
                continue;
            }
            $winner = self::winner($configuration, $producer, $half['path'], $refusal);
            $writers[] = $winner;
            $descriptions[] = \sprintf('%s: %s from %s', $role, $half['value'], self::location($winner));
        }
        if ($writers === []) {
            throw new LogicException('An invalid threshold band must have an authored half.', previous: $refusal);
        }
        $details = implode('; ', $descriptions);
        throw Provenance::refusalOf($writers, $refusal->getMessage() . ' ' . ucfirst($details) . '.');
    }

    /** @param list<string> $path */
    private static function winner(FindingConfiguration $configuration, string $producer, array $path, RuleOptionRefusal $refusal): Provenance
    {
        $node = $configuration->document->get('rules', $producer, ...$path);
        if (!$node instanceof ResolvedWriteHistoryInterface) {
            throw new LogicException('A refused threshold must expose its authored writes.', previous: $refusal);
        }
        $contributors = $node->contributors();
        $winner = $contributors[\count($contributors) - 1];
        foreach ($node->writes() as $write) {
            if ($write['provenance']->layerIndex === $winner->layerIndex) {
                return $write['provenance'];
            }
        }
        throw new LogicException('The winning threshold must occur in its authored history.', previous: $refusal);
    }

    private static function location(Provenance $winner): string
    {
        $where = $winner->path === null
            ? $winner->origin->describe()
            : \sprintf('"%s" in %s', $winner->displayPath(), $winner->origin->describe());
        return $winner->line === null ? $where : $where . \sprintf(' at line %d', $winner->line);
    }
}
