<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

/**
 * The namespaces each namespace is coupled to, which namespace-level CBO counts.
 *
 * A namespace is a region: itself and every namespace below it, the same
 * prefix boundary the graph's published (subtree) Ca and Ce are counted over.
 * An edge couples a region to the namespace declaring the class on its far
 * side when exactly one end lies inside the region. For a namespace without
 * sub-namespaces the region is the namespace alone.
 *
 * The far side is named by the namespace that declares the class, never
 * truncated to the region's depth: a leaf's answer then stays the one it had
 * before regions were introduced, and only parent namespaces, which used to
 * answer 0, move.
 *
 * The own scope is the same count over the declarations of exactly one
 * namespace, where a sub-namespace is outside like any other: the scope the
 * graph's own Ca and Ce are counted over. It does not depend on which other
 * namespaces the run holds, which the subtree does — a namespace's region
 * shrinks to itself when its sub-namespaces are left out of the run.
 */
final readonly class CoupledNamespaces
{
    /**
     * One row per namespace, both scopes together, as the graph keeps its Ca
     * and Ce: held apart, the two are one lookup away from being paired
     * across namespaces. `subtree` holds the namespaces the region is coupled
     * to, `own` those the namespace's own declarations are.
     *
     * @param array<string, array{subtree?: array<string, true>, own?: array<string, true>}> $byNamespace
     */
    private function __construct(private array $byNamespace) {}

    /**
     * Read through the per-class dependency lists, which carry exactly the
     * edges Ca and Ce are counted from.
     */
    public static function of(DependencyGraphInterface $graph): self
    {
        $coupled = [];

        foreach ($graph->getAllClasses() as $class) {
            foreach ($graph->getClassDependencies($class) as $dependency) {
                $sourceNs = $dependency->sourceLogical()->namespace ?? '';
                $targetNs = $dependency->targetLogical()->namespace ?? '';

                if ($sourceNs !== $targetNs) {
                    $coupled = self::withCrossings($coupled, $sourceNs, $targetNs);
                    $coupled = self::withCrossings($coupled, $targetNs, $sourceNs);
                    $coupled[$sourceNs]['own'][$targetNs] = true;
                    $coupled[$targetNs]['own'][$sourceNs] = true;
                }
            }
        }

        return new self($coupled);
    }

    /** How many namespaces the region rooted at `$namespace` is coupled to. */
    public function countFor(string $namespace): int
    {
        return \count($this->byNamespace[$namespace]['subtree'] ?? []);
    }

    /** How many namespaces the declarations of exactly `$namespace` are coupled to. */
    public function ownCountFor(string $namespace): int
    {
        return \count($this->byNamespace[$namespace]['own'] ?? []);
    }

    /**
     * Couples every region holding `$near` but not `$far` to `$far`.
     *
     * @param array<string, array{subtree?: array<string, true>, own?: array<string, true>}> $coupled
     *
     * @return array<string, array{subtree?: array<string, true>, own?: array<string, true>}>
     */
    private static function withCrossings(array $coupled, string $near, string $far): array
    {
        foreach (self::regionsContaining($near) as $region) {
            if (!self::isInsideRegion($far, $region)) {
                $coupled[$region]['subtree'][$far] = true;
            }
        }

        return $coupled;
    }

    /**
     * The namespace itself and each of its ancestors, innermost first.
     *
     * @return list<string>
     */
    private static function regionsContaining(string $namespace): array
    {
        $regions = [$namespace];

        while (($separator = strrpos($namespace, '\\')) !== false) {
            $namespace = substr($namespace, 0, $separator);
            $regions[] = $namespace;
        }

        return $regions;
    }

    private static function isInsideRegion(string $namespace, string $region): bool
    {
        return $namespace === $region || ($region !== '' && str_starts_with($namespace, $region . '\\'));
    }
}
