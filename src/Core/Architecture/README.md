# Core / Architecture

Framework-agnostic primitives for layer membership and policy evaluation. Reusable
by `src/Rules/Architecture/`, future layer-aware metrics, and reporting. No
dependencies on the rest of the codebase beyond other parts of `Core/`.

## Structure

```
Architecture/
└── Layer/
    ├── LayerDefinition.php                  # VO: layer name + namespace patterns + specificity
    ├── LayerRegistry.php                    # All layers + class → layer resolution (cached)
    ├── LayerPolicy.php                      # Allow-list of inter-layer dependencies
    ├── LayerCollisionException.php          # Thrown on ambiguous (tied-specificity) resolution
    └── InvalidLayerDefinitionException.php  # Thrown on construction-time validation failures
```

## Classes

### LayerDefinition

Immutable VO describing a single layer: name plus a list of namespace patterns
that identify its classes.

- `__construct(string $name, list<string> $patterns)` — validates name regex
  `[a-z][a-z0-9_-]*`, requires a non-empty pattern list, and rejects empty
  patterns. Throws `InvalidLayerDefinitionException` on failure.
- `match(string $fqn): ?int` — returns the **maximum specificity** across
  patterns that match `$fqn`, or `null` if no pattern matches. Specificity is
  the length (chars) of the literal prefix before the first wildcard
  character (`*`, `?`, `[`); for pure-literal patterns it equals full length.
- `name(): string`
- `patterns(): list<string>` — original patterns, for diagnostics.

### LayerRegistry

Final (mutable cache). Wraps the full set of `LayerDefinition`s and resolves a
class to its owning layer.

- `__construct(list<LayerDefinition> $layers)` — throws
  `\InvalidArgumentException` on duplicate layer names.
- `resolveLayer(SymbolPath $class): ?string` — picks the layer with the highest
  specificity match. Equal-specificity ties throw `LayerCollisionException`.
  Out-of-layer classes return `null`. Results are cached by
  `SymbolPath::toCanonical()`.
- `layerNames(): list<string>` — sorted unique names.
- `isEmpty(): bool`
- `definitions(): list<LayerDefinition>` — for diagnostics.

FQN construction from `SymbolPath`: `namespace + '\\' + type`. Empty namespace
→ bare type. Both empty → no layer.

### LayerPolicy

Immutable allow-list.

- `__construct(array<string, list<string>> $allowedTargets)`.
- `isAllowed(string $from, string $to): bool` — `$from === $to` always allowed;
  otherwise checks `$to ∈ $allowedTargets[$from]`. Unknown `$from` → false.
- `allowedTargets(string $from): list<string>` — empty list if `$from` is unknown.
- `knownLayers(): list<string>` — sorted union of keys and target values, used
  for cross-validation against `LayerRegistry::layerNames()`.

### Exceptions

- `LayerCollisionException extends \RuntimeException` — carries the FQN and the
  list of `[layerName, pattern]` candidates that tied.
- `InvalidLayerDefinitionException extends \InvalidArgumentException`.

## Notes

- Pattern matching reuses `Qualimetrix\Core\Util\NamespaceMatcher` for the
  boolean check; specificity is computed locally.
- `LayerRegistry` is single-threaded by design — the resolution cache is a plain
  array. Parallel collection has its own snapshotting protocol upstream.
