<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection;

use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Resolves namespace-filter bindings against one graph class inventory. */
final readonly class NamespaceSelection
{
    /**
     * @param list<NamespacePattern>|null $includes
     * @param list<NamespacePattern> $excludes
     */
    public function __construct(
        private ?array $includes,
        private array $excludes,
    ) {}

    /**
     * @param iterable<SymbolPath> $classes
     *
     * @return array{include: list<string>, exclude: list<array{selector: string, suggestion: ?string}>}
     */
    public function binding(iterable $classes): array
    {
        $namespaces = [];
        foreach ($classes as $classPath) {
            $namespaces[$classPath->namespace ?? ''] = true;
        }
        $namespaces = array_keys($namespaces);

        return [
            'include' => self::unboundIncludes($this->includes, $namespaces),
            'exclude' => self::unboundExcludes($this->excludes, $namespaces),
        ];
    }

    /**
     * @param list<NamespacePattern>|null $patterns
     * @param list<string> $namespaces
     *
     * @return list<string>
     */
    private static function unboundIncludes(?array $patterns, array $namespaces): array
    {
        if ($patterns === null) {
            return [];
        }

        $unbound = [];
        foreach ($patterns as $pattern) {
            if (!self::binds($pattern, $namespaces)) {
                $unbound[] = $pattern->definition->display();
            }
        }

        return $unbound;
    }

    /**
     * @param list<NamespacePattern> $patterns
     * @param list<string> $namespaces
     *
     * @return list<array{selector: string, suggestion: ?string}>
     */
    private static function unboundExcludes(array $patterns, array $namespaces): array
    {
        $unbound = [];
        foreach ($patterns as $pattern) {
            if (!self::binds($pattern, $namespaces)) {
                $unbound[] = [
                    'selector' => $pattern->definition->display(),
                    'suggestion' => self::caseSuggestion($pattern, $namespaces),
                ];
            }
        }

        return $unbound;
    }

    /** @param list<string> $namespaces */
    private static function binds(NamespacePattern $pattern, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if ($pattern->matches($namespace)) {
                return true;
            }
        }

        return false;
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
            if ($folded->matches(ClassNameSpelling::fold($namespace))) {
                return $definition->kind === SelectorKind::Exact
                    ? $namespace
                    : substr($namespace, 0, \strlen($definition->value));
            }
        }

        return null;
    }
}
