<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

use InvalidArgumentException;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Owns the full ordered set of {@see LayerDefinition} instances declared by
 * configuration and resolves classes to their owning layer.
 *
 * Resolution semantics — **declaration order, first match wins**:
 * - {@see resolveLayer()} iterates definitions in declared order and returns
 *   the name of the first layer whose patterns match (or null if no layer
 *   matches). This is the hot path used during dependency-edge analysis.
 * - {@see resolveAll()} returns every layer whose patterns match, in
 *   declaration order. The first entry is the assignment; the rest are
 *   layers that would have matched if they were declared earlier. Used by
 *   evidence-based shadow detection and the debug command.
 *
 * Both lookups are cached by {@see SymbolPath::toCanonical()} so a class
 * queried by both methods does not re-walk the pattern list. The cache
 * is the only mutable state on the registry (which is therefore final
 * but not readonly).
 *
 * There is intentionally no specificity scoring, no collision detection,
 * and no exception class for ambiguity — declaration order is the user's
 * tool to express intent, and the engine does not second-guess it. The
 * {@see \Qualimetrix\Rules\Architecture\LayerViolationRule} emits
 * `architecture.unreachable-layer` and `architecture.potential-shadow`
 * info-level diagnostics to surface misordered or overlapping declarations.
 */
final class LayerRegistry
{
    /**
     * @var list<LayerDefinition>
     */
    private array $layers;

    /**
     * First-match cache. Keyed by {@see SymbolPath::toCanonical()}.
     *
     * - string: name of the first matching layer
     * - false: explicitly resolved to "no layer" (negative hit)
     *
     * @var array<string, string|false>
     */
    private array $resolveCache = [];

    /**
     * Full-match cache. Keyed by {@see SymbolPath::toCanonical()}.
     *
     * Each value is the complete list of {@see LayerMatch} entries in
     * declaration order. Empty list means the class matches no layer.
     *
     * @var array<string, list<LayerMatch>>
     */
    private array $resolveAllCache = [];

    /**
     * @param list<LayerDefinition> $layers Layer definitions in declaration order;
     *                                      layer names must be unique.
     *
     * @throws InvalidArgumentException If two layers share the same name.
     */
    public function __construct(array $layers)
    {
        $seenNames = [];
        foreach ($layers as $layer) {
            $name = $layer->name();
            if (isset($seenNames[$name])) {
                throw new InvalidArgumentException(\sprintf(
                    'Duplicate layer name "%s" — each layer must have a unique identifier.',
                    $name,
                ));
            }
            $seenNames[$name] = true;
        }

        $this->layers = $layers;
    }

    /**
     * Returns the name of the first layer (in declaration order) whose
     * patterns match the class FQN, or null if no layer matches.
     *
     * This is the hot path for {@see \Qualimetrix\Rules\Architecture\LayerViolationRule}
     * — called once per dependency-edge endpoint.
     */
    public function resolveLayer(SymbolPath $class): ?string
    {
        $fqn = $this->buildFqn($class);
        if ($fqn === null) {
            return null;
        }

        $cacheKey = $class->toCanonical();
        if (\array_key_exists($cacheKey, $this->resolveCache)) {
            $cached = $this->resolveCache[$cacheKey];

            return $cached === false ? null : $cached;
        }

        foreach ($this->layers as $layer) {
            if ($layer->matches($fqn)) {
                $this->resolveCache[$cacheKey] = $layer->name();

                return $layer->name();
            }
        }

        $this->resolveCache[$cacheKey] = false;

        return null;
    }

    /**
     * Returns every layer whose patterns match the class FQN, in declaration
     * order.
     *
     * Returns an empty list when no layer matches. The first entry is the
     * actual assignment; subsequent entries are layers that would have matched
     * had they been declared earlier (used by `architecture.potential-shadow`
     * and the debug command).
     *
     * @return list<LayerMatch>
     */
    public function resolveAll(SymbolPath $class): array
    {
        $fqn = $this->buildFqn($class);
        if ($fqn === null) {
            return [];
        }

        $cacheKey = $class->toCanonical();
        if (\array_key_exists($cacheKey, $this->resolveAllCache)) {
            return $this->resolveAllCache[$cacheKey];
        }

        $matches = [];
        foreach ($this->layers as $layer) {
            $pattern = $layer->firstMatchingPattern($fqn);
            if ($pattern === null) {
                continue;
            }
            $matches[] = new LayerMatch($layer->name(), $pattern);
        }

        $this->resolveAllCache[$cacheKey] = $matches;

        return $matches;
    }

    /**
     * Returns layer names in **declaration order** (NOT alphabetically
     * sorted). The order is meaningful — it is the user's disambiguation
     * tool and the factory's cross-validation reference.
     *
     * @return list<string>
     */
    public function layerNames(): array
    {
        return array_map(static fn(LayerDefinition $layer): string => $layer->name(), $this->layers);
    }

    public function isEmpty(): bool
    {
        return $this->layers === [];
    }

    /**
     * @return list<LayerDefinition>
     */
    public function definitions(): array
    {
        return $this->layers;
    }

    /**
     * Builds the FQN string from a class-level SymbolPath.
     *
     * Rules:
     * - Both `$namespace` and `$type` empty/null → null (no FQN, never a layer match).
     * - Namespace empty/null → bare type name.
     * - Both present → `namespace\\type`.
     */
    private function buildFqn(SymbolPath $class): ?string
    {
        $namespace = $class->namespace;
        $type = $class->type;

        $hasNamespace = $namespace !== null && $namespace !== '';
        $hasType = $type !== null && $type !== '';

        if (!$hasNamespace && !$hasType) {
            return null;
        }

        if (!$hasNamespace) {
            return $type;
        }

        if (!$hasType) {
            return $namespace;
        }

        return $namespace . '\\' . $type;
    }
}
