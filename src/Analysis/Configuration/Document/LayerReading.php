<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedBareName;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedList;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMap;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedOpaque;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

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

        return $this->readMap($root, $layer->root, $at, KeyClaims::of($root->keys()->keys(), $at));
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
            MergePolicy::DeepMerge => $this->readMap($schema, $node, $at, KeyClaims::of($schema->keys()->keys(), $at)),
            MergePolicy::Replace, MergePolicy::Accumulate => $this->readList($schema, $node, $at),
            MergePolicy::ByName => $this->readNamedMap($schema, $node, $at),
            MergePolicy::PerLayer => $node->isUnwritten() ? null : self::opaque($node, $at),
        });
    }

    /** The value as this layer wrote it, once its owner's judgement of it passes. */
    private static function judged(NodeSchema $schema, ReadingContext $at, ?ResolvedValueInterface $value): ?ResolvedValueInterface
    {
        $judge = $schema->layerJudge();
        if ($value !== null && $judge !== null) {
            $judge($value, $at->canonicalPath);
        }

        return $value;
    }

    private function readMap(NodeSchema $schema, AuthoredNode $node, ReadingContext $at, KeyClaims $keys): ?ResolvedMap
    {
        if (!WrittenForm::isMap($schema, $node, $at)) {
            return null;
        }

        [$entries, $shorthandNodes] = $this->readFields($schema, $node, $at, $keys);
        $entries = $this->spreadShorthands($schema, $at, $keys, $entries, $shorthandNodes);

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
        $dictionary = $schema->keys();
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

    /**
     * @param array<string, ResolvedValueInterface> $entries
     * @param array<string, array{string, AuthoredNode}> $shorthandNodes canonical shorthand => [written key, node]
     *
     * @return array<string, ResolvedValueInterface>
     */
    private function spreadShorthands(NodeSchema $schema, ReadingContext $at, KeyClaims $keys, array $entries, array $shorthandNodes): array
    {
        $dictionary = $schema->keys();

        foreach ($shorthandNodes as $key => [$written, $child]) {
            $targets = $dictionary->shorthand($key)->targets ?? [];

            foreach ($targets as $target) {
                if (\array_key_exists($target, $entries)) {
                    throw $at->child($written, $key, $child)->refusal(\sprintf(
                        '%s writes both "%s" and "%s"; "%s" is shorthand for %s — write either the shorthand or the full keys in one layer.',
                        ucfirst($at->where()),
                        $written,
                        $keys->spellingOf($target),
                        $key,
                        '"' . implode('" and "', $targets) . '"',
                    ));
                }
            }

            foreach ($targets as $target) {
                $entries = self::with($entries, $target, $this->read($dictionary->fields()[$target], $child, $at->child($written, $target, $child)));
            }
        }

        return $entries;
    }

    private function readNamedMap(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): ?ResolvedMap
    {
        if (!WrittenForm::isMap($schema, $node, $at)) {
            return null;
        }

        $vocabulary = WrittenNames::vocabulary($schema, $at);
        $fixed = $vocabulary?->isFixed() === true ? KeyClaims::of($vocabulary->fixedNames(), $at) : null;
        $entries = [];

        foreach ($node->children as $writtenKey => $child) {
            $written = (string) $writtenKey;
            $childAt = $at->child($written, $written, $child);
            $name = $this->names->nameOf($written, $child, $at, $vocabulary, $fixed);

            $value = $child->isUnwritten() ? null : $this->read($schema->element(), $child, $at->child($written, $name, $child));
            $entries[$name] = $value ?? new ResolvedBareName([$childAt->provenance($child)]);
        }

        return $entries === [] ? null : new ResolvedMap($entries, [$at->provenance($node)]);
    }

    private function readList(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): ?ResolvedList
    {
        if (!WrittenForm::isList($schema, $node, $at)) {
            return null;
        }

        $items = [];
        foreach ($node->children as $index => $child) {
            $items[] = $this->readItem($schema->element(), (int) $index, $child, $at);
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
