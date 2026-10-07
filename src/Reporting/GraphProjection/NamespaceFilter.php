<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection;

use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
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
 * Binding is decided **before** exclusion: matching include and exclude
 * selectors leave an empty graph, but the include selector still pointed at
 * real classes. Only a selector matching no class at all is unbound.
 */
final readonly class NamespaceFilter
{
    private ?NamespaceMatcher $includeMatcher;
    private NamespaceMatcher $excludeMatcher;

    /**
     * @param list<NamespacePattern>|null $includeNamespaces null means "every namespace"
     * @param list<NamespacePattern> $excludeNamespaces
     */
    public function __construct(
        private ?array $includeNamespaces = null,
        private array $excludeNamespaces = [],
    ) {
        $this->includeMatcher = $includeNamespaces === null ? null : new NamespaceMatcher($includeNamespaces);
        $this->excludeMatcher = new NamespaceMatcher($excludeNamespaces);
    }

    public static function fromRequest(GraphProjectionRequest $request): self
    {
        return new self($request->includeNamespaces, $request->excludeNamespaces);
    }

    public function accepts(SymbolPath $classPath): bool
    {
        $namespace = $classPath->namespace ?? '';

        if ($this->excludeMatcher->matches($namespace) !== null) {
            return false;
        }

        if ($this->includeMatcher !== null) {
            return $this->includeMatcher->matches($namespace) !== null;
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
                if ($includeNs->matches($namespace)) {
                    continue 2;
                }
            }

            $unbound[] = $includeNs->definition->display();
        }

        return $unbound;
    }

    /**
     * The exclude values that bound to no class of the graph, together with
     * the graph's exact namespace spelling when only ASCII case differs.
     *
     * @param iterable<SymbolPath> $classes
     *
     * @return list<array{selector: string, suggestion: ?string}>
     */
    public function unboundExcludeNamespaces(iterable $classes): array
    {
        if ($this->excludeNamespaces === []) {
            return [];
        }

        $namespaces = [];
        foreach ($classes as $classPath) {
            $namespaces[$classPath->namespace ?? ''] = true;
        }

        $unbound = [];
        foreach ($this->excludeNamespaces as $excludeNs) {
            foreach (array_keys($namespaces) as $namespace) {
                if ($excludeNs->matches($namespace)) {
                    continue 2;
                }
            }

            $unbound[] = [
                'selector' => $excludeNs->definition->display(),
                'suggestion' => self::caseSuggestion($excludeNs, array_keys($namespaces)),
            ];
        }

        return $unbound;
    }

    /** @param list<string> $namespaces */
    private static function caseSuggestion(NamespacePattern $pattern, array $namespaces): ?string
    {
        $definition = $pattern->definition;
        if ($definition->kind === SelectorKind::Regex) {
            return null;
        }

        $folded = new NamespacePattern(new SelectorDefinition(
            $definition->kind,
            ClassNameSpelling::fold($definition->value),
        ));

        foreach ($namespaces as $namespace) {
            if (!$folded->matches(ClassNameSpelling::fold($namespace))) {
                continue;
            }

            return $definition->kind === SelectorKind::Exact
                ? $namespace
                : substr($namespace, 0, \strlen($definition->value));
        }

        return null;
    }

}
