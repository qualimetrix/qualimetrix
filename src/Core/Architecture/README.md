# Core / Architecture

Framework-agnostic primitives for layer membership and policy evaluation. Reusable
by `src/Rules/Architecture/`, future layer-aware metrics, and reporting. No
dependencies on the rest of the codebase beyond other parts of `Core/`.

## Structure

```
Architecture/
├── ArchitectureConfiguration.php           # Typed holder: registry + policy + coverage
├── ArchitectureConfigurationHolder.php     # Mutable runtime holder (DI-injected)
├── CoverageMode.php                        # ignore / warn / error enum
└── Layer/
    ├── LayerDefinition.php                 # VO: layer name + namespace patterns
    ├── LayerMatch.php                      # VO: layer name + the pattern that matched
    ├── LayerRegistry.php                   # Ordered layers + class → layer resolution (cached)
    ├── LayerPolicy.php                     # Allow-list of inter-layer dependencies
    └── InvalidLayerDefinitionException.php # Thrown on construction-time validation failures
```

## Classes

### LayerDefinition

Immutable VO describing a single layer: a name plus the list of namespace
patterns that identify its classes.

- `__construct(string $name, list<string> $patterns)` — validates name regex
  `[a-z][a-z0-9_-]*`, requires a non-empty pattern list, and rejects empty
  patterns. Throws `InvalidLayerDefinitionException` on failure.
- `matches(string $fqn): bool` — true if any pattern matches `$fqn`.
- `firstMatchingPattern(string $fqn): ?string` — the first pattern (in
  declaration order) that matches, or null if none. Used by `LayerMatch`
  population and the `qmx debug:layer-assignment` command (Step 6 of the
  follow-up plan).
- `name(): string`
- `patterns(): list<string>` — original patterns, for diagnostics.

There is no specificity scoring. Pattern matching is boolean only; the
disambiguation rule between overlapping layers is the user's declaration
order (`LayerRegistry`). See [ADR 0006](../../../docs/adr/0006-architecture-rules-declaration-order.md).

### LayerMatch

Immutable VO carrying `layerName` and `matchingPattern`. Returned by
`LayerRegistry::resolveAll()` — one entry per layer whose patterns match the
class FQN, in declaration order. The first entry is the layer the class is
assigned to; subsequent entries are layers that would have matched if they
were declared earlier (used by `architecture.potential-shadow` evidence and
the debug command).

### LayerRegistry

Final (with mutable cache). Wraps the full ordered set of `LayerDefinition`s
and resolves a class to its owning layer.

- `__construct(list<LayerDefinition> $orderedLayers)` — throws
  `\InvalidArgumentException` on duplicate layer names. Order is significant.
- `resolveLayer(SymbolPath $class): ?string` — first match, short-circuits,
  returns the name of the first matching layer or null. Used in the hot path
  (per-edge analysis).
- `resolveAll(SymbolPath $class): list<LayerMatch>` — every layer whose
  patterns match, in declaration order. Used by `architecture.potential-shadow`
  evidence collection and the debug command.
- `layerNames(): list<string>` — preserves declaration order (NOT alphabetical).
  This is also the factory's cross-validation reference for `allow` entries.
- `isEmpty(): bool`
- `definitions(): list<LayerDefinition>` — preserves order, for diagnostics.

Both `resolveLayer()` and `resolveAll()` share a per-`SymbolPath::toCanonical()`
cache so a class queried by both methods does not re-walk the pattern list.

FQN construction from `SymbolPath`: `namespace + '\\' + type`. Empty namespace
→ bare type. Both empty → no layer.

### LayerPolicy

Immutable allow-list.

- `__construct(array<string, list<string>> $allowedTargets)`.
- `isAllowed(string $from, string $to): bool` — `$from === $to` always allowed;
  otherwise checks `$to ∈ $allowedTargets[$from]`. Unknown `$from` → false.
- `allowedTargets(string $from): list<string>` — empty list if `$from` is unknown.

Cross-validation against `LayerRegistry::layerNames()` (which preserves
declaration order) is the factory's responsibility — this class trusts the
input.

### Exceptions

- `InvalidLayerDefinitionException extends \InvalidArgumentException`.

## Notes

- Pattern matching reuses `Qualimetrix\Core\Util\NamespaceMatcher` semantics
  (prefix + glob auto-detection) but the boolean check is duplicated inside
  `LayerDefinition` to avoid coupling `Core/Architecture` to that utility's
  API. Consolidation is tracked as Step 2 of the architecture-rules follow-up
  plan.
- `LayerRegistry` is single-threaded by design — the resolution caches are
  plain arrays. Parallel collection has its own snapshotting protocol upstream.
- The pivot from specificity-based to declaration-order matching is documented
  in [ADR 0006](../../../docs/adr/0006-architecture-rules-declaration-order.md);
  [ADR 0005](../../../docs/adr/0005-architecture-rules.md) is marked Superseded.
