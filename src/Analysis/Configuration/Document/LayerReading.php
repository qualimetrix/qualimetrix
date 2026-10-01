<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\BareElementPolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\IntegerJudgement;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedBareName;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedList;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedMap;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedOpaque;

/**
 * Phase 1, one layer: recognises every dictionary key, judges the form of
 * every written value — and passes it to its owner's judgement where the node
 * declares one — expands shorthands, and drops `~` as unwritten. What it
 * returns carries that layer's provenance and is ready to merge.
 *
 * A name whose vocabulary comes from a sibling node is collected for phase 3
 * instead of judged here. A named entry written without a body is kept as a
 * {@see ResolvedBareName}: dropping it would take the name out of every
 * judgement after this one.
 */
final class LayerReading
{
    private readonly WrittenNames $names;

    public function __construct()
    {
        $this->names = new WrittenNames();
    }

    /**
     * @throws ConfigurationRefusal
     */
    public function readRoot(NodeSchema $root, AuthoredLayer $layer, int $layerIndex): ?ResolvedValueInterface
    {
        $at = ReadingContext::of($layer, $layerIndex);

        return $this->readMap($root, $layer->root, $at, KeyClaims::of($root->map->keys->keys(), $at));
    }

    /** @return list<PendingName> */
    public function pendingNames(): array
    {
        return $this->names->pending();
    }

    private function read(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): ?ResolvedValueInterface
    {
        return self::judged($schema, $at, match ($schema->policy) {
            MergePolicy::LastWriterWins => WrittenForm::scalar($schema, $node, $at),
            MergePolicy::DeepMerge => $this->readMap($schema, $node, $at, KeyClaims::of($schema->map->keys->keys(), $at, $schema->map->retiredKeys)),
            MergePolicy::Replace, MergePolicy::Accumulate => $this->readList($schema, $node, $at),
            MergePolicy::ByName => $this->readNamedMap($schema, $node, $at),
            MergePolicy::PerLayer => $node->isUnwritten() ? null : self::opaque($node, $at),
        });
    }

    /** The value as this layer wrote it, once its owner's judgement of it passes. */
    private static function judged(NodeSchema $schema, ReadingContext $at, ?ResolvedValueInterface $value): ?ResolvedValueInterface
    {
        $judge = $schema->layerJudge;
        if ($value !== null && $judge instanceof IntegerJudgement) {
            $integer = $value->plain();
            \assert(\is_int($integer));
            $refusal = $judge->refusal($integer);
            if ($refusal !== null) {
                $value->refuse($refusal);
            }
        } elseif ($value !== null && $judge instanceof Closure) {
            $judge($value, $at->canonicalPath);
        }

        return $value;
    }

    private function readMap(NodeSchema $schema, AuthoredNode $node, ReadingContext $at, KeyClaims $keys): ?ResolvedMap
    {
        if ($schema->map->bareField !== null && $node->shape === AuthoredShape::Scalar && !$node->isUnwritten()) {
            $field = $schema->map->bareField;
            $value = $this->read($schema->map->keys->fields()[$field], $node, $at->atCanonicalPath([...$at->canonicalPath, $field]));

            return $value === null ? null : new ResolvedMap([$field => $value], [$at->provenance($node)]);
        }

        if (!WrittenForm::isMap($schema, $node, $at)) {
            return null;
        }

        [$entries, $shorthandNodes] = $this->readFields($schema, $node, $at, $keys);
        $entries = (new ShorthandExpansion($this->read(...)))->spread($schema, $at, $entries, $shorthandNodes);

        return $entries === [] ? null : new ResolvedMap($entries, [$at->provenance($node)]);
    }

    /**
     * Every key of the map in written order; a shorthand is only collected,
     * to be spread once every full key of the layer is read.
     *
     * @return array{array<string, ResolvedValueInterface>, array<string, array{string, AuthoredNode}>}
     */
    private function readFields(NodeSchema $schema, AuthoredNode $node, ReadingContext $at, KeyClaims $keys): array
    {
        $dictionary = $schema->map->keys;
        $entries = [];
        $shorthandNodes = [];

        foreach ($node->children as $writtenKey => $child) {
            $written = (string) $writtenKey;
            $canonical = $keys->claim($written, $child);

            if ($child->isUnwritten()) {
                continue;
            }

            if ($dictionary->shorthand($canonical) !== null) {
                $shorthandNodes[$canonical] = [$written, $child];
            } else {
                $entries = self::with($entries, $canonical, $this->read($dictionary->fields()[$canonical], $child, $at->child($written, $canonical, $child)));
            }
        }

        return [$entries, $shorthandNodes];
    }

    private function readNamedMap(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): ?ResolvedMap
    {
        if (!WrittenForm::isMap($schema, $node, $at)) {
            return null;
        }

        $entries = NamedEntryReading::entries($schema, $node, $at, $this->names, $this->read(...));

        if ($entries === []) {
            self::judged($schema, $at, new ResolvedMap([], [$at->provenance($node)]));
            return null;
        }

        return new ResolvedMap($entries, [$at->provenance($node)]);
    }

    private function readList(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): ?ResolvedList
    {
        if ($schema->collection?->bareElement === BareElementPolicy::SingleAllowed && $node->shape === AuthoredShape::Scalar && !$node->isUnwritten()) {
            $item = $this->read($schema->collection->element, $node, $at)
                ?? throw $at->refusal(\sprintf('%s writes no list element.', ucfirst($at->where())));

            return new ResolvedList([$item], [$at->provenance($node)]);
        }

        if (!WrittenForm::isList($schema, $node, $at)) {
            return null;
        }

        $items = [];
        foreach ($node->children as $index => $child) {
            $items[] = $this->readItem(($schema->collection ?? throw new LogicException(\sprintf('A %s node has no element schema.', $schema->policy->value)))->element, (int) $index, $child, $at);
        }

        return new ResolvedList($items, [$at->provenance($node)]);
    }

    private function readItem(NodeSchema $element, int $index, AuthoredNode $child, ReadingContext $at): ResolvedValueInterface
    {
        $itemAt = $at->item($index, $child);

        if ($element->policy === MergePolicy::PerLayer) {
            $item = self::opaque($child, $itemAt);
            self::judged($element, $itemAt, $item);

            return $item;
        }

        if ($child->isUnwritten()) {
            throw $itemAt->refusal(\sprintf(
                'Item %d of %s is null (`~`); a list item is a value, not an unwritten key — remove it or write a value.',
                $index,
                $at->where(),
            ));
        }

        // Unwritten nodes are refused above, so null here is an item that
        // is an empty map, or a map of nothing but `~`: dropping it would
        // shorten the list without a trace.
        return $this->read($element, $child, $itemAt) ?? throw $itemAt->refusal(\sprintf(
            'Item %d of %s writes nothing; remove it or give it a value.',
            $index,
            $at->where(),
        ));
    }

    /**
     * @param array<string, ResolvedValueInterface> $entries
     *
     * @return array<string, ResolvedValueInterface>
     */
    private static function with(array $entries, string $key, ?ResolvedValueInterface $value): array
    {
        if ($value !== null) {
            $entries[$key] = $value;
        }

        return $entries;
    }

    private static function opaque(AuthoredNode $node, ReadingContext $at): ResolvedOpaque
    {
        return new ResolvedOpaque([['provenance' => $at->provenance($node), 'value' => $node->plain()]]);
    }
}
