<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

/**
 * Phase 3: judges the names whose vocabulary is another node of the document
 * — `allow` keys against the layer names `layers` declares — against that
 * node as merged across every layer, so a name a preset declares is known to
 * the file that uses it. A name is compared exactly, unless the vocabulary
 * says how a name refers to the declared ones: it is the author's
 * identifier, not a schema key with spellings.
 */
final class NameRecognition
{
    /**
     * @param list<PendingName> $pending
     *
     * @throws ConfigurationRefusal on the first unknown name, naming every layer that wrote it
     */
    public static function judge(ResolvedDocument $document, array $pending): void
    {
        $groups = [];
        foreach ($pending as $name) {
            $groups[serialize([$name->mapPath, $name->name])][] = $name;
        }

        foreach ($groups as $group) {
            $first = $group[0];
            $parentPath = \array_slice($first->mapPath, 0, -1);
            $sibling = $first->vocabulary->siblingKey === null
                ? null
                : $document->get(...[...$parentPath, $first->vocabulary->siblingKey]);
            $known = $first->vocabulary->namesFrom($sibling?->plain());

            if ($first->vocabulary->admits($first->name, $known)) {
                continue;
            }

            throw self::refusal($group, $known);
        }
    }

    /**
     * @param non-empty-list<PendingName> $group one name, every layer that wrote it
     * @param list<string> $known
     */
    private static function refusal(array $group, array $known): ConfigurationRefusal
    {
        $writers = array_map(static fn(PendingName $name): Provenance => $name->provenance, $group);
        $last = $writers[\count($writers) - 1];
        $first = $group[0];
        $suggestion = KeyRecognition::closest($first->name, $known);

        $summary = \sprintf(
            'Unknown name "%s" under "%s", written in %s: the names come from "%s", which declares %s%s.',
            $first->name,
            implode('.', $first->mapPath),
            implode(', ', array_unique(array_map(static fn(Provenance $writer): string => $writer->origin->describe(), $writers))),
            $first->vocabulary->siblingKey,
            $known === [] ? 'none' : '"' . implode('", "', $known) . '"',
            $suggestion === null ? '' : \sprintf(' (did you mean "%s"?)', $suggestion),
        );

        $position = $last->path === null ? null : RefusedPosition::closed($last->path, $first->name, $known);

        return Provenance::refusalOf($writers, $summary, $position);
    }
}
