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
    ├── LayerDefinition.php                 # VO: layer name + MembershipSpec
    ├── MembershipSpec.php                  # VO: criteria a class must match to join a layer
    ├── MatchMode.php                       # enum: any | all (cross-kind criterion combination)
    ├── ClassContext.php                    # VO: minimal class view consumed by matches()
    ├── MembershipResult.php                # VO: Match (carrying matched pattern) | NoMatch
    ├── LayerMatch.php                      # VO: layer name + the pattern that matched
    ├── LayerRegistry.php                   # Ordered layers + class → layer resolution (cached)
    ├── LayerPolicy.php                     # Allow-list of inter-layer dependencies
    └── InvalidLayerDefinitionException.php # Thrown on construction-time validation failures
```

## Classes

### LayerDefinition

Immutable VO describing a single layer: a name plus the `MembershipSpec` that
decides which classes belong to it.

- `__construct(string $name, MembershipSpec $membership)` — validates the name
  regex `[a-z][a-z0-9_-]*`. Pattern-list validation lives on the spec. Throws
  `InvalidLayerDefinitionException` on name failure; `InvalidArgumentException`
  bubbles up from the spec on invalid criteria.
- `matches(ClassContext $context): MembershipResult` — evaluates the
  membership spec against the class context and returns either a Match
  (carrying the FIRST pattern, in declaration order, that matched) or NoMatch.
  An empty FQN is always NoMatch.
- `name(): string`
- `patterns(): list<string>` — original patterns, for diagnostics (delegates
  to the membership spec).
- `membership(): MembershipSpec`

There is no specificity scoring. Within a single layer, pattern matching
returns the first hit in declaration order; the disambiguation rule between
overlapping layers is the user's declaration order on the layer list
(`LayerRegistry`). See [ADR 0006](../../../docs/adr/0006-architecture-rules-declaration-order.md).

### MembershipSpec

Immutable specification of the criteria a class must satisfy to belong to a
layer. The shape evolves with Phase 2:

- Step A (current): `patterns: list<string>` + `MatchMode $mode`.
- Step B (planned): adds `suffix`, `attributes`, `implements`, `extends`.
- Step F (planned): adds optional `ExcludeSpec $exclude`.

Construction-time invariant (Step A): at least one non-empty `patterns` entry;
each pattern is a non-empty string. Step B will broaden the invariant to "at
least one of the five criterion lists is non-empty".

Within a single criterion kind, list entries are always OR'd. `MatchMode` only
controls how multiple criterion **kinds** combine (relevant once Step B opens
the schema). See [ADR 0007](../../../docs/adr/0007-architecture-rules-flexibility.md).

### MatchMode

Enum with two cases:

- `Any` (default) — at least one declared criterion kind must match.
- `All` — every declared criterion kind must match. Missing/empty kinds are
  trivially satisfied.

A YAML `match: any | all` flag on long-form layer entries selects the mode;
omitted means `Any`.

### ClassContext

Read-only view of a class consumed by `LayerDefinition::matches()`. Step A
carries only the FQN and the short name (the data `LayerRegistry` already had
via `SymbolPath`). Step B will extend the VO with resolved attribute FQNs,
the interface chain and the parent-class chain — without that, the
`attributes`, `implements`, `extends` criteria can't be evaluated.

`ClassContext` is built in the main process from already-merged collection
output; it does not need to be serializable for `amphp/parallel` workers.

### MembershipResult

Outcome of `LayerDefinition::matches()`. Step A carries the matched pattern
string on the Match variant — just enough for `LayerRegistry::resolveAll()`
to feed `LayerMatch` and the `architecture.potential-shadow` diagnostic.
Step B will extend the Match variant with a list of matched criterion
descriptors so violation messages can report **which** criterion caught the
class under `match: any`.

Modelled as one final value object with two static factories
(`MembershipResult::match($pattern)` / `MembershipResult::noMatch()`) rather
than a sealed hierarchy. The `matched` flag is the discriminant.

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

Internally builds a minimal `ClassContext` (FQN + short name only) from the
`SymbolPath` and delegates to `LayerDefinition::matches()`. Step B will inject
a full `ClassContextFactory` so attribute / interface / parent-class data is
populated.

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

- Pattern matching delegates to `Qualimetrix\Core\Util\NamespaceMatcher::matchesSingle()`
  so `LayerDefinition` shares the prefix-vs-glob decision logic with the rest
  of the codebase.
- `LayerRegistry` is single-threaded by design — the resolution caches are
  plain arrays. Parallel collection has its own snapshotting protocol upstream.
- The pivot from specificity-based to declaration-order matching is documented
  in [ADR 0006](../../../docs/adr/0006-architecture-rules-declaration-order.md);
  [ADR 0005](../../../docs/adr/0005-architecture-rules.md) is marked Superseded.
- The Phase 2 design (multi-criterion membership, template layers, exclude
  block, relation filters) is locked in
  [ADR 0007](../../../docs/adr/0007-architecture-rules-flexibility.md). The VO
  scaffolding above (Step A) is the extensibility seam Phase 2 builds on.
