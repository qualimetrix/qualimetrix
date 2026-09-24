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
 */
final readonly class CoupledNamespaces
{
    /** @param array<string, array<string, true>> $byNamespace */
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
                }
            }
        }

        return new self($coupled);
    }

    /** How many namespaces the region rooted at `$namespace` is coupled to. */
    public function countFor(string $namespace): int
    {
        return \count($this->byNamespace[$namespace] ?? []);
    }

    /**
     * Couples every region holding `$near` but not `$far` to `$far`.
     *
     * @param array<string, array<string, true>> $coupled
     *
     * @return array<string, array<string, true>>
     */
    private static function withCrossings(array $coupled, string $near, string $far): array
    {
        foreach (self::regionsContaining($near) as $region) {
            if (!self::isInsideRegion($far, $region)) {
                $coupled[$region][$far] = true;
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
