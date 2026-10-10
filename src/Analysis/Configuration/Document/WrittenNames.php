<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * The names one layer writes as keys of named maps, each judged by the map's
 * vocabulary in that layer, or held for phase 3 when the vocabulary is a
 * sibling node only the merge completes.
 */
final class WrittenNames
{
    /** @var list<PendingName> */
    private array $pending = [];

    /** @throws LogicException for a sibling's vocabulary inside a list item, which no single sibling holds */
    public static function vocabulary(NodeSchema $schema, ReadingContext $at): ?NameVocabulary
    {
        $vocabulary = $schema->map->names;
        if ($vocabulary?->isFromSibling() === true && $at->insideList) {
            throw new LogicException(\sprintf('"%s": a name vocabulary drawn from a sibling cannot be judged inside a list item.', implode('.', $at->canonicalPath)));
        }

        return $vocabulary;
    }

    /**
     * A fixed vocabulary recognises the name as a key, a predicate judges it
     * here, and a sibling's vocabulary is known only once the layers merge,
     * so the name waits for phase 3.
     *
     * @throws ConfigurationRefusal
     */
    public function nameOf(string $written, AuthoredNode $child, ReadingContext $at, ?NameVocabulary $vocabulary, ?KeyClaims $fixed): string
    {
        $childAt = $at->child($written, $written, $child);

        if ($fixed !== null || $vocabulary === null) {
            return $fixed?->claim($written, $child) ?? $written;
        }

        if (!$vocabulary->isPredicate()) {
            $this->pending[] = new PendingName($at->canonicalPath, $written, $vocabulary, $childAt->provenance($child));

            return $written;
        }

        $refused = $vocabulary->refuse($written);
        if ($refused !== null) {
            throw $childAt->refusal($refused->summary, $written, $refused->accepted);
        }

        return $written;
    }

    /** @return list<PendingName> */
    public function pending(): array
    {
        return $this->pending;
    }
}
