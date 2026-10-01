<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
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
            MergePolicy::DeepMerge => $this->readMap($schema, $node, $at, KeyClaims::of($schema->keys()->keys(), $at, $schema->retiredKeys())),
            MergePolicy::Replace, MergePolicy::Accumulate => $this->readList($schema, $node, $at),
            MergePolicy::ByName => $this->readNamedMap($schema, $node, $at),
            MergePolicy::PerLayer => $node->isUnwritten() ? null : self::opaque($node, $at),
        });
    }

    /** The value as this layer wrote it, once its owner's judgement of it passes. */
    private static function judged(NodeSchema $schema, ReadingContext $at, ?ResolvedValueInterface $value): ?ResolvedValueInterface
    {
        $judge = $schema->layerJudge();
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
        if ($schema->bareField() !== null && $node->shape === AuthoredShape::Scalar && !$node->isUnwritten()) {
            $field = $schema->bareField();
            $value = $this->read($schema->fields()[$field], $node, $at->atCanonicalPath([...$at->canonicalPath, $field]));

            return $value === null ? null : new ResolvedMap([$field => $value], [$at->provenance($node)]);
        }

        if (!WrittenForm::isMap($schema, $node, $at)) {
            return null;
        }

        [$entries, $shorthandNodes] = $this->readFields($schema, $node, $at, $keys);
        $entries = $this->spreadShorthands($schema, $at, $entries, $shorthandNodes);

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
    private function spreadShorthands(NodeSchema $schema, ReadingContext $at, array $entries, array $shorthandNodes): array
    {
        foreach ($shorthandNodes as $key => [$written, $child]) {
            $writtenAt = $at->child($written, $key, $child);
            $shorthand = $schema->keys()->shorthand($key)
                ?? throw new LogicException(\sprintf('No shorthand is declared for "%s".', $key));
            $targets = $shorthand->targets;
            $description = \sprintf('"%s" is shorthand for "%s"', $key, implode('" and "', $targets));
            foreach ($this->expandShorthand($schema, $key, $child, $writtenAt, $at->canonicalPath) as [$path, $value]) {
                $entries = $this->placeExpanded($entries, $path, $value, $at, $writtenAt, $description);
            }
        }

        return $entries;
    }

    /**
     * @param list<string> $mapPath
     *
     * @return list<array{list<string>, ResolvedValueInterface}>
     */
    private function expandShorthand(NodeSchema $schema, string $key, AuthoredNode $node, ReadingContext $writtenAt, array $mapPath): array
    {
        $expanded = [];
        $shorthand = $schema->keys()->shorthand($key)
            ?? throw new LogicException(\sprintf('No shorthand is declared for "%s".', $key));
        foreach ($shorthand->targets as $target) {
            $segments = explode('.', $target);
            $targetSchema = $schema;
            $prefix = [];
            foreach ($segments as $index => $segment) {
                $last = $index === \count($segments) - 1;
                if ($last && $targetSchema->keys()->shorthand($segment) !== null) {
                    foreach ($this->expandShorthand($targetSchema, $segment, $node, $writtenAt, [...$mapPath, ...$prefix]) as [$path, $value]) {
                        $expanded[] = [[...$prefix, ...$path], $value];
                    }
                    continue 2;
                }

                $field = $targetSchema->fields()[$segment];
                $prefix[] = $segment;
                if ($last) {
                    $value = $this->read($field, $node, $writtenAt->atCanonicalPath([...$mapPath, ...$prefix]));
                    if ($value !== null) {
                        foreach (self::leaves($prefix, $value) as $leaf) {
                            $expanded[] = $leaf;
                        }
                    }
                } else {
                    $targetSchema = $field;
                }
            }
        }

        return $expanded;
    }

    /**
     * @param list<string> $path
     *
     * @return list<array{list<string>, ResolvedValueInterface}>
     */
    private static function leaves(array $path, ResolvedValueInterface $value): array
    {
        if (!$value instanceof ResolvedMap) {
            return [[$path, $value]];
        }

        $leaves = [];
        foreach ($value->entries() as $key => $child) {
            foreach (self::leaves([...$path, $key], $child) as $leaf) {
                $leaves[] = $leaf;
            }
        }

        return $leaves;
    }

    /**
     * @param array<string, ResolvedValueInterface> $entries
     * @param list<string> $path
     *
     * @return array<string, ResolvedValueInterface>
     */
    private function placeExpanded(array $entries, array $path, ResolvedValueInterface $value, ReadingContext $mapAt, ReadingContext $writtenAt, string $description): array
    {
        if ($path === []) {
            throw new LogicException('A shorthand must resolve to a leaf path.');
        }
        $key = array_shift($path);
        $existing = $entries[$key] ?? null;
        if ($path === []) {
            if ($existing !== null) {
                $writer = $existing->contributors()[0];
                $base = $mapAt->authoredPath;
                $first = $writer->path === null ? ($writer->origin->locator() ?? $key) : Provenance::display(\array_slice($writer->path, \count($base)));
                $second = Provenance::display(\array_slice($writtenAt->authoredPath, \count($base)));
                throw $writtenAt->refusal(\sprintf(
                    '%s writes both "%s" and "%s" in one layer; %s — write either the shorthand or the full keys in one layer.',
                    ucfirst($mapAt->where()),
                    $second,
                    $first,
                    $description,
                ));
            }
            $entries[$key] = $value;

            return $entries;
        }

        if ($existing !== null && !$existing instanceof ResolvedMap) {
            throw $writtenAt->refusal(\sprintf('%s writes a leaf and a nested value at the same path.', ucfirst($mapAt->where())));
        }
        $children = $this->placeExpanded($existing?->entries() ?? [], $path, $value, $mapAt, $writtenAt, $description);
        $entries[$key] = new ResolvedMap($children, $existing?->contributors() ?? [$value->contributors()[0]]);

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

            $entrySchema = $schema->entryForName($name);
            $bareWithoutSchema = $child->isUnwritten()
                || (($child->shape === AuthoredShape::Mapping || $child->shape === AuthoredShape::EmptyCollection) && $child->children === []);
            if ($entrySchema === null && !$bareWithoutSchema) {
                throw $childAt->refusal(\sprintf('No value schema is declared for named entry "%s".', $written));
            }
            $value = $child->isUnwritten() || $entrySchema === null
                ? null
                : $this->read($entrySchema, $child, $at->child($written, $name, $child));
            $entries[$name] = $value ?? new ResolvedBareName([$childAt->provenance($child)]);
        }

        if ($entries === []) {
            self::judged($schema, $at, new ResolvedMap([], [$at->provenance($node)]));
            return null;
        }

        return new ResolvedMap($entries, [$at->provenance($node)]);
    }

    private function readList(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): ?ResolvedList
    {
        if ($schema->admitsBareElement() && $node->shape === AuthoredShape::Scalar && !$node->isUnwritten()) {
            $item = $this->read($schema->element(), $node, $at)
                ?? throw $at->refusal(\sprintf('%s writes no list element.', ucfirst($at->where())));

            return new ResolvedList([$item], [$at->provenance($node)]);
        }

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
