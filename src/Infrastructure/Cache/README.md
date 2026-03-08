# Cache — AST Caching

## Overview

Parsing PHP into AST is the most expensive operation. Caching avoids repeated parsing of unchanged files.

**Performance:**

| Scenario   | Time relative to cold cache |
| ---------- | --------------------------- |
| Cold cache | 100%                        |
| Warm cache | 10-20%                      |

## Cache Levels

| Level       | What is cached | Key depends on                           | When invalidated               |
| ----------- | -------------- | ---------------------------------------- | ------------------------------ |
| **AST**     | Parse result   | file + mtime + size + php-parser version | File change, php-parser update |
| **Metrics** | File MetricBag | AST key + collectors                     | File change, aimd update       |

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

| Component      | Purpose                      |
| -------------- | ---------------------------- |
| `realpath`     | Absolute file path           |
| `mtime`        | Modification time            |
| `size`         | File size                    |
| `cacheVersion` | php-parser version (for AST) |

**Hashing:** `xxh128` (fast non-cryptographic hash)

**Important:** For the AST cache, the aimd version is NOT included in the key — the AST does not depend on the tool version.

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
.aimd-cache/
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

| Event              | Result                                  |
| ------------------ | --------------------------------------- |
| File changed       | New mtime/size -> new key -> cache miss |
| php-parser updated | New cacheVersion -> all keys are new    |
| PHP updated        | New cacheVersion                        |

### Manual Invalidation

- `--clear-cache` — full cache directory cleanup

### Orphan Entries

When a file is deleted/moved, old entries remain in the cache but are not used.

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
1. Key generation
2. Cache hit -> return from cache
3. Cache miss -> parse via `$inner`, save

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

1. **CI/CD:** Cache `.aimd-cache` between builds
2. **Git:** Add `.aimd-cache/` to `.gitignore`
3. **Large changes:** Use `--clear-cache` after refactoring

## CLI Options

| Option          | Description                            |
| --------------- | -------------------------------------- |
| `--no-cache`    | Disable caching                        |
| `--cache-dir`   | Cache directory (default: .aimd-cache) |
| `--clear-cache` | Clear cache before analysis            |

## Examples

```bash
# Disable cache
bin/aimd check src/ --no-cache

# Clear cache before analysis
bin/aimd check src/ --clear-cache

# Custom cache directory
bin/aimd check src/ --cache-dir=/tmp/aimd-cache
```

## Definition of Done

- Cache miss -> parsing and saving
- Cache hit -> reading without parsing
- File changed -> cache miss
- `--clear-cache` clears the cache
- `--no-cache` disables caching
- FileParserFactory returns the correct implementation
- Atomic writes via rename
- Unit tests for FileCache
- Integration test showing speedup
