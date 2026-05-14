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
 * Both lookups share a single cache keyed by {@see SymbolPath::toCanonical()}:
 * the full {@see LayerMatch} list is computed once and stored, and
 * {@see resolveLayer()} reads the first entry off that list. A class queried
 * by both methods therefore walks the patterns at most once. The cache is the
 * only mutable state on the registry (which is therefore final but not
 * readonly).
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
     * Shared cache for {@see resolveLayer()} and {@see resolveAll()}. Keyed by
     * {@see SymbolPath::toCanonical()}.
     *
     * Each value is the complete list of {@see LayerMatch} entries in
     * declaration order. Empty list means the class matches no layer.
     * {@see resolveLayer()} reads the first entry off this list.
     *
     * @var array<string, list<LayerMatch>>
     */
    private array $matchCache = [];

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
        $matches = $this->resolveAll($class);

        return $matches === [] ? null : $matches[0]->layerName;
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
        $cacheKey = $class->toCanonical();
        if (\array_key_exists($cacheKey, $this->matchCache)) {
            return $this->matchCache[$cacheKey];
        }

        $fqn = $this->buildFqn($class);
        if ($fqn === null) {
            return $this->matchCache[$cacheKey] = [];
        }

        $context = new ClassContext($fqn, self::deriveShortName($fqn));

        $matches = [];
        foreach ($this->layers as $layer) {
            $result = $layer->matches($context);
            if (!$result->matched) {
                continue;
            }
            \assert($result->matchedPattern !== null);
            $matches[] = new LayerMatch($layer->name(), $result->matchedPattern);
        }

        return $this->matchCache[$cacheKey] = $matches;
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
     * Builds the FQN string from a SymbolPath.
     *
     * Rules:
     * - Both `$namespace` and `$type` empty/null → null (no FQN, never a layer match).
     * - Namespace empty/null → bare type name.
     * - Both present → `namespace\\type`.
     * - Pure-namespace SymbolPath (type null) → returns the namespace string by design,
     *   so prefix-mode patterns like `App\Service` match both `App\Service\Foo` and the
     *   `App\Service` namespace itself. This is intentional: a layer's patterns describe
     *   "things in this namespace", which includes the namespace node as well as its
     *   classes. Today only class-level lookups reach this method (the rule iterates
     *   `metrics->all(SymbolType::Class_)`), but the behavior is pinned by
     *   `LayerRegistryTest::resolveLayer_namespaceOnlyPath_isResolvable` so future
     *   consumers (e.g. a layer-aware metric over namespace symbols) get a stable
     *   contract.
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

    /**
     * Extracts the short name (the segment after the last backslash) from a
     * fully-qualified name. Returns the input unchanged when it has no
     * namespace separator — covers bare type names and pure-namespace FQNs.
     */
    private static function deriveShortName(string $fqn): string
    {
        $position = strrpos($fqn, '\\');

        return $position === false ? $fqn : substr($fqn, $position + 1);
    }
}
