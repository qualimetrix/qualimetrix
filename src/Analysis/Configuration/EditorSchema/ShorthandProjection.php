<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\EditorSchema;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\Shorthand;

/**
 * What a map's shorthands look like to an editor: the form of their value and
 * the keys they may not be written beside.
 *
 * The engine expands a shorthand into scalar leaves inside the layer that
 * wrote it and refuses a leaf written twice; `~` writes nothing, and neither
 * does an intermediate map written empty. So the refusal is projected only
 * when both keys are present and neither is `~`, and every intermediate step
 * must be an object: on `[]`, `required` would hold vacuously and refuse a
 * layer the engine accepts.
 *
 * A target that is not a scalar is left out of the conflicts: the engine
 * compares its leaves one by one, which this projection does not follow.
 */
final class ShorthandProjection
{
    /** The node a shorthand's value is read as: its first target's, through dotted paths and chained shorthands. */
    public static function valueNode(NodeSchema $map, Shorthand $shorthand): NodeSchema
    {
        $node = $map;
        $segments = explode('.', $shorthand->targets[0]);
        foreach ($segments as $index => $segment) {
            $chained = $node->map->keys->shorthand($segment);
            if ($chained !== null && $index === \count($segments) - 1) {
                return self::valueNode($node, $chained);
            }

            $node = $node->map->keys->fields()[$segment]
                ?? throw new LogicException(\sprintf('Shorthand "%s" spreads to undeclared "%s".', $shorthand->key, $shorthand->targets[0]));
        }

        return $node;
    }

    /**
     * One `if`/`then` per key a shorthand of the map conflicts with: when the
     * other key is written, the shorthand must be `~` or absent.
     *
     * @return list<array<string, mixed>>
     */
    public static function conflicts(NodeSchema $map): array
    {
        $conditions = [];
        foreach ($map->map->keys->shorthands() as $shorthand) {
            $unwritten = array_fill_keys(ConfigKeySpelling::acceptedSpellings($shorthand->key), ['type' => 'null']);
            foreach (self::rivals($map, $shorthand) as $rival) {
                $conditions[] = ['if' => self::written($rival), 'then' => ['properties' => $unwritten]];
            }
        }

        return $conditions;
    }

    /**
     * Every key path, relative to the map, whose non-null value writes one of
     * the shorthand's scalar leaves: the leaves themselves and every other
     * shorthand on the way to them that reaches one of them.
     *
     * @return list<non-empty-list<string>>
     */
    private static function rivals(NodeSchema $map, Shorthand $shorthand): array
    {
        $leaves = self::leaves($map, $shorthand, []);
        $rivals = [];
        foreach ($leaves as $leaf) {
            $rivals[implode('.', $leaf)] = $leaf;
            $node = $map;
            foreach ($leaf as $depth => $segment) {
                $prefix = \array_slice($leaf, 0, $depth);
                foreach ($node->map->keys->shorthands() as $other) {
                    $path = [...$prefix, $other->key];
                    if ($path !== [$shorthand->key] && self::overlap(self::leaves($node, $other, $prefix), $leaves)) {
                        $rivals[implode('.', $path)] = $path;
                    }
                }
                $node = $node->map->keys->fields()[$segment];
            }
        }

        return array_values($rivals);
    }

    /**
     * The scalar leaves a shorthand writes, as key paths below `$prefix`.
     *
     * @param list<string> $prefix
     *
     * @return list<non-empty-list<string>>
     */
    private static function leaves(NodeSchema $map, Shorthand $shorthand, array $prefix): array
    {
        $leaves = [];
        foreach ($shorthand->targets as $target) {
            $node = $map;
            $path = $prefix;
            $segments = explode('.', $target);
            foreach ($segments as $index => $segment) {
                $last = $index === \count($segments) - 1;
                $chained = $node->map->keys->shorthand($segment);
                if ($last && $chained !== null) {
                    $leaves = [...$leaves, ...self::leaves($node, $chained, $path)];
                    break;
                }

                $node = $node->map->keys->fields()[$segment]
                    ?? throw new LogicException(\sprintf('Shorthand "%s" spreads to undeclared "%s".', $shorthand->key, $target));
                $path[] = $segment;
                if ($last && $node->policy === MergePolicy::LastWriterWins) {
                    $leaves[] = $path;
                }
            }
        }

        return $leaves;
    }

    /**
     * @param list<list<string>> $some
     * @param list<list<string>> $others
     */
    private static function overlap(array $some, array $others): bool
    {
        return array_intersect(array_map(self::joined(...), $some), array_map(self::joined(...), $others)) !== [];
    }

    /** @param list<string> $path */
    private static function joined(array $path): string
    {
        return implode('.', $path);
    }

    /**
     * Holds when the key path is written with a value other than `~`, in any
     * accepted spelling of each of its keys.
     *
     * @param non-empty-list<string> $path
     *
     * @return array<string, mixed>
     */
    private static function written(array $path): array
    {
        $rest = \array_slice($path, 1);
        $value = $rest === [] ? ['not' => ['type' => 'null']] : ['type' => 'object'] + self::written($rest);

        $branches = array_map(
            static fn(string $spelling): array => ['required' => [$spelling], 'properties' => [$spelling => $value]],
            ConfigKeySpelling::acceptedSpellings($path[0]),
        );

        return \count($branches) === 1 ? $branches[0] : ['anyOf' => $branches];
    }
}
