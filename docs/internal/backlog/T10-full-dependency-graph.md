# T10: Full Dependency Graph Always

**Proposal:** #14 | **Priority:** Batch 5 (architecture) | **Effort:** ~12h | **Dependencies:** none (blocks T11)

## Motivation

When analyzing a subset of files (`--analyze=git:staged`), the dependency graph is built only from
analyzed files. This makes afferent couplings (Ca) invisible, causing:
- Instability = Ce / (Ca + Ce) = always 1.0 (Ca=0)
- Distance from Main Sequence = incorrect
- ClassRank (PageRank) = incorrect (graph is incomplete)
- Abstractness = potentially incorrect (missing implementations)

**Principle:** File scope filtering should affect **reporting** (which violations to show), not
**collection** (which metrics to compute). The graph must always be complete.

## Current Data Flow

```
CheckCommand
  → GitScopeResolver.resolve()
    → GitFileDiscovery (returns ONLY scoped files)
  → AnalysisPipeline.analyze(paths, discovery)
    → discovery.discover(paths) → iterable<SplFileInfo>  ← ONLY SCOPED FILES
    → CollectionOrchestrator.collect(files, repository)   ← PARSES ONLY SCOPED
    → DependencyGraphBuilder.build(dependencies)          ← INCOMPLETE GRAPH
    → MetricEnricher.enrich()
    → RuleExecutor.execute(context)
  → ViolationFilterOrchestrator
    → ViolationFilterPipeline.filter(violations)          ← FILTERS BY BASELINE/SUPPRESSION/GIT
  → ResultPresenter.presentResults(result, partialAnalysis=true)
    → SummaryEnricher.enrich(report, partialAnalysis=true)  ← SKIPS SOME SECTIONS
    → Formatter.format(report, context)
```

**Key constraint:** `partialAnalysis` flag (set when `analyzeScope !== null`) suppresses health
scores, worst offenders, and other aggregate sections in formatters.

## Proposed Data Flow

```
CheckCommand
  → GitScopeResolver.resolve()
    → scopeFiles: list of in-scope files (for reporting filter)
    → FinderFileDiscovery (returns ALL project files)  ← CHANGED
  → AnalysisPipeline.analyze(paths, discovery)
    → discovery.discover(paths) → ALL PHP files        ← FULL COLLECTION
    → CollectionOrchestrator.collect(files, repository) ← PARSES ALL (with cache)
    → DependencyGraphBuilder.build(dependencies)        ← COMPLETE GRAPH
    → MetricEnricher.enrich()
    → RuleExecutor.execute(context)                     ← ALL VIOLATIONS GENERATED
  → ViolationFilterOrchestrator
    → ViolationFilterPipeline.filter(violations)
      → NEW: ScopeFilter (keep only violations for scoped files)  ← CHANGED
  → ResultPresenter.presentResults(result, partialAnalysis=false) ← CHANGED
    → SummaryEnricher.enrich(report, partialAnalysis=false)       ← FULL HEALTH
    → Formatter.format(report, context)
```

## Design

### 1. Scope Resolution — Two File Sets

**File:** `src/Infrastructure/Git/GitScopeResolver.php`

Change `resolve()` to return both:
- `fullDiscovery: FileDiscoveryInterface` — `FinderFileDiscovery` for ALL project files (always)
- `scopeFiles: ?Set<string>` — set of absolute file paths that are "in scope" (null = all in scope)

```php
// GitScopeResolution — extend or replace current return type
final readonly class GitScopeResolution {
    public function __construct(
        public FileDiscoveryInterface $discovery,    // Always FinderFileDiscovery (full)
        public ?array $scopeFilePaths = null,        // null = no scope filter (full analysis)
        public ?GitScope $analyzeScope = null,
        public ?GitScope $reportScope = null,
    ) {}
}
```

When `--analyze=git:staged`: `discovery` = Finder (all files), `scopeFilePaths` = git staged files.
When no `--analyze`: `discovery` = Finder (all files), `scopeFilePaths` = null (everything in scope).

### 2. Pipeline — Always Full Collection

**File:** `src/Analysis/Pipeline/AnalysisPipeline.php`

No changes to `analyze()` itself — it already uses `discovery.discover()` and processes all files.
The change is that `discovery` is now always `FinderFileDiscovery`, so all files are collected.

### 3. Cache Interaction — Critical for Performance

**File:** `src/Infrastructure/Ast/CachedFileParser.php`

The cache uses file path + mtime + content hash as key (`CacheKeyGenerator`). This means:
- **Unchanged files** (not in git scope) will be **cache hits** if a previous full run exists
- **First scoped run** without cache: ALL files parsed (slow, but correct)
- **Subsequent runs**: only changed files re-parsed, rest from cache

**Performance mitigation:** For `--analyze=git:staged` on a large project:
- First run: ~same time as full analysis (all files parsed, cached)
- Subsequent runs: fast (most files cached, only staged files re-parsed)
- This is acceptable because correctness > speed, and cache amortizes the cost

### 4. Scope Filtering — New ScopeFilter

**File:** `src/Infrastructure/Console/ViolationFilterPipeline.php` (or new `ScopeFilter`)

Add a new filter step AFTER baseline/suppression/path filters:

```php
// In ViolationFilterPipeline — new filter step
if ($this->scopeFilePaths !== null) {
    $violations = array_filter($violations, fn(Violation $v) =>
        in_array($v->location->file, $this->scopeFilePaths, true)
    );
}
```

Use a `Set` (or pre-built hashmap) for O(1) lookup, not `in_array`.

**Filter order (updated):**
1. Baseline (known violations)
2. Suppression (@qmx-ignore tags)
3. Path exclusion (--exclude-path)
4. Git scope (--report=git:...)
5. **NEW: Analysis scope** (--analyze=git:... → scopeFilePaths)

### 5. partialAnalysis Flag — Eliminated or Redefined

**Current:** `$partialAnalysis = $scopeResolution->analyzeScope !== null`

**After T10:** Since we always build the full graph, `partialAnalysis` should be `false` for
scoped analysis. The graph IS complete, metrics ARE correct. The only "partial" aspect is that
violations are filtered to scoped files.

**Option A (recommended):** Set `partialAnalysis = false` always. Remove conditional sections
in formatters that check it. Worst offenders / health scores are computed from full graph.

**But:** Worst offenders and top issues should still be filtered to show only scoped items.
Otherwise `--analyze=git:staged` shows the project's worst class (which may not be in the diff).

**Solution:** Introduce `scopedReporting` flag instead:
- `partialAnalysis` → removed (always false)
- `scopedReporting` → true when `--analyze` is set
- Formatters use `scopedReporting` to filter worst offenders to scope, but health scores
  are computed from full graph (since metrics are complete)

**File:** `src/Reporting/FormatterContext.php`

```php
// Replace partialAnalysis with scopedReporting
public bool $scopedReporting = false,
public ?array $scopeFilePaths = null,  // For filtering worst offenders
```

### 6. Summary/Worst Offenders Filtering

**Files:**
- `src/Reporting/Health/SummaryEnricher.php`
- `src/Reporting/Formatter/Summary/SummaryFormatter.php`

When `scopedReporting = true`:
- **Health scores**: computed from FULL graph (correct aggregate metrics)
- **Worst namespaces/classes**: filtered to only those containing scoped files
- **Top issues**: filtered to scoped violations only
- **File count header**: show "N files analyzed (M in scope)" instead of just "N files analyzed"

## Files to modify

| File                                                         | Change                                                              |
| ------------------------------------------------------------ | ------------------------------------------------------------------- |
| `src/Infrastructure/Git/GitScopeResolver.php`                | Return full discovery + scope file set                              |
| `src/Infrastructure/Git/GitScopeResolution.php`              | Add `scopeFilePaths` field                                          |
| `src/Infrastructure/Console/Command/CheckCommand.php`        | Pass scope to filter pipeline                                       |
| `src/Infrastructure/Console/ViolationFilterPipeline.php`     | Add scope filter step                                               |
| `src/Infrastructure/Console/ViolationFilterOrchestrator.php` | Pass scope files                                                    |
| `src/Reporting/FormatterContext.php`                         | Replace `partialAnalysis` with `scopedReporting` + `scopeFilePaths` |
| `src/Infrastructure/Console/FormatterContextFactory.php`     | Create context with new fields                                      |
| `src/Infrastructure/Console/ResultPresenter.php`             | Use scopedReporting instead of partialAnalysis                      |
| `src/Reporting/Health/SummaryEnricher.php`                   | Remove partialAnalysis guard, always compute health                 |
| `src/Reporting/Formatter/Summary/SummaryFormatter.php`       | Filter worst offenders by scope                                     |
| `src/Reporting/Formatter/Json/JsonFormatter.php`             | Filter worst offenders by scope                                     |
| `src/Reporting/Formatter/Html/HtmlFormatter.php`             | Remove partialAnalysis guard                                        |
| Tests: scoped analysis integration tests                     | Full graph + scoped violation filtering                             |
| `src/Analysis/README.md`                                     | Document the principle                                              |

## Acceptance criteria

- [ ] `--analyze=git:staged` shows violations only for staged files
- [ ] Instability metrics for staged files reflect full project graph (Ca ≠ 0)
- [ ] ClassRank for staged files reflects full project graph
- [ ] Distance from Main Sequence is correct for scoped analysis
- [ ] Health scores are computed from full graph even in scoped mode
- [ ] Worst offenders are filtered to scoped files only
- [ ] `--analyze=git:staged` with cache is fast (only changed files re-parsed)
- [ ] Full analysis (`bin/qmx check src/`) behavior unchanged
- [ ] `partialAnalysis` flag removed from codebase
- [ ] PHPStan passes, tests pass

## Edge cases

- `--analyze=git:staged` with no staged files → empty violations, full graph built, health shown
- New file in staged (not yet in project) → included in both graph and scope
- Deleted file in staged → excluded from graph and scope
- `--analyze=git:main..HEAD` → scope = files changed in range, graph = all files
- `--report=git:main..HEAD` (reporting filter only) → already works at filter level, no change
- No cache exists → first scoped run is slow (full parse), subsequent runs fast
- `--workers=0` (no parallelism) + full graph → slower but correct

## Risk

**Performance regression** for `--analyze=git:staged` on large projects without cache.
Mitigation: cache amortizes the cost. Document in CLI help that first scoped run may be slower.

**Behavioral change:** Users (when they exist) may see different results for scoped analysis:
- Fewer false instability violations (Ca now visible)
- Health scores now shown (were hidden in partial mode)
- Worst offenders filtered to scope (was showing nothing)

**Scope of change:** Touches core pipeline data flow. Requires careful integration testing across
all analysis modes (`--analyze`, `--report`, full, git:staged, git:main..HEAD).
