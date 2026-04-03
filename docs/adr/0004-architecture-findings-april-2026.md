# 0004. Architecture Findings (April 2026)

**Date:** 2026-04-03
**Status:** Accepted

## Context

During user-perspective testing and bug fixing of CLI options (`--cache-dir`, `--class`, `--log-level`), four architectural issues were discovered. This ADR captures the decisions made to resolve them and the principles they established.

## Decision

### 1. Lazy Command Loading (Eager DI Resolution)

**Problem:** `bin/qmx` eagerly called `$container->get()` for all 7 commands at startup, forcing instantiation of the entire dependency tree even for lightweight operations like `--help` or `hook:status`. Any config-dependent service that memoized values during construction would silently ignore CLI options set later.

**Fix:** Replaced eager command instantiation with Symfony's `ContainerCommandLoader`. Commands are now only instantiated when actually executed.

**Tactical fix preserved:** `CacheFactory` still uses the factory + memoization pattern for deferred cache creation, as defense-in-depth against config-dependent services reading defaults too early.

**Principle:** Config-dependent services must defer reads to method calls, never constructor.

### 2. Cross-Cutting Concerns in Pipeline Layer

**Problem:** `--class` and `--namespace` drill-down filters were implemented at the formatter level. Only 2 of 11 formatters actually filtered — the rest showed unfiltered data.

**Fix:** Moved filtering to `ResultPresenter.presentResults()`, the central point where violations flow to formatters. All formatters now receive pre-filtered data.

**Principle:** Formatters are pure renderers. Cross-cutting concerns (filtering, sorting, truncation) belong in the pipeline layer above.

### 3. PSR-3 Message Interpolation

**Problem:** `ConsoleLogger` and `FileLogger` appended context as raw JSON instead of interpolating `{placeholder}` tokens per the PSR-3 specification.

**Fix:** Created `LoggerHelperTrait` with `interpolate()` (per PSR-3 recommendation) and `meetsMinLevel()` (shared level filtering). Both loggers now use this trait, eliminating duplicated `shouldLog()` logic.

### 4. YAGNI Infrastructure (Storage Module)

**Problem:** A complete SQLite metric storage system (6 classes: `StorageInterface`, `SqliteStorage`, `InMemoryStorage`, `StorageFactory`, `ChangeDetector`, `FileRecord`) plus `CachedCollector` was fully implemented with tests but never wired into DI or used in production.

**Fix:** Deleted the entire `src/Infrastructure/Storage/` directory, `CachedCollector`, and associated tests. Removed corresponding deptrac layer definitions.

**Principle:** Don't build infrastructure ahead of integration. If metric caching is needed later, design it when the requirements and integration points are clear.

## Consequences

- `bin/qmx --help` and lightweight commands no longer pay the cost of resolving the full analysis dependency tree
- All 11+ formatters automatically receive filtered violations without per-formatter implementation
- Log messages with `{key}` placeholders now display interpolated values instead of raw JSON context
- ~13 unused source + test files removed, reducing maintenance surface
- Deptrac configuration simplified (2 fewer layers: `Infra.Storage`, `Infra.Collector`)
