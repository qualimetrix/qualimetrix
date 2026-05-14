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
├── Allow/
│   ├── LayerSelector.php                   # Sealed VO: exact / glob / captured selector (D4 grammar)
│   ├── LayerSelectorParser.php             # Static parser: raw string → LayerSelector
│   ├── ParseCapturedState.php              # Transient parser scratch state
│   ├── SelectorKind.php                    # enum: exact | glob | captured
│   ├── SelectorSegment.php                 # VO: one parsed segment of a captured selector
│   ├── CaptureBinding.php                  # Immutable map: capture variable → bound value
│   ├── AllowTarget.php                     # VO: target selector + relations + cross-instance flag
│   ├── AllowListEntry.php                  # VO: source selector + list<AllowTarget>
│   └── InvalidSelectorException.php        # Thrown by LayerSelectorParser::parse() on grammar errors
└── Layer/
    ├── LayerDefinition.php                 # VO: layer name + MembershipSpec (with `expanded()` factory for template-produced layers)
    ├── TemplateLayerDefinition.php         # VO: parameterised layer (name template + MembershipSpec + variables)
    ├── CapturePattern.php                  # PCRE compiler for FQN patterns with {var} / {var:**} captures
    ├── ClassSet.php                        # VO: discovered class symbols + ClassContextFactory (input to expansion)
    ├── MembershipSpec.php                  # VO: criteria a class must match to join a layer
    ├── MatchMode.php                       # enum: any | all (cross-kind criterion combination)
    ├── ClassContext.php                    # VO: class view consumed by matches()
    ├── ClassContextFactory.php             # Builds ClassContext from the run's dependency graph
    ├── MatchedCriterionKind.php            # enum: pattern / suffix / attribute / implements / extends
    ├── MatchedCriterion.php                # VO: matched criterion kind + value
    ├── MembershipResult.php                # VO: Match (carrying matched criteria) | NoMatch
    ├── LayerMatch.php                      # VO: layer name + list of matched criteria
    ├── LayerRegistry.php                   # Ordered layers + class → layer resolution (cached)
    ├── LayerPolicy.php                     # Traversal-based allow-list of inter-layer dependencies
    └── InvalidLayerDefinitionException.php # Thrown on construction-time validation failures
```

## Classes

### LayerDefinition

Immutable VO describing a single layer: a name plus the `MembershipSpec` that
decides which classes belong to it.

- `__construct(string $name, MembershipSpec $membership, bool $expanded = false)` —
  validates the name regex. For user-declared layers (default), the strict
  regex `[a-z][a-z0-9_-]*` applies. For layers produced by template expansion
  (`$expanded = true`), a relaxed regex `[A-Za-z][A-Za-z0-9_-]*` accepts
  PascalCase binding values (`domain-Order`). Criterion-list validation lives
  on the spec.
- `LayerDefinition::expanded(string $name, MembershipSpec $membership): self` —
  static factory used by `LayerExpansionStage` for template-produced layers.
- `matches(ClassContext $context): MembershipResult` — walks the five criterion
  kinds (patterns, suffix, attributes, implements, extends) and returns either
  a Match (carrying one `MatchedCriterion` per kind that fired, in declaration
  order) or NoMatch. An empty FQN is always NoMatch.
- `name(): string`
- `patterns(): list<string>` — original patterns, for diagnostics (delegates
  to the membership spec).
- `membership(): MembershipSpec`
- `$expanded: bool` — readonly flag indicating whether this layer was produced
  by template expansion. Diagnostic-only; Step D itself does not branch on it.

Under `MatchMode::Any` (default) a match succeeds if at least one declared
criterion kind fires. Under `MatchMode::All` every declared kind must fire;
empty/unset kinds are trivially satisfied and contribute no descriptor.

There is no specificity scoring. Within a single criterion kind, scanning
returns the first hit in declaration order; the disambiguation rule between
overlapping layers is the user's declaration order on the layer list
(`LayerRegistry`). See [ADR 0006](../../../docs/adr/0006-architecture-rules-declaration-order.md).

### TemplateLayerDefinition

Immutable VO describing a *parameterised* layer entry — Phase 2 direction 2.
The `nameTemplate` and one or more `patterns` carry `{var}` placeholders;
`LayerExpansionStage` walks the project's class set and produces one concrete
`LayerDefinition` per observed binding tuple (NOT cartesian product).

- `__construct(string $nameTemplate, MembershipSpec $membership)` — enforces:
  (1) name template non-empty, (2) name template references at least one
  capture variable, (3) every variable in the name template is bound by at
  least one capture-producing pattern (otherwise expansion would be
  non-deterministic).
- `nameTemplate(): string`
- `membership(): MembershipSpec`
- `variables(): list<string>` — sorted, distinct list of every variable
  referenced by either the name template or some capture-producing pattern.
- `TemplateLayerDefinition::containsCaptureVariable(string $raw): bool` —
  cheap structural check used by `LayersValidator` to decide between the
  static and template construction paths.

**D7 carve-out** (locked in ADR 0007): `MatchMode` governs only how the
capture-producing patterns are combined. Non-capturing criteria (suffix /
attributes / implements / extends / any non-capture pattern) ALWAYS act as
an AND-filter regardless of the declared mode.

Captures are currently allowed in `name` and `patterns` only. Suffix, attribute,
implements, and extends entries are fixed strings; adding captures to them is
out of scope for Step D.

### CapturePattern

Compiles an FQN glob pattern with capture variables (D4 grammar) into a PCRE
regex with named subpatterns. Consumed by `TemplateLayerDefinition` (for the
variable-extraction invariant) and `LayerExpansionStage` (for runtime
tuple extraction).

Grammar:

| Source     | Regex                          | Semantics                                                     |
| ---------- | ------------------------------ | ------------------------------------------------------------- |
| `{var}`    | `(?P<var>[^\\]+)`              | Exactly one namespace segment.                                |
| `{var:**}` | `(?P<var>[^\\]+(?:\\[^\\]+)*)` | One or more namespace segments.                               |
| `**`       | `.+`                           | One or more characters, including separators (cross-segment). |
| `*`        | `[^\\]*`                       | Any chars within one segment.                                 |
| `?`        | `[^\\]`                        | One char within one segment.                                  |
| `\`        | `\\`                           | Namespace separator (literal; no escape semantics).           |
| other      | `preg_quote()`                 | Literal.                                                      |

Rejects: unbalanced braces, empty captures, invalid identifier, unknown
quantifier, duplicate capture name, **adjacent captures** (`{a}{b}` without
a separator — almost always a typo).

**Semantic note vs `NamespaceMatcher`.** For glob patterns the two engines
agree. For non-glob non-capture patterns CapturePattern produces exact-match
regex whereas `NamespaceMatcher` does prefix matching. Call sites that need
Phase-1 prefix semantics for filter patterns (`LayerExpansionStage::passesNonCapturePatterns`)
route through `NamespaceMatcher::matchesSingle` directly.

### ClassSet

Immutable VO bundling a list of class `SymbolPath`s with a `ClassContextFactory`.
Built by `AnalysisPipeline` between collection and rule execution; consumed by
`LayerExpansionStage::expand()` to walk the project's class set for each
template.

### MembershipSpec

Immutable specification of the criteria a class must satisfy to belong to a
layer. The shape evolves with Phase 2:

- Step A: `patterns: list<string>` + `MatchMode $mode`.
- Step B: adds `suffix`, `attributes`, `implements`, `extends`.
- Step C (current): introduces the `Allow/` sub-package (`LayerSelector` and
  the parser) and migrates `LayerPolicy` to entry-list traversal; the
  `MembershipSpec` shape itself is unchanged from Step B.
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

Immutable allow-list. Phase 2 Step C migrated the internal representation
from `array<string, list<string>>` to `list<AllowListEntry>`; each entry
carries a source `LayerSelector` plus a list of `AllowTarget`s. The selector
abstraction lets glob / captured allow entries flow end-to-end (see the
`Allow/` subdirectory below).

- `__construct(list<AllowListEntry> $entries)`.
- `isAllowed(string $from, string $to): bool` — `$from === $to` always allowed;
  otherwise traverses entries, runs `source->matchSource($from)` for each, and
  on a hit checks each `AllowTarget` via `target->matchesTarget($to, $binding)`.
  Returns `true` on the first match. The Phase-1-shape (exact-only) allow-list
  preserves byte-for-byte truth values vs the pre-Step-C map lookup.
- `allowedTargets(string $from): list<string>` — used by the rule's
  human-readable recommendation. Only entries whose target is an **exact**
  selector contribute; glob / captured targets are advisory-only and excluded
  until Step E refines the recommendation surface.
- `entries(): list<AllowListEntry>` — raw entry list, for detectors like
  `MutualAllowDetector` and downstream rule helpers.

Cross-validation against `LayerRegistry::layerNames()` is the factory's
responsibility (and is intentionally limited to `exact` selectors so glob /
captured forms don't get rejected before Step D's template-layer expansion
runs); this class trusts the input.

### Allow/ — LayerSelector and friends

Phase 2 Step C introduces a small VO package under `Allow/` for parsing and
matching `architecture.allow` selectors per the D4 grammar (ADR 0007):

- **`LayerSelector`** — sealed VO with private constructor + `exact()` /
  `glob()` / `captured()` factories. Two match methods:
  `matchSource(string $name): ?CaptureBinding` (source side; produces a
  binding on success) and `matchesTarget(string $name, CaptureBinding $binding): bool`
  (target side; substitutes bound values once Step E lights up the flow).
- **`LayerSelectorParser`** — static, stateless parser. The single entry point
  for raw-string input: `LayerSelectorParser::parse('domain-*')` detects the
  kind from content (captured > glob > exact) and returns the appropriate
  `LayerSelector`. The parser lives in its own class so `LayerSelector` stays
  a small VO; the grammar machinery (escape handling, brace matching,
  quantifier validation) is kept together where it can be understood as one
  piece.
- **`SelectorKind`** / **`SelectorSegment`** / **`ParseCapturedState`** —
  enum, VO, and transient parsing record used by the parser.
- **`CaptureBinding`** — immutable `array<string, string>` carrying captured
  variable → value pairs. In Step C the source-side binding is always built;
  the target-side match deliberately ignores it (Step E enables binding
  identity enforcement).
- **`AllowTarget`** — VO bundling a target `LayerSelector` with two
  forward-looking optional fields: `?list<DependencyType> $relations` (Step G)
  and `bool $allowCrossInstance` (Step E). Both default to "off" in Step C so
  Phase-1 BC is preserved.
- **`AllowListEntry`** — VO pairing a source `LayerSelector` with a
  `list<AllowTarget>`. The unit `LayerPolicy` traverses linearly.
- **`InvalidSelectorException`** — thrown by `LayerSelectorParser::parse()`
  on grammar errors (empty input, unbalanced braces, unknown quantifier,
  invalid variable name, captures without any unescaped `{`). The
  Configuration validator catches and rewraps it as `ConfigLoadException`
  with a user-facing config-path context (e.g. `architecture.allow.controller[0]`).

Grammar reminder (D4): `{var}` → captured (variable name matches
`[A-Za-z_][A-Za-z0-9_]*`); contains `*`, `?`, or `[` → glob; else exact. Layer
**names** reject `* ? [ { }` at `LayersValidator` time — those characters are
selector metacharacters.

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
  Step C landed the D4 selector grammar — glob and captured allow-list
  selectors with `LayerPolicy` migrated to entry-list traversal. Step D
  (current) lands template-layer expansion: `TemplateLayerDefinition` +
  `CapturePattern` (Core) feed `LayerExpansionStage` (Analysis), which
  walks the project's `ClassSet` after collection and produces one
  concrete `LayerDefinition` per observed binding tuple. Templates that
  observe zero tuples surface as the `architecture.empty-template`
  warning diagnostic; cumulative expansion is bounded by
  `architecture.max_expanded_layers` (default 500). Steps E
  (capture-binding identity), F (`exclude:` block), and G (`relations:`
  filter) follow.
