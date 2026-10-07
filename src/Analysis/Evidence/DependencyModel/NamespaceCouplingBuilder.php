<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Builds the own and subtree coupling views of the graph's namespace universe. */
final class NamespaceCouplingBuilder
{
    /**
     * @param list<Dependency> $dependencies
     * @param array<string, SymbolPath> $leafNamespaces
     *
     * @return array{list<SymbolPath>, NamespaceCouplings}
     */
    public function build(array $dependencies, array $leafNamespaces): array
    {
        [$namespaceMap, $parentNamespaces] = $this->expandNamespaceUniverse($leafNamespaces);
        $own = $this->ownCouplings($dependencies, $namespaceMap);
        $rollup = $parentNamespaces === []
            ? $own
            : $this->withParentCouplings($dependencies, $parentNamespaces, $own);

        return [
            array_values($namespaceMap),
            NamespaceCouplings::fromScopes(
                $rollup['coupling.ce'],
                $rollup['coupling.ca'],
                $own['coupling.ce'],
                $own['coupling.ca'],
            ),
        ];
    }

    /**
     * @param array<string, SymbolPath> $leafNamespaces
     *
     * @return array{array<string, SymbolPath>, array<string, SymbolPath>}
     */
    private function expandNamespaceUniverse(array $leafNamespaces): array
    {
        $namespaceMap = [];
        foreach ($leafNamespaces as $namespace) {
            $namespaceMap[$namespace->toCanonical()] = $namespace;
        }

        $parents = [];
        foreach (array_keys($leafNamespaces) as $namespace) {
            while (($separator = strrpos($namespace, '\\')) !== false) {
                $namespace = substr($namespace, 0, $separator);
                $parents[$namespace] ??= SymbolPath::forNamespace($namespace);
            }
        }
        foreach ($parents as $parent) {
            $namespaceMap[$parent->toCanonical()] = $parent;
        }

        return [$namespaceMap, $parents];
    }

    /**
     * @param list<Dependency> $dependencies
     * @param array<string, SymbolPath> $namespaceMap
     *
     * @return array{'coupling.ce': array<string, StringSet>, 'coupling.ca': array<string, StringSet>}
     */
    private function ownCouplings(array $dependencies, array $namespaceMap): array
    {
        $ce = [];
        $ca = [];
        foreach ($namespaceMap as $key => $_namespace) {
            $ce[$key] = new StringSet();
            $ca[$key] = new StringSet();
        }

        $canonicalCache = [];
        foreach ($dependencies as $dependency) {
            $source = $dependency->sourceLogical();
            $target = $dependency->targetLogical();
            if ($source->namespace === $target->namespace) {
                continue;
            }
            if ($source->namespace !== null) {
                $key = $canonicalCache[$source->namespace] ??= SymbolPath::forNamespace($source->namespace)->toCanonical();
                $ce[$key] = $ce[$key]->add($target->toCanonical());
            }
            if ($target->namespace !== null) {
                $key = $canonicalCache[$target->namespace] ??= SymbolPath::forNamespace($target->namespace)->toCanonical();
                $ca[$key] = $ca[$key]->add($source->toCanonical());
            }
        }

        return ['coupling.ce' => $ce, 'coupling.ca' => $ca];
    }

    /**
     * @param list<Dependency> $dependencies
     * @param array<string, SymbolPath> $parentNamespaces
     * @param array{'coupling.ce': array<string, StringSet>, 'coupling.ca': array<string, StringSet>} $own
     *
     * @return array{'coupling.ce': array<string, StringSet>, 'coupling.ca': array<string, StringSet>}
     */
    private function withParentCouplings(array $dependencies, array $parentNamespaces, array $own): array
    {
        $ce = $own['coupling.ce'];
        $ca = $own['coupling.ca'];
        $prefixes = [];
        $canonical = [];
        foreach ($parentNamespaces as $name => $path) {
            $prefixes[$name] = $name . '\\';
            $canonical[$name] = $path->toCanonical();
            $ce[$canonical[$name]] = new StringSet();
            $ca[$canonical[$name]] = new StringSet();
        }

        foreach ($dependencies as $dependency) {
            $source = $dependency->sourceLogical();
            $target = $dependency->targetLogical();
            if ($source->namespace === null || $target->namespace === null || $source->namespace === $target->namespace) {
                continue;
            }
            foreach ($prefixes as $parent => $prefix) {
                $sourceInside = $source->namespace === $parent || str_starts_with($source->namespace, $prefix);
                $targetInside = $target->namespace === $parent || str_starts_with($target->namespace, $prefix);
                if ($sourceInside === $targetInside) {
                    continue;
                }
                $key = $canonical[$parent];
                if ($sourceInside) {
                    $ce[$key] = $ce[$key]->add($target->toCanonical());
                } else {
                    $ca[$key] = $ca[$key]->add($source->toCanonical());
                }
            }
        }

        return ['coupling.ce' => $ce, 'coupling.ca' => $ca];
    }
}
