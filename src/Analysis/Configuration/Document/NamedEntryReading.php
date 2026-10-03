<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Closure;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedBareName;

/** Reads one named entry while retaining a bare but authored name. */
final class NamedEntryReading
{
    /**
     * @param Closure(NodeSchema, AuthoredNode, ReadingContext): ?ResolvedValueInterface $read
     *
     * @return array<string, ResolvedValueInterface>
     */
    public static function entries(NodeSchema $schema, AuthoredNode $node, ReadingContext $at, WrittenNames $names, Closure $read): array
    {
        $vocabulary = WrittenNames::vocabulary($schema, $at);
        $fixed = $vocabulary?->isFixed() === true ? KeyClaims::of($vocabulary->fixedNames(), $at) : null;
        $entries = [];
        foreach ($node->children as $writtenKey => $child) {
            $written = (string) $writtenKey;
            $name = $names->nameOf($written, $child, $at, $vocabulary, $fixed);
            $entries[$name] = self::read($schema, $name, $written, $child, $at, $read);
        }
        return $entries;
    }

    /** @param Closure(NodeSchema, AuthoredNode, ReadingContext): ?ResolvedValueInterface $read */
    public static function read(NodeSchema $schema, string $name, string $written, AuthoredNode $child, ReadingContext $at, Closure $read): ResolvedValueInterface
    {
        $childAt = $at->child($written, $written, $child);
        $entrySchema = $schema->map->entryForName($name);
        $bareWithoutSchema = $child->isUnwritten()
            || (($child->shape === AuthoredShape::Mapping || $child->shape === AuthoredShape::EmptyCollection) && $child->children === []);
        if ($entrySchema === null && !$bareWithoutSchema) {
            throw $childAt->refusal(\sprintf('No value schema is declared for named entry "%s".', $written));
        }
        $value = $child->isUnwritten() || $entrySchema === null
            ? null : $read($entrySchema, $child, $at->child($written, $name, $child));
        return $value ?? new ResolvedBareName([$childAt->provenance($child)]);
    }
}
