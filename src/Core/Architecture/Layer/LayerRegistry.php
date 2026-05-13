<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

use InvalidArgumentException;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Owns the full set of {@see LayerDefinition} instances declared by configuration
 * and resolves classes to their owning layer.
 *
 * Resolution semantics:
 * - For each layer, ask {@see LayerDefinition::match()} for a specificity score.
 * - The layer with the highest specificity wins.
 * - If two or more layers tie on the highest specificity, throw
 *   {@see LayerCollisionException} — the configuration is ambiguous.
 * - If no layer matches, return null. The caller decides what to do with
 *   out-of-layer classes (coverage diagnostics, silent skip, etc.).
 *
 * Resolution results are cached per {@see SymbolPath::toCanonical()} key so that
 * repeated lookups for the same class skip the per-pattern scan. This is the
 * hot path during rule analysis on large projects.
 *
 * The registry is final but not readonly: the cache is mutable internal state.
 */
final class LayerRegistry
{
    /**
     * @var list<LayerDefinition>
     */
    private array $layers;

    /**
     * Resolved layer per canonical symbol path.
     * - string: layer name
     * - false: explicitly resolved to "no layer" (cache miss vs. negative hit)
     * - LayerCollisionException: cached ambiguous resolution; re-thrown on each
     *   subsequent lookup of the same canonical key so repeated probes for the
     *   same FQN do not repeat the full O(L*P) scan or rebuild the diagnostic.
     *
     * @var array<string, string|false|LayerCollisionException>
     */
    private array $resolutionCache = [];

    /**
     * @param list<LayerDefinition> $layers Layer definitions; layer names must be unique.
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
     * Resolves the layer that owns the class identified by `$class`.
     *
     * Returns null when no layer matches. Throws on equal-specificity collisions.
     *
     * @throws LayerCollisionException If two or more layers tie on the highest specificity.
     */
    public function resolveLayer(SymbolPath $class): ?string
    {
        $fqn = $this->buildFqn($class);
        if ($fqn === null) {
            return null;
        }

        $cacheKey = $class->toCanonical();
        if (\array_key_exists($cacheKey, $this->resolutionCache)) {
            $cached = $this->resolutionCache[$cacheKey];

            if ($cached instanceof LayerCollisionException) {
                throw $cached;
            }

            return $cached === false ? null : $cached;
        }

        $bestMatches = $this->findBestMatches($fqn);

        if ($bestMatches === []) {
            $this->resolutionCache[$cacheKey] = false;

            return null;
        }

        if (\count($bestMatches) > 1) {
            // Cache the collision so repeated probes of the same ambiguous FQN
            // skip the full O(L*P) scan and avoid rebuilding the diagnostic.
            $exception = new LayerCollisionException($fqn, $bestMatches);
            $this->resolutionCache[$cacheKey] = $exception;

            throw $exception;
        }

        $resolved = $bestMatches[0][0];
        $this->resolutionCache[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * Returns the sorted, deduplicated list of layer names.
     *
     * @return list<string>
     */
    public function layerNames(): array
    {
        $names = array_map(static fn(LayerDefinition $layer): string => $layer->name(), $this->layers);
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
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

    /**
     * Scans every layer and returns the set of `[layerName, pattern]` candidates
     * that tie on the highest specificity.
     *
     * @return list<array{0: string, 1: string}> Empty when no layer matches.
     */
    private function findBestMatches(string $fqn): array
    {
        $bestSpecificity = -1;
        /** @var list<array{0: string, 1: string}> $bestMatches */
        $bestMatches = [];

        foreach ($this->layers as $layer) {
            $specificity = $layer->match($fqn);
            if ($specificity === null) {
                continue;
            }

            if ($specificity > $bestSpecificity) {
                $bestSpecificity = $specificity;
                $bestMatches = [[$layer->name(), $this->bestMatchingPattern($layer, $fqn)]];

                continue;
            }

            if ($specificity === $bestSpecificity) {
                $bestMatches[] = [$layer->name(), $this->bestMatchingPattern($layer, $fqn)];
            }
        }

        return $bestMatches;
    }

    /**
     * Returns the highest-specificity pattern of `$layer` that matches `$fqn`,
     * used purely for diagnostics inside {@see LayerCollisionException}.
     */
    private function bestMatchingPattern(LayerDefinition $layer, string $fqn): string
    {
        $bestPattern = '';
        $bestSpecificity = -1;

        foreach ($layer->patterns() as $pattern) {
            $candidate = new LayerDefinition($layer->name(), [$pattern]);
            $specificity = $candidate->match($fqn);
            if ($specificity === null) {
                continue;
            }
            if ($specificity > $bestSpecificity) {
                $bestSpecificity = $specificity;
                $bestPattern = $pattern;
            }
        }

        return $bestPattern;
    }
}
