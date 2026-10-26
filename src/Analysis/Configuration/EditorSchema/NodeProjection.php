<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\EditorSchema;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\BareElementPolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use stdClass;

/**
 * The JSON Schema of one node, by its merge policy.
 *
 * It accepts whatever form the YAML loader hands the engine for the node: `~`
 * under a key, `[]` for any empty collection, a map keyed 0..n for a list, and
 * the YAML merge key `<<` that an editor's parser may leave in place. It never
 * projects what an owner's closure judges.
 */
final class NodeProjection
{
    private const string OPAQUE = 'The form of this value is judged by its owner.';

    /**
     * The loader reads a map keyed 0..n as a list. The schema accepts any
     * integer keys, wider than the engine: JSON Schema cannot check the order.
     */
    private const string INTEGER_KEY = '^(?:0|-?[1-9][0-9]*)$';

    private const string MERGE_KEY = '^<<$';

    public function __construct(private readonly DefinitionPool $pool) {}

    /**
     * The document root: a map whose keys are listed alphabetically.
     *
     * @return array<string, mixed>
     */
    public function root(NodeSchema $root): array
    {
        if ($root->policy !== MergePolicy::DeepMerge) {
            throw new LogicException(\sprintf('The document root must be a map, got a %s node.', $root->policy->value));
        }

        $children = $this->children($root);
        ksort($children, \SORT_STRING);

        return self::map($root, $children, NullAdmission::Admitted);
    }

    /** @return array<string, mixed>|PooledNode */
    public function node(NodeSchema $node, NullAdmission $null): array|PooledNode
    {
        $description = $node->wording->description;

        return match ($node->policy) {
            MergePolicy::LastWriterWins => self::described(ScalarProjection::of($node->scalar, $null), $description),
            MergePolicy::PerLayer => ['description' => $description ?? self::OPAQUE],
            MergePolicy::DeepMerge => $this->pool->node(self::map($node, $this->children($node), $null), $description),
            MergePolicy::ByName => $this->pool->node($this->namedMap($node, $null), $description),
            MergePolicy::Replace, MergePolicy::Accumulate => $this->pool->node($this->collection($node, $null), $description),
        };
    }

    /**
     * The schema of every key a map declares, fields first, then shorthands.
     *
     * @return array<string, array<string, mixed>|PooledNode>
     */
    private function children(NodeSchema $node): array
    {
        $keys = $node->map->keys;
        $children = array_map(fn(NodeSchema $field): array|PooledNode => $this->node($field, NullAdmission::Admitted), $keys->fields());
        foreach ($keys->shorthands() as $shorthand) {
            $children[$shorthand->key] = $this->node(ShorthandProjection::valueNode($node, $shorthand), NullAdmission::Admitted);
        }

        return $children;
    }

    /**
     * @param array<string, array<string, mixed>|PooledNode> $children
     *
     * @return array<string, mixed>
     */
    private static function map(NodeSchema $node, array $children, NullAdmission $null): array
    {
        $retired = [];
        foreach ($node->map->retiredKeys as $key => $sentence) {
            $retired[PortableRegex::exactly(ConfigKeySpelling::acceptedSpellings($key))] = ['description' => $sentence, 'not' => new stdClass()];
        }

        $types = $node->map->bareField === null ? ['object'] : ['object', 'boolean'];
        $schema = self::object([...$types, 'array', ...$null->types()], $children, [...self::alternativeSpellings($children), ...$retired]);
        $conflicts = ShorthandProjection::conflicts($node);

        return $conflicts === [] ? $schema : $schema + ['allOf' => $conflicts];
    }

    /** @return array<string, mixed> */
    private function namedMap(NodeSchema $node, NullAdmission $null): array
    {
        $names = $node->map->names;
        $closed = $names?->closedNames();
        $fixed = $closed === null && $names !== null && $names->isFixed() ? $names->fixedNames() : null;
        $listed = $closed ?? $fixed;

        if ($listed === null) {
            if ($node->map->namedEntrySchema !== null) {
                throw new LogicException('A named map that derives its entries from names needs a closed vocabulary to be projected.');
            }

            $entry = $node->map->entry ?? throw new LogicException('A named map needs an entry schema.');

            return array_replace(
                self::object(['object', 'array', ...$null->types()], [], []),
                ['additionalProperties' => $this->node($entry, NullAdmission::Admitted)],
            );
        }

        sort($listed, \SORT_STRING);
        $entries = [];
        foreach ($listed as $name) {
            $entry = $node->map->entryForName($name)
                ?? throw new LogicException(\sprintf('The named entry "%s" declares no schema.', $name));
            $entries[$name] = $this->node($entry, NullAdmission::Admitted);
        }

        // A fixed vocabulary is recognised with the spelling rule of keys; a closed one exactly as written.
        return self::object(['object', 'array', ...$null->types()], $entries, $fixed === null ? [] : self::alternativeSpellings($entries));
    }

    /** @return array<string, mixed> */
    private function collection(NodeSchema $node, NullAdmission $null): array
    {
        $collection = $node->collection ?? throw new LogicException(\sprintf('A %s node needs an element schema.', $node->policy->value));
        $element = $collection->element;
        $item = $this->node($element, $element->policy === MergePolicy::PerLayer ? NullAdmission::Admitted : NullAdmission::Refused);

        $list = [
            'type' => ['array', 'object', ...$null->types()],
            'items' => $item,
            'patternProperties' => [self::INTEGER_KEY => $item],
            'additionalProperties' => false,
        ];

        return match ($collection->bareElement) {
            BareElementPolicy::ListOnly => $list,
            BareElementPolicy::SingleAllowed => ['anyOf' => [$item, $list]],
        };
    }

    /**
     * A map's schema: `[]` is its empty form, and `<<` is admitted beside its keys.
     *
     * @param list<string> $types
     * @param array<string, array<string, mixed>|PooledNode> $properties
     * @param array<string, mixed> $patterns
     *
     * @return array<string, mixed>
     */
    private static function object(array $types, array $properties, array $patterns): array
    {
        if ($properties !== [] && array_is_list($properties)) {
            throw new LogicException('Keys that PHP holds as a list cannot be written as JSON object keys.');
        }

        $schema = ['type' => $types, 'maxItems' => 0];
        if ($properties !== []) {
            $schema['properties'] = $properties;
        }

        return $schema + ['patternProperties' => [...$patterns, self::MERGE_KEY => true], 'additionalProperties' => false];
    }

    /**
     * The spellings of each key other than its canonical one, all leading to the same schema.
     *
     * @param array<string, array<string, mixed>|PooledNode> $children
     *
     * @return array<string, array<string, mixed>|PooledNode>
     */
    private static function alternativeSpellings(array $children): array
    {
        $patterns = [];
        foreach ($children as $key => $schema) {
            $others = array_values(array_diff(ConfigKeySpelling::acceptedSpellings((string) $key), [(string) $key]));
            if ($others !== []) {
                $patterns[PortableRegex::exactly($others)] = $schema;
            }
        }

        return $patterns;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function described(array $schema, ?string $description): array
    {
        return $description === null ? $schema : ['description' => $description] + $schema;
    }
}
