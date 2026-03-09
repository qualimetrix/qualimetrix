# Technical Debt & Known Limitations

For the strategic product roadmap (features, rules, competitive positioning), see [docs/internal/PRODUCT_ROADMAP.md](internal/PRODUCT_ROADMAP.md).

## Known Limitations & Future Improvements

### MetricBag: Support for Non-Numeric Data

**Priority**: Medium | **Effort**: Large

`MetricBag` stores only `int|float` values. Several collectors need to pass richer data (line numbers per occurrence, dependency lists, pattern names). Current workarounds:

- **Indexed keys hack** (`line.0`, `line.1`, `pattern.0`) — used by `HardcodedCredentialsCollector`, `CodeSmellCollector`. Works but is fragile, non-type-safe, and prevents proper aggregation of such metrics.
- **Side-channels** (`MethodWithMetrics`, `ClassWithMetrics`) — ad-hoc, not unified.

**Options to explore**:
- `DataBag` companion object alongside `MetricBag` in collection results
- `MetricBag::withData(string $key, mixed $value)` / `getData()` for non-numeric data
- Typed DTO repositories per collector category

**Impact**: Would simplify `HardcodedCredentialsCollector`, `CodeSmellCollector`, and any future collectors that need to pass structured data to rules.

---

### Collector Runtime Configuration

**Priority**: Medium | **Effort**: Medium

Collectors are created at DI compile time and have no access to runtime configuration. Rules receive options via `RuleOptionsCompilerPass`, but collectors don't. This means filtering logic (e.g., `exclude_namespaces`) can only be applied post-factum in rules, not during collection.

**Impact**: Duplicate `exclude_namespaces` handling in `InstabilityRule`, `CboRule`, `DistanceRule` options. If a collector could be configured, exclusions would happen earlier and more efficiently.

**Options to explore**:
- Inject `ConfigurationProviderInterface` into collectors that need it
- Collector-specific options via a `CollectorOptionsCompilerPass` (analogous to `RuleOptionsCompilerPass`)

---

### InheritanceDepthVisitor: `use ... as` Alias Resolution

**Priority**: Low | **Effort**: Small

`InheritanceDepthVisitor::resolveClassName()` does not track PHP `use ... as ...` import statements. When a class extends an aliased name (`class X extends BaseAlias`), the visitor resolves it as `{currentNamespace}\BaseAlias` instead of the actual imported FQN. DIT falls back to 1 (unknown external parent).

**Fix**: Prepend php-parser's `NameResolver` visitor before `InheritanceDepthVisitor` in the traversal, or track `use` statements manually.

---

### Global Function Metrics Aggregation

**Priority**: Low | **Effort**: Small

Global functions (`SymbolType::Function_`) are not aggregated to namespace or project level. `MethodToClassAggregator` explicitly skips symbols without a class (`$path->type === null`). Function-level metrics (CCN, NPATH, etc.) remain only at the function level.

**Options to explore**:
- `FunctionToNamespaceAggregator` that rolls up function metrics directly to namespace level
- Include functions in a virtual "global scope" class per namespace

---

### Metric Name Constants

**Priority**: Low | **Effort**: Medium

Metric names (`ccn.max`, `classCount.sum`, `cbo.avg`) are hardcoded as strings in rules. No compile-time link to `MetricDefinition`. Renaming a metric in a collector silently breaks rules that reference it.

**Options to explore**:
- Constants on collector classes for metric names (e.g., `CyclomaticComplexityCollector::METRIC_CCN`)
- Generated constants from `MetricDefinition` aggregation patterns
- Static analysis rule (custom PHPStan rule) to verify metric name references

---

### Formatter LCOM / SRP

**Priority**: Low | **Effort**: Medium

All 7 formatters have high LCOM (5-11) due to mixing protocol methods, data transformation, and rendering. `ViolationSorter` has 6 disconnected static methods. `AnsiColor` is an infrastructure utility in the Reporting namespace.

**Options to explore**:
- Extract shared formatting helpers (grouping, sorting) into separate services
- Move `AnsiColor` to `Infrastructure/Output/`
- Apply Strategy pattern to `ViolationSorter`
