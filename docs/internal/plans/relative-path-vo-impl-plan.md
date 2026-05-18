# RelativePath VO — Implementation Plan (Stage 2, Round 4)

**Status:** Round-4 draft after narrow Round-3 verification (Claude + Codex). Both verdicts = Needs Round 4 due to convergent HIGH findings on (a) H1 swapped-arg test logic, (b) M3 `tryRelativizeTo` on the wrong VO, (c) `CircularDependencyRule.php:70` `''` literal becoming a `TypeError` after Phase 1c, plus inventory gaps in Phase 1a (`DuplicateLocation` consumers + `SarifFormatter:125`) and missing test enumeration in Phase 5 (`AnalysisConfigurationTest`). All applied below. Gemini CLI remains unavailable; two-reviewer round-4 accepted.
**Companion documents:**
- Concept plan: [`relative-path-vo-concept.md`](./relative-path-vo-concept.md) — locked design decisions
- ADR: [`docs/adr/0015-relative-path-vo.md`](../../adr/0015-relative-path-vo.md)

This plan is the **execution checklist** for the migration. It lists files to touch, signatures to add/change, test classes to add (names only, no bodies), and commit message templates. Per CLAUDE.md global rules: no full implementations, no test bodies, no boilerplate. Pseudocode only when essential to disambiguate behavior.

**Phase count:** Phase 0 + Phases 1a/1b/1c + Phase 2 + 3 + 4 + 5 + 6 = **9 commits** on `main` (Pre-Phase 0 dropped per round-2 Claude F13 — `@internal` 9-commits-before-deletion was theatre).

**All paths in this plan are grep-verified against `main` at `57d74a8`.** Round-1 review caught 4 phantom paths; round-2 verification surfaced one more (`Configuration/Pipeline/Pipeline.php` → fixed to `ConfigurationPipeline.php`). Round-3 pinned every claim.

**Round-1 reviewers (Stage 2):** Claude (13 findings; 4 HIGH), Gemini (5; 1 HIGH), Codex (6; 2 HIGH). All 3 verdicts = Needs Round 2.
**Round-2 verification (Stage 2):** Claude general-purpose (10 findings; 3 HIGH + 4 MEDIUM + 3 LOW), Codex (5 findings; 3 HIGH + 2 MEDIUM), Gemini fallback (1 MEDIUM, CLI crash → self-review). All findings either applied below or explicitly rejected with rationale. Round-2 HIGH summary (verified by grep against `main@57d74a8`):
- H1 — `ChangedFile::fromGitOutput()` arg order + `GitClient::$repoRoot` is project root, NOT git toplevel — **fixed in Phase 1b row below**.
- H2 — Phase 1a transient surface is 39 `new Location()` production sites + 5+ comparator sites + 4 sentinel sites, not ~5 — **fixed in Phase 1a inventory below**.
- H3 — `AbstractRule::getEffectiveOptions`/`getEffectiveSeverity` cascade unannounced — **fixed by adding `AbstractRule.php` to Phase 1c table**.
- H4 — PHPStan property-only rule misses promoted constructor properties (`Node\Param`) — **fixed by splitting rule into `BannedStringPathPropertyRule` (Stmt\Property) + `BannedStringPathPromotedPropertyRule` (Param)**.

---

## Pre-flight (run once, before Phase 0)

Hard requirements that must be true at the start:

1. `composer check` is green on `main` at `57d74a8`.
2. Latest `bin/qmx check src/` (self-analysis) returns zero violations.
3. `composer benchmark:check` baseline numbers captured.
4. No in-flight work on `main` that touches `Core\Util\PathNormalizer`, `Core\Violation\Location`, `Analysis\Collection\FileProcessingResult`, `Infrastructure\Git\ChangedFile`.

---

## Phase 0 — Introduce VOs in `Core\Path` (+ `@internal` annotation + PHPStan rule spike)

### Files added (4)

- `src/Core/Path/AbsolutePath.php` — `final readonly class AbsolutePath implements \Stringable` with the §3 contract from concept plan.
- `src/Core/Path/RelativePath.php` — `final readonly class RelativePath implements \Stringable` with the §3 contract.
- `src/Core/Path/PathFactory.php` — `final class PathFactory` (static factory methods only, no state).
- `src/Core/Path/README.md` — one-paragraph orientation pointing at ADR 0015.

### Files annotated (1)

- `src/Core/Util/PathNormalizer.php` — `@internal` on class docblock with link to ADR 0015 (folded in here per round-2 Claude F13; the deletion happens in Phase 6 anyway).

### PHPStan rule skeleton (committed but NOT wired into `phpstan.neon` until Phase 6)

Scope decision (round-2 Claude F3 + Gemini F3): rule fires **only on property declarations**, not on free-standing parameters — `$path` parameters in production are too commonly semantic (config-key dotted paths in `AnalysisConfiguration::getString`, YAML loader file-path arg, etc.). Properties carry the long-lived state where the bug class actually lives.

**Promoted-properties caveat (round-2 Codex H4):** PHP promoted constructor properties (e.g., `FileProcessingTask::__construct(private readonly string $filePath, …)`) appear in the AST as `Node\Param` with non-zero `flags`, **not** as `Node\Stmt\Property`. A single `Node\Stmt\Property`-only rule silently misses every promoted property in the codebase. Solution: split into two small rules sharing one matcher helper.

Files committed:

- `tools/phpstan/Rules/PathPropertyMatcher.php` — shared helper. Inputs: property name + declared type + enclosing class FQN. Returns `bool` (matches forbidden semantic + scoped namespace).
- `tools/phpstan/Rules/BannedStringPathPropertyRule.php` — `PHPStan\Rules\Rule<Node\Stmt\Property>` skeleton. Delegates to `PathPropertyMatcher`.
- `tools/phpstan/Rules/BannedStringPathPromotedPropertyRule.php` — `PHPStan\Rules\Rule<Node\Param>`. Filters via `$param->isPromoted()` (the `nikic/php-parser` canonical helper returning `flags !== 0 || hooks !== []`; forward-compatible with PHP 8.4 property hooks). Delegates to `PathPropertyMatcher`. Existing codebase precedent: `src/Metrics/Structure/MethodCountVisitor.php:410`, `src/Metrics/Structure/TccLccVisitor.php:116` already inspect `flags`/promotion on params.
- `tools/phpstan/Rules/README.md` — orientation.

**Forbidden-name set** (both rules): `$file`, `$filePath`, `$oldPath`. **NOT** `$path` — `HtmlTreeNode::$path` is a namespace path, and after Phase 1b `ChangedFile::$path` is `RelativePath` so the name is irrelevant to type-safety. **Forbidden types:** `string`, `?string`, `string|null`.

**Namespace scope** (both rules): only fires when class namespace starts with `Qualimetrix\Core`, `Qualimetrix\Analysis`, `Qualimetrix\Reporting`, `Qualimetrix\Baseline`, `Qualimetrix\Infrastructure\Git`, `Qualimetrix\Infrastructure\Parallel`, or `Qualimetrix\Infrastructure\Cache`. Excludes `Qualimetrix\Configuration\…` and `Qualimetrix\Infrastructure\Console\…` (CLI/loader boundary classes that may stay string-typed).

Initial skeletons return `[]` (no errors) — they ship "off" so Phase 0 doesn't fail CI; Phase 6 turns them on.

### Tests added

Path namespace: `tests/Unit/Core/Path/`.

| Test class                                 | Cases                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `AbsolutePathTest`                         | Construction (valid POSIX, leading `/` enforcement), normalization (`/a//b` → `/a/b`, `/a/./b` → `/a/b`, `/a/b/../c` → `/a/c`, trailing-slash strip), `equals`, `relativizeTo` (in-base success, out-of-base throw), `tryRelativizeTo` (null on out-of-base), `joinRelative`, `canonicalize` (existing path), `canonicalize` (missing path throws), `exists` / `isFile` / `isDirectory` smoke. Reject: empty, relative path, Windows `C:\`.                                        |
| `RelativePathTest`                         | Construction (valid relative), normalization (strip leading `./`, replace `\` → `/`, lex `..` resolution `a/../b` → `b`), reject: empty, pure `.`, absolute, leading `..` after norm. `segments`, `parent` (null on single segment), `basename`, `extension` (null when missing). `equals`, `startsWith` segment-based (`foobar` ⊄ `foo`), `withoutPrefix` throw, `tryWithoutPrefix` null, `join`, `resolveAgainst`.                                                               |
| `PathFactoryTest`                          | `projectRelative` (raw absolute under root, raw relative passes through). `tryProjectRelative` (null on out-of-base). `gitRelative` (git path under project root → RelativePath; git path outside project root → null; git path equal to project root → null). `fromCliArgument` (absolute pass-through, relative against cwd, symlink resolution).                                                                                                                                |
| `AbsolutePathSerializationTest`            | PHP `serialize`/`unserialize` round-trip; `igbinary_serialize`/`igbinary_unserialize` round-trip (`@requires extension igbinary` for the igbinary half); assert wire format is `['value' => string]`.                                                                                                                                                                                                                                                                              |
| `RelativePathSerializationTest`            | Same as above for RelativePath.                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| `PathBenchmarkTest`                        | Microbench: `RelativePath::fromString('src/Core/X.php')` ≤500ns/call median over 100k iterations. `AbsolutePath::fromString('/very/long/.../path.php')` ≤500ns. Marked `@group benchmark` so it skips by default.                                                                                                                                                                                                                                                                  |
| `BannedStringPathPropertyRuleTest`         | Standard `PHPStan\Testing\RuleTestCase` (lives in `phpstan/phpstan` core — no separate package needed). Fixture cases: (a) `Qualimetrix\Reporting\Health\WorstOffender` with `string $file` → reported; (b) `Qualimetrix\Reporting\Formatter\Html\HtmlTreeNode` with `string $path` → not reported (different field name); (c) class outside scoped namespaces → not reported.                                                                                                     |
| `BannedStringPathPromotedPropertyRuleTest` | Same harness. Fixture cases mirror `FileProcessingTask` shape: (a) `final class X { public function __construct(private readonly string $filePath) {} }` in scoped namespace → reported; (b) same with `private readonly RelativePath $filePath` → not reported; (c) non-promoted plain `__construct` param with type `string $filePath` → not reported (matches only `flags !== 0`); (d) promoted param `private readonly string $configKey` (non-forbidden name) → not reported. |

**DoD:**
- ≥30 unit assertions across the path tests.
- `composer check` green.
- `composer test -- --group benchmark` shows ≤500ns median per construction.
- PHPStan rule skeleton produces zero errors when wired against a clean fixture.
- `Core\Util\PathNormalizer` has `@internal` annotation but is otherwise unchanged.

**Commit:**
```
feat(core,path): introduce AbsolutePath + RelativePath VOs (ADR 0015 Phase 0)

Two final readonly VOs in Core\Path with PathFactory boundary helpers.
Explicit __serialize/__unserialize pins wire format to ['value' => string]
for IPC/cache compatibility. Lexical normalization (no I/O). Microbench
budget ≤500ns/construction.

BannedStringPathPropertyRule skeleton committed in tools/phpstan/Rules/
but not yet wired into phpstan.neon — wiring happens in Phase 6.
PathNormalizer marked @internal ahead of Phase 6 removal.

Production code untouched: nothing uses these VOs yet.
```

---

## Phase 1a — Pure value carriers

### Files changed (production, grep-verified)

| File                                         | Change                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Core/Violation/Location.php`            | `public string $file` → `public ?RelativePath $file`; `Location::none()` → `new self(null, null, false)`; `isNone()` → `$this->file === null`; `toString()` → `$this->file !== null ? ($this->file->value() . ($this->line !== null ? ":$this->line" : '')) : ''` (round-2 Claude F11: explicit `!== null`, not falsy). **Add `pathString(): string` returning `$this->file?->value() ?? ''`** (round-2 H2 bridge — canonical Phase 4 formatter pattern, used at all 28 consumer sites enumerated below). |
| `src/Core/Duplication/DuplicateLocation.php` | `public string $file` → `public RelativePath $file`. Add `pathString(): string` for consumer symmetry.                                                                                                                                                                                                                                                                                                                                                                                                    |
| `src/Core/Exception/ParseException.php`      | `public readonly string $filePath` → `public readonly RelativePath $filePath`.                                                                                                                                                                                                                                                                                                                                                                                                                            |
| `src/Reporting/Health/WorstOffender.php`     | `public ?string $file` → `public ?RelativePath $file`. Add `pathString(): string` for consumer symmetry.                                                                                                                                                                                                                                                                                                                                                                                                  |

### Transient bridge surface — explicit inventory (round-2 H2)

Round-2 verification (grep `new Location(` against `main@57d74a8`) returned **39 production construction sites** plus an additional 28 read-sites of `location->file`, of which 5+ use `strcmp` / `<=>` comparators and 4 use `=== ''` sentinels. The original "~50" estimate undersold this by ~10×. Phase 1a in its current form will **not compile** without addressing every comparator and sentinel site.

**Strategy choice:** add `Location::pathString(): string` returning `$this->file?->value() ?? ''` as a deliberate bridge method (Phase 1a). This single method:
1. Replaces every `$location->file` read inside formatters/comparators/sentinels with a one-token swap (`->pathString()`), preserving today's string semantics.
2. Survives Phase 4 (formatters keep using `pathString()` — it documents the VO-to-wire conversion explicitly).
3. Avoids spreading `?->value() ?? ''` across 28 call-sites and a future maintenance ask to find them all.

The bridge method is **NOT** a long-term API smell — it pins the wire-surface contract at one location and is explicitly the canonical formatter pattern from Phase 4 onward. Comparators that need stable sort behavior over null use `pathString()` (empty string sorts first).

**Construction sites (39, grep `new Location\|new DuplicateLocation\|new ParseException\|new WorstOffender`):**
- 35 in `src/Rules/**/*Rule.php` (all rule files passing `$classInfo->file`, `$methodInfo->file`, `$symbolInfo->file`, `$fileInfo->file`)
- 2 in `src/Architecture/Rules/**/*Rule.php`
- 1 in `src/Analysis/Pipeline/AnalysisPipeline.php`
- 1 in `src/Analysis/Collection/Dependency/Handler/DependencyContext.php` (passes `$this->file`)

All 39 take their first arg from a **`string`-typed field that becomes `RelativePath` in Phase 1b/1c/5**. Strategy: leave the bridge implicit (the field becomes a VO at exactly the same commit that drops the string assumption). The 39 callsites need **no code change in Phase 1a** as long as the called class no longer requires `string` — only the 4 VO carriers themselves (Location, DuplicateLocation, ParseException, WorstOffender) accept `?RelativePath` as first arg, and the `string $classInfo->file` is still `string` until Phase 1c. Bridge applied here: each construction site wraps `$classInfo->file` in `RelativePath::fromString(...)` for the duration of Phase 1a → 1c.

To minimize churn, Phase 1a adds **explicit bridge constructions** in the 39 sites listed above. They are deleted in Phase 1c when the upstream fields turn into `RelativePath`. The same automated rewrite script (`scripts/refactor/phase-1a-test-rewrite.php`) is reused — its skip-on-variable behavior covers the rule-construction case; manual wrap on construction is grep-driven.

**Consumer sites with sentinel / comparator changes (Phase 1a, permanent):**

`location->file` reads that don't survive a pure type swap:

| File                                                                                                                                                                                                                                                                                | Site                                                                                                                                               | Change                                                                                                                                                                |
| ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Reporting/Formatter/Support/ViolationSorter.php`                                                                                                                                                                                                                               | 6 `<=>` comparators + 1 `GroupBy::File` array key + `extractClassName()`                                                                           | Replace `$violation->location->file` with `$violation->location->pathString()` at lines 52, 68, 74, 83, 91, 99, 117. Comparators get stable sort over null.           |
| `src/Reporting/Formatter/Json/JsonViolationSection.php`                                                                                                                                                                                                                             | comparator (l. 57) + relativizePath arg (l. 94)                                                                                                    | `pathString()` for comparator; `relativizePath()` arg is migrated in Phase 4 via FormatterContext signature.                                                          |
| `src/Reporting/Impact/ImpactCalculator.php:82`                                                                                                                                                                                                                                      | `<=>` comparator                                                                                                                                   | `pathString()`.                                                                                                                                                       |
| `src/Reporting/Debt/DebtCalculator.php:35`                                                                                                                                                                                                                                          | `$file = $violation->location->file`                                                                                                               | `$file = $violation->location->pathString()`.                                                                                                                         |
| `src/Analysis/Pipeline/AnalysisResult.php:122`                                                                                                                                                                                                                                      | `strcmp($a->location->file, $b->location->file)`                                                                                                   | `strcmp($a->location->pathString(), $b->location->pathString())`.                                                                                                     |
| `src/Core/Violation/Filter/PathExclusionFilter.php:23,27`                                                                                                                                                                                                                           | `=== ''` sentinel + `pathMatcher->matches(string)`                                                                                                 | Replace sentinel with `$violation->location->isNone()`; pass `pathString()` to matcher (PathMatcher itself migrates to VO in Phase 1c — string accepted in Phase 1a). |
| `src/Analysis/RuleExecution/RuleExecutor.php:65-66`                                                                                                                                                                                                                                 | `=== ''` sentinel + `isExcluded(…, string)`                                                                                                        | Same: `isNone()` + `pathString()`.                                                                                                                                    |
| `src/Infrastructure/Console/ViolationFilterOrchestrator.php:91`                                                                                                                                                                                                                     | `=== ''` sentinel                                                                                                                                  | `isNone()`.                                                                                                                                                           |
| `src/Baseline/Suppression/SuppressionFilter.php:43`                                                                                                                                                                                                                                 | `$file = $violation->location->file` assignment                                                                                                    | `$file = $violation->location->pathString()`.                                                                                                                         |
| `src/Reporting/Formatter/Support/DetailedViolationRenderer.php:102,191`                                                                                                                                                                                                             | reads                                                                                                                                              | `pathString()` or formatter-context call (the latter migrates in Phase 4).                                                                                            |
| `src/Reporting/Formatter/Summary/TopIssuesRenderer.php:100`                                                                                                                                                                                                                         | `$context->relativizePath($violation->location->file)`                                                                                             | Phase 1a: argument becomes `$violation->location->pathString()` (string-keeping). Phase 4 migrates `relativizePath` signature.                                        |
| `src/Reporting/Formatter/Json/JsonFormatter.php:124`                                                                                                                                                                                                                                | `$context->relativizePath($violation->location->file)`                                                                                             | Same.                                                                                                                                                                 |
| `src/Reporting/Formatter/Html/HtmlViolationPartitioner.php:100-102`                                                                                                                                                                                                                 | `$context->relativizePath($violation->location->file)`                                                                                             | Same.                                                                                                                                                                 |
| `src/Reporting/Formatter/TextFormatter.php:114`, `src/Reporting/Formatter/CheckstyleFormatter.php:64`, `src/Reporting/Formatter/GithubActionsFormatter.php:55`, `src/Reporting/Formatter/Sarif/SarifFormatter.php:107`, `src/Reporting/Formatter/GitLabCodeQualityFormatter.php:32` | `$context->relativizePath($violation->location->file)`                                                                                             | Same.                                                                                                                                                                 |
| `src/Infrastructure/Git/GitScopeFilter.php`                                                                                                                                                                                                                                         | `changedPaths` array key (Phase 1b path is `RelativePath`; `location->file` is `?RelativePath` after 1a; both compared)                            | `pathString()` until Phase 3 where both become VOs and compare via `equals()`.                                                                                        |
| `src/Reporting/Formatter/Sarif/SarifFormatter.php:125`                                                                                                                                                                                                                              | `$context->relativizePath($loc->file)` — `$loc` is a `Core\Violation\Location` rendered as a SARIF `relatedLocations` entry (typehint at line 121) | Phase 1a: replace arg with `$loc->pathString()` for the duration of Phase 1a → Phase 4 keeps the same shape since `relativizePath` survives as nullable-VO → string.  |
| `src/Core/Duplication/DuplicateBlock.php:40`                                                                                                                                                                                                                                        | `usort` comparator `$a->file <=> $b->file` over `DuplicateLocation::$file`                                                                         | Replace with `$a->pathString() <=> $b->pathString()`. `DuplicateLocation::pathString()` is added in the carrier table above.                                          |
| `src/Analysis/Duplication/DuplicationDetector.php:350,364`                                                                                                                                                                                                                          | `$covered[$loc->file]` array key for range-overlap dedup                                                                                           | Replace with `$covered[$loc->pathString()]`. Array remains string-keyed (the value is opaque to PHP).                                                                 |

Other consumers of `Location` constructors are construction-only — handled by the construction-site bridge above.

**Read-sites of `worstOffender->file` / `exception->filePath` / `dupLoc->file`:**
- `src/Reporting/Formatter/Health/*` (~4 files) — read `$worstOffender->file` for display. Bridge via mirror method `WorstOffender::pathString()` (same pattern). Permanent in Phase 4.
- `src/Core/Exception/` consumers (~3 sites) — read `$exception->filePath` for messages. Bridge via `$e->filePath?->value() ?? '<unknown>'`. Acceptable inline.
- `src/Analysis/Duplication/*` consumers (~2 sites) of `DuplicateLocation::$file` — bridge identically.

**DoD addendum:** zero direct `$location->file` string-context reads outside formatters after Phase 1a. Self-check: `grep -rn '\->file === ' src/ | wc -l` → 0. `grep -rn '\->file <=>\|strcmp(.*->file' src/ | wc -l` → 0.

### Test rewrite strategy (round-2 Claude F4 + Codex F4)

**Three-step process**, not a one-shot script:

1. **Automated pass for safe literal-relative-paths only** — `scripts/refactor/phase-1a-test-rewrite.php`:
   - Uses `nikic/php-parser` (already production dep).
   - Finds `new Location($x, …)` / `new DuplicateLocation($x, …)` / `new ParseException($x, …)` / `new WorstOffender($x, …)` in `tests/`.
   - **Only** rewrites when first arg is a `Node\Scalar\String_` with value matching `#^(?!/)[a-zA-Z0-9_./-]+$#` (relative path, no leading `/`, no special chars). Wraps as `RelativePath::fromString('<value>')`.
   - Empty string `''` first arg → rewrites to `Location::none()` (or `null` for non-Location classes that have nullable file).
   - **Skips and reports** for manual handling: absolute literals (starting with `/`), variables, constants (`__FILE__`, `self::*`), method calls, ternaries, `null` literal.
   - Adds `use Qualimetrix\Core\Path\RelativePath;` if absent.
   - Idempotency: a `RelativePath::fromString(...)` call as first arg → skipped (already wrapped).
   - Emits a report file `phase-1a-manual-sites.txt` listing every skipped site (file:line:reason). Estimated 50-100 manual sites.

2. **Manual pass for variables / constants / absolute literals** — using the report from step 1:
   - `__FILE__` → wrap in `RelativePath::fromString(basename(__FILE__))` or convert test to use a fixed relative literal.
   - `$file` variable from data provider → either change the data provider to yield `RelativePath` instances, or wrap at the construction site.
   - Absolute literals like `'/tmp/test.php'` → reject. Either rewrite the test to use a relative literal or use `Location::none()` if the test doesn't actually care about the path.
   - Estimated touch time: ~1 hour for an LLM agent, ~3 hours for a human.

3. **`composer check` gate** — re-run after each pass. Phase 1a is not committed until the gate is green.

### Tests added

- `tests/Unit/Core/Violation/LocationNullFileTest.php` — round-trip for nullable file (`none()`, `isNone()`, `toString()` for none, JSON-shape preservation via formatters).

### DoD

- `composer check` green.
- `composer test:js` (vitest for HTML report) green — confirms JSON wire surface unchanged.
- `bin/qmx check src/` green.
- Zero `string $file|$filePath` in the 4 changed classes (grep self-check).
- Manual-sites report committed alongside the script for reproducibility (in `scripts/refactor/`, not in repo root).

**Commit:**
```
feat(core,reporting): adopt RelativePath in pure value carriers (ADR 0015 Phase 1a)

Location, DuplicateLocation, ParseException, WorstOffender now carry
?RelativePath / RelativePath instead of raw strings. Construction sites
bridge via RelativePath::fromString(...) until Phase 1b/1c/4/5 eliminate
the remaining string boundaries. JSON wire surface preserved (formatters
emit $file?->value() ?? '').

Test migration via scripts/refactor/phase-1a-test-rewrite.php for safe
literal cases + manual touch-up for variables/constants/absolute paths.
```

---

## Phase 1b — Worker-IPC types + Git boundary producer

### Files changed (production, grep-verified)

| File                                                                                                                 | Change                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| -------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Analysis/Collection/FileProcessingResult.php`                                                                   | `public readonly string $filePath` → `public readonly RelativePath $filePath`. Static factories `success()` / `failure()` signatures updated.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| `src/Infrastructure/Parallel/FileProcessingTask.php`                                                                 | `string $filePath` (absolute per docblock) → `AbsolutePath $filePath`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| `src/Infrastructure/Git/ChangedFile.php`                                                                             | `public string $path`, `?string $oldPath` → `public RelativePath $path`, `?RelativePath $oldPath`. Add named factory: `public static function fromGitOutput(string $rawGitPath, ChangeStatus $status, ?string $rawOldGitPath, AbsolutePath $gitToplevel, AbsolutePath $projectRoot): ?self`. Constructor annotated `@internal — use fromGitOutput() in production; direct construction reserved for tests` (round-2 Gemini F5).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `src/Infrastructure/Git/GitClient.php`                                                                               | **Constructor rewrite (round-2 H1 — critical):** today's `__construct(private readonly string $repoRoot)` is misnamed. `$repoRoot` is wired from `$resolved->analysis->projectRoot` (`GitScopeResolver.php:30`) — it is the **project root**, not the git toplevel. Git toplevel is obtained separately via `getRoot()` (`git rev-parse --show-toplevel`). New signature: `__construct(private readonly AbsolutePath $projectRoot, private readonly ?LoggerInterface $logger = null)`. Add lazy accessor `private function gitToplevel(): AbsolutePath` that caches the result of `getRoot()` (one `git rev-parse --show-toplevel` per `GitClient` instance). `parseNameStatus()` (the actual private method, not `parseChangedFiles`) calls `ChangedFile::fromGitOutput($rawGitPath, $status, $rawOldGitPath, $this->gitToplevel(), $this->projectRoot)` per row — **note the arg order matches the concept §3 signature: `$gitToplevel` before `$projectRoot`**. Rows returning `null` collected; one PSR-3 `warning` emitted at end of parsing: `"Skipped {n} changed file(s) outside project root: {…}"` (round-2 Claude F2). `getRoot(): string` keeps its current return type until Phase 3 (which promotes it to `AbsolutePath`). |
| `src/Infrastructure/Git/GitScopeResolver.php`                                                                        | Update the `new GitClient(…)` call site (`GitScopeResolver.php:30`): drop the `$resolved->analysis->projectRoot` *string* arg and pass it as `AbsolutePath` (Phase 5 migrates `AnalysisConfiguration::$projectRoot` to `AbsolutePath`; Phase 1b uses a transient `AbsolutePath::fromString($resolved->analysis->projectRoot)` wrap until then).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `src/Infrastructure/Git/GitScopeFilter.php`                                                                          | `buildIndex()` no longer calls `PathNormalizer::relativize($file->path)` (path is already project-relative); array key is `(string) $file->path`. `extractNamespace()` keeps its current string-based file_get_contents call until Phase 3.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| `src/Analysis/Collection/FileProcessor.php` (NOT `Lifecycle/`, NOT `Worker.php`)                                     | Constructs `FileProcessingResult` with `RelativePath`. Transient `PathNormalizer::relativize(...)` calls removed (this file becomes a clean VO consumer).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `src/Analysis/Collection/CollectionOrchestrator.php:77,90,94,100,104,133,134,141,151` (round-4 F2 — array-key shape) | Reads `$result->filePath` (becomes `RelativePath` here). 9 sites use it as array key (`$allSuppressions[$result->filePath]`, `$allThresholdOverrides[$result->filePath]`, etc.) and as `basename(...)` arg. Convert array-key writes to `$result->filePath->value()`. `SymbolPath::forFile($result->filePath)` at line 133 is fine (factory takes `RelativePath` in Phase 1c). `repository->add(..., $result->filePath, 1)` at line 134 is fine (interface accepts `RelativePath`). `basename($result->filePath)` at line 77 becomes `basename($result->filePath->value())`. Matches consumer side `AnalysisContext::getThresholdOverride` keyed by `->value()` (Phase 1c).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| `src/Analysis/Pipeline/AnalysisPipeline.php`                                                                         | Consumer of `FileProcessingResult.filePath` — updates to VO. `analyze(string\|array $paths)` signature: **defer** — string remains here through Phase 1b/1c, migrates to `AbsolutePath\|list<AbsolutePath>` in Phase 2.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `src/Baseline/Suppression/SuppressionFilter.php` (NOT `Core/Suppression/`)                                           | Consumer of `Location` and `FileProcessingResult` — VO updates.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `src/Analysis/Collection/Metric/CompositeCollector.php` (round-2 Gemini F1, was missed)                              | Calls `PathNormalizer::relativize($file->getPathname())` → migrate to VO-based path resolution. Receives `AbsolutePath` from caller, converts via `$absolutePath->tryRelativizeTo($projectRoot)`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| `src/Analysis/Duplication/DuplicationDetector.php` (round-2 Gemini F1, was missed)                                   | Same: `PathNormalizer::relativize()` consumer → VO.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |

### Tests updated (~5 production-test files)

- `tests/Unit/Analysis/Collection/FileProcessingResultTest.php`
- `tests/Unit/Infrastructure/Git/ChangedFileTest.php` + new `ChangedFileFromGitOutputTest`
- `tests/Unit/Infrastructure/Git/GitScopeFilterTest.php`
- Mocks of `GitClient` in `GitClientTest`/`GitScopeResolverTest` — updated for new constructor signature.

### Tests added

- `tests/Integration/Infrastructure/Git/GitSubdirScopeTest.php` — **T10 regression**. Sets up a temp git repo where the project root is a strict subdirectory of git top-level, runs `--report=git:HEAD~1`, asserts:
  - Changed files within project subdir → violations included.
  - Changed files outside project subdir → not included, warning logged with count.
  - All 4 git diff row shapes (Added/Modified/Deleted/Renamed/Copied) handled.
  - **Argument-order pin (round-4 H1 fix):** when `$projectRoot` is a strict subdir of `$gitToplevel`, a swapped call to `fromGitOutput($raw, $status, $rawOld, $projectRoot, $gitToplevel)` does **not** return `null` — both arguments are valid absolute paths and one is still under the other, just inverted. The correct pin asserts the **resulting path value differs** between correct and swapped invocations. Test shape: setup git repo with `gitToplevel = /tmp/x`, `projectRoot = /tmp/x/sub`; raw git path `sub/Foo.php`. Correct call: `sub/Foo.php` resolved against `gitToplevel = /tmp/x` → `/tmp/x/sub/Foo.php` → relativize against `projectRoot = /tmp/x/sub` → `RelativePath('Foo.php')`. Swapped call: `sub/Foo.php` resolved against `gitToplevel = /tmp/x/sub` → `/tmp/x/sub/sub/Foo.php` → relativize against `projectRoot = /tmp/x` → `RelativePath('sub/sub/Foo.php')`. `assertNotEquals` between the two `value()`s, plus `assertSame('Foo.php', $correct->value())`.

- `tests/Unit/Infrastructure/Parallel/FileProcessingResultWireFormatTest.php` (round-2 Claude F7 — replaces the brittle `@requires extension parallel` integration test with a pure unit test):
  - `serialize(new FileProcessingTask(AbsolutePath::fromString('/tmp/x.php')))` round-trip via `unserialize()` → equal task.
  - Same for `FileProcessingResult::success(RelativePath::fromString('src/X.php'), …)`.
  - Same via `igbinary_serialize`/`igbinary_unserialize` (under `@requires extension igbinary`).
  - This gives the wire-format guarantee without depending on `ext-parallel` / `ext-pcntl` availability in CI. An optional `@group ext-parallel` integration test can be added later if CI gains the extension.

### DoD

- `composer check` green.
- T10 regression test green.
- `bin/qmx check src/ --report=git:HEAD~1` smoke green.
- Wire-format unit tests green (no `ext-parallel` requirement on CI).
- `ChangedFile` direct-construction call sites outside tests: zero (grep verified). All production paths use `ChangedFile::fromGitOutput()`.

**Commit:**
```
feat(analysis,parallel,git): adopt path VOs in worker-IPC types (ADR 0015 Phase 1b)

FileProcessingResult, FileProcessingTask, ChangedFile now carry path VOs.
GitClient gains AbsolutePath $projectRoot + ?LoggerInterface in constructor;
parseNameStatus uses ChangedFile::fromGitOutput() (translation eagerly to
project-relative) and emits a PSR-3 warning for out-of-project rows.

T10 regression test pins the git-subdir scenario; wire-format unit tests
pin serialization compatibility for PHP serialize + igbinary.
```

---

## Phase 1c — Symbol identity

### Files changed (production, grep-verified)

| File                                                                | Change                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Core/Symbol/SymbolPath.php`                                    | `public ?string $filePath = null` → `public ?RelativePath $filePath = null`. Factory `forFile(string $path)` → `forFile(RelativePath $path)`. **Canonical-key string format unchanged**: `forFile()` produces `"file:" . $path->value()`. (There is no separate `SymbolKeyFactory.php` — the canonical-key generation lives inside `SymbolPath` itself.)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `src/Core/Symbol/ClassInfo.php`                                     | `public string $file` → `public RelativePath $file`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `src/Core/Symbol/MethodInfo.php`                                    | Same.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `src/Core/Symbol/SymbolInfo.php`                                    | Same.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `src/Core/Metric/MethodWithMetrics.php`                             | `toSymbolInfo(string $filePath)` → `toSymbolInfo(RelativePath $filePath)`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `src/Core/Metric/ClassWithMetrics.php`                              | Same.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `src/Core/Metric/MetricRepositoryInterface.php`                     | `add(SymbolPath, MetricBag, string $file, ?int $line)` → `add(SymbolPath, MetricBag, RelativePath $file, ?int $line)`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| `src/Analysis/Repository/InMemoryMetricRepository.php`              | `add()` signature mirrors interface.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `src/Core/Rule/AnalysisContext.php`                                 | `getThresholdOverride(string $ruleName, string $file, int $line)` → `getThresholdOverride(string $ruleName, ?RelativePath $file, int $line)` (nullable per round-4 H3 — see below). Implementation: when `$file === null`, return `null` early. Otherwise, array-key lookup uses `$file->value()`: `$this->thresholdOverrides[$file->value()] ?? null`. The matching producer side migrates in Phase 1b (see `CollectionOrchestrator.php:95` row below) — both sides convert `RelativePath` → `string` key via `->value()` to keep array-key shape consistent.                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| `src/Rules/AbstractRule.php` (round-2 H3 — was missed)              | `getEffectiveOptions(AnalysisContext, options, string $file, int $line)` → `getEffectiveOptions(…, ?RelativePath $file, int $line)`. Same for `getEffectiveSeverity(…, string $file, int $line, value)` → `getEffectiveSeverity(…, ?RelativePath $file, …)`. **Nullable rationale (round-4 round-3 F3 from both reviewers):** `src/Architecture/Rules/CircularDependencyRule.php:70` passes literal `''` as `$file` today — a cycle is a graph-level concept with no single owning file. A non-nullable `RelativePath` flip would `TypeError` here (`RelativePath::fromString('')` is documented as rejected in concept §3). Going nullable preserves the "no file" semantic. All 40+ `*Rule.php` callers passing `$classInfo->file` / `$methodInfo->file` / `$symbolInfo->file` are unaffected (those fields become `RelativePath`, which is a subtype of `?RelativePath`). `CircularDependencyRule.php:70` updates to pass `null`. Construction-site bridges from Phase 1a are deleted here. |
| `src/Core/Util/PathMatcher.php`                                     | `matches(string $filePath)` → `matches(RelativePath $filePath)`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| `src/Architecture/Rules/CircularDependencyRule.php:70` (round-4 H3) | `getEffectiveSeverity(…, '', 1, $cycle->getSize())` → `getEffectiveSeverity(…, null, 1, $cycle->getSize())`. Cycle has no single owning file by domain.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |

### Tests updated (~43 files reference `SymbolPath::forFile`)

Same scripted rewrite approach as Phase 1a — `scripts/refactor/phase-1c-test-rewrite.php`, mirrored for `SymbolPath::forFile`, `new ClassInfo(string, …)`, `new MethodInfo`, `new SymbolInfo`, `MetricRepository::add(…, string, int)`.

### Tests added

- `tests/Unit/Core/Symbol/SymbolPathCanonicalKeyStabilityTest.php` — uses **hard-coded `assertSame` pairs** (round-2 Claude F8: avoids golden-file complexity). Example: `assertSame('file:src/Foo.php', SymbolPath::forFile(RelativePath::fromString('src/Foo.php'))->canonicalKey())`. Tests forFile only — `forClass` / `forMethod` / `forNamespace` don't take file paths and are out of scope for this stability test.

### DoD

- `composer check` green.
- `bin/qmx check src/` green.
- Baseline round-trip test still passes (canonical keys unchanged → baselines remain forward-compatible).
- `composer benchmark:check` <1% wall-time regression.

**Commit:**
```
feat(core,analysis): adopt RelativePath in Symbol identity types (ADR 0015 Phase 1c)

SymbolPath::forFile, ClassInfo/MethodInfo/SymbolInfo, MetricRepository::add,
AnalysisContext::getThresholdOverride, PathMatcher::matches now carry
?RelativePath. Canonical-key string format unchanged — baselines stay
backward-compatible. Stability test pins the format with hard-coded
assertSame pairs.
```

---

## Phase 2 — Discovery boundary + CLI input

### Files changed (production, grep-verified)

| File                                                                                      | Change                                                                                                                                                                                       |
| ----------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Analysis/Discovery/FileDiscoveryInterface.php`                                       | `discover(string\|array): iterable` → `discover(AbsolutePath\|array<AbsolutePath>): iterable<AbsolutePath, SplFileInfo>`.                                                                    |
| `src/Analysis/Discovery/FinderFileDiscovery.php`                                          | Updated to consume `AbsolutePath`, yield `AbsolutePath` keys.                                                                                                                                |
| `src/Analysis/Pipeline/AnalysisPipelineInterface.php`                                     | `analyze(string\|array $paths, …)` → `analyze(AbsolutePath\|list<AbsolutePath>, …)` (round-2 Codex F3 — decided to migrate the interface too; string boundary stays in `CheckCommand` only). |
| `src/Analysis/Pipeline/AnalysisPipeline.php`                                              | Signature mirror.                                                                                                                                                                            |
| `src/Infrastructure/Console/Command/CheckCommand.php`                                     | CLI argument loop converts each string arg via `PathFactory::fromCliArgument($raw, $cwd)`. `$cwd` is `AbsolutePath::fromString((string) getcwd())`.                                          |
| `src/Infrastructure/Console/Command/GraphExportCommand.php`                               | Same.                                                                                                                                                                                        |
| `src/Infrastructure/Console/Command/HookInstallCommand.php` (round-2 Gemini F1)           | Uses `getcwd()` / `realpath()` ad-hoc → consolidate via `PathFactory::fromCliArgument()`.                                                                                                    |
| `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php` (round-2 Gemini F1) | Same.                                                                                                                                                                                        |
| `src/Infrastructure/Git/GitScopeResolution.php`                                           | Updated for `AbsolutePath`-typed discovery output (already consumes `FileDiscoveryInterface`).                                                                                               |
| `src/Infrastructure/DependencyInjection/Configurator/AnalysisConfigurator.php`            | DI wiring — verify `FileDiscoveryInterface` consumers updated.                                                                                                                               |
| `src/Infrastructure/DependencyInjection/Configurator/OutputConfigurator.php`              | DI `Reference()` site — no signature change needed but verify.                                                                                                                               |

### Mock fallout (round-2 Codex F3)

Mock/stub call sites in tests to migrate:
- `tests/Unit/Analysis/Pipeline/AnalysisPipelineTest.php` — mock `FileDiscoveryInterface::discover()`
- `tests/Integration/Analysis/Pipeline/AnalysisPipelineIntegrationTest.php` — same
- `tests/Support/TestPipelineBuilder.php` (or equivalent harness — verify exact name) — same
- Any other test that mocks the interface — grep before phase start

### Tests added

- `tests/Unit/Analysis/Discovery/FinderFileDiscoveryAbsolutePathTest.php` — yields AbsolutePath keys; rejects relative-path input at boundary; handles `./` prefix, symlinks, nonexistent paths.
- `tests/Functional/Console/CheckCommandPathInputTest.php` — CLI input parsing for relative/absolute/`./`-prefixed/symlinked/nonexistent paths.

### DoD

- `composer check` green.
- `bin/qmx check src/`, `bin/qmx check ./src/`, `bin/qmx check $(pwd)/src/` all produce identical output.

**Commit:**
```
feat(analysis,console): adopt AbsolutePath at Discovery + CLI boundaries (ADR 0015 Phase 2)

FileDiscoveryInterface and AnalysisPipelineInterface now exchange
AbsolutePath. CLI commands (Check, GraphExport, HookInstall, LayerAssignment
debug) convert string args via PathFactory::fromCliArgument().
Functional test covers relative/absolute/./-prefixed/symlinked/nonexistent.
```

---

## Phase 3 — Git boundary completion

### Files changed (production, grep-verified)

| File                                                       | Change                                                                                                                                                                                                                                                                           |
| ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Infrastructure/Git/GitRepositoryLocatorInterface.php` | `findGitDir(?string $workingDir = null): ?string` → `findGitDir(?AbsolutePath $workingDir = null): ?AbsolutePath`.                                                                                                                                                               |
| `src/Infrastructure/Git/GitRepositoryLocator.php`          | Implementation mirror.                                                                                                                                                                                                                                                           |
| `src/Infrastructure/Git/GitClient.php`                     | `getRoot(): string` → `getRoot(): AbsolutePath` (this is the `GitClient::getRoot()` method that calls `git rev-parse --show-toplevel`, NOT the locator).                                                                                                                         |
| `src/Infrastructure/Git/GitScopeFilter.php`                | `extractNamespace()` builds the absolute path via `$projectRoot->joinRelative($file->path)` (NOT `$repoRoot` — `$file->path` is project-relative after Phase 1b). `$projectRoot` is injected via constructor (added to constructor signature in this phase, AbsolutePath-typed). |

### Tests added

- `tests/Integration/Infrastructure/Git/GitScopeFilterProjectSubdirTest.php` — repeats the T10 regression from Phase 1b but exercises `extractNamespace` specifically, asserting the absolute path used for `file_get_contents()` is `$projectRoot . '/' . $relPath`, not `$gitRoot . '/' . $relPath`.

### DoD

- `composer check` green.
- T10 regression from Phase 1b still green.
- `bin/qmx check src/ --report=git:HEAD~1` smoke green.

**Commit:**
```
feat(infra,git): complete path VO migration at git boundary (ADR 0015 Phase 3)

GitRepositoryLocator::findGitDir and GitClient::getRoot return AbsolutePath.
GitScopeFilter uses projectRoot (not git toplevel) for namespace extraction —
fixes the latent bug exposed when project root is a git subdir.
```

---

## Phase 4 — Reporting + Baseline output

### Files changed (production, ~21 grep-verified — round-2 M1 expanded by 7)

Round-2 verification (`grep -rn 'location->file' src/Reporting src/Core/Violation src/Baseline src/Analysis/Pipeline`) returned 28 read-sites — round-1 inventory listed 14. The 7 missing files all live outside `src/Reporting/Formatter/*.php` glob (Support/, Summary/, Impact/, Debt/, Json/, Html/, Core/Violation/, Baseline/, Analysis/Pipeline/) and are enumerated explicitly below.

Most of these consumers were migrated to the `pathString()` bridge in Phase 1a (see "Consumer sites with sentinel / comparator changes" in Phase 1a). Phase 4 then promotes the bridge: `relativizePath` arg becomes a `RelativePath` directly (no `pathString()` indirection) at the formatter print-sites.

**Formatters — top-level (8):**
- `src/Reporting/Formatter/TextFormatter.php`
- `src/Reporting/Formatter/TextVerboseFormatter.php`
- `src/Reporting/Formatter/CheckstyleFormatter.php`
- `src/Reporting/Formatter/GithubActionsFormatter.php`
- `src/Reporting/Formatter/GitLabCodeQualityFormatter.php`
- `src/Reporting/Formatter/MetricsJsonFormatter.php`
- `src/Reporting/Formatter/Sarif/SarifFormatter.php` — also removes `pathToFileUri`'s `str_replace('\\', '/', $path)` line (VO is POSIX-only) but signature `pathToFileUri(string $path)` keeps `string` arg type — this is a URI builder, not a path VO consumer.
- `src/Reporting/Formatter/Html/HtmlFormatter.php` — `readFile(string $path)` keeps string (raw file read boundary).

**Formatters — subdir (5, round-2 M1 — not in `Reporting/Formatter/*.php` glob):**
- `src/Reporting/Formatter/Support/ViolationSorter.php` — `pathString()` (set in Phase 1a) stays; no VO migration needed here (sorter is string-keyed by design).
- `src/Reporting/Formatter/Support/DetailedViolationRenderer.php`
- `src/Reporting/Formatter/Summary/TopIssuesRenderer.php`
- `src/Reporting/Formatter/Json/JsonViolationSection.php`
- `src/Reporting/Formatter/Html/HtmlViolationPartitioner.php`

**Formatters — Json/Health subdirs (covered by globs):**
- `src/Reporting/Formatter/Json/*` other than above
- `src/Reporting/Formatter/Health/*`

**Reporting helpers (4 — round-4 added `JsonOffenderSection` + `ClassRankResolver`):**
- `src/Reporting/Formatter/Json/JsonOffenderSection.php:71,120` — `$context->relativizePath($offender->file)` where `$offender->file` is `?RelativePath` after Phase 1a. Type already correct after the `FormatterContext` signature swap.
- `src/Reporting/Impact/ClassRankResolver.php:116` — `$index->getMaxForFile($sp->filePath ?? '')` passes `''` fallback against `getMaxForFile(RelativePath)`. Round-4 fix: `$sp->filePath !== null ? $index->getMaxForFile($sp->filePath) : 0.0` (or whatever the empty-key fallback returns today; verify ClassRankIndex behavior).

**FormatterContext + impact + exclusion (3):**
- `src/Reporting/FormatterContext.php` — **Signature decision (round-3 M3 — corrected in round 4):** the current `relativizePath(string $filePath): string` exists because today's `Location::$file` can carry either an absolute or relative string and the method does basePath-stripping via `str_starts_with`. After Phase 1a, `Location::$file` is `?RelativePath` — relative by construction, so the basePath-stripping semantic becomes a no-op. The method **does not become vestigial** because callers still need null-handling and the "[project]" rendering rule. Final signature: `relativizePath(?RelativePath $filePath): string`. Implementation: `return $filePath?->value() ?? '';`. (Round-3 pseudocode `$filePath->tryRelativizeTo($this->basePath)` was wrong: `tryRelativizeTo` lives on `AbsolutePath`, not `RelativePath`, per concept §3.) **Caller fix-up:** `src/Reporting/Formatter/Support/DetailedViolationRenderer.php:102` passes a string group-by key (`$key` from `ViolationSorter` group-by index) to `relativizePath()` — this works today only because the method takes `string`. Phase 4 changes this call to just `$key` directly (the key is already the `pathString()` from Phase 1a; no further conversion needed). The `[project]` sentinel rendering stays at the call-site (`$key !== '' ? $key : '[project]'`).
- `src/Reporting/Impact/ClassRankIndex.php` — `getMaxForFile(string $filePath)` → `getMaxForFile(RelativePath $filePath)`.
- `src/Configuration/RulePathExclusionProvider.php` — `isExcluded(string $ruleName, string $filePath)` → `isExcluded(string $ruleName, RelativePath $filePath)`.

**Cross-domain consumers (3, round-2 M1):**
- `src/Reporting/Impact/ImpactCalculator.php` — comparator (l. 82) keeps `pathString()` from Phase 1a (sort behavior over string is correct; no VO migration needed).
- `src/Reporting/Debt/DebtCalculator.php` — `pathString()` from Phase 1a stays (string is the calculator's natural shape).
- `src/Analysis/Pipeline/AnalysisResult.php` — `strcmp` comparator keeps `pathString()` from Phase 1a.

**Baseline (2):**
- `src/Baseline/BaselineWriter.php` — `relativizeCanonical()` uses `PathFactory::tryProjectRelative()`; writes via `->value()`.
- `src/Baseline/BaselineLoader.php` — **Scope clarification (round-2 Claude F7):** Loader operates on **opaque canonical-key strings** (`"file:src/Foo.php"`); it does NOT parse paths. The only path-aware step is `BaselineEntry::create()` if it materializes a `?RelativePath` from a stored key; otherwise the loader is untouched. The malformed-baseline-validation story shifts to **BaselineWriter input only** — load remains tolerant of any string. (Drops "parses via `RelativePath::fromString()`" from earlier draft.)

### Tests added

- `tests/Unit/Reporting/FormatterContextTest.php` — already exists; update for VO contract.
- `tests/Unit/Reporting/SarifFormatterPosixSeparatorTest.php` — assert no `\` in output regardless of input platform.
- `tests/Unit/Baseline/BaselineRoundTripVOTest.php` — write Baseline, read it back, equality.
- `tests/Functional/Reporting/JsonShapePreservationTest.php` — golden-file test asserting the JSON wire surface for each formatter is unchanged vs. pre-migration snapshots.

### DoD

- `composer check` + `composer test:js` green.
- All format-level integration tests green.
- Baseline round-trip green.

**Commit:**
```
feat(reporting,baseline): adopt path VOs in formatters and baseline I/O (ADR 0015 Phase 4)

All formatters consume Location::file via VO; SarifFormatter drops its
manual separator normalization (VO is POSIX-only). BaselineWriter uses
PathFactory::tryProjectRelative to preserve out-of-tree entries.
FormatterContext, ClassRankIndex, RulePathExclusionProvider migrated.
Golden-file JSON snapshots confirm no wire-surface change.
```

---

## Phase 5 — Internal pipelines + cache + config (incl. `$projectRoot` migration)

### Files changed (production, ~22 grep-verified)

**Configuration (round-2 Codex F1 — full `$projectRoot` migration, not just `$cacheDir`):**

- `src/Configuration/AnalysisConfiguration.php` — three fields migrate:
  - `public string $cacheDir = self::DEFAULT_CACHE_DIR` → `public AbsolutePath $cacheDir`
  - `public ?string $composerJsonPath = null` → `public ?AbsolutePath $composerJsonPath` (round-2 Gemini F3)
  - `public string $projectRoot = '.'` → `public AbsolutePath $projectRoot`

  **Construction contract (round-2 Codex F1, Claude F6 + round-2 M2 phantom-path fix):** `AnalysisConfiguration` is constructed at runtime by `ConfigurationPipeline::build()` after all stages have run (verified in `src/Configuration/Pipeline/ConfigurationPipeline.php` — earlier draft cited a phantom `Pipeline.php`). However, **there is a DI-bootstrap default instance** at `src/Infrastructure/DependencyInjection/Configurator/ConfigurationConfigurator.php:64`: `$configProvider->setConfiguration(new AnalysisConfiguration());` — this fires at compile-cache time before any pipeline runs, with no arguments. **Fix:** the no-arg constructor uses lazy defaults — `$projectRoot` resolves via `AbsolutePath::fromString((string) getcwd())` and `$cacheDir` via `$projectRoot->joinRelative(RelativePath::fromString('.qmx-cache'))`. The runtime `fromArray()` path overrides those defaults with merged-config values. Two distinct construction modes (DI default vs. pipeline-built) thus coexist without VO/string collision.

  **Resolution order in `fromArray($merged)`:**
  1. Read raw `project_root` string from `$merged` (default `'.'`).
  2. Resolve to `AbsolutePath` via `PathFactory::fromCliArgument($rawProjectRoot, AbsolutePath::fromString((string) getcwd()))`.
  3. Read raw `cache.dir` from `$merged` (default `'.qmx-cache'`).
  4. Resolve to `AbsolutePath` via `PathFactory::fromCliArgument($rawCacheDir, $projectRoot)`.
  5. Read raw `namespace.composer_json` from `$merged` (default `null`); resolve same way if non-null.

  **`merge()` updated** to accept VO fallback without colliding with the string default. Pseudocode key change: instead of `$overrides[KEY] ?? $this->projectRoot` returning mixed-type, the override value if present is re-resolved via `PathFactory::fromCliArgument()` before merging.

  **A new factory `AnalysisConfiguration::fromResolvedArray(array $merged, AbsolutePath $projectRoot, AbsolutePath $cacheDir, ?AbsolutePath $composerJsonPath)`** is added for callers that have already resolved the paths (DI-friendly). The legacy `fromArray($merged)` becomes a thin wrapper that calls the resolution-then-build sequence above.

- `src/Configuration/Pipeline/Stage/DefaultsStage.php` (round-2 Gemini F1) — uses `realpath()` for `ConfigSchema::PROJECT_ROOT`; switch to `AbsolutePath::fromString(realpath(...))` with `false` handling.
- `src/Configuration/Discovery/ComposerReader.php` (concept §5) — autoload paths resolved via `PathFactory::fromCliArgument()` against project root.
- `src/Configuration/RulePathExclusionProvider.php` — `isExcluded(string $filePath)` migrated in Phase 4 already.

**Console (round-2 Gemini F1):**

- `src/Infrastructure/Console/ScopeWarningChecker.php` — replaces ad-hoc `realpath()` calls with `AbsolutePath` operations; `tryRelativizeTo()` for the under-root check.

**Cache:**

- `src/Infrastructure/Cache/CacheKeyGenerator.php` — `getRealPath()` boundary → `AbsolutePath::canonicalize()` with explicit `false` handling.
- `src/Infrastructure/Cache/FileCache.php` — cache directory as `AbsolutePath`.
- `src/Infrastructure/Cache/CacheFactory.php` — adopts VO.
- `src/Infrastructure/Parallel/WorkerBootstrap.php` — `buildCacheKey()` uses VO inputs.

**Analysis pipelines:**

- `src/Analysis/Aggregator/AggregationHelper.php` — map keys via `(string) $path` explicitly; consumes `RelativePath`.
- `src/Analysis/Repository/InMemoryMetricRepository.php` — keyed by SymbolPath canonical-key string (unchanged); ingestion API takes VO (already in Phase 1c via interface).
- `src/Analysis/Namespace_/ProjectNamespaceResolver.php` (round-2 Gemini F1) — `getcwd()` fallback → use injected `AbsolutePath $projectRoot`.
- `src/Analysis/Namespace_/Psr4NamespaceDetector.php` (round-2 Gemini F1) — `realpath()` + manual path manipulation → `AbsolutePath` operations.

**Dependency pipeline:**

- `src/Analysis/Collection/Dependency/Handler/DependencyContext.php` — `readonly string $file` → `readonly RelativePath $file`.
- `src/Analysis/Collection/Dependency/DependencyVisitor.php` — `setFile(string)` → `setFile(?RelativePath)`.
- `src/Analysis/Collection/Metric/DerivedMetricExtractor.php` — VO on input.

### Tests updated (round-4 M2 — was missed in round 3)

- `tests/Unit/Configuration/AnalysisConfigurationTest.php` — 7 lines reference `cacheDir` directly: lines 23, 42, 371, 412 assert `'.qmx-cache'`; line 69 asserts `'/tmp/cache'`; lines 85, 99 pass `cacheDir: '/original/cache'` / `'/new/cache'` to constructor. After Phase 5 the default resolves to `$projectRoot/.qmx-cache` as `AbsolutePath` — assertions cannot use the raw string `'.qmx-cache'` anymore. Migration shape: `assertTrue($config->cacheDir->equals(AbsolutePath::fromString(getcwd() . '/.qmx-cache')))` for default-resolved tests; `equals(AbsolutePath::fromString('/tmp/cache'))` for absolute-passthrough tests. Constructor args wrap raw strings in `AbsolutePath::fromString(...)`. Approximately 9 lines of touch-up. Other `tests/Unit/Configuration/*` and `tests/Integration/Configuration/*` files: `composer test` red-output drives the rest; no separate enumeration needed.
- `tests/Unit/Configuration/*` and `tests/Integration/Configuration/*` — `composer test` will surface every other site after the migration; manual touch-up driven by red-test output, no separate enumeration needed.

### Tests added

- `tests/Unit/Configuration/AnalysisConfigurationCacheDirResolutionTest.php` — default `.qmx-cache` resolves relative to projectRoot; absolute path passes through; relative custom dir resolves correctly.
- `tests/Functional/Console/HookInstallCommandSmokeTest.php` (round-2 Claude F6) — `bin/qmx hook:install` runs successfully against a temp git repo (verifies that commands not going through CheckCommand still construct `AnalysisConfiguration` correctly).
- `tests/Integration/Infrastructure/Cache/CacheKeyGeneratorVOTest.php` — symlinked source files produce stable cache keys.
- `tests/Unit/Infrastructure/Console/ScopeWarningCheckerVOTest.php` — autoload path coverage check uses `tryRelativizeTo`.

### DoD

- `composer check` green.
- `bin/qmx check src/` green.
- `bin/qmx hook:install` smoke green.
- `composer benchmark:check` <1% wall-time regression.
- `qmx.yaml` `architecture:` topology test still green.

**Commit:**
```
feat(analysis,infra,config): adopt path VOs in pipelines, cache, and config (ADR 0015 Phase 5)

AnalysisConfiguration.cacheDir/composerJsonPath/projectRoot now AbsolutePath.
Resolution contract pinned in fromArray (projectRoot first, then cacheDir
against it). fromResolvedArray added for DI-friendly construction.

AggregationHelper, repositories, dependency visitor, namespace resolvers,
CacheKeyGenerator, WorkerBootstrap, ScopeWarningChecker, DefaultsStage,
Psr4NamespaceDetector — all consume path VOs.
```

---

## Phase 6 — Cleanup + lint guard

### Files changed

Delete:
- `src/Core/Util/PathNormalizer.php` — superseded by `PathFactory`. (Already `@internal` since Phase 0.)

Wire PHPStan rule:
- `phpstan.neon` (or `phpstan-extensions.neon`) — register `BannedStringPathPropertyRule`. Add the `allowList` parameter (none expected after Phase 5 — every legitimate filesystem-path property is migrated; the rule is regression guard).
- `tools/phpstan/Rules/BannedStringPathPropertyRule.php` — implement the actual logic (skeleton was committed in Phase 0).
- `tests/PHPStan/BannedStringPathPropertyRuleTest.php` — `PHPStan\Testing\RuleTestCase` (lives in `phpstan/phpstan` core); fixture cases for allowed (HtmlTreeNode-like classes with `$path` field) and rejected (deliberate `private string $file` in a fixture class).

PHPDoc array-shape audit:
- Grep `src/` for `array{file: string|@var array.*file:` patterns. Document findings; convert where appropriate.

CHANGELOG entry:
- `## [Unreleased]` → add `### Changed` entry: "Internal: file paths now travel through typed `Core\Path\AbsolutePath` / `RelativePath` VOs. JSON output surface unchanged; deprecated `Core\Util\PathNormalizer` removed (was `@internal` since v0.x)."

ADR status flip:
- `docs/adr/0015-relative-path-vo.md` — `Status: Proposed (round-2 ...)` → `Status: Accepted`.
- `docs/adr/README.md` — add `0015` entry to the index.

Memory cleanup:
- Update `[[backlog_attention_items]]` — mark item #1 closed.

Plan deletion:
- After phase landing, delete `docs/internal/plans/relative-path-vo-concept.md` and `docs/internal/plans/relative-path-vo-impl-plan.md` per CLAUDE.md policy ("docs/internal/plans/ deleted post-landing; ADRs carry the why").

### DoD

- `composer check` green.
- `bin/qmx check src/` green.
- PHPStan rule fires on a deliberately-broken fixture (`private string $file`) and passes on the allow-list entry.
- CHANGELOG updated.
- ADR 0015 status: Accepted.
- `backlog_attention_items` memory updated.

**Commits (two, round-2 Claude F10):**

```
chore(core,phpstan): delete PathNormalizer, wire BannedStringPath rule (ADR 0015 Phase 6 cleanup)

Removes Core\Util\PathNormalizer (superseded by Core\Path\PathFactory).
Activates BannedStringPathPropertyRule in phpstan.neon — regression guard
against re-introducing string typed properties for filesystem paths.
```

```
docs(adr): mark ADR 0015 Accepted; remove landed plan files

Flips ADR 0015 to Accepted; removes companion concept + impl plans per
the post-landing cleanup policy. Backlog attention item #1 closed.
```

---

## Stage-2 acceptance — round 4 readiness

Round-3 verification surfaced convergent HIGH findings from Claude + Codex (Gemini still unavailable):
- **H1 swapped-arg test** was logically wrong (null return assumed; reality: both args are absolute paths, swap produces a different VO, not null). Fixed: assert resulting path values differ.
- **F2 (`FormatterContext::relativizePath` pseudocode)** called `tryRelativizeTo` on a `RelativePath` — that method lives on `AbsolutePath`. Fixed: implementation is now `return $filePath?->value() ?? ''`; method is not vestigial (still handles null-rendering). `DetailedViolationRenderer.php:102` string-key caller dropped to direct `$key` use.
- **F3 (`CircularDependencyRule.php:70` literal `''`)** — fixed by making `AbstractRule::getEffectiveOptions/Severity` and `AnalysisContext::getThresholdOverride` accept `?RelativePath`. CircularDependencyRule passes `null`.
- **Phase 1a inventory gaps** — `SarifFormatter:125`, `DuplicateBlock:40`, `DuplicationDetector:350,364` added.
- **Phase 4 gaps** — `JsonOffenderSection:71,120`, `ClassRankResolver:116` added.
- **Phase 5 test enumeration gap** — `AnalysisConfigurationTest` `assertSame('.qmx-cache', …)` lines enumerated.
- **F4 LOW** — promoted-property rule uses `$param->isPromoted()` (forward-compat with PHP 8.4 hooks).

ADR 0015 + concept plan have residual references to "parseChangedFiles" and "Pre-Phase 0" (round-2 Claude F8 LOW). Align those after Phase 0 lands (one-line `chore(docs)` commit; does not block execution).

**Round-4 self-assessment:** all round-3 findings applied. Round-4 edits are narrow (~9 inline edits, no new structural changes), so the marginal value of another review round is low — past two rounds, the same reviewers either re-converge (we already addressed both reviewers' findings) or surface fresh edits that round-3 couldn't see (and those don't compound — round-3 found code-vs-plan mismatches, not architectural rethinks). Decision: **start execution from Phase 0** unless a final eyeball over the round-3→4 diff catches anything obviously wrong.

Each phase is a separate commit; do not batch phases. Each phase ends on a green `composer check`.
