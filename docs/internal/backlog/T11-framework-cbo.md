# T11: Framework CBO Distinction

**Proposal:** #3 | **Priority:** Batch 5 (architecture) | **Effort:** ~6h | **Dependencies:** T10

## Motivation

Importing 50 `PhpParser\Node\*` types counts the same as depending on 50 application services.
Framework coupling is structural (can't be eliminated without changing framework), while application
coupling is architectural (should be minimized). Separating them makes CBO actionable.

## Design

### New metrics (not replacing CBO)

Per Metrics Policy, CBO must stay faithful to the original formula. Add supplementary metrics:

| Metric         | Formula                         | Purpose                      |
| -------------- | ------------------------------- | ---------------------------- |
| `CBO`          | Unchanged (Ca + Ce)             | Original Chidamber & Kemerer |
| `CBO_APP`      | Ca_app + Ce_app                 | Application-only coupling    |
| `CE_FRAMEWORK` | Count of framework dependencies | Informational                |

### Framework namespace configuration

```yaml
# qmx.yaml
coupling:
  framework-namespaces:
    - PhpParser
    - Symfony
    - Psr
    - Amp
```

**Config injection into collector:** Collectors in Metrics domain don't receive rule configuration
directly. The `framework-namespaces` list should be injected via a DI parameter or a dedicated
`CouplingConfiguration` VO resolved in a `ConfigurationStage`. The collector receives it through
constructor injection — no dependency on Configuration domain, just a plain VO from Core.

**Namespace matching:** Use boundary-aware prefix matching: `str_starts_with($fqcn, $prefix . '\\')`
to prevent `Psr` from matching `PsrExtended\Custom`.

**Auto-detection (optional enhancement):** Parse `composer.json` → `require` → extract top-level
namespaces from `vendor/*/composer.json` autoload. Not for v1 — config-only is sufficient.

### Rule behavior

The coupling rule (`coupling.cbo`) gains a new option:

```yaml
coupling.cbo:
  scope: application  # 'all' (default, uses CBO) | 'application' (uses CBO_APP)
```

When `scope: application`, thresholds apply to `CBO_APP` instead of `CBO`.

## Files to modify

| File                                            | Change                                          |
| ----------------------------------------------- | ----------------------------------------------- |
| `src/Metrics/Coupling/CouplingCollector.php`    | Split Ce into framework/application             |
| `src/Core/Metric/MetricName.php`                | Add `COUPLING_CBO_APP`, `COUPLING_CE_FRAMEWORK` |
| `src/Rules/Coupling/CboRule.php` (or similar)   | Add `scope` option                              |
| `src/Configuration/`                            | Parse `framework-namespaces` from config        |
| `src/Analysis/Aggregator/AggregationHelper.php` | Aggregate new metrics                           |
| Tests                                           | CBO_APP calculation tests                       |
| Website: CBO documentation (EN + RU)            | Document framework distinction                  |

## Acceptance criteria

- [ ] `CBO` metric unchanged (backward compatible)
- [ ] `CBO_APP` excludes dependencies on configured framework namespaces
- [ ] `CE_FRAMEWORK` counts framework-only outgoing dependencies
- [ ] `Ce = Ce_app + Ce_framework` (outgoing dependencies partition cleanly)
- [ ] `Ca_app ≤ Ca` (afferent from app only, framework classes are not scanned)
- [ ] Config `framework-namespaces` is optional — if absent, CBO_APP = CBO
- [ ] `coupling.cbo.scope: application` uses CBO_APP for violation thresholds
- [ ] JSON metrics output includes all three metrics
- [ ] PHPStan passes, tests pass

## Edge cases

- Class depends on both framework and app types → CBO includes all, CBO_APP excludes framework
- Bidirectional coupling: A→FrameworkClass and FrameworkClass→A → CBO counts 1, CBO_APP counts 0
- Framework namespace is a prefix: `PhpParser` matches `PhpParser\Node\Stmt\Class_`
- Empty `framework-namespaces` config → CBO_APP = CBO (no framework exclusion)
- User's own namespace starts with framework prefix (unlikely but possible) → user must configure correctly
- Vendor classes without namespace (rare) → treated as application by default
- Import aliases (`use PhpParser\Node as N`) → resolved to original FQCN before matching
