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

The php-parser version is read from the Composer runtime API by package name.
Computing it from a path relative to this file answered correctly only while
Qualimetrix was the root package; installed as a dependency the path pointed at
a `vendor/` that does not exist, and the fallback named the major version alone
— one key for every 5.x release. When the runtime cannot name the package,
`cacheVersion` is empty and key generation returns an empty key: no caching
beats caching against a version that does not move. That decision is announced
once, as a PSR-3 `warning` from the generator's constructor — what it costs is
otherwise invisible, a run several times slower over a cache directory that
stays empty.

A tagged version names one set of bytes and is the whole key. A **branch**
version does not: Composer normalizes a branch requirement to `dev-<name>` or
`<n>-dev`, so every commit on that branch carries the same string while the
parser's node classes move underneath it. There the install's `reference` is
appended — `getVersion()` never carries it, `reference` being a separate field
of the install record — and a branch install that has no reference to offer
keeps the branch name rather than turning into a refusal to cache.

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

**Atomic writes (important for parallelization):** every write goes to a
temporary neighbour and is renamed into place — POSIX-atomic. That includes the
`.serializer` marker, not only the entries: a marker torn by a concurrent write
reads as a serializer mismatch, and every process reading it that way clears the
whole directory, taking with it the entries its siblings have just written.

The temporary name carries random bytes rather than the process id. A worker is
a process today, but the parallel transport this cache is declared safe for
admits threads, and two threads writing one key would agree on a pid and
disagree on bytes.

`clear()` survives a subdirectory it cannot enter. It runs from `get()` and
`set()`, so an escaping exception would turn a cache problem into a failed
file.

**The marker is written only over an empty directory.** A clear can fall short
three ways — the directory refuses to open, a subdirectory refuses to open, or
an unlink is refused — and the serializer marker is a claim about what the
directory holds, so writing it after a clear that fell short states a format
the surviving entries do not have. Whether the directory ended up empty is
therefore measured by looking at it again, not tallied from the walk:
`CATCH_GET_CHILD` drops an unreadable subtree entirely, so every removal in
the walk can succeed over a directory that is not empty.

The marker is also kept back from the walk and removed last, once everything
else is gone. Deleting it beside a surviving entry is the same lie one process
later: the next process would read "nothing says", skip the clear on that
ground and write its own name over the old format.

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
- `CacheFactory|CacheInterface $cache`
- `CacheKeyGenerator $keyGenerator`
- `CacheConfigurationStoreInterface $configurationStore`

**Algorithm of parse():**
1. Ask the store whether caching is on; delegate to `$inner` if it is not.
2. Refuse anything that is not a readable regular file — `$inner` owns the
   typed error. `file_get_contents()` on a directory returns an empty string,
   not `false`, so reading first would report a phantom analyzed file.
3. Read source bytes once from the original file.
4. Generate the cache key from those bytes.
5. Cache hit -> return from cache.
6. Cache miss -> parse those same bytes via `$inner` while retaining the original file for diagnostics, save.

**Why the store and not a constructor flag:** both halves of the cache decision
are taken at parse time. The container builds this service before a run is
configured, so a decorator that resolved "caching enabled?" once, at
construction, resolved it from the defaults — and `--no-cache` never reached the
parse, while `--cache-dir` did, because the directory was already resolved
lazily.

### FileParserFactory

Factory with runtime configuration awareness.

**Dependencies:**
- `PhpFileParser $parser`
- `CacheFactory $cacheFactory`
- `CacheKeyGenerator $keyGenerator`
- `CacheConfigurationStoreInterface $configurationStore`

**Method:**
- `create(): FileParserInterface` — always returns the runtime-aware
  `CachedFileParser`, handing it the store. The factory does not decide whether
  to cache; it is called too early to know.

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
- `--no-cache` and `cache.enabled: false` disable caching, measured by the
  absence of the cache directory after a run that was configured **after** the
  parser was built
- FileParserFactory hands the parser the store rather than a resolved answer
- Atomic writes via rename
- Unit tests for FileCache
- Integration test showing speedup


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
