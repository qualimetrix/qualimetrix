<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

/**
 * Immutable allow-list of inter-layer dependencies.
 *
 * The policy is intentionally minimal:
 * - Same-layer dependencies (`A → A`) are always allowed, regardless of map contents.
 * - For different layers, `$from` must have an entry in the allow map AND `$to`
 *   must appear in `$allowedTargets[$from]`.
 * - An unknown source layer (no entry at all) means "no targets allowed".
 *
 * Cross-validation against {@see LayerRegistry::layerNames()} is the factory's
 * responsibility — this class trusts the input.
 */
final readonly class LayerPolicy
{
    /**
     * @var array<string, list<string>>
     */
    private array $allowedTargets;

    /**
     * @param array<string, list<string>> $allowedTargets Map `sourceLayer → list of allowed target layers`.
     */
    public function __construct(array $allowedTargets)
    {
        $this->allowedTargets = $allowedTargets;
    }

    /**
     * Returns true if a dependency from `$from` to `$to` is permitted.
     *
     * Same-layer dependencies (`$from === $to`) are always allowed.
     *
     * Contract: callers MUST first resolve `$from` and `$to` to layer names via
     * {@see LayerRegistry::resolveLayer()}. An unknown source layer (one that
     * has no entry in the allow map) is intentionally treated as "no targets
     * allowed" — this is the documented strict behaviour, not a defensive
     * fallback. Unresolved layers should be filtered by the caller before this
     * call (see {@see \Qualimetrix\Core\Architecture\CoverageMode} for how
     * out-of-layer classes are handled).
     */
    public function isAllowed(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        if (!isset($this->allowedTargets[$from])) {
            return false;
        }

        return \in_array($to, $this->allowedTargets[$from], true);
    }

    /**
     * Returns the list of explicitly allowed target layers for `$from`.
     *
     * Returns an empty list when `$from` is unknown to this policy. The list
     * does NOT include `$from` itself (same-layer is implicit, not declared).
     *
     * @return list<string>
     */
    public function allowedTargets(string $from): array
    {
        return $this->allowedTargets[$from] ?? [];
    }

    /**
     * Returns the sorted, deduplicated union of every layer name referenced
     * by this policy — both as keys (sources) and within values (targets).
     *
     * Used by the configuration factory to verify that every name in the policy
     * also exists in the layer registry.
     *
     * @return list<string>
     */
    public function knownLayers(): array
    {
        $names = array_keys($this->allowedTargets);

        foreach ($this->allowedTargets as $targets) {
            foreach ($targets as $target) {
                $names[] = $target;
            }
        }

        $unique = array_values(array_unique($names));
        sort($unique);

        return $unique;
    }
}
