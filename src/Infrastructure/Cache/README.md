# Infrastructure Cache

AST caching is shared across projects, content addressed, versioned and bounded.
Cache data is untrusted input. A cache problem costs a miss; the parser still
uses the current immutable source bytes.

## Structure

```
Cache/
├── Contract/
│   ├── CacheConfiguration.php
│   ├── CacheConfigurationResolverInterface.php
│   ├── CacheConfigurationStoreInterface.php
│   └── CacheOpener.php
├── AstEntryCodec.php
├── CacheClearOutcome.php
├── CacheConfigurationResolver.php
├── CacheConfigurationStore.php
├── CacheDirectoryContents.php
├── CacheDirectoryEligibility.php
├── CacheFactory.php
├── CacheInterface.php
├── CacheKeyGenerator.php
├── CacheLayout.php
├── CacheLocationJudgement.php
├── CacheRootUntrusted.php
├── CacheSection.php
├── CacheSource.php
├── CacheSweep.php
├── CacheWriteException.php
├── DefaultCacheDirectory.php
├── FileCache.php
├── SweepPolicy.php
└── SweepStepOutcome.php
```

## Directory and trust boundary

`DefaultCacheDirectory::for()` takes an environment snapshot. An absolute
supported `XDG_CACHE_HOME` takes precedence; otherwise Linux/BSD use `$HOME/.cache`,
macOS uses `$HOME/Library/Caches`, and the Windows branch reads `LOCALAPPDATA`.
The root is `<base>/qmx`. Core's existing path contract is POSIX: native Windows
drive and UNC paths cannot be represented, and unsupported defaults are disabled.
Canonicalisation must also retain a supported path. Native Windows operation is
not promised by selecting its environment variable.
The old checkout `.qmx-cache` is not read. The Docker image sets
`XDG_CACHE_HOME=/cache`; retaining entries across containers requires a mounted
volume at `/cache`, with a separate volume for each uid.

The resolver captures `getenv()` for a configured run. The store captures cwd
and environment at its first runtime read after reset, never during container
construction. `CacheSource` retains the explicit writer or default source.

The resolver separates directory selection and provenance from the explicit
directory judgement. Location refusals retain the resolved value and its writers;
trust failures retain the winning source spelling.

Explicit directories are judged before analysis when caching is enabled or
`--clear-cache` uses the location. `--no-cache` without clearing does not judge
an unused root. A canonical root equal to or
below the project root refuses with its source and advice to use a directory
outside the checkout; project root `/` is exempt. The root must be creatable
under Core's link/access policy. On POSIX an existing root must belong to the
effective uid and have no group or other write bits. `CacheRootUntrusted` carries
an environment failure without importing Console. A default failure instead
disables caching with one warning on opening. Ancestor ownership and permissions
are not additional cache-root trust checks.

## Entry producer

Only `ast-v<N>/` is created inside the supplied root. Entries are
`ast-v<N>/<two lowercase hex characters>/<64 lowercase hex characters>`.
Directories use mode `0700`; `CACHEDIR.TAG` belongs to the version directory,
so an explicit root containing unrelated data is not tagged as cache.

`CacheKeyGenerator::generateForContent()` computes SHA-256 over length-prefixed
fields (`<decimal byte length>:<bytes>`): PHP major/minor, exact installed parser
version (including a branch reference), `short_open_tag`, `zend.multibyte`,
`zend.script_encoding`, then source bytes. Paths, project and writer do not enter
the key. An unavailable parser version returns an empty key; `open()` publishes
that verdict after the run logger is configured.

`AstEntryCodec::FORMAT_VERSION` versions the producer. Increase it whenever
encoding, parser construction/options/lexer or pre-write AST processing changes.
PHP and parser versions remain key fields. The producer witness records encoded
AST bytes alongside the parser version: a changed parser requires re-recording;
changed producer bytes require a format bump and re-recording.

The codec serializes under `serialize_precision=-1` and restores the setting in
`finally`. Allowed classes are derived once from the installed parser: concrete
Node implementations and Comment classes. Decode rejects active object cycles while preserving shared nodes in an acyclic
graph. The parser produces no parent links; a cyclic attribute is refused too.
Decode requires `list<Node>` and
rejects foreign or incomplete objects inside nested attributes, uninitialised
mandatory node or comment properties and excessively deep/cyclic array structures. Invalid
entries are deleted and counted; workers' discards are not added to the coordinator count.
Writes retain Core's judged full temporary-sibling publication and identity
checks. There is no serializer marker or cross-version invalidation.

## Run lifecycle

`CacheFactory` implements `Contract\CacheOpener`. `open()` is idempotent until
reset: judge the default, emit its verdict once, sweep, then return the directory
or `null`. `StrategySelector` opens before choosing sequential or parallel
execution. Coordinator `CachedFileParser` also opens lazily for parsing outside
the strategy. Workers receive the accepted directory and construct `FileCache`
directly. `create()` never sweeps.

`clear()` reuses the same memoized location judgement, independently of AST
enablement or parser-version availability. It neither opens the current format
nor sweeps. An unusable default returns no clear outcome; an explicit invalid
source retains its named refusal, including with caching disabled.

Runtime configures the factory logger after logging is ready. Completion calls
`reportDiscardedEntries()` once for the final coordinator count. Reset drops the
opened directory, cache instance, count-publication state and logger; the default
logger is `NullLogger`. CachedFileParser does not retain a previous run's cache.

## Bounded storage and clear

Each open sweeps 16 consecutive shards from `ast-v<N>/.sweep`, across all versions.
An unreadable cursor chooses a random start. Entries older than 30 days are
removed. A shard over 8 MiB then loses oldest entries until within quota, except
for its newest entry, which may exceed the quota alone. Hits touch mtime; empty
shards are removed. Concurrent removal failures are ignored. Core publishes the
cursor; a failed cursor write is a debug diagnostic.

Sixteen successful cursor steps cover all 256 shards. Per format the bound is
2 GiB plus entries written since their shard's last sweep and one oversized newest
entry per shard. Foreign names and linked shards are not swept. There is no
configurable quota.

`clear()` atomically detaches each version directory to `.clearing-<random hex>`.
It removes only layout names, retains foreign files and reports incomplete clear
when owned entries remain or the layout cannot be inspected. Foreign-only detached
residue is successful and its path is retained in the outcome. Root-level unrelated
files are untouched. Clear affects all projects sharing this user's root; an
invalid default is not cleared.

## Definition of Done

- Cold, warm and poisoned cache runs produce source-derived findings.
- Keys reflect source bytes and parsing environment, excluding paths.
- Explicit invalid roots refuse when caching or clearing uses them; default failures disable once. Disabling caching without clearing leaves an unused root unjudged.
- Codec round trips preserve floats/comments and reject foreign objects.
- Open/sweep/warnings occur once per run on both coordinator entry paths.
- Sweep covers shards/versions with age and quota bounds.
- Clear preserves unrelated data and reports failed deletion.
- Reset releases cache and logger state between invocations.

## Locality

Cache owns configuration, trust, codec, layout and storage lifecycle. Named
contracts serve its external consumers; delivery and DI retain their adapters.
