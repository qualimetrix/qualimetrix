# Core — Contracts and Primitives

## Overview

Core contains neutral primitives with no natural capability owner. Core has no
dependencies except PHP and php-parser (only for Node types).

> **Note.** Layer primitives, allow-list types, capture grammar, registry, and
> declared-layer policy now belong to
> [`Analysis\\Policy\\Architecture`](../Analysis/Policy/Architecture/README.md).

## Structure

```
Core/
├── Ast/
│   └── FileParserInterface.php            # AST parsing contract
├── Exception/
│   └── ParseException.php                 # Parse error value
├── Observation/
│   └── WorseDirection.php                 # Enum: higher-is-worse / lower-is-worse + the comparison operators
├── Path/
│   ├── AbsolutePath.php                   # Absolute file path value object
│   ├── PathFactory.php                    # Boundary factory creating absolute/relative paths
│   └── RelativePath.php                   # Relative file path value object
├── Profiler/
│   └── Contract/
│       └── ProfilerInterface.php          # Neutral instrumentation vocabulary
├── Symbol/
│   ├── CallableKind.php                   # PHP callable declaration kind enum
│   ├── ClassType.php
│   ├── DeclarationKey.php                 # What the file index groups positions by
│   ├── DeclarationOrdinal.php             # Assigned rank of one declaration in its file
│   ├── DeclarationPath.php                # Durable source declaration identity
│   ├── FileDeclarationIndex.php           # Sole owner of declaration numbering per file
│   ├── LogicalClassPath.php               # Validated class-level logical identity
│   ├── MetricSubject.php                  # Declaration, class, or aggregate metric subject
│   ├── MetricSubjectCodec.php             # Scalar wire codec for metric subjects
│   ├── PhpBuiltinClassRegistry.php        # Single source of truth for PHP built-in classes
│   ├── SymbolInfo.php
│   ├── SymbolPath.php                     # Stable symbol identifier
│   └── SymbolType.php
├── Time/
│   ├── ClockInterface.php                 # "What time is it?" contract
│   └── SystemClock.php                    # Wall-clock reading of ClockInterface
├── Util/
│   ├── NamespaceMatcher.php               # Glob pattern matching for namespaces
│   ├── PathMatcher.php                    # Glob pattern matching for file paths
│   └── StringSet.php                      # Immutable set of unique strings
└── Version.php                            # Package version at runtime
```

---

## Metric Contracts

### BaseCollectorInterface

Common base interface for all collector types. Defines the shared contract: `getName()`, `provides()`, `getMetricDefinitions()`. Extended by `MetricCollectorInterface`, `DerivedCollectorInterface`, and `GlobalContextCollectorInterface`.

**Methods:**
- `getName(): string` — unique collector name
- `provides(): array<string>` — list of provided metric names
- `getMetricDefinitions(): array<MetricDefinition>` — metric definitions with aggregation strategies

### MetricCollectorInterface

Extends `BaseCollectorInterface`. A metric collector gathers a specific group of metrics from AST.

**Methods:**
- `getName(): string` — unique collector name
- `provides(): array<string>` — list of collected metrics (for dependency resolution)
- `getMetricDefinitions(): array<MetricDefinition>` — metric descriptions and aggregation strategies
- `getVisitor(): NodeVisitorAbstract` — visitor for AST traversal
- `collect(SplFileInfo $file, array $ast): MetricBag` — metric collection after traversal
- `reset(): void` — reset visitor state between files

**DI Tags:** `qmx.collector`

### DerivedCollectorInterface

Extends `BaseCollectorInterface`. Collector that derives metrics from other collectors' results. Executed **after** all regular collectors complete, in a separate phase. Calculates composite metrics from base metrics (e.g., Maintainability Index from Halstead Volume, CCN, and LOC).

**Methods:**
- `getName(): string` — unique collector name
- `requires(): array<string>` — names of required collectors
- `provides(): array<string>` — list of provided metric names
- `getMetricDefinitions(): array<MetricDefinition>` — metric definitions
- `calculate(MetricBag $sourceBag): MetricBag` — calculate derived metrics from source metrics

**DI Tags:** `qmx.derived_collector`

### GlobalContextCollectorInterface

Extends `BaseCollectorInterface`. Collector that computes metrics from global context (cross-file knowledge). Unlike `MetricCollectorInterface` which operates on individual files via AST, this operates on already-collected metrics and the dependency graph. Used for coupling, distance, and other cross-file metrics.

**Methods:**
- `getName(): string` — unique collector name
- `requires(): array<string>` — required metric names; drives topological sorting, and a name no collector provides is rejected when the aggregation service is built
- `provides(): array<string>` — list of provided metric names
- `getMetricDefinitions(): array<MetricDefinition>` — metric definitions
- `calculate(DependencyGraphInterface $graph, MetricRepositoryInterface $repository): void` — compute and store metrics

**DI Tags:** `qmx.global_collector`

### ParallelSafeCollectorInterface

Marker interface for collectors that can be safely instantiated in parallel workers. Parallel workers cannot use DI — collectors are instantiated via `new $className()`. Only collectors implementing this interface will be used in parallel mode; others fall back to sequential execution.

**Requirements for implementing classes:**
- Must have no required constructor parameters
- Must not depend on external services
- All state must be self-contained and resettable via `reset()`

### CallableMetricsProviderInterface

Optional interface for collectors that provide callable-level metrics.

Allows Analyzer to extract detailed metrics without knowledge of specific collector types.
This ensures proper layer separation: Analysis depends on Core abstractions, not on Metrics implementations.

**Methods:**
- `getCallablesWithMetrics(RelativePath $file): list<CallableWithMetrics>` — returns declaration-scoped callable metrics after AST traversal. Each payload carries its exact `DeclarationPath`, `CallableKind`, anonymous syntax metadata where applicable, lexical class context, and nullable class aggregation owner.

**Usage:** Implemented by collectors that gather callable-level metrics (e.g., CyclomaticComplexityCollector).

### ClassMetricsProviderInterface

Optional interface for collectors that provide class-level metrics.

Analogous to `CallableMetricsProviderInterface` but for class-level data. Allows extracting class metrics without knowing concrete collector types.

**Methods:**
- `getClassesWithMetrics(RelativePath $file): list<ClassWithMetrics>` — returns class metrics after AST traversal

**Usage:** Implemented by collectors that gather class-level metrics (e.g., TccLccCollector, RfcCollector).

### NamespaceMetricProviderInterface

Optional interface for collectors that can attribute file-collected metrics to
individual namespace source blocks. `NamespaceWithMetrics` carries the namespace,
source line, and contribution bag through sequential and parallel collection.

### CallableWithMetrics

Value Object — one concrete callable declaration with collected metrics.

**Fields:**
- `declarationPath: DeclarationPath` — exact source declaration identity: logical symbol, file, and assigned ordinal
- `startFilePos: int` — the position this declaration was collected at; an in-run join key, never part of a stored identity
- `kind: CallableKind` — method, function, property hook, or anonymous callable
- `anonymousSyntax: ?string` — `closure` or `arrow` for anonymous callables
- `lexicalClassContext: ?DeclarationPath` — enclosing class declaration where applicable
- `classAggregationOwner: ?LogicalClassPath` — explicit owner for method/property-hook class roll-up
- `metrics: MetricBag` — collected metrics

### ClassWithMetrics

Value Object — one concrete class declaration with its collected metrics.

**Fields:**
- `declarationPath: DeclarationPath` — exact source declaration identity: logical symbol, file, and assigned ordinal
- `startFilePos: int` — the position this declaration was collected at; an in-run join key, never part of a stored identity
- `line: int` — line number, presentation only
- `metrics: MetricBag` — collected metrics
- `subject: MetricSubject` — the declaration subject derived from `declarationPath`

### MetricBag

Value Object — metric container for a single entity (file/class/method).

**Methods:**
- `with(string $name, int|float $value): self` — returns new MetricBag with the metric set (immutable)
- `fromArray(array $metrics): self` — static factory method
- `get(string $name): int|float|null`
- `has(string $name): bool`
- `all(): array<string, int|float>`
- `merge(self $other): self` — merge metrics (for parallelization)
- `withPrefix(string $prefix): self` — adds prefix to metric names

**Serializable:** Yes (for inter-process transfer)

### MetricRepositoryInterface

Access to collected metrics for rules. Aggregate APIs remain `SymbolPath`-based;
typed APIs preserve declaration and logical-class identity without collapsing them.

**Methods:**
- `get(SymbolPath $symbol): MetricBag` — metrics for any symbol
- `all(SymbolType $type): iterable<SymbolInfo>` — iterator over symbols of a given type
- `has(SymbolPath $symbol): bool` — check if metrics exist
- `getSubject(MetricSubject $subject): MetricBag` / `hasSubject(...)` — typed lookup
- `addSubject(...)` and `addCallable(CallableWithMetrics $callable)` — typed writes
- `allDeclarations()`, `allCallables()`, `allLogicalClasses()` — typed iteration

All symbol levels (Callable, Class, File, Namespace, Project) return `MetricBag`.
Aggregated metrics use naming convention: `{metric}.{strategy}` (e.g., `ccn.sum`, `loc.avg`).

**SymbolType (Enum):**
```php
enum SymbolType: string {
    case Method;     // all methods
    case Function_;  // all functions
    case Class_;     // all classes
    case File;       // all files
    case Namespace_; // all namespaces
    case Project;    // project-level (aggregated from all namespaces)
}
```

**Usage examples:**
```php
// Exact callable metrics (raw; duplicate logical declarations stay distinct)
foreach ($repository->allCallables() as $callableInfo) {
    $subject = $callableInfo->subject
        ?? throw new LogicException('Callable metrics require an exact declaration subject');
    $ccn = $repository->getSubject($subject)->get('ccn'); // int|null
}

// Namespace metrics (aggregated)
$nsMetrics = $repository->get(SymbolPath::forNamespace('App\Service'));
$avgCcn = $nsMetrics->get('ccn.avg'); // float
$totalLoc = $nsMetrics->get('loc.sum'); // int
$classCount = $nsMetrics->get('classCount.sum'); // int

```

**Advantages of a unified API:**
- Single `MetricBag` type for all levels — simpler to work with
- Naming convention `{metric}.{strategy}` — clear which aggregation was applied
- SymbolPath is already used for findings — reuse

### AggregationStrategy (Enum)

Defines how metrics are aggregated when transitioning to a higher level.

| Value          | Description             |
| -------------- | ----------------------- |
| `Sum`          | Sum of values           |
| `Average`      | Arithmetic mean         |
| `Max`          | Maximum                 |
| `Min`          | Minimum                 |
| `Count`        | Number of elements      |
| `Percentile95` | 95th percentile (`p95`) |

### SymbolLevel (Enum)

Hierarchy level of a symbol in the aggregation tree.

| Value        | Description                       |
| ------------ | --------------------------------- |
| `Callable`   | Callable (PHP method or function) |
| `Class_`     | Class, interface, trait, enum     |
| `File`       | File                              |
| `Namespace_` | Namespace                         |
| `Project`    | Project (root)                    |

### MetricDefinition

Value Object — describes a metric and its aggregation strategies.

**Fields:**
- `name: string` — base name (`ccn`, `loc`, `classCount`)
- `collectedAt: SymbolLevel` — collection level
- `aggregations: array<string, list<AggregationStrategy>>` — strategies by level

**Methods:**
- `aggregatedName(AggregationStrategy $strategy): string` — `{name}.{strategy}`
- `getStrategiesForLevel(SymbolLevel $level): list<AggregationStrategy>`
- `hasAggregationsForLevel(SymbolLevel $level): bool`

**Example:**
```php
new MetricDefinition(
    name: 'ccn',
    collectedAt: SymbolLevel::Callable,
    aggregations: [
        'class' => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
        'namespace' => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
        'project' => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
    ],
);
```

### Metric Aggregation Model

Metrics are aggregated **upward** through the symbol hierarchy: Callable → Class → Namespace → Project.
Each level aggregates only from its **direct children** (flat aggregation):

- **Class** metrics = aggregated from its callables (e.g., `ccn.sum` = sum of all callable CCN values)
- **Namespace** metrics = aggregated from callables/classes directly in the namespace (not from nested namespaces). For callable-collected metrics (CCN, Cognitive, NPath, MI), `.max`/`.avg`/`.p95` reflect per-callable values.
- **Project** metrics = aggregated from all namespaces

This means namespace metrics describe the namespace **as an organizational unit**, not its entire subtree.
For example, `App\Payment` with `ccn.avg = 4` reflects only callables in classes directly in `App\Payment`,
not callables in `App\Payment\Gateway` or other sub-namespaces.

**Hierarchical (subtree) aggregation** — recursive roll-up across nested namespaces — is not part of the
core metric system. It is a presentation concern, computed on the client side (e.g., JS in the HTML report)
for drill-down navigation and "worst sub-namespaces" views.

**Rationale:** Rules and findings target specific symbols. A finding on `App\Payment` means that
namespace itself has a problem. Hierarchical roll-up would mask issues (averaging hides bad sub-namespaces)
and produce non-actionable findings (e.g., "namespace too large" when it is properly decomposed).

---

## Finding Contracts

Finding owns rule vocabulary, executable rules, finding values, filtering, and
inline control values. Its public class-string metadata boundary is
`Analysis\Finding\Contract\Rule\RuleDefinitionInterface`, which provides only
`getOptionsClass(): class-string<RuleOptionsInterface>`. The extended
`RuleInterface` is the internal executable contract; it does not publish an
instance API, factory, registration mechanism, or optional reflection metadata.

### RuleOptionsInterface

Base options interface for all rules.

**Methods:**
- `fromArray(array $config): self` — create options from configuration array (static)
- `isEnabled(): bool` — whether the rule is enabled
- `getSeverity(int|float $value): ?Severity` — severity for a metric value (null if acceptable)

### HierarchicalRuleOptionsInterface

Extends `RuleOptionsInterface` with level-specific capabilities.

**Methods:**
- `forLevel(SymbolLevel $level): LevelOptionsInterface` — options for a specific level
- `isLevelEnabled(SymbolLevel $level): bool` — whether a specific level is enabled
- `getSupportedLevels(): list<SymbolLevel>` — all supported levels

### LevelOptionsInterface

Options for a specific level of a hierarchical rule.

**Methods:**
- `fromArray(array $config): self` — create from configuration array (static)
- `isEnabled(): bool` — whether this level is enabled
- `getSeverity(int|float $value): ?Severity` — severity for the given metric value

### ThresholdAwareOptionsInterface

Interface for options that support `@qmx-threshold` overrides. Implemented by options with warning/error thresholds. Options without thresholds (boolean rules) do not implement this.

Note that whether a rule *supports* an override is no longer read off this interface. Support is **declared** by the rule itself, as a `SUPPORTS_THRESHOLD_OVERRIDE` constant, so the answer is available without inspecting the options class and can drive both the "did you mean" hint and the `annotation.unsupported-threshold` diagnostic. This interface remains the mechanism by which a supported override is applied.

**Methods:**
- `withOverride(int|float|null $warning, int|float|null $error): static` — returns a copy with overridden thresholds (null keeps original)

### AdditionalOptionKeysInterface

Declares top-level configuration keys that an Options class consumes in `fromArray()` but
which are neither constructor parameters nor threshold shorthands. `RuleOptionsFactory`
validates keys before creating the Options instance, so such classes return their canonical
kebab-case keys from `getAdditionalOptionKeys(): list<string>` to keep valid configuration
from producing an unknown-option warning. For bare threshold-style keys, use
`ShorthandOptionKeysInterface` instead; an Options class may implement both contracts.

### NameSelector

A user-authored selector over the rule / channel name space. Exactly two forms;
nothing is inferred from the number of dot-separated segments.

**Forms:**
- `X` — equality. `architecture.coverage` addresses that name and nothing else;
  it does **not** swallow `architecture.coverage.source`
- `X.*` — strict descendants of `X`; `X` itself is not included. A directive
  meaning both is written twice

Anything else is not a selector and `tryParse()` answers `null` for it — in
particular a bare prefix (`coupling`) and a lone `*`. Text that is not a
selector matches nothing.

**Methods:**
- `tryParse(string $raw): ?self` — the two accepted forms, or `null`
- `matches(string $subject): bool`
- `anyMatch(array $rawSelectors, string $subject): bool`
- `name(): string` — the `X` half
- `selectsDescendantsOnly(): bool` — whether this is the `X.*` form

### ChannelSelector

`NameSelector` plus the one thing a name alone cannot say: which rule the channel
belongs to. Every surface that reads the pair form reads this grammar — the three
inline suppression directives, the `exclude_namespace_channels` keys, and rule
selection (`only_rules` / `disabled_rules` / `--only-rule` / `--disable-rule`),
whose one-part branch is its own because selection deliberately matches a producer
name too. `@qmx-threshold` is absent on purpose: it addresses a rule, not a channel.

**Forms:**
- a one-part `NameSelector`, read against the channel's finding code
- `ruleName#violationCode` — both halves exact, no `*` in either. It is what
  distinguishes `a#x` from `b#x`, and the only way to address a channel whose
  `ruleName` half is not the rule that produces it

**Methods:**
- `tryParse(string $raw): ?self` — either form, or `null`
- `looksLikePair(string $raw): bool` — whether the text used the separator at all,
  so a parse failure can be explained rather than merely reported
- `matches(FindingChannel $channel): bool`
- `matchesNames(string $ruleName, string $code): bool` — the same question for
  callers holding the halves as strings, so the inline suppression filter does not build a
  channel per finding
- `exactChannel(): ?FindingChannel` — the pair form's channel
- `target(): NameSelector|FindingChannel` — the two forms as one total answer

### RuleSelector

The single selection policy used by rule execution, expensive prerequisite phases, and CLI
selector validation. It distinguishes a registered **producer rule** from the full
`FindingChannel` values that producer emits; their names need not share a prefix.

A one-part selector (`X` or `X.*`) can address the producer name, a channel's
`ruleName`, or its `code`. The explicit `ruleName#violationCode` form
addresses both channel components, and both halves are exact. `--only-rule=computed.health` therefore selects every channel produced by
that rule, while `--only-rule=health.complexity` selects only that computed channel and
still starts its `computed.health` producer.

`RuleChannelRegistryInterface::channelsProducedBy()` — one view of the channel universe — supplies the producer relationship.
Its Infrastructure implementation combines compiler-collected static declarations with
the run-time computed metric definitions. `InMemoryRuleChannelRegistry` provides the
same explicit contract where a composition root already owns the complete declaration map.

### RuleCategory (Enum)

Located in `Analysis\Finding\Contract\Rule`, not Core. How a rule is grouped
for **display** — `qmx rules --group` and report headings — and nothing else.
A category is not addressable: no directive or selector matches on it, and it
carries no methods. `qmx rules --group` is its sole consumer, reading
`RuleMetadata::$category->value` directly.

| Value             | Description                                            |
| ----------------- | ------------------------------------------------------ |
| `Complexity`      | CCN, NPath, Cognitive, WMC                             |
| `Size`            | MethodCount, ClassCount, PropertyCount                 |
| `Design`          | NOC, Inheritance, Type Coverage, Data Class, God Class |
| `Cohesion`        | LCOM                                                   |
| `Maintainability` | Maintainability Index                                  |
| `Coupling`        | Instability, CBO, Distance                             |
| `Architecture`    | Layer Policy Findings, Circular Dependencies           |
| `CodeSmell`       | Boolean Arguments, Debug Code, etc.                    |
| `Security`        | Hardcoded Credentials, Sensitive Parameter             |
| `Duplication`     | Code Duplication                                       |

The value happening to equal the first segment of a rule name (and
`computed.health` disagreeing with `Maintainability`) is a harmless
correlation nothing reads. Behavioural exemptions such as "always let
architecture findings through an `exclude_paths`/`exclude_namespaces` filter"
are declared per channel instead, see `ChannelFileScope` below.

---

## Symbol Contracts

### SymbolPath

Stable symbol identifier for baseline. Does not depend on line number. Located in `Core\Symbol` namespace.

**Fields:**
- `namespace: ?string` — `App\Service`
- `type: ?string` — `UserService` (class/interface/trait/enum)
- `member: ?string` — `calculateTotal` (method/function)

**Methods:**
- `toCanonical(): string` — canonical format for baseline

**Factory methods:**
- `forMethod(namespace, class, method): self`
- `forClass(namespace, class): self`
- `forNamespace(namespace): self` — use empty string for global PHP namespace
- `forProject(): self` — project-level (aggregated from all namespaces)
- `forFile(path): self`
- `forGlobalFunction(namespace, function): self`

**Canonical examples:**
- `App\Service\UserService::calculateTotal` — method
- `App\Service\UserService` — class
- `file:src/Service/UserService.php` — file
- `App\Service` — namespace
- `::globalFunction` — global function

### MetricSubjectCodec

`MetricSubjectCodec` is the canonical scalar wire grammar for metric subjects.
Collectors encode file, class, method, and function identity without serializing
paths or identity objects through IPC. Rules decode those components with the
authoritative container `RelativePath`.

`decodeEntry(array<string, scalar>, RelativePath): MetricSubject` is the DataBag
ingress. It selects exactly `subjectKind`, `logicalKind`, `namespace`, `class`,
`member`, and `collisionOrdinal`, retains only `int|string`, and
delegates all grammar validation to `decode()`. Unrelated entry data is ignored;
a retained bool or float is dropped and therefore fails when the component is
required. Entry data can never replace the caller-supplied container path.

## Finding Values and Filtering

Finding owns `Finding`, `Severity`, `Location`, `OccurrenceKey`,
`FindingChannel`, channel declarations, filtering contracts, and rule-exclusion
capture. Their current definitions and lifecycle are documented in
[`Analysis/Finding`](../Analysis/Finding/README.md).

### Finding

A rule finding.

**Fields:**
- `location: Location`
- `symbolPath: SymbolPath`
- `ruleName: string`
- `code: string` — stable machine identifier for baseline hashing
- `message: string`
- `severity: Severity`
- `metricValue: int|float|null` — metric value (for reports)
- `relatedLocations: list<Location>` — additional locations related to this finding (e.g., other occurrences of duplicated code)
- `recommendation: ?string` — human-readable message for summary/detail formatters (e.g., "Cyclomatic complexity: 15 (threshold: 10) — too many code paths")
- `threshold: int|float|null` — threshold that was exceeded (for programmatic comparison)
- `dependencyTarget: ?SymbolPath` — target symbol of the offending dependency edge (for dependency-based rules such as `architecture.layer-violation`); target presence defines whether an edge exists
- `dependencyType: ?DependencyType` — optional reference type for a target-bearing edge; a target without a type is a valid untyped edge
- `occurrenceKey: ?OccurrenceKey` — stable semantic discriminator for repeated findings on one channel and subject

**Methods:**
- `getFingerprint(): string` — formatter identity built from channel, exact subject, optional occurrence, and optional edge. No-edge and fully typed edge bytes retain their established forms; a target-only edge uses the collision-safe `untyped-edge:<byte-length>:<canonical-target>` component. Baseline identity is owned separately by `BaselineIdentity`.
- `channel(): FindingChannel` — the `(ruleName, code)` pair this finding was emitted on

### OccurrenceKey

Immutable semantic discriminator for occurrence-shaped findings that share a
channel and metric subject. `semantic(string $kind, array $scalarEvidence):
self` sorts the named evidence, serializes `{kind, evidence}` with stable JSON
flags, and exposes the resulting 64-character SHA-256 digest through the
readonly `value` field. The kind and every evidence name must be non-empty;
evidence values are scalar. Invalid input throws `InvalidArgumentException`.

The type depends only on PHP scalar/JSON/hash primitives and
`InvalidArgumentException`; it does not depend on Baseline or Reporting.
`OccurrenceKeyTest` directly covers order-independent canonicalization,
kind/evidence separation, digest shape, and an empty-kind rejection. Baseline identity and
serialization are integration consumers, not owners of this contract.

### FindingChannel

The address of a *kind* of finding: a `(ruleName, code)` pair that can appear on an emitted `Finding`.

Channels are **not** in bijection with rule classes, which is why nothing downstream may key on a rule class or on a rule name alone:

- one rule class can emit several channels, some under rule names no class declares as its own (`LayerViolationRule` emits `architecture.coverage`, `architecture.unreachable-layer`, `architecture.potential-shadow`, `architecture.empty-template` besides its own name);
- one rule class can emit one channel per configured definition (`ComputedMetricRule`, one per `health.*` / `computed.*` metric), each with its own thresholds and inversion;
- one rule class can emit one channel whose boundaries depend on the symbol (`LongParameterListRule`).

**Fields:** `ruleName: string`, `code: string` (both non-empty).

**Methods:**
- `equals(self $other): bool`
- `toKey(): string` — stable string form, suitable as an array key

Read the channel of an emitted finding via `Finding::channel()`. There is deliberately no `FindingChannel::fromFinding()` factory — the pair would form a dependency cycle (caught by `architecture.circular-dependency` during dogfooding), and the direction that survives is the one where the richer type knows the primitive.

### ChannelShape (Enum)

What a channel's `Finding::$metricValue` means for baseline purposes — never a rule's options or its severity ladder.

| Value        | Description                                                                                                   |
| ------------ | ------------------------------------------------------------------------------------------------------------- |
| `Magnitude`  | The reported value is a real measured magnitude — a boundary a later run's value can be compared against.     |
| `Occurrence` | The reported value, if any, is not a magnitude (a fixed marker such as `1.0`, or absent). Only count matters. |

### ChannelDeclaration

The two facts a channel declares for baseline purposes: its shape, and — for a `magnitude` shape only — the `WorseDirection` that makes its reported number comparable. Nothing else: no axis name, no threshold binding, no epsilon.

**Fields:** `shape: ChannelShape`, `direction: ?WorseDirection`.

**Invariant** (enforced in the constructor): a direction is present *exactly* when the shape is `Magnitude`. A `Magnitude` declaration without a direction, or an `Occurrence` declaration carrying one, throws `InvalidArgumentException`.

**Factory methods:**
- `magnitude(WorseDirection $direction): self`
- `occurrence(): self`

A channel that declares no `ChannelDeclaration` at all is not an error — it is simply not baselineable. Finding owns declaration discovery and the channel registry, including the run-time `computed.*` / `health.*` family.

### FindingFilterInterface

Foundation for baseline and suppression.

**Methods:**
- `shouldInclude(Finding $finding): bool` — whether to include finding in the report

### PathExclusionFilter

Suppresses findings whose file path matches configured exclusion patterns (the global `exclude_paths` / `--exclude-path` mechanism). Findings without a file (e.g., namespace-level or project-wide architectural diagnostics) are never filtered. Findings on a channel its owner declared **project-scoped** (e.g. `architecture.*`) are always exempt for the same reason as `NamespaceExclusionFilter` below — the exemption is declared per channel via `ChannelFileScope`, not derived from the rule name's spelling.

**Constructor:** `__construct(PathMatcher $pathMatcher)`

### NamespaceExclusionFilter

Suppresses findings whose symbol namespace matches configured exclusion patterns (the global `exclude_namespaces` / `--exclude-namespace` mechanism). `architecture.*` rule findings (e.g., `architecture.layer-violation`, `architecture.circular-dependency`) are always exempt — a layer-policy finding is not a metric, so a namespace exclusion aimed at quieting noisy metrics must not double as a silent way to disable architecture enforcement. The exemption is **declared per channel**, not derived from the `architecture.` spelling: each capability publishes its project-scoped channel keys (`LayerPolicyPreparationInterface::PROJECT_SCOPED_CHANNELS`, `CircularDependencyPreparationInterface::PROJECT_SCOPED_CHANNELS`) and the filter consults `ChannelFileScope`. A channel nobody declared is file-scoped, which is the right default for the open `computed.*` vocabulary. Occurrence-style findings (code-smell, security) carry a file symbol path whose namespace is `null`; the filter falls back to the declaring namespace on `Finding::$subject` so those findings are still suppressible per namespace.

**Constructor:** `__construct(NamespaceMatcher $namespaceMatcher)`

### RuleExclusionCaptureHolder

Finding-owned static holder controlling whether `Analysis\Finding\RuleExecution`
retains individual `Finding` objects suppressed by per-rule
`exclude_namespaces` / `exclude_paths`, rather than only their counts. It
defaults to `false`; `Infrastructure\Console\AnalysisRuntimeConfigurator`
sets it from `--show-suppressed` before the analysis pipeline runs. This keeps
the CLI option at the Infrastructure boundary and the capture state with
Finding.

**Methods:**
- `set(bool $enabled): void`
- `isEnabled(): bool`
- `reset(): void` — restores the default (disabled)

---

## Dependency Contracts

Dependency edges, types, graph queries, location and builder contracts moved to
[`Analysis/Evidence/DependencyModel`](../Analysis/Evidence/DependencyModel/README.md)
in P2. P4 moved cycle values and their preparation to
[`Analysis/Evidence/CircularDependency`](../Analysis/Evidence/CircularDependency/README.md).

---

## Progress and profiling boundaries

Collection progress is a Run-owned consumer port implemented by the Console
adapter. The Console switch is instance-owned and resets to no-op for quiet,
non-TTY, and `--no-progress` runs; Core has no progress type.

`Core\Profiler\Contract\ProfilerInterface` contains only neutral
instrumentation operations (`start()` and `stop()`). Profiling session state,
spans, summaries, and exports belong to Infrastructure Profiler. The
per-container session is disabled by default and exposes distinct control/report
contracts to Console; no holder or public no-op implementation exists.

---

## Utility Classes

### StringSet

An immutable set of unique strings with O(1) lookups. Implements `Countable` and `IteratorAggregate`.

**Methods:**
- `add(string $value): self` — new set with the value added
- `addAll(iterable $values): self` — new set with multiple values added
- `contains(string $value): bool` — check membership
- `count(): int` — number of unique strings
- `isEmpty(): bool` — whether set is empty
- `toArray(): array<int, string>` — all strings as indexed array
- `filter(callable $predicate): self` — filter by predicate
- `union(self $other): self` — set union
- `intersect(self $other): self` — set intersection
- `diff(self $other): self` — set difference
- `fromArray(array $values): self` — create from array (static)

### PathMatcher

Matches file paths against patterns. Supports two modes per pattern: prefix matching (no glob characters — `src/Entity` matches all files under it) and glob matching (with `*`, `?`, `[` — `src/Metrics/*Visitor.php`). Used for `exclude_paths` configuration.

**Constructor:** `__construct(list<string> $patterns)`

**Methods:**
- `matches(string $filePath): bool` — whether path matches any pattern
- `isEmpty(): bool` — whether no patterns are configured

### NamespaceMatcher

Matches namespaces against patterns. Same dual-mode logic as `PathMatcher` but uses `\` as boundary separator, and a trailing `\` in a pattern is cosmetic (`App\Entity\` ≡ `App\Entity`) — normalization lives in `matchesSingle()`, so every caller gets it. Used for `exclude_namespaces`, the `--namespace` selector, health drill-down, worst-offender lists, `coupling.distance`'s `include_namespaces` and layer-policy `patterns:`.

**Constructor:** `__construct(list<string> $patterns)`

**Methods:**
- `matches(string $namespace): bool` — whether namespace matches any pattern
- `isEmpty(): bool` — whether no patterns are configured

---

## Finding Control Values

Finding owns `ControlScope` and `ThresholdOverride` under its `Contract`
surface. Inline extracts those values from source annotations, while Finding
applies them when it resolves effective rule thresholds. See
[`Analysis/Policy/Inline`](../Analysis/Policy/Inline/README.md) for extraction
and suppression lifecycle.

### ControlScope (Enum)

Declaration-ranked scope carried by symbol suppressions and threshold
overrides. Its cases are `Hook`, `Property`, `Callable`, and `Class_`;
`specificity(): int` returns `4`, `3`, `2`, and `1` respectively. Physical
file and next-line controls remain represented by `SuppressionType` and never
enter this precedence enum.

`ControlScope` has no dependencies beyond PHP. `DeclarationBindingsTest` and
`PropertyHookControlPrecedenceTest` cover production binding and the exact
hook-over-property-over-class precedence, while `SourceControlsTest` verifies
that controls with different declaration scopes remain distinct.

### Suppression

Value Object representing a suppression tag from a docblock (e.g., `@qmx-ignore complexity.wmc Reason`). The authored text names a channel exactly, or `X.*` for its strict descendants; a bare prefix such as `complexity` is rejected.

**Fields:**
- `rule: string` — the authored text: a fully qualified `code`, `X.*`, or `*` for "no rule filter"
- `reason: ?string` — optional reason for suppression
- `line: int` — line number of the suppression tag
- `type: SuppressionType` — scope of suppression
- `endLine: ?int` — end line for scoped suppressions

**Methods:**
- `matches(string $code): bool` — checks if suppression applies to a finding code
- `target(): SuppressionTarget` — what the directive filters on: a `ChannelSelector`, or the
  explicit "no rule filter" state that `@qmx-ignore *` and a bare `@qmx-ignore-file` carry

### SuppressionType (Enum)

Defines the scope of a suppression tag.

| Value      | Description                                      |
| ---------- | ------------------------------------------------ |
| `Symbol`   | Suppress at symbol level (class/method docblock) |
| `NextLine` | Suppress the next line only                      |
| `File`     | Suppress all matching findings in entire file    |

### ThresholdOverride

Value Object representing a `@qmx-threshold` annotation from a docblock. Allows per-symbol threshold overrides.

**Syntaxes:**
- Shorthand: `@qmx-threshold complexity.cyclomatic 15` (sets both warning and error)
- Explicit: `@qmx-threshold complexity.cyclomatic warning=15 error=25`
- Partial: `@qmx-threshold complexity.cyclomatic warning=15` (override warning only)
- Explicit keys may appear in either order; only the generic `warning` and `error` keys are accepted
- Values are non-negative integers or decimals
- An optional non-empty reason must follow `--` or an em dash (`—`)

Only the **exact rule name** is supported. A threshold belongs to one rule's options object, so
there is no group form at all — neither a bare prefix (`complexity`) nor `complexity.*` nor `*`
addresses anything, and an annotation written that way applies to no rule. A class annotation
applies to evaluations inside the class, including its methods. When annotations overlap, the smallest source span wins; the first extracted
annotation wins when spans are equal.

**Fields:**
- `rulePattern: string` — the exact rule name
- `warning: int|float|null` — warning threshold override (null = keep default)
- `error: int|float|null` — error threshold override (null = keep default)
- `line: int` — docblock line (for scope matching)
- `endLine: ?int` — symbol end line (scope)

**Methods:**
- `matches(string $ruleName): bool` — string equality against the rule name

---

## Computed Metrics

Formula definitions, defaults, evaluation, Health semantics, and their public
contracts belong to
[`Analysis\\Evidence\\ComputedMetrics`](../Analysis/Evidence/ComputedMetrics/README.md).
Core contains no computed-metric holder or capability-specific value type.

---

## Observation Contracts

### WorseDirection

`Higher` (larger is worse — CCN, CBO) or `Lower` (smaller is worse — Maintainability Index, cohesion, health scores). Per axis, not per rule.

Carries the two comparison operators, so consumers do not each re-derive their own sign handling:

- `morePermissive(int|float $a, int|float $b): int|float` — the primitive behind `baseline:update` (ADR 0017): a boundary may move only toward stricter, never toward more permissive
- `isWorse(int|float $current, int|float $allowance, float $epsilon = 0.0): bool` — the comparison behind an entry's group-acceptance decision (ADR 0017)

Epsilon is a tolerance band around the allowance, never a shift of it: inside the band a value is not worse.

`morePermissive()`'s result type does not depend on argument order, even when the two boundaries are numerically equal but differ in `int`/`float` type: the result is written to the baseline file (ADR 0017), whose byte-stability contract leaves no room for `morePermissive(10, 10.0)` and `morePermissive(10.0, 10)` to disagree. A tie normalizes to `int` only when both inputs are `int`, and to `float` the moment either one is.

---

## Other Contracts

### FileParserInterface

**Methods:**
- `parse(SplFileInfo $file): array<Node>` — parse PHP file into AST
- `parseContent(SplFileInfo $file, string $content): array<Node>` — parse an already-read source snapshot while retaining the original file for diagnostics
- Throws: `ParseException`

### NamespaceDetectorInterface

**Methods:**
- `detect(SplFileInfo $file): string` — detect file namespace (empty string for global)

### ProjectNamespaceResolverInterface

Determines whether a namespace belongs to the project (not an external dependency).

**Methods:**
- `isProjectNamespace(string $namespace): bool` — check if namespace belongs to the project
- `getProjectPrefixes(): list<string>` — list of detected prefixes (without trailing backslash)

### ParseException

**Fields:**
- `file: string` — path to the file with error
- `message: string` — parse error description

---

## Info Classes for Iterators

### SymbolInfo

**Fields:**
- `symbolPath: SymbolPath`
- `file: ?RelativePath`
- `line: ?int`

---

## Implementation Stages

### Steps

1. [x] Severity enum
2. [x] RuleCategory enum
3. [x] Location VO
4. [x] SymbolPath VO
5. [x] Finding VO
6. [x] MetricBag VO
7. [x] AggregationStrategy enum
8. [x] SymbolLevel enum
9. [x] MetricDefinition VO
11. [x] MetricCollectorInterface (with getMetricDefinitions)
12. [x] MetricRepositoryInterface (unified MetricBag)
13. [x] RuleInterface
14. [x] FileParserInterface
15. [x] NamespaceDetectorInterface
16. [x] FindingFilterInterface
17. [x] ParseException
18. [x] Unit tests

### Definition of Done

- All contracts and VOs are created
- Unit tests for SymbolPath::toCanonical()
- Unit tests for MetricBag::merge()
- Unit tests for Finding::getFingerprint()
- Unit tests for MetricDefinition::aggregatedName()
- PHPStan level 8 with no errors

## Locality

Core publishes only neutral primitives that lack a natural leaf owner. Any new
Core type must satisfy that ownership test and have subject-owned tests and
documentation.

---

## Edge Cases

- Location with null line — display only file
- `Location::none()` — architectural findings without a file; formatters must check `isNone()`
- Global namespace — empty string
- SymbolPath with null namespace — starts with `::` for global functions
- MetricBag::get() for non-existent metric — null
- MetricBag::merge() with key conflict — value from `$other`
