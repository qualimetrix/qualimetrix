<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitDefinitionFacts;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitMethodSource;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitUseFacts;

/** Class and trait declarations required to resolve effective Cohesion members. */
final class CohesionDeclarationData
{
    /** @var array<string, TraitMethodSource> */
    private array $methods = [];

    /** @var list<TraitUseFacts> */
    private array $traitUses = [];

    private int $instancePropertyCount = 0;

    public function __construct(private readonly string $qualifiedName) {}

    public function addMethod(string $name): void
    {
        $key = ClassNameSpelling::fold($name);
        $this->methods[$key] ??= new TraitMethodSource($this->qualifiedName, $name);
    }

    /**
     * @param 'public'|'protected'|'private' $visibility
     *
     * @qmx-ignore code-smell.boolean-argument -- Abstractness is an immutable method-declaration fact copied into trait composition, not an execution mode.
     */
    public function declareMethod(string $name, string $visibility, bool $abstract): void
    {
        $this->methods[ClassNameSpelling::fold($name)] = new TraitMethodSource($this->qualifiedName, $name, $visibility, $abstract);
    }

    public function addTraitUse(TraitUseFacts $use): void
    {
        $this->traitUses[] = $use;
    }

    /** @return list<TraitUseFacts> */
    public function traitUses(): array
    {
        return $this->traitUses;
    }

    public function traitDefinitionFacts(): TraitDefinitionFacts
    {
        return new TraitDefinitionFacts($this->methods, $this->traitUses);
    }

    /** @return list<string> */
    public function methodNames(): array
    {
        return array_map(static fn(TraitMethodSource $source): string => $source->originalMethod, array_values($this->methods));
    }

    /** @return array<string, TraitMethodSource> */
    public function methods(): array
    {
        return $this->methods;
    }

    public function methodSource(string $name): ?TraitMethodSource
    {
        return $this->methods[ClassNameSpelling::fold($name)] ?? null;
    }

    public function incrementInstancePropertyCount(int $count = 1): void
    {
        $this->instancePropertyCount += $count;
    }

    public function instancePropertyCount(): int
    {
        return $this->instancePropertyCount;
    }
}
