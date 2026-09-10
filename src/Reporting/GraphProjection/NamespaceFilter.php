<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection;

use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphProjectionRequest;

/**
 * The single place `graph:export`'s namespace filters are compared.
 *
 * Both exporters carried a private `namespaceMatches()` of their own, and the
 * binding answer `graph:export` now refuses on would have made a third: a
 * `--namespace` value the command calls unbound while an exporter still
 * renders it (or the reverse) is a divergence nothing would report, because
 * each copy is right by its own reading.
 *
 * Binding is decided **before** exclusion: `--namespace=App
 * --exclude-namespace=App` leaves an empty graph, but the include value did
 * point at real classes, and the emptiness is what the caller asked for
 * literally. Only a value matching no class at all is unbound.
 */
final readonly class NamespaceFilter
{
    /**
     * @param array<string>|null $includeNamespaces null means "every namespace"
     * @param array<string> $excludeNamespaces
     */
    public function __construct(
        private ?array $includeNamespaces = null,
        private array $excludeNamespaces = [],
    ) {}

    public static function fromRequest(GraphProjectionRequest $request): self
    {
        return new self($request->includeNamespaces, $request->excludeNamespaces);
    }

    public function accepts(SymbolPath $classPath): bool
    {
        $namespace = $classPath->namespace ?? '';

        foreach ($this->excludeNamespaces as $excludeNs) {
            if (self::matches($namespace, $excludeNs)) {
                return false;
            }
        }

        if ($this->includeNamespaces !== null) {
            foreach ($this->includeNamespaces as $includeNs) {
                if (self::matches($namespace, $includeNs)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * @param iterable<SymbolPath> $classes
     *
     * @return list<SymbolPath>
     */
    public function apply(iterable $classes): array
    {
        $filtered = [];

        foreach ($classes as $classPath) {
            if (!$this->accepts($classPath)) {
                continue;
            }

            $filtered[] = $classPath;
        }

        return $filtered;
    }

    /**
     * The include values that bound to no class of the graph, in the order
     * they were given.
     *
     * @param iterable<SymbolPath> $classes
     *
     * @return list<string>
     */
    public function unboundIncludeNamespaces(iterable $classes): array
    {
        if ($this->includeNamespaces === null || $this->includeNamespaces === []) {
            return [];
        }

        $namespaces = [];
        foreach ($classes as $classPath) {
            $namespaces[$classPath->namespace ?? ''] = true;
        }

        $unbound = [];
        foreach ($this->includeNamespaces as $includeNs) {
            foreach (array_keys($namespaces) as $namespace) {
                if (self::matches($namespace, $includeNs)) {
                    continue 2;
                }
            }

            $unbound[] = $includeNs;
        }

        return $unbound;
    }

    /**
     * Exact match, or prefix match on a namespace boundary — `App\Service`
     * matches `App\Service\User` but not `App\ServiceLocator`.
     */
    private static function matches(string $classNamespace, string $filterNamespace): bool
    {
        if ($classNamespace === $filterNamespace) {
            return true;
        }

        return str_starts_with($classNamespace, $filterNamespace . '\\');
    }
}
