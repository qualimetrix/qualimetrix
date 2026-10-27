<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
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

    public function qualifiedName(): string
    {
        return $this->qualifiedName;
    }

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

    /** Records a trait use, or the instance properties a property statement declares. */
    public function declareMember(TraitUse|Property $node): void
    {
        if ($node instanceof TraitUse) {
            $this->addTraitUse(CohesionTraitUses::fromNode($node));
        } elseif (!$node->isStatic()) {
            $this->incrementInstancePropertyCount(\count($node->props));
        }
    }

    /** Records a method declaration, and the properties a constructor promotes. */
    public function declareClassMethod(ClassMethod $node): void
    {
        $name = $node->name->toString();
        $this->declareMethod($name, $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public'), $node->isAbstract());
        if (strtolower($name) !== '__construct') {
            return;
        }
        foreach ($node->params as $param) {
            if ($param->flags !== 0) {
                $this->incrementInstancePropertyCount();
            }
        }
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
