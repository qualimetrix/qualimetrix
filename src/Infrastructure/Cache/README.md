# Cache — AST Caching

## Overview

Parsing PHP into AST is the most expensive operation. Caching avoids repeated parsing of unchanged files.

**Performance:**

| Scenario   | Time relative to cold cache |
| ---------- | --------------------------- |
| Cold cache | 100%                        |
| Warm cache | 10-20%                      |

## Cache Levels

| Level       | What is cached | Key depends on                                   | When invalidated                  |
| ----------- | -------------- | ------------------------------------------------ | --------------------------------- |
| **AST**     | Parse result   | content hash + cache schema + php-parser version | Content change, php-parser update |
| **Metrics** | File MetricBag | AST key + collectors                             | File change, qmx update           |

**Priority:** AST caching is primary (80%+ of time). MetricBag caching is an additional optimization for incremental runs.

## Components

### CacheInterface

**Methods:**
- `get(string $key): mixed` — null if not found
- `set(string $key, mixed $value): void`
- `has(string $key): bool`
- `delete(string $key): void`
- `clear(): void`

### CacheKeyGenerator

Generates cache key for a file.

**Methods:**
- `generate(SplFileInfo $file): string`
- `getCacheVersion(): string`

**Key components:**

| Component      | Purpose                                                                                      |
| -------------- | -------------------------------------------------------------------------------------------- |
| `contentHash`  | Detects every source-content change, including same-size rewrites with a preserved timestamp |
| cache schema   | Keeps entries from incompatible key layouts separate                                         |
| `cacheVersion` | php-parser version (for AST)                                                                 |

**Hashing:** `xxh128` (fast non-cryptographic hash)

**Important:** For the AST cache, the qmx version is NOT included in the key — the AST does not depend on the tool version.

If the file cannot be resolved or hashed, key generation returns an empty key and
the parser bypasses the cache for that file rather than risking reuse of an
unverified AST.

### FileCache

File-based implementation of `CacheInterface`.

**Constructor:** `__construct(string $directory)`

**Features:**
- **Sharding:** first 2 characters of the key as a subdirectory
- **Atomic writes:** temporary file + rename (POSIX atomic)
- **Serialization:** igbinary (if available) or standard serialize

**Atomic writes (important for parallelization):**
```php
public function set(string $key, mixed $value): void
{
    $path = $this->getPath($key);
    $tmp = $path . '.tmp.' . getmypid();

    file_put_contents($tmp, serialize($value));
    rename($tmp, $path); // atomic on POSIX
}
```

This prevents race conditions during parallel writes from different workers.

**Storage structure:**
```
.qmx-cache/
├── ab/
│   └── cdef1234567890abcdef.cache
├── 12/
│   └── 34567890abcdef1234.cache
└── ...
```

### CacheFactory

Lazy cache creation based on runtime configuration.

**Method:**
- `create(): CacheInterface` — creates FileCache with path from ConfigurationProvider

**Features:**
- Cache is created on first access
- Uses cacheDir from current configuration

## Invalidation Strategy

### Automatic Invalidation

| Event                | Result                                    |
| -------------------- | ----------------------------------------- |
| File content changed | New content hash -> new key -> cache miss |
| Only mtime changed   | Same content hash -> cache hit            |
| php-parser updated   | New cacheVersion -> all keys are new      |
| PHP updated          | New cacheVersion                          |

### Manual Invalidation

- `--clear-cache` — full cache directory cleanup

### Orphan Entries

When a file is deleted, its entry can remain in the cache until cleanup. A moved
file with unchanged content can safely reuse the same AST entry.

**Cleanup strategies:**
1. `--clear-cache` — removes everything
2. GC command — removing orphans
3. TTL — automatic removal of old entries

## Integration

### CachedFileParser (Decorator)

Decorator for `FileParserInterface`.

**Dependencies:**
- `FileParserInterface $inner`
- `CacheInterface $cache`
- `CacheKeyGenerator $keyGenerator`

**Algorithm of parse():**
1. Read source bytes once from the original file.
2. Generate the cache key from those bytes.
3. Cache hit -> return from cache.
4. Cache miss -> parse those same bytes via `$inner` while retaining the original file for diagnostics, save.

### FileParserFactory

Factory with runtime configuration awareness.

**Dependencies:**
- `PhpFileParser $parser`
- `CacheInterface $cache`
- `CacheKeyGenerator $keyGenerator`
- `ConfigurationProviderInterface $configurationProvider`

**Method:**
- `create(): FileParserInterface` — returns `CachedFileParser` or `PhpFileParser` depending on `config.cacheEnabled`

## Recommendations

1. **CI/CD:** Cache `.qmx-cache` between builds
2. **Git:** Add `.qmx-cache/` to `.gitignore`
3. **Large changes:** Content changes invalidate automatically; use `--clear-cache` only to reclaim space

## CLI Options

| Option          | Description                           |
| --------------- | ------------------------------------- |
| `--no-cache`    | Disable caching                       |
| `--cache-dir`   | Cache directory (default: .qmx-cache) |
| `--clear-cache` | Clear cache before analysis           |

## Examples

```bash
# Disable cache
bin/qmx check src/ --no-cache

# Clear cache before analysis
bin/qmx check src/ --clear-cache

# Custom cache directory
bin/qmx check src/ --cache-dir=/tmp/qmx-cache
```

## Definition of Done

- Cache miss -> parsing and saving
- Cache hit -> reading without parsing
- File content changed -> cache miss, including same-size rewrites with a restored mtime
- Metadata-only mtime change -> cache hit
- `--clear-cache` clears the cache
- `--no-cache` disables caching
- FileParserFactory returns the correct implementation
- Atomic writes via rename
- Unit tests for FileCache
- Integration test showing speedup
