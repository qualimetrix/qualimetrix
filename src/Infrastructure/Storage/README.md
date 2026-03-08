# Storage — Metric Storage

## Overview

Unified metric storage architecture based on SQLite, addressing two goals:

1. **Metrics Cache** — avoiding repeated analysis of unchanged files
2. **Memory Efficiency** — saving memory for large projects (10K+ files)

**Speedup scenarios:**

| Scenario              | Without cache | With cache | Speedup |
| --------------------- | ------------- | ---------- | ------- |
| 1000 files, 0 changed | 30s           | 0.5s       | 60x     |
| 1000 files, 1 changed | 30s           | 0.8s       | 37x     |
| 10000 files, InMemory | OOM           | 10s        | —       |

## Architectural Decision

**Storage separation:**

| Data         | Storage    | Reason                                |
| ------------ | ---------- | ------------------------------------- |
| AST          | File-based | Large objects, fast PHP serialization |
| Metrics      | SQLite     | Many small records, queries needed    |
| Dependencies | SQLite     | Graph, JOINs needed                   |
| Aggregated   | SQLite     | GROUP BY needed                       |

```
.aimd-cache/
├── ast/
│   ├── a1b2c3.php      # Serialized ASTs
│   └── d4e5f6.php
└── metrics.db          # SQLite database
```

## Components

### StorageInterface

Abstraction over metric storage.

**Main operations:**
- `getFile(string $path): ?FileRecord` — get file information
- `storeFile(FileRecord $record): int` — save file
- `hasFileChanged(string $path, string $contentHash): bool` — check for changes
- `getMetrics(SymbolPath $path): ?array` — get metrics by symbol path
- `storeMetrics(SymbolPath $path, array $metrics): void` — save metrics
- `allMetrics(SymbolType $type): iterable` — get all metrics of type (Generator)

**Transactions:**
- `beginTransaction(): void`
- `commit(): void`
- `rollback(): void`

### SqliteStorage

`StorageInterface` implementation based on SQLite.

**Database Schema:**

```sql
-- File information
CREATE TABLE files (
    id INTEGER PRIMARY KEY,
    path TEXT UNIQUE NOT NULL,
    content_hash TEXT NOT NULL,
    mtime INTEGER NOT NULL,
    size INTEGER NOT NULL,
    namespace TEXT,
    collected_at INTEGER NOT NULL
);

-- Metrics at file/class/method level
CREATE TABLE file_metrics (...);
CREATE TABLE class_metrics (...);
CREATE TABLE method_metrics (...);

-- Aggregated metrics
CREATE TABLE aggregated_metrics (...);
```

**Optimizations:**
- WAL mode for concurrent reads
- 64MB cache size
- Memory-mapped I/O (256MB)
- Prepared statements for all queries

### InMemoryStorage

`StorageInterface` implementation in memory for small projects (<1000 files).

**Usage:**
- Simple implementation using PHP arrays
- Used by default for small projects
- No persistence

### StorageFactory

Automatic storage selection based on project size.

**Logic:**
- Explicit config (`storage: sqlite` or `storage: memory`)
- Auto-detect: SQLite for projects > 1000 files
- InMemory for small projects by default

### ChangeDetector

File change detection for cache invalidation.

**Methods:**
- `getContentHash(SplFileInfo $file): string` — content hash (xxh3)
- `quickCheck(SplFileInfo $file, int $cachedMtime, int $cachedSize): bool` — quick check by mtime+size

**Cache Invalidation Rules:**
- File changed (content_hash) -> Invalidate file + children
- File deleted -> CASCADE removes all metrics
- Config changed -> Does NOT invalidate metrics cache (metrics do not depend on config)
- AIMD version changed -> Full cache clear

## CLI Options

| Option               | Description                            |
| -------------------- | -------------------------------------- |
| `--storage=<type>`   | Storage type (auto/sqlite/memory)      |
| `--cache-dir=<path>` | Cache directory (default: .aimd-cache) |
| `--no-cache`         | Disable cache                          |

## Examples

```bash
# Analysis with auto-detect storage
bin/aimd check src/

# Explicitly specify SQLite
bin/aimd check src/ --storage=sqlite

# Clear cache
bin/aimd cache:clear
bin/aimd cache:clear --only=metrics
bin/aimd cache:clear --only=ast

# Cache statistics
bin/aimd cache:stats

# Vacuum (SQLite compaction)
bin/aimd cache:vacuum
```

## Definition of Done

- `StorageInterface` defined
- `SqliteStorage` implemented with full schema
- `InMemoryStorage` for small projects
- `StorageFactory` with auto-detection (> 1000 files -> SQLite)
- WAL mode and SQLite optimizations (64MB cache, mmap)
- Generator-based iteration for memory efficiency
- CASCADE delete on file removal
- `ChangeDetector` with content hash (xxh3)
- CLI options `--storage`, `--cache-dir`, `--no-cache`
- Unit tests for SqliteStorage
- Integration test showing speedup
- Memory benchmark for large projects
