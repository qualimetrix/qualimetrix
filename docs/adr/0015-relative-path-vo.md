# 0015. Typed `AbsolutePath` and `RelativePath` Value Objects

**Date:** 2026-05-17
**Status:** Proposed (round-2 of plan-stage triple review applied; round-2 Gemini unavailable due to auth/transport failure — accepted with Claude + Codex round-2 coverage)
**Related:** Concept plan at [`docs/internal/plans/relative-path-vo-concept.md`](../internal/plans/relative-path-vo-concept.md); ADR 0012 (hybrid direction — `Core\Path` lives in the cross-cutting `Core` layer per the framework).

## Context

The codebase carries file paths in untyped `string` fields with three
different semantic meanings — absolute, project-relative (to `getcwd()` at
the start of the run, set by `--working-dir`), and git-relative (to git
top-level). The three are normalized only inside the static helper
`Core\Util\PathNormalizer::relativize()` and consumed inconsistently
elsewhere.

T10 from the 2026-03-29 backlog (see `[[backlog_attention_items]]` #1)
documented one symptom: `GitScopeFilter` compared
`$violation->location->file` (project-relative) against `$file->path`
(git-relative), and the comparison succeeded only when the project root
coincided with the git top-level. The point-wise fix shipped, but the
underlying bug class — "wrong kind of path silently flowing through a
`string` parameter" — survives across roughly 80 files and 422 string
instantiations.

PHPStan level 8 cannot help: `string` is `string`, regardless of which
of the three semantic kinds is meant.

## Decision

Introduce a two-VO family in a new `Qualimetrix\Core\Path` namespace and
adopt it everywhere except raw I/O boundaries:

- `AbsolutePath` — POSIX absolute path, `final readonly`, validates on
  construction (must start with `/`, must not be empty), normalizes
  separator and `.` segments, **resolves `..` segments lexically** with
  no I/O. Operations: `relativizeTo(AbsolutePath $base):
  RelativePath` / `tryRelativizeTo(): ?RelativePath`,
  `joinRelative(RelativePath): AbsolutePath`, `canonicalize()` (explicit
  `realpath()`, throws on missing file), `exists() / isFile() /
  isDirectory()`.
- `RelativePath` — POSIX relative path, `final readonly`, validates on
  construction (must not start with `/`, must not be empty, must not
  resolve to a leading `..` after lexical normalization — the in-base
  invariant). Operations: `resolveAgainst(AbsolutePath): AbsolutePath`,
  segment-based `startsWith(self): bool`, `withoutPrefix(self): self` /
  `tryWithoutPrefix(): ?self`, `join(self): self`, `segments() /
  parent() / basename() / extension()`.

Both VOs implement explicit `__serialize`/`__unserialize` returning
`['value' => string]`, pinning the wire format independently of internal
property names — critical for `amphp/parallel` IPC and cache-file
compatibility across versions.

A `PathFactory` helper in the same namespace owns the boundary
conversions today scattered across `PathNormalizer::relativize()`,
`BaselineWriter::normalizeProjectRoot()`, and ad-hoc `realpath()` calls
in `ScopeWarningChecker`. It also exposes
`gitRelative(string, AbsolutePath, AbsolutePath): ?RelativePath`, which
performs the git-to-project translation **eagerly inside
`GitClient::parseChangedFiles()`**. Once a `ChangedFile` exists, its
`path` is invariantly project-relative; out-of-project git output
returns `null` and is filtered out at the producer. No third
`GitRelativePath` VO is introduced — the boundary is narrow enough that
one location suffices.

Existing domain types adopt the VOs in-place — `Location.file` becomes
`?RelativePath` (with the wire-surface preserved via `?->value() ?? ''`
in formatters), `FileProcessingResult.filePath` becomes `RelativePath`,
`ChangedFile.path/oldPath` become `RelativePath`. The migration is
phased across **eight** commits (Pre-Phase 0 → Phase 0 → Phases
1a/1b/1c → Phases 2–6), each ending on a green `composer check`. The
last phase introduces a PHPStan custom rule
(`BannedStringPathRule`) that rejects any `string`-typed property or
parameter in `Qualimetrix\…` namespaces with semantic names
(`$file`, `$path`, `$filePath`, `$oldPath`), with an explicit
`phpstan.neon` allow-list for documented exceptions
(e.g., `HtmlTreeNode.$path` — a namespace path, not a filesystem path).

### Architectural placement

`Core\Path` is a cross-cutting primitive per ADR 0012's "retained
horizontal layers" rule: it has no dependencies (PHP runtime + standard
library only), it is consumed by every domain slice and by
Infrastructure, and it does not own its own adapters. Boundary
conversions (CLI input, git output, baseline I/O) live in
`Infrastructure` / `Analysis` and call `PathFactory`; no feature slice
acquires a dependency on raw I/O through this namespace.

### Alternatives considered

| Alternative                                                                | Reason rejected                                                                                                                                                                                                                                          |
| -------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Single `RelativePath` only**                                             | The T10 bug class involves absolute paths leaking into relative-path contexts; without an `AbsolutePath` type, `realpath()` results re-enter as `string` and PHPStan still cannot help.                                                                  |
| **Single `Path` with a tagged `enum Kind`**                                | The kind would be a runtime check, not a compile-time guarantee — same blind spot PHPStan has today.                                                                                                                                                     |
| **A third `GitRelativePath` VO**                                           | Codex F1 in round-1 review raised the case; rejected via D5 in the concept plan: translation happens eagerly inside `GitClient::parseChangedFiles()` (1 location), so the third VO would only thread through ~5 callsites without preventing a real bug. |
| **Introduce `AnalyzedFile` aggregate alongside paths**                     | Bundles two unrelated concerns and inflates blast radius. Deferred to a separate ADR if a need emerges.                                                                                                                                                  |
| **`ProjectRoot` and `GitToplevel` as separate VOs**                        | They are absolute paths; adding type distinctions adds friction without a corresponding bug class to close.                                                                                                                                              |
| **Surgical fix (only `GitScopeFilter`, `SarifFormatter`, `Baseline`)**     | Leaves the bug class alive elsewhere. AI-cost of full migration is low; partial migration is the worst of both worlds.                                                                                                                                   |
| **Incremental opportunistic migration ("touch as you go")**                | Same as above. Mixed-state code is harder to reason about than fully-typed or fully-untyped.                                                                                                                                                             |
| **PHPUnit reflection test instead of PHPStan custom rule (Phase 6 guard)** | A name-based reflection test false-flags `HtmlTreeNode.$path` (namespace, not file) and misses array-shape leaks (`['file' => string]` in formatters). PHPStan custom rule with explicit `phpstan.neon` allow-list is more precise.                      |

### Why this work now

- T10 has been a known follow-up since 2026-03-29; no adjacent refactor
  has organically pulled the migration along, so an explicit pass is
  required.
- The post-remediation backlog is empty, the architecture vertical-slice
  migration has landed, deptrac is retired. There is no larger
  in-flight refactor to compete for review attention.
- The codebase has the structural test infrastructure
  (`DogfoodingTopologyTest` precedent in ADR 0014) to add the Phase 6
  PHPStan custom rule guard cheaply.

## Consequences

- PHPStan rejects every path-kind mismatch at compile time. The T10 bug
  class is closed structurally rather than by review vigilance.
- Phase 6 deletes `Core\Util\PathNormalizer`. Pre-Phase 0 annotates it
  `@internal` first; if round-2 surfaces external consumers, a
  deprecated one-line shim around `PathFactory::projectRelative()` can
  bridge one minor release (§10 rollback in the concept plan).
  Note on SemVer (round-2 Claude F6): Qualimetrix is a CLI tool, not a
  library — its public API is the CLI surface and the `qmx.yaml` config
  schema, not its PHP classes. Removing an `@internal`-marked
  cross-cutting helper is not a SemVer break; the annotation is a soft
  signal to any third party that does import the class anyway.
- `Location::file` becomes nullable (`?RelativePath`); the sentinel
  `''` for `Location::none()` becomes `null`. **JSON wire surface is
  preserved** — formatters call `$location->file?->value() ?? ''`, so
  HTML report consumers (`composer test:js` / vitest) and SARIF
  consumers see no change in output shape.
- The Reporting layer no longer normalizes separators manually
  (`SarifFormatter.php:173`): `RelativePath` stores POSIX-only state.
- Parallel-worker serialization (`amphp/parallel` ships
  `FileProcessingTask` / `FileProcessingResult` between processes) keeps
  working — explicit `__serialize`/`__unserialize` pins the wire format
  to `['value' => string]` regardless of internal property name. Phase
  1b adds an integration test exercising the actual worker IPC path.
- A small performance cost per path construction (one normalization
  pass). Phase 0 microbench requirement: `fromString()` ≤500ns/call,
  measured against `str_starts_with`/`substr` ops, not against
  `realpath()` (which is I/O-bound). Benchmark regression budget:
  `composer benchmark:check` <1% wall-time.

### What this is NOT

- Not Windows native path support. `AbsolutePath::fromString()` rejects
  `C:\…`. Users on Windows currently rely on WSL or normalized inputs;
  that does not change.
- Not a path-traversal security boundary. `RelativePath` rejects leading
  `..` for type-invariant consistency, not as a security control.
- Not an `AnalyzedFile` aggregate. Domain types continue to carry path
  fields alongside their other state; only the path field's type
  changes.
- Not a third `GitRelativePath` VO (see Alternatives).

## References

- Concept plan: [`docs/internal/plans/relative-path-vo-concept.md`](../internal/plans/relative-path-vo-concept.md)
- Bug origin: `[[backlog_attention_items]]` #1 (T10 follow-up,
  2026-03-29 backlog execution session)
- Affected boundary today: `src/Core/Util/PathNormalizer.php`,
  `src/Infrastructure/Git/GitScopeFilter.php:74`,
  `src/Baseline/BaselineWriter.php:86-100`,
  `src/Reporting/Formatter/Sarif/SarifFormatter.php:170-191`
- Architectural framework: ADR 0012 (hybrid direction) — `Core\Path` is
  a cross-cutting primitive in the retained `Core` horizontal layer
