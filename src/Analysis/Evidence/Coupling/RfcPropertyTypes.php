<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

/** Resolves declared property types in their class or consuming-trait context. */
final class RfcPropertyTypes
{
    public function __construct(private readonly RfcDeclarationIndex $index, private readonly RfcEffectiveMembers $members) {}

    /** @param array<string, true> $active */
    public function resolve(RfcDeclarationFacts $facts, string $property, array $active): ?string
    {
        $key = $facts->declaration->toCanonical();
        if (isset($active[$key])) {
            return null;
        }
        $active[$key] = true;
        if (isset($facts->propertyNames[$property])) {
            return $this->propertyContext($facts->properties[$property] ?? null, $facts);
        }
        $sources = $this->members->propertySources($facts, $active)[$property] ?? [];
        if ($sources !== []) {
            return $this->propertySourceType($sources, $property);
        }
        $parents = $this->index->parents($facts);
        if (\count($parents) !== 1) {
            return null;
        }
        $parent = $this->index->unique($parents[0]);

        return $parent !== null ? $this->resolve($parent, $property, $active) : null;
    }

    /** @param array<string, RfcDeclarationFacts> $sources */
    private function propertySourceType(array $sources, string $property): ?string
    {
        if (\count($sources) !== 1) {
            return null;
        }
        $source = reset($sources);

        return $this->propertyContext($source->properties[$property] ?? null, $source);
    }

    private function propertyContext(?string $type, RfcDeclarationFacts $owner): ?string
    {
        if ($owner->kind === 'Stmt_Trait') {
            return $type;
        }
        if ($type === 'self' || $type === 'static') {
            return $owner->declaration->logical->toString();
        }
        if ($type === 'parent') {
            $parents = $this->index->parents($owner);

            return \count($parents) === 1 ? $parents[0] : null;
        }

        return $type;
    }
}
