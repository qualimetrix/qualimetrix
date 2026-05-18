# RelativePath VO — Concept Plan (Stage 1, Round 2)

**Status:** Round-2 draft after triple review of Round 1. Ready for narrow round-2 verification before Stage 2.
**Tier:** 1 (cross-cutting, ~80 files / ~422 string instantiations).
**Companion ADR:** [`0015-relative-path-vo.md`](../../adr/0015-relative-path-vo.md) — Proposed.
**Origin:** [[backlog_attention_items]] #1 (T10 follow-up: `GitScopeFilter` had an absolute/relative mismatch bug, fixed point-wise; this plan closes the entire bug class via types).

This is a **concept plan** per CLAUDE.md global rules: architectural decisions, contracts (signatures, no bodies), DoD, edge cases, phase sequence, test plan. No implementation code.

**Round-1 reviewers:** Claude reviewer agent (10 findings), Gemini (5 findings), Codex (8 findings). All round-1 findings either applied below or explicitly rejected with rationale.

**Round-2 reviewers:** Claude reviewer (6 findings, 1 HIGH factual bug + 2 MEDIUM doc gaps), Codex (1 HIGH factual bug + 1 MEDIUM contract + 1 LOW + condition on D5 + R6 detail list). Gemini round-2 unavailable (auth/transport failure — see [[reviewer_effectiveness]]); two-reviewer round-2 accepted given both independently surfaced different HIGH factual bugs. All round-2 findings applied below.

---

## 1. Problem statement

The codebase mixes three semantic kinds of file path in untyped `string` fields:

- **Absolute** — POSIX paths starting with `/`, often produced by `realpath()`, used for I/O and cross-host comparisons (`ScopeWarningChecker.php:45,54,94`, `BaselineWriter.php:108`).
- **Project-relative** — relative to the project root (= `getcwd()` after `Application::doRun()` applies `--working-dir`). Used in violations, baseline output, reports (`Location.file`, `FileProcessingResult.filePath`).
- **Git-relative** — relative to git top-level. Produced by `git diff --name-status` / `git ls-files` (`ChangedFile.path` before translation).

The three are normalized only in a single helper, `Core\Util\PathNormalizer::relativize()` (a static function), and used inconsistently elsewhere. T10 was a concrete symptom: `GitScopeFilter` compared `$violation->location->file` (project-relative) against `$file->path` (git-relative) — they matched only when project root coincided with git top-level. The fix was point-wise; the bug class remains.

**Goal of this work:** make the kind of every path machine-checkable so PHPStan rejects mismatched mixes at compile time, eliminating the bug class.

## 2. Locked decisions (from design session + round-1 review)

| #   | Decision                                                                                                                                                                                                                                                     | Rationale                                                                                                                                                                                                                                              |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| D1  | Two VOs: `AbsolutePath` + `RelativePath` (family, not single)                                                                                                                                                                                                | Type-safe: PHPStan rejects passing absolute where relative is required. Closes T10 class.                                                                                                                                                              |
| D2  | VOs travel everywhere except raw I/O boundaries                                                                                                                                                                                                              | `string` survives only at: PHP I/O calls (`file_get_contents`, etc.), `proc_open`/`exec` args, CLI input parsing, JSON deserialization input. Everywhere else: VO.                                                                                     |
| D3  | Scope is paths only — do **not** introduce `AnalyzedFile`/`FileInfo` aggregates                                                                                                                                                                              | Keeps blast radius contained. `AnalyzedFile` can come later as its own ADR.                                                                                                                                                                            |
| D4  | Full migration in phased commits, not incremental opportunistic                                                                                                                                                                                              | AI-cost of full rewrite is low; partial migration would leave the bug class alive.                                                                                                                                                                     |
| D5  | **No third `GitRelativePath` VO.** Translation from git-relative to project-relative happens **inside `GitClient::parseChangedFiles()`**, before `ChangedFile` is constructed. Once a `ChangedFile` exists, `$file->path` is invariantly project-relative.   | Codex F1 raised the risk of git-relative leaking through `ChangedFile.path: RelativePath`. Eager translation at the producer (1 location) is cheaper than a third VO threading through ~5 callsites.                                                   |
| D6  | **`RelativePath` rejects leading `..` after normalization.** It is an in-base invariant for project-relative paths. `..` segments interleaved with named segments are resolved lexically (`a/../b` → `b`); leading `..` triggers `InvalidArgumentException`. | Claude F6, Gemini F1, Codex F2 converged on strict invariant. Out-of-base scenarios are handled at the boundary (`PathFactory::gitRelative()` returns `?RelativePath` → null on out-of-base, caller skips).                                            |
| D7  | **`AbsolutePath` resolves `..` lexically (no I/O).** `/a/b/../c` → `/a/c` at construction time.                                                                                                                                                              | Gemini F5. Makes `equals()` predictable without `realpath()`. `canonicalize()` retains the I/O-bound symlink semantics.                                                                                                                                |
| D8  | **Phase 6 guard is a PHPStan custom rule**, not a PHPUnit reflection test.                                                                                                                                                                                   | Claude F3+F10. Rule rejects `string`-typed properties/parameters named `$file|$path|$filePath|$oldPath` in production `Qualimetrix\…` namespaces, with a documented allow-list of legitimate exceptions (`HtmlTreeNode.$path` — namespace path, etc.). |

## 3. VO contracts

Namespace: `Qualimetrix\Core\Path` (new). Two `final readonly` classes implementing `\Stringable`.

### `AbsolutePath`

```text
fromString(string $value): self                // throws InvalidArgumentException if not absolute or empty
value(): string                                // raw normalized POSIX string
__toString(): string                           // explicit (string)-cast form; does NOT autoload as array offset (PHP requires explicit cast)
equals(self $other): bool                      // structural after normalization
relativizeTo(self $base): RelativePath         // throws if $this is not under $base
tryRelativizeTo(self $base): ?RelativePath    // null on out-of-base; preferred form for callers that handle both branches
joinRelative(RelativePath $tail): self
canonicalize(): self                           // explicit realpath(); throws if path does not exist
exists(): bool                                 // file_exists()
isFile(): bool                                 // is_file()
isDirectory(): bool                            // is_dir()
```

`fromString` validation:
- Must start with `/` (POSIX). Windows native paths out of scope.
- Must not be empty.
- Normalization: collapse `//` → `/`, resolve `.` segments, **resolve `..` segments lexically** (no I/O, no `realpath()`), strip trailing `/` unless path is `/` itself. Result: any two paths denoting the same logical location compare equal.
- **Symlink caveat (Q-EC4 cross-ref):** lexical `..` resolution in `fromString()` does **not** account for symlinks; `/a/symlink-to-b/../x` becomes `/a/x`, which may differ from the symlink-aware truth. Callers needing symlink-aware resolution call `canonicalize()` explicitly. This is a deliberate trade-off (lexical = predictable + I/O-free).

### `RelativePath`

```text
fromString(string $value): self            // throws if absolute, empty, pure '.', or has leading '..' after normalization
value(): string                            // raw normalized POSIX string, no leading './'
__toString(): string                       // explicit (string)-cast form
equals(self $other): bool
resolveAgainst(AbsolutePath $base): AbsolutePath
startsWith(self $prefix): bool             // segment-based, NOT str_starts_with — 'foobar' does not start with 'foo'
withoutPrefix(self $prefix): self          // throws if !$this->startsWith($prefix)
tryWithoutPrefix(self $prefix): ?self      // null on no-match; preferred form when both branches are valid
join(self $tail): self                     // concatenation with separator normalization
segments(): list<string>                   // split on '/', empty list never returned (path must have ≥1 segment)
parent(): ?self                            // RelativePath of parent directory; null if single segment
basename(): string                         // last segment
extension(): ?string                       // null if none
```

`fromString` validation:
- Must not start with `/`.
- Strip leading `./`.
- Replace `\` → `/` (Windows-style separator on input; VO state is POSIX-only).
- Reject empty string and pure `.`.
- Lexically resolve `..` interleaved with named segments (`a/../b` → `b`).
- Reject paths that, after lexical normalization, would start with `..` (= out-of-base). Out-of-base paths are signaled through `PathFactory::gitRelative()` returning `null`, not through a "weak" `RelativePath`.

### `Stringable` contract (resolves round-1 Codex F7)

`__toString()` exists for explicit `(string) $path` casts at I/O edges and for interpolation in error messages. It **does not** automatically allow `$array[$path] = …` (PHP requires `(string)` cast for object offsets). Callers using a path as an array key write `$array[$path->value()] = …` or `$array[(string) $path] = …` explicitly.

### Wire-format stability (resolves round-1 Codex F6 / Q-EC5)

Both VOs implement explicit `__serialize(): array` and `__unserialize(array): void` returning `['value' => $value]`. This pins the wire format independently of the internal property name, so cache files and `amphp/parallel` IPC payloads written by version N deserialize unchanged in version N+1 even if the VO's private property name evolves.

### `PathFactory` (boundary helper, also in `Core\Path`)

A thin namespace-level factory to consolidate the three boundary conversions PathNormalizer does today:

```text
PathFactory::projectRelative(string $raw, AbsolutePath $projectRoot): RelativePath
    // raw may be absolute or already relative; result is always project-relative; throws on out-of-base

PathFactory::tryProjectRelative(string $raw, AbsolutePath $projectRoot): ?RelativePath
    // null on out-of-base — used by BaselineWriter::relativizeCanonical() which preserves out-of-tree entries

PathFactory::gitRelative(string $rawGitPath, AbsolutePath $gitToplevel, AbsolutePath $projectRoot): ?RelativePath
    // resolves git-relative against gitToplevel, then translates to project-relative; null if outside projectRoot

PathFactory::fromCliArgument(string $raw, AbsolutePath $cwd): AbsolutePath
    // resolves user-supplied CLI argument (may be relative or absolute) into an AbsolutePath
```

`gitRelative` is the producer of project-relative paths for the git-input pipeline. By design (D5), `GitClient` calls it before constructing `ChangedFile`, so `ChangedFile.path` is invariantly project-relative.

## 4. Replacements in existing types

(round-1 Claude F1 expanded the inventory; below is the verified full list.)

| Class                                                             | Field today                                              | After                                                                                                        | Phase             |
| ----------------------------------------------------------------- | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ | ----------------- |
| `Core\Violation\Location`                                         | `public string $file`                                    | `public ?RelativePath $file` (`null` replaces sentinel `''`)                                                 | 1a                |
| `Core\Duplication\DuplicateLocation`                              | `string $file`                                           | `RelativePath`                                                                                               | 1a                |
| `Core\Exception\ParseException`                                   | `string $filePath`                                       | `RelativePath`                                                                                               | 1a                |
| `Reporting\Health\WorstOffender`                                  | `string $file`                                           | `?RelativePath` (mirrors Location nullability)                                                               | 1a                |
| `Analysis\Collection\FileProcessingResult`                        | `string $filePath`                                       | `RelativePath`                                                                                               | 1b                |
| `Infrastructure\Git\ChangedFile`                                  | `string $path`, `?string $oldPath`                       | `RelativePath`, `?RelativePath` (invariant: project-relative per D5, enforced via named factory — see below) | 1b                |
| `Infrastructure\Parallel\FileProcessingTask`                      | `string $filePath` (per docblock: absolute)              | `AbsolutePath`                                                                                               | 1b                |
| `Core\Symbol\SymbolPath`                                          | `?string $filePath` (canonical key for `forFile`)        | `?RelativePath`                                                                                              | 1c                |
| `Core\Symbol\ClassInfo::$file`                                    | `?string`                                                | `?RelativePath`                                                                                              | 1c                |
| `Core\Symbol\MethodInfo::$file`                                   | `?string`                                                | `?RelativePath`                                                                                              | 1c                |
| `Core\Symbol\SymbolInfo::$file`                                   | `?string`                                                | `?RelativePath`                                                                                              | 1c                |
| `Analysis\Collection\Dependency\Handler\DependencyContext::$file` | `string` (`readonly`, constructor-initialized)           | `RelativePath`                                                                                               | 5 (deep pipeline) |
| `Analysis\Collection\Dependency\DependencyVisitor::$file`         | `?string` (mutable, via `setFile()` on each `enterNode`) | `?RelativePath`                                                                                              | 5                 |

`Location::none()` → `new Location(null, null, false)`; `isNone()` → `$this->file === null`. JSON serialization (HTML/SARIF/JSON formatters) preserves the current wire surface via `$location->file?->value() ?? ''` (resolves round-1 Claude F9 — not a breaking change for consumers).

**`ChangedFile` named factory (D5 invariant enforcement, Codex round-2 condition):** to prevent future code from constructing a `ChangedFile` with a git-relative path passed in by mistake, the public constructor stays available but is supplemented by a named factory `ChangedFile::fromGitOutput(string $rawGitPath, ChangeStatus $status, ?string $rawOldGitPath, AbsolutePath $gitToplevel, AbsolutePath $projectRoot): ?self` that internally calls `PathFactory::gitRelative()` and returns `null` if the path is out-of-project. `GitClient::parseChangedFiles()` uses only this factory; direct constructor calls are reserved for tests that build pre-translated `ChangedFile` instances.

## 5. Boundary mapping

| Boundary                                                                            | Direction                 | What happens                                                                                                                                                                                                                                                     |
| ----------------------------------------------------------------------------------- | ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **CLI args** (`CheckCommandDefinition.php`)                                         | string → VO               | `PathFactory::fromCliArgument()` against cwd; result `AbsolutePath`.                                                                                                                                                                                             |
| **CLI `--working-dir`** (`Application::doRun()`)                                    | string → VO               | Sets `$cwd: AbsolutePath` for the run.                                                                                                                                                                                                                           |
| **Discovery** (`FinderFileDiscovery.php`)                                           | VO emitted                | Yields `iterable<AbsolutePath, SplFileInfo>`. The `SplFileInfo` payload preserved for downstream consumers.                                                                                                                                                      |
| **Collection IPC** (`FileProcessingTask`, `FileProcessingResult`)                   | VO across worker boundary | Serialized via PHP `serialize` or `igbinary`; pinned by `__serialize`/`__unserialize` (D7 contract).                                                                                                                                                             |
| **Worker bootstrap** (`WorkerBootstrap::buildCacheKey`)                             | VO → opaque string        | Cache key is hash over (`$projectRoot->value()`, `$cacheDir->value()`, file content) — `$projectRoot`/`$cacheDir` are `AbsolutePath`.                                                                                                                            |
| **Cache directory resolution** (`CacheFactory`, `AnalysisConfiguration::$cacheDir`) | string → VO               | Config layer resolves cache dir to `AbsolutePath` once at startup.                                                                                                                                                                                               |
| **Cache key generation** (`CacheKeyGenerator`)                                      | VO → opaque string        | Consumes `SplFileInfo` and produces hash; the `getRealPath()` boundary returns `string|false` — wrapped via `AbsolutePath::canonicalize()` (throws on `false`).                                                                                                  |
| **Git output** (`GitClient.php::parseChangedFiles()`)                               | string → VO               | Parses git stdout (`name-status` columns), calls `PathFactory::gitRelative()` per row; rows returning `null` (out-of-project) are skipped. Result is `ChangedFile` with project-relative `RelativePath`.                                                         |
| **Git repo location** (`GitRepositoryLocator`)                                      | string → VO               | Returns `AbsolutePath` (git top-level).                                                                                                                                                                                                                          |
| **Git scope filter** (`GitScopeFilter::extractNamespace`)                           | VO → AbsolutePath → I/O   | `$projectRoot->joinRelative($file->path)` (not `$repoRoot` — `$file->path` is project-relative after D5; joining with git top-level would produce a wrong absolute path in the git-subdir scenario, Codex round-2 HIGH); then `file_get_contents()` at I/O edge. |
| **Reporting context** (`FormatterContext::relativizePath`)                          | VO ↔ string               | Method signature becomes `relativizePath(RelativePath $filePath, AbsolutePath $basePath): RelativePath`. Existing callers in SARIF/HTML/text formatters update accordingly during Phase 4.                                                                       |
| **Reporting** (formatters)                                                          | VO → string               | All formatters call `$location->file?->value() ?? ''` at the print site. `SarifFormatter`'s manual `str_replace('\\', '/', ...)` is removed — VO is already POSIX.                                                                                               |
| **Baseline write/load** (`BaselineWriter`, `BaselineLoader`)                        | VO ↔ string               | Write via `->value()`; load via `RelativePath::fromString()` (validation surfaces malformed baselines as load errors). `BaselineWriter::relativizeCanonical()` uses `PathFactory::tryProjectRelative()` (preserves out-of-tree entries).                         |
| **ScopeWarningChecker**                                                             | string → VO               | Consumes PSR-4 autoload paths from `ComposerReader` (raw strings from `composer.json`); resolves each via `PathFactory::fromCliArgument()` against project root → `AbsolutePath`; missing paths skipped per existing semantics.                                  |
| **bin/qmx autoloader**                                                              | raw I/O                   | `require_once` arguments stay `string` — raw I/O exception.                                                                                                                                                                                                      |

## 6. Phased migration

Each phase is a single commit (or a small batch). After each phase: `composer check` must be green. We don't move to phase N+1 with a red main.

### Pre-Phase 0 — Internal annotation
- Annotate `Core\Util\PathNormalizer` and any other `Core\Util` helpers slated for removal with `@internal`. One-line doc-only commit.
- **DoD:** `composer check` green. No code change beyond the annotation.

### Phase 0 — Introduce VOs in `Core\Path`
- Files added: `src/Core/Path/AbsolutePath.php`, `src/Core/Path/RelativePath.php`, `src/Core/Path/PathFactory.php`.
- No production code uses them yet.
- **DoD:** ≥30 unit tests covering normalization, equality, validation, edge cases. Tests for:
  - lexical `..` resolution in `AbsolutePath`
  - rejection of leading `..` / `.` / empty in `RelativePath`
  - segment-based `startsWith` (`foobar` ⊄ `foo`)
  - `relativizeTo` / `tryRelativizeTo` both branches
  - `__serialize` / `__unserialize` round-trip via `serialize()` and `igbinary_serialize()`
  - `PathFactory::gitRelative()` returning `null` for out-of-base
- Microbench: VO `fromString` ≤500ns/call (R3 budget). PHPStan level 8 clean.
- **Phase 6 PHPStan rule spike committed** (Codex round-2 F4 derisking): a minimal working `BannedStringPathRule` is committed in Phase 0 alongside the VOs, even though it won't be wired into `phpstan.neon` until Phase 6. Catches Phase 6 surprises early.

### Phase 1a — Pure value carriers
- Change `Location.file`, `DuplicateLocation`, `ParseException.filePath`, `WorstOffender.file`.
- These types are read-only at construction and consumed by formatters/exception handlers; no flow-through to other domain types.
- **DoD:** `composer check` green. `composer test:js` (vitest for HTML report JSON shape) green — confirms `$location->file?->value() ?? ''` preserves wire surface.

### Phase 1b — Worker-IPC types
- Change `FileProcessingResult.filePath`, `ChangedFile.path/oldPath`, `FileProcessingTask.filePath`.
- `GitClient::parseChangedFiles()` adopts `ChangedFile::fromGitOutput()` (which delegates to `PathFactory::gitRelative()`). D5 invariant established here.
- When `fromGitOutput` returns `null` (out-of-project git row), `GitClient` collects the dropped raw path and emits a single PSR-3 `warning` at end of parsing: `"Skipped {n} changed file(s) outside project root: {paths}"`. Prevents silent-drop UX (round-2 Claude F2).
- **DoD:** `composer check` green. New integration test sends a `FileProcessingTask` → worker → `FileProcessingResult` round-trip through the actual `amphp/parallel` worker (R1 mitigation). Regression test for T10: project root is git subdir, `--report=git:HEAD~1` matches violations correctly, including all 4 diff-row shapes (Added/Modified/Deleted/Renamed).
- **R6 / amphp worker integration test details (round-2 Codex):**
  - `@requires extension pcntl` and `@requires extension parallel` on the test, OR `markTestSkipped()` if either is missing.
  - Force worker path explicitly: instantiate the parallel strategy with `setMinFilesForParallel(1)` so the test does not silently fall through to sequential.
  - Each test creates a unique temp directory under `sys_get_temp_dir() . '/qmx-test-' . uniqid()` for project root, cache dir, and fixture sources. Static caches (`WorkerBootstrap::reset()`) are cleared in `setUp` and `tearDown`.
  - Fixture classes used inside the worker are listed in `composer.json` `autoload-dev.classmap` so the worker process can autoload them.

### Phase 1c — Symbol identity
- Change `SymbolPath::$filePath`, `ClassInfo::$file`, `MethodInfo::$file`, `SymbolInfo::$file`.
- `SymbolPath::forFile()` factory now takes `RelativePath`.
- Repositories (`InMemoryMetricRepository`) keyed by `SymbolPath` canonical key — the canonical-key string format is unchanged (uses `$filePath?->value()`).
- **DoD:** `composer check` green. Self-analysis (`bin/qmx check src/`) green.

### Phase 2 — Discovery boundary
- `FinderFileDiscovery::discover()` emits `iterable<AbsolutePath, SplFileInfo>`.
- `FileDiscoveryInterface` signature updated.
- CLI argument parsing converted via `PathFactory::fromCliArgument()`.
- **DoD:** `composer check` green. CLI argument tests cover relative + absolute + `./prefix` + symlinked + nonexistent paths.

### Phase 3 — Git boundary completion
- `GitScopeFilter::buildIndex()` uses `$projectRoot->joinRelative($file->path)` (not `$repoRoot` — `$file->path` is project-relative after Phase 1b). Comparisons against `$violation->location->file` are VO-to-VO via `equals()` or array-key on `->value()`.
- `GitRepositoryLocator::getRoot()` returns `AbsolutePath`.
- **DoD:** `composer check` green. T10 regression test (from Phase 1b) still green.

### Phase 4 — Reporting + Baseline output
- All formatters consume `Location::file?->value() ?? ''` at print sites.
- `BaselineWriter::relativizeCanonical()` and `BaselineLoader` use `PathFactory::tryProjectRelative()` and `RelativePath::fromString()`.
- `SarifFormatter` separator-substitution code removed (VO is POSIX-only).
- **DoD:** Format-level tests (text/JSON/SARIF/Checkstyle/GitLab/HTML) green. Baseline round-trip test green. `composer test:js` green.

### Phase 5 — Internal pipelines + cache
- `AggregationHelper`: array keys via `(string) $path` explicitly; helper accepts `RelativePath` on input.
- `InMemoryMetricRepository`, `ProjectNamespaceResolver`, `DependencyVisitor::setFile()`, `DependencyContext::$file`, `DerivedMetricExtractor`, `SuppressionFilter`.
- `CacheKeyGenerator`, `FileCache`, `CacheFactory`, `WorkerBootstrap::buildCacheKey` adopt `AbsolutePath`.
- `AnalysisConfiguration::$cacheDir`: `string → AbsolutePath`. **Resolution contract (Codex round-2 MEDIUM):** `AnalysisConfiguration::fromArray($merged)` reads `project_root` from `$merged` first, then resolves `cache.dir` via `PathFactory::fromCliArgument($rawCacheDir, $projectRoot)`. The default `'.qmx-cache'` thus becomes `$projectRoot . '/.qmx-cache'` as `AbsolutePath`. `merge()` is updated so VO fallback does not collide with the string default; `StrategySelector` / `CacheFactory` / `WorkerBootstrap` downstream getters return `AbsolutePath`.
- `ScopeWarningChecker` + `ComposerReader` autoload-path resolution via `PathFactory::fromCliArgument()`.
- **DoD:** Self-analysis (`bin/qmx check src/`) green. All integration tests green. `composer benchmark:check`: <1% wall-time regression.

### Phase 6 — Cleanup + lint guard
- Delete `Core\Util\PathNormalizer` (superseded by `PathFactory`).
- Audit remaining `string $path|$file|$filePath` in production code (grep + manual review). Allowed survivors: PHP I/O wrappers (`file_get_contents`, `file_put_contents`, `is_file`, `is_dir`, `pathinfo`), `proc_open` / `exec` args, JSON deserializer entry points.
- Wire the **PHPStan custom rule** (D8, spike committed in Phase 0): `BannedStringPathRule` is split into two rules over `Node\Stmt\Property` and `Node\Param` (Codex round-2: promoted constructor properties need the Param rule too); both reject `string`, `?string`, and `string|null` types when the property/parameter name matches `$file|$path|$filePath|$oldPath` in `Qualimetrix\…` namespaces. Tested via PHPStan's standard `RuleTestCase` harness (not a separate package — the test base lives in `phpstan/phpstan` itself). `phpstan.neon` allow-list documents legitimate exceptions (`HtmlTreeNode.$path` — namespace path, etc.).
- PHPDoc array-shape audit: search for `array{file: string, …}` / `['file' => string]` patterns in HTML/SARIF formatters; convert to `array{file: ?RelativePath, …}` where appropriate or document why string is correct (e.g., serialized JSON output).
- **DoD:** Structural test passes. CHANGELOG updated. ADR 0015 status flipped to Accepted. `[[backlog_attention_items]]` #1 marked closed.

## 7. Edge cases (all round-1 questions resolved)

- **Q-EC1 → RESOLVED (D6):** `RelativePath` rejects leading `..` after normalization. Out-of-base scenarios from git input are signaled via `PathFactory::gitRelative() → null`.
- **Q-EC2 → RESOLVED:** `Location.file` is nullable (`?RelativePath`); `Location::none()` produces a path-less location. JSON wire surface preserved via `?->value() ?? ''`.
- **Q-EC3 (Windows):** VO normalizes `\` → `/` on input, stores POSIX only. `AbsolutePath::fromString('C:\src')` rejects (must start with `/`). Documented limitation; users on Windows continue to use WSL.
- **Q-EC4 (symlinks):** `AbsolutePath::canonicalize()` returns a new `AbsolutePath`; the canonical form may be outside the project root (symlinked source tree). Decision: do not enforce in-tree; let callers (`ScopeWarningChecker`) decide. **Additional note (round-2 Claude F3):** D7 lexical `..` resolution in `fromString()` does **not** call `realpath()` and therefore does not account for symlinks — `fromString('/a/symlink-to-b/../x')` produces `/a/x` lexically, which may differ from the symlink-aware truth. This is acceptable for type-level normalization (predictable + I/O-free); callers needing symlink-aware semantics call `canonicalize()` explicitly. The behavior shift vs the old `PathNormalizer` (which never resolved `..`) is documented in CHANGELOG under "Internal type changes."
- **Q-EC5 → RESOLVED (Codex F6):** Both VOs implement explicit `__serialize`/`__unserialize`. Wire format is pinned to `['value' => string]`.
- **Q-EC6 → RESOLVED (Codex F7):** `Stringable` for explicit `(string)` cast and string interpolation only. Array keys require explicit `->value()` or `(string) $path`.
- **Q-EC7 → RESOLVED:** `Location::toString()` returns `$this->file?->value() . ($this->line ? ":$line" : '')` and `''` for `none()`. JSON shape unchanged.
- **Q-EC8 (Gemini F2 — `.` as root) → REJECTED:** No use case in current codebase for "RelativePath denoting current/root directory." Project root is always carried as `AbsolutePath`. Allowing `.` would weaken invariants (`segments()`, `parent()`, `basename()` all need special cases). If a future use case emerges, revisit.
- **Q-EC9 (D5 — `ChangedFile.path` semantics):** `ChangedFile.path` is project-relative `RelativePath`. Translation happens inside `GitClient::parseChangedFiles()` before construction. The single boundary point is unit-tested for the git-subdir scenario.

## 8. Test plan

- **Phase 0 unit tests:** ≥30 cases. Pin every validation rule in §3 and every edge case in §7. Include `__serialize`/`__unserialize` round-trip via PHP `serialize` AND `igbinary_serialize` (Q-EC5). Microbench: `fromString` ≤500ns/call (R3).
- **Phase 1b integration test (T10 regression):** temporary git repo with project root as a subdirectory of git top-level. `--report=git:HEAD~1` must correctly include violations in changed files inside the project subdir AND correctly **exclude** files in sibling subdirs (out-of-base case). Also: actual amphp worker round-trip of `FileProcessingTask` → `FileProcessingResult` to validate IPC serialization (R1).
- **Boundary round-trip tests:** CLI arg → AbsolutePath → RelativePath → output → re-parse → equality. Same for git output → ChangedFile → reporting. Same for baseline write → read.
- **Self-analysis after each phase:** `bin/qmx check src/` must be green.
- **Benchmark regression:** `composer benchmark:check` <1% wall-time regression vs current baseline (R3 budget). Microbench on the largest benchmark fixture.
- **Phase 6 PHPStan custom rule test:** unit-tested via `phpstan/phpstan-rules` test harness; fixture cases for allowed (in allow-list) and rejected (production with semantic name + `string` type).
- **Phase 6 array-shape audit:** grep + manual; documented in plan completion.

## 9. Risks and mitigation

| #   | Risk                                                                                                    | Mitigation                                                                                                                                                                           |
| --- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| R1  | Serialization break for parallel workers (`amphp/parallel` IPC)                                         | Phase 0 unit tests + Phase 1b integration test through actual amphp worker path. Explicit `__serialize`/`__unserialize` pins wire format (Q-EC5).                                    |
| R2  | `AggregationHelper` array-key drift after path-representation change                                    | Phase 5 contract test: all keys come from the same boundary factory; VO normalization is idempotent. PHPStan custom rule (Phase 6) prevents silent re-introduction of `string` keys. |
| R3  | Performance regression on large projects                                                                | Phase 0 microbench: `fromString` ≤500ns/VO. `composer benchmark:check` <1% wall-time regression. Stop migration if exceeded.                                                         |
| R4  | Symlinks: `canonicalize()` may map a project file to a path outside project root                        | Documented; callers in `ScopeWarningChecker` already handle this via existing `tryRelativizeTo`.                                                                                     |
| R5  | Phase 1c (Core/Symbol) is deep in the pipeline; canonical-key drift possible                            | After Phase 1c: run dogfooding + baseline round-trip + full test suite. Canonical-key strings unchanged (use `->value()` on serialization).                                          |
| R6  | `ChangedFile` invariant: git-relative leaks into `RelativePath` field if translation misses a code path | Phase 1b integration test exercises all four `git diff` row types (Added/Modified/Deleted/Renamed) from a git-subdir invocation.                                                     |
| R7  | Phase 6 PHPStan custom rule false-positives `HtmlTreeNode.$path` (namespace path, not file)             | Explicit allow-list in `phpstan.neon` with documented rationale per entry; fixture-tested in the rule's own test suite.                                                              |

## 10. Rollback strategy

- Each phase is a single commit on `main`.
- Phase 0 reversible at zero cost (nobody uses the VO yet).
- Phases 1a → 1b → 1c are independently reversible — splitting Phase 1 minimizes red-state risk (round-1 Claude F2 + Codex F5).
- **Phase 1a → 1b ordering note (round-2 Claude F5):** between Phase 1a and Phase 1b there is a transient state where `Location.file: ?RelativePath` exists but `FileProcessingResult.filePath: string` does not yet. In this gap `FileProcessor` constructs `FileProcessingResult` from a `PathNormalizer::relativize()` string, then the string later flows into a `Location` via `RelativePath::fromString(…)`. This introduces ~3-5 `RelativePath::fromString()` calls inside production code that Phase 1b will remove. Acceptable; do **not** revert Phase 1b without also reverting Phase 1a, or those temporary call sites become dangling.
- Phases 2–5 reversible via `git revert`; PHPStan + tests ensure each phase is internally consistent.
- Phase 6 deletes `PathNormalizer`. If round-2 surfaces unexpected external consumers, keep a deprecated shim (one-line wrapper around `PathFactory::projectRelative()`) for one minor release.

## 11. Out of scope (explicit non-goals)

- `AnalyzedFile` / `FileInfo` aggregate VO (separate ADR if needed later).
- Windows native path support (`C:\…`).
- Path traversal / security validation (`..` jailing) as a security boundary — `RelativePath` rejects leading `..` for type consistency, not security.
- A typed `ProjectRoot` distinct from `AbsolutePath` — adds friction without strong type-safety win at this scale.
- A typed `GitToplevel` distinct from `AbsolutePath` — same as above.
- **A typed `GitRelativePath` distinct from `RelativePath`** — Codex F1 raised the case; D5 resolves via eager translation at the producer instead of a third VO.

## 12. Acceptance for round-2 review

Reviewers should answer:

1. Are D5, D6, D7, D8 the right resolutions for the round-1 conflicts?
2. Are the **expanded §4 inventory** and **§5 boundary map** now complete? Any field/boundary still missing?
3. Are the **split phases 1a/1b/1c** sized correctly?
4. Is the **PHPStan custom rule** in Phase 6 (D8) a realistic guard, given the noted allow-list cases?
5. Does **R6** + Phase 1b integration test adequately address the git-subdir regression (T10 root case)?
6. Anything new exposed by the changes that the round-1 reviewers couldn't see?

After round-2 review: incorporate any new findings, finalize ADR 0015, then move to Stage 2 (implementation plan with per-file checklist).
