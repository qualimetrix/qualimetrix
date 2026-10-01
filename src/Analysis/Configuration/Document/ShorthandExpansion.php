<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedMap;

/** Spreads one layer's shorthand values into declared canonical leaf paths. */
final readonly class ShorthandExpansion
{
    /** @param Closure(NodeSchema, AuthoredNode, ReadingContext): ?ResolvedValueInterface $read */
    public function __construct(private Closure $read) {}

    /**
     * @param array<string, ResolvedValueInterface> $entries
     * @param array<string, array{string, AuthoredNode}> $shorthandNodes
     *
     * @return array<string, ResolvedValueInterface>
     */
    public function spread(NodeSchema $schema, ReadingContext $at, array $entries, array $shorthandNodes): array
    {
        foreach ($shorthandNodes as $key => [$written, $child]) {
            $writtenAt = $at->child($written, $key, $child);
            $shorthand = $schema->map->keys->shorthand($key)
                ?? throw new LogicException(\sprintf('No shorthand is declared for "%s".', $key));
            $description = \sprintf('"%s" is shorthand for "%s"', $key, implode('" and "', $shorthand->targets));
            foreach ($this->expand($schema, $key, $child, $writtenAt, $at->canonicalPath) as $target) {
                $entries = $this->place($entries, $target->path, $target->value, $at, $target->writtenAt, $description);
            }
        }
        return $entries;
    }

    /** @param list<string> $mapPath
     * @return list<ShorthandTarget>
     */
    private function expand(NodeSchema $schema, string $key, AuthoredNode $node, ReadingContext $writtenAt, array $mapPath): array
    {
        $expanded = [];
        $shorthand = $schema->map->keys->shorthand($key)
            ?? throw new LogicException(\sprintf('No shorthand is declared for "%s".', $key));
        foreach ($shorthand->targets as $target) {
            foreach ($this->expandTarget($schema, $target, $node, $writtenAt, $mapPath) as $leaf) {
                $expanded[] = $leaf;
            }
        }
        return $expanded;
    }

    /** @param list<string> $mapPath
     * @return list<ShorthandTarget>
     */
    private function expandTarget(NodeSchema $schema, string $target, AuthoredNode $node, ReadingContext $writtenAt, array $mapPath): array
    {
        $segments = explode('.', $target);
        $targetSchema = $schema;
        $prefix = [];
        foreach ($segments as $index => $segment) {
            $last = $index === \count($segments) - 1;
            if ($last && $targetSchema->map->keys->shorthand($segment) !== null) {
                $nested = $this->expand($targetSchema, $segment, $node, $writtenAt, [...$mapPath, ...$prefix]);
                return array_map(static fn(ShorthandTarget $leaf): ShorthandTarget => new ShorthandTarget(
                    [...$prefix, ...$leaf->path],
                    $leaf->schema,
                    $leaf->value,
                    $leaf->writtenAt,
                ), $nested);
            }
            $field = $targetSchema->map->keys->fields()[$segment];
            $prefix[] = $segment;
            if ($last) {
                $value = ($this->read)($field, $node, $writtenAt->atCanonicalPath([...$mapPath, ...$prefix]));
                return $value === null ? [] : self::leaves($field, $prefix, $value, $writtenAt);
            }
            $targetSchema = $field;
        }
        return [];
    }

    /** @param non-empty-list<string> $path
     * @return list<ShorthandTarget>
     */
    private static function leaves(NodeSchema $schema, array $path, ResolvedValueInterface $value, ReadingContext $writtenAt): array
    {
        if (!$value instanceof ResolvedMap) {
            return [new ShorthandTarget($path, $schema, $value, $writtenAt)];
        }
        $leaves = [];
        foreach ($value->entries() as $key => $child) {
            $childSchema = $schema->policy === MergePolicy::ByName
                ? ($schema->map->entryForName($key) ?? NodeSchema::opaque())
                : ($schema->map->keys->fields()[$key] ?? NodeSchema::opaque());
            foreach (self::leaves($childSchema, [...$path, $key], $child, $writtenAt) as $leaf) {
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
    private function place(array $entries, array $path, ResolvedValueInterface $value, ReadingContext $mapAt, ReadingContext $writtenAt, string $description): array
    {
        if ($path === []) {
            throw new LogicException('A shorthand must resolve to a leaf path.');
        }
        $key = array_shift($path);
        $existing = $entries[$key] ?? null;
        if ($path === []) {
            if ($existing !== null) {
                self::refuseDuplicate($key, $existing, $mapAt, $writtenAt, $description);
            }
            $entries[$key] = $value;
            return $entries;
        }
        $prefix = self::prefixMap($existing, $mapAt, $writtenAt);
        $children = $this->place($prefix?->entries() ?? [], $path, $value, $mapAt, $writtenAt, $description);
        $entries[$key] = new ResolvedMap($children, $prefix?->contributors() ?? [$value->contributors()[0]]);
        return $entries;
    }

    private static function prefixMap(?ResolvedValueInterface $existing, ReadingContext $mapAt, ReadingContext $writtenAt): ?ResolvedMap
    {
        if ($existing !== null && !$existing instanceof ResolvedMap) {
            throw $writtenAt->refusal(\sprintf('%s writes a leaf and a nested value at the same path.', ucfirst($mapAt->where())));
        }
        return $existing;
    }

    private static function refuseDuplicate(string $key, ResolvedValueInterface $existing, ReadingContext $mapAt, ReadingContext $writtenAt, string $description): never
    {
        $writer = $existing->contributors()[0];
        $base = $mapAt->authoredPath;
        $first = $writer->path === null ? ($writer->origin->locator() ?? $key) : Provenance::display(\array_slice($writer->path, \count($base)));
        $second = Provenance::display(\array_slice($writtenAt->authoredPath, \count($base)));
        throw $writtenAt->refusal(\sprintf('%s writes both "%s" and "%s" in one layer; %s — write either the shorthand or the full keys in one layer.', ucfirst($mapAt->where()), $second, $first, $description));
    }
}
