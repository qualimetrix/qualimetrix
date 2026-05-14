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
    ├── ClassContext.php                    # VO: class view consumed by matches()
    ├── ClassContextFactory.php             # Builds ClassContext from the run's dependency graph
    ├── MatchedCriterionKind.php            # enum: pattern / suffix / attribute / implements / extends
    ├── MatchedCriterion.php                # VO: matched criterion kind + value
    ├── MembershipResult.php                # VO: Match (carrying matched criteria) | NoMatch
    ├── LayerMatch.php                      # VO: layer name + list of matched criteria
    ├── LayerRegistry.php                   # Ordered layers + class → layer resolution (cached)
    ├── LayerPolicy.php                     # Allow-list of inter-layer dependencies
    └── InvalidLayerDefinitionException.php # Thrown on construction-time validation failures
```

## Classes

### LayerDefinition

Immutable VO describing a single layer: a name plus the `MembershipSpec` that
decides which classes belong to it.

- `__construct(string $name, MembershipSpec $membership)` — validates the name
  regex `[a-z][a-z0-9_-]*`. Criterion-list validation lives on the spec.
- `matches(ClassContext $context): MembershipResult` — walks the five criterion
  kinds (patterns, suffix, attributes, implements, extends) and returns either
  a Match (carrying one `MatchedCriterion` per kind that fired, in declaration
  order) or NoMatch. An empty FQN is always NoMatch.
- `name(): string`
- `patterns(): list<string>` — original patterns, for diagnostics (delegates
  to the membership spec).
- `membership(): MembershipSpec`

Under `MatchMode::Any` (default) a match succeeds if at least one declared
criterion kind fires. Under `MatchMode::All` every declared kind must fire;
empty/unset kinds are trivially satisfied and contribute no descriptor.

There is no specificity scoring. Within a single criterion kind, scanning
returns the first hit in declaration order; the disambiguation rule between
overlapping layers is the user's declaration order on the layer list
(`LayerRegistry`). See [ADR 0006](../../../docs/adr/0006-architecture-rules-declaration-order.md).

### MembershipSpec

Immutable specification of the criteria a class must satisfy to belong to a
layer. The shape evolves with Phase 2:

- Step A: `patterns: list<string>` + `MatchMode $mode`.
- Step B (current): adds `suffix`, `attributes`, `implements`, `extends`.
- Step F (planned): adds optional `ExcludeSpec $exclude`.

Construction-time invariant: at least one of the five criterion lists must be
non-empty; each entry is a non-empty string.

Within a single criterion kind, list entries are always OR'd. `MatchMode`
controls how multiple criterion **kinds** combine. See
[ADR 0007](../../../docs/adr/0007-architecture-rules-flexibility.md).

### MatchMode

Enum with two cases:

- `Any` (default) — at least one declared criterion kind must match.
- `All` — every declared criterion kind must match. Missing/empty kinds are
  trivially satisfied.

A YAML `match: any | all` flag on long-form layer entries selects the mode;
omitted means `Any`.

### ClassContext

Read-only view of a class consumed by `LayerDefinition::matches()`. Five fields
back the five criterion kinds:

- `fqn` → matched against `patterns`.
- `shortName` → matched against `suffix` (`str_ends_with`).
- `attributeFqns` → matched against `attributes`.
- `interfaces` → matched against `implements` (already transitive).
- `parentClasses` → matched against `extends` (already transitive).

`ClassContext` is built in the main process from already-merged collection
output (see `ClassContextFactory`); it does not need to be serializable for
`amphp/parallel` workers.

### ClassContextFactory

Builds `ClassContext` instances from the per-analysis-run dependency graph.
The factory is the bridge between the collection phase's
`DependencyGraphInterface` and the membership-evaluation hot path:

- `bindGraph(?DependencyGraphInterface $graph): void` — called once at the
  start of every `LayerViolationRule::analyze()` run. Resets internal lookup
  maps and the per-class context cache.
- `build(SymbolPath $class): ClassContext` — for class-level symbols,
  populates attribute / interface / parent-class lists by walking the graph;
  for pure-namespace paths or empty FQNs returns a minimal context with empty
  lists. Memoised by FQN within one binding.

Implements / extends resolution is **transitive**: parent classes follow
`DependencyType::Extends` recursively; interfaces follow direct `Implements`
edges plus interfaces inherited from parent classes plus
interface-extends-interface chains (again via `DependencyType::Extends` —
disambiguated by walk start point). Vendor classes outside the analysed
project are NOT followed via reflection — chains end at the project boundary.

### MatchedCriterion / MatchedCriterionKind

`MatchedCriterion` is a tiny VO carrying a `MatchedCriterionKind` enum value
(`Pattern`, `Suffix`, `Attribute`, `Implements`, `Extends`) plus the criterion
list entry that fired (e.g. `'App\\Service\\**'` for a pattern,
`'Repository'` for a suffix). `describe()` renders it as `pattern "..."` for
diagnostic messages.

### MembershipResult

Outcome of `LayerDefinition::matches()`. The Match variant carries
`matchedCriteria: list<MatchedCriterion>` — one descriptor per criterion
kind that fired, in declaration order (Pattern, Suffix, Attribute, Implements,
Extends). `LayerRegistry::resolveAll()` forwards the list into `LayerMatch` so
the `architecture.layer-violation` and `architecture.potential-shadow`
messages can report **which** criterion caught the class.

Modelled as one final value object with two static factories
(`MembershipResult::match([$c1, ...])` / `MembershipResult::noMatch()`) rather
than a sealed hierarchy. The `matched` flag is the discriminant; the
`matchedCriteria` list is empty on NoMatch.

### LayerMatch

Immutable VO carrying `layerName` and `matchedCriteria` (the descriptor list
from the underlying `MembershipResult`). Returned by `LayerRegistry::resolveAll()`
— one entry per layer whose criteria match the class, in declaration order. The
first entry is the layer the class is assigned to; subsequent entries are
layers that would have matched if they were declared earlier (used by
`architecture.potential-shadow` evidence and the debug command). The
constructor rejects an empty descriptor list — a match cannot occur with zero
firing criteria.

`primaryCriterion()` returns the first descriptor, used by the rule for
single-criterion summary lines in shadow / unreachable diagnostics.

### LayerRegistry

Final (with mutable cache). Wraps the full ordered set of `LayerDefinition`s
and resolves a class to its owning layer.

- `__construct(list<LayerDefinition> $orderedLayers, ?ClassContextFactory $factory = null)`
  — throws `\InvalidArgumentException` on duplicate layer names. Order is
  significant. Factory defaults to a fresh no-graph instance for tests / the
  debug command.
- `resolveLayer(SymbolPath $class): ?string` — first match, short-circuits,
  returns the name of the first matching layer or null. Used in the hot path
  (per-edge analysis).
- `resolveAll(SymbolPath $class): list<LayerMatch>` — every layer whose
  criteria match, in declaration order. Used by `architecture.potential-shadow`
  evidence collection and the debug command.
- `contextFactory(): ClassContextFactory` — returns the injected factory so
  callers can rebind it to the per-run dependency graph.
- `bindGraph(?DependencyGraphInterface $graph): void` — convenience: forwards
  to the factory and clears the match cache. `LayerViolationRule::analyze()`
  calls this at the top of every run.
- `clearCache(): void` — drops cached matches without touching the factory.
- `layerNames(): list<string>` — preserves declaration order (NOT alphabetical).
  This is also the factory's cross-validation reference for `allow` entries.
- `isEmpty(): bool`
- `definitions(): list<LayerDefinition>` — preserves order, for diagnostics.

Both `resolveLayer()` and `resolveAll()` share a per-`SymbolPath::toCanonical()`
cache so a class queried by both methods does not re-walk the criterion list.
The factory provides the underlying `ClassContext`; before `bindGraph()` is
called (e.g. during config load, or in the `debug:layer-assignment` command)
the factory operates in no-graph mode and only `patterns` and `suffix`
criteria can fire.

FQN construction from `SymbolPath` happens inside the factory: `namespace +
'\\' + type`. Empty namespace → bare type. Both empty → no layer.

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
- Suffix matching uses `str_ends_with($shortName, $entry)`; attribute /
  implements / extends matching is set-membership against the corresponding
  `ClassContext` list.
- `LayerRegistry` is single-threaded by design — the resolution caches are
  plain arrays. Parallel collection has its own snapshotting protocol upstream.
- The pivot from specificity-based to declaration-order matching is documented
  in [ADR 0006](../../../docs/adr/0006-architecture-rules-declaration-order.md);
  [ADR 0005](../../../docs/adr/0005-architecture-rules.md) is marked Superseded.
- The Phase 2 design (multi-criterion membership, template layers, exclude
  block, relation filters) is locked in
  [ADR 0007](../../../docs/adr/0007-architecture-rules-flexibility.md).
  Step B (current) implements direction 1 (class-membership beyond namespace).
