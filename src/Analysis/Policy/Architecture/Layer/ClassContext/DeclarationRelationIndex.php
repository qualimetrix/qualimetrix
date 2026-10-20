<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext;

use Closure;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\AttributeSite;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ExternalSupertypes;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Direct declaration relations indexed for one run's class contexts. */
final class DeclarationRelationIndex
{
    /** @var array<string, list<string>> */
    private array $extends = [];

    /** @var array<string, true> */
    private array $interfaceSources = [];

    /** @var array<string, list<string>> */
    private array $implements = [];

    /** @var array<string, list<string>> */
    private array $attributes = [];

    /** @var array<string, list<string>> */
    private array $memberAttributes = [];

    /** @var array<string, list<string>> */
    private array $traitUses = [];

    /** @param Closure(SymbolPath): ?string $nameOf */
    public function __construct(DependencyGraphInterface $graph, Closure $nameOf)
    {
        $this->collectDependencies($graph, $nameOf);
    }

    /** @return list<string>|null */
    public function attributesOf(string $fqn): ?array
    {
        return $this->attributes[$fqn] ?? null;
    }

    /** @return list<string> */
    public function memberAttributesOf(string $fqn): array
    {
        return $this->memberAttributes[$fqn] ?? [];
    }

    /** @return list<string>|null */
    public function extendsOf(string $fqn): ?array
    {
        return $this->extends[$fqn] ?? null;
    }

    /** @return list<string> */
    public function implementsOf(string $fqn): array
    {
        return $this->implements[$fqn] ?? [];
    }

    /** @return array<string, list<string>> */
    public function implementsMap(): array
    {
        return $this->implements;
    }

    /** @return list<string> */
    public function traitsOf(string $fqn): array
    {
        return $this->traitUses[$fqn] ?? [];
    }

    public function isInterface(string $fqn): bool
    {
        return isset($this->interfaceSources[$fqn]);
    }

    public function recordDeclarationKind(string $fqn, ClassType $type): void
    {
        if ($type === ClassType::Interface_) {
            $this->interfaceSources[$fqn] = true;
        }
    }

    public function addExternal(string $fqn, ExternalSupertypes $facts): void
    {
        if ($facts->classType === ClassType::Interface_) {
            $this->interfaceSources[$fqn] = true;
            foreach ($facts->interfaces as $interface) {
                $this->append($this->extends, $fqn, $interface);
            }
        } else {
            foreach ($facts->interfaces as $interface) {
                $this->append($this->implements, $fqn, $interface);
            }
        }
        if ($facts->parent !== null) {
            $this->append($this->extends, $fqn, $facts->parent);
        }
        foreach ($facts->traits as $trait) {
            $this->append($this->traitUses, $fqn, $trait);
        }
    }

    public function addImplicitStringable(string $fqn, ClassType $type): void
    {
        if ($type === ClassType::Interface_) {
            $this->append($this->extends, $fqn, 'Stringable');

            return;
        }
        if ($type === ClassType::Class_) {
            $this->append($this->implements, $fqn, 'Stringable');
        }
    }

    /** @param Closure(SymbolPath): ?string $nameOf */
    private function collectDependencies(DependencyGraphInterface $graph, Closure $nameOf): void
    {
        foreach ($graph->getDeclarationDependencies() as $dependency) {
            if ($dependency->describesNestedAnonymousClass) {
                continue;
            }
            $source = $nameOf($dependency->sourceLogical());
            $target = $nameOf($dependency->targetLogical());
            if ($source === null || $target === null) {
                continue;
            }
            $this->recordRelation($dependency, $source, $target);
        }
    }

    private function recordRelation(Dependency $dependency, string $source, string $target): void
    {
        if ($dependency->type === DependencyType::Attribute) {
            $this->collectAttribute($source, $target, $dependency->attributeSite);

            return;
        }
        if ($dependency->type === DependencyType::Extends) {
            $this->append($this->extends, $source, $target);
        } elseif ($dependency->type === DependencyType::Implements) {
            $this->append($this->implements, $source, $target);
        } elseif ($dependency->type === DependencyType::TraitUse) {
            $this->append($this->traitUses, $source, $target);
        }
        if ($dependency->interfaceExtends) {
            $this->interfaceSources[$source] = true;
        }
    }

    private function collectAttribute(string $source, string $target, ?AttributeSite $site): void
    {
        if ($site === AttributeSite::ClassHeader) {
            $this->append($this->attributes, $source, $target);
        } elseif ($site?->isDeclaredMember() === true) {
            $this->append($this->memberAttributes, $source, $target);
        }
    }

    /** @param array<string, list<string>> $map */
    private function append(array &$map, string $source, string $target): void
    {
        if (!\in_array($target, $map[$source] ?? [], true)) {
            $map[$source][] = $target;
        }
    }
}
