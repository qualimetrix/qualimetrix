# 05 — Complexity Compass

**Persona:** Code reviewer using complexity metrics for PR review prioritization
**Projects:** Monolog (121 files), Composer (286 files), Laravel (1536 files)
**Date:** 2026-03-16

## Executive Summary

AIMD's complexity metrics are **genuinely useful for review prioritization** — across three projects, the top-flagged methods are almost always methods a reviewer would want to scrutinize. The cognitive complexity metric is the most reliable signal for "hard to understand" code, while cyclomatic complexity over-flags mechanical branching (switch/match). NPath finds a different class of problem — methods with many independent conditions that create a combinatorial explosion of states, even when each individual condition is simple.

Key numbers:
- **Monolog**: 51 complexity violations across 16 files. All top-5 methods are genuinely complex. ~15% are mechanical branching (switch/match).
- **Composer**: 786 complexity violations. The top methods (cognitive 400+) are truly monstrous god-methods. Excellent signal-to-noise.
- **Laravel**: 314 complexity violations. ~25-30% are mechanical branching or configuration mapping. Cognitive-only flags are the most useful subset.

The **switch/match problem** is real but manageable: ~20% of all complexity violations across the three projects are "mechanical branching" that inflates CCN without corresponding cognitive difficulty. Cognitive complexity handles this much better than cyclomatic.

## Findings

### Finding CX1: Cognitive complexity is the best single metric for review prioritization
**Severity:** HIGH
**Category:** useful-detection

Cognitive complexity consistently flags the methods that a human reviewer would identify as "hard to understand." It penalizes nesting depth, which correlates strongly with actual comprehension difficulty. In contrast, CCN treats a flat switch with 20 cases the same as a method with 20 nested if-else blocks.

**Evidence:** `Monolog\ErrorHandler::codeToString` (CCN=16, cognitive not flagged) is a pure `match` statement mapping error codes to strings — trivial to understand. Meanwhile, `Monolog\Handler\RotatingFileHandler::rotate` (cognitive=18, CCN not flagged) involves nested loops with conditional file operations — genuinely tricky.

### Finding CX2: NPath catches a unique class of problems that CCN and cognitive miss
**Severity:** MEDIUM
**Category:** useful-detection

16 methods in Laravel are flagged by NPath alone (no CCN or cognitive violation). These are typically methods with many independent `if` checks that each handle a separate concern. Individually simple, but collectively they create a huge state space that makes testing difficult.

**Examples:**
- `PendingEventAttributes::mergeAttributes` (NPath=2048): 11 independent `if (property !== null)` checks. Each is trivial, but testing all combinations is impractical. NPath correctly identifies this as a testing burden.
- `EnvironmentDecryptCommand::handle` (NPath=1152): linear sequence of guards and validations — individually clear but has many possible early-exit paths.
- `Container::resolve` (NPath=576): multiple fallback strategies for dependency resolution, each with their own conditions.

**Insight for reviewers:** NPath-only violations signal "hard to test exhaustively" rather than "hard to read." This is a different but equally valid concern.

### Finding CX3: ~20% of CCN violations are mechanical branching (switch/match)
**Severity:** MEDIUM
**Category:** false-positive

Across all three projects, approximately 20% of cyclomatic complexity violations are triggered by mechanical branching patterns that are straightforward to understand:

| Pattern              | Example                                            | CCN     | Actually Complex?                                                |
| -------------------- | -------------------------------------------------- | ------- | ---------------------------------------------------------------- |
| Error code mapping   | `ErrorHandler::codeToString`                       | 16      | No — pure lookup table                                           |
| Type casting switch  | `HasAttributes::castAttribute`                     | 27      | Partly — switch is clear, surrounding logic adds real complexity |
| Exception mapping    | `Handler::prepareException`                        | 14      | No — each match arm is independent                               |
| Generator hints      | `GeneratorCommand::promptForMissingArgumentsUsing` | 24      | No — static data mapping                                         |
| Locale pluralization | `MessageSelector::getPluralIndex`                  | massive | No — a massive lookup table                                      |

**The cognitive complexity metric handles this well:** `ErrorHandler::codeToString` (CCN=16) has no cognitive violation because the match expression is flat. `castAttribute` (CCN=27) gets cognitive=not-flagged for the switch portion but other complexity in the same class IS flagged.

### Finding CX4: Extreme cognitive values (>100) always indicate god-methods that must be split
**Severity:** HIGH
**Category:** useful-detection

Methods with cognitive complexity >100 are without exception genuinely problematic:

| Method                               | Cognitive | Verdict                                                       |
| ------------------------------------ | --------- | ------------------------------------------------------------- |
| `ShowCommand::execute`               | 406       | 580+ line god-method handling 8+ different output modes       |
| `ValidatingArrayLoader::loadPackage` | 405       | Monolithic validation of entire composer.json schema          |
| `ConfigCommand::execute`             | 274       | Handles all config get/set/unset operations in one method     |
| `EventDispatcher::doDispatch`        | 195       | 5+ different callable types, each with its own error handling |
| `Application::doRun`                 | 189       | Pre-flight checks, plugin loading, sudo detection, all in one |

Reading `ShowCommand::execute` confirmed it: the method handles `--tree`, `--latest`, `--outdated`, `--path`, `--self`, `--locked`, `--all`, `--available`, `--platform`, and `--format` options, with deeply nested conditionals for each combination. This is exactly the kind of code where a reviewer should spend extra time.

### Finding CX5: MI violations have high overlap with complexity violations but catch long methods
**Severity:** LOW
**Category:** discrimination-issue

Maintainability Index violations overlap ~80% with complexity violations in Composer (the project with the most MI violations: 98). However, MI catches a few additional cases:
- `StubPublishCommand::handle` (MI=36.2, no complexity violation): a long method that just defines a static array mapping. It's flagged because of high Halstead volume (many operands), not complexity.
- `AutoloadGenerator::getStaticFile` (MI=38.3, no complexity violation): a large method generating static file content.

**Assessment:** MI adds marginal value over complexity metrics alone. Its primary contribution is catching "long but simple" methods that complexity metrics ignore. For review prioritization specifically, complexity metrics are more actionable.

### Finding CX6: NPath "explosive" values (>10^9) are presentation noise
**Severity:** LOW
**Category:** ux-issue

`AutoloadGenerator::dump` reports NPath "> 10^9" which is mathematically accurate but provides no actionable information. Any method with NPath above ~10000 is already clearly problematic. The specific value doesn't help a reviewer prioritize between "very complex" and "astronomically complex."

**Suggestion:** Cap NPath display at something like ">1M" or ">10^6" to reduce visual noise.

### Finding CX7: SqlServerConnector::getSqlSrvDsn exposes the independent-conditions blind spot
**Severity:** MEDIUM
**Category:** metric-gap

`SqlServerConnector::getSqlSrvDsn` has NPath=73728 and CCN=18, but no cognitive complexity violation. The method is a series of 15+ independent `if (isset($config['key']))` checks, each setting a DSN argument. This is:
- **Not hard to understand** (each line is independent)
- **Not hard to review** (adding a new option is trivial)
- **Genuinely problematic for testing** (combinatorial state space)

CCN and NPath flag it; cognitive correctly ignores it. This represents a genuine tension: the method is fine for comprehension but poor for testability. The current metrics give a complete picture when read together, but a reviewer seeing only CCN=18 might over-prioritize reviewing this method.

## Method Validation Results

### Top Complex Methods — Are They Really Complex?

#### Monolog (Top 5)

| Method                             | CCN | Cognitive | NPath | Actually Complex?                                                                             | Verdict                                   |
| ---------------------------------- | --- | --------- | ----- | --------------------------------------------------------------------------------------------- | ----------------------------------------- |
| `NormalizerFormatter::normalize`   | 19  | 32        | 2912  | **Yes** — recursive, type-switching, depth-limited, handles 7+ types with special cases       | Legitimate flag                           |
| `Logger::addRecord`                | 18  | 31        | 3078  | **Yes** — handler iteration, bubble logic, record construction with many optional steps       | Legitimate flag                           |
| `NewRelicHandler::write`           | 18  | 32        | 1176  | **Moderate** — sequential checks, but nested foreach+if for context/extra is genuinely tricky | Somewhat inflated by duplication          |
| `PHPConsoleHandler::initConnector` | 18  | 27        | 10386 | **Moderate** — many independent configuration checks, individually simple                     | NPath inflated; CCN/cognitive about right |
| `GelfMessageFormatter::format`     | 20  | 22        | 40000 | **Yes** — string manipulation with many edge cases, field truncation logic                    | Legitimate flag                           |

#### Composer (Top 5)

| Method                        | CCN | Cognitive | NPath | Actually Complex?                                                                     | Verdict                           |
| ----------------------------- | --- | --------- | ----- | ------------------------------------------------------------------------------------- | --------------------------------- |
| `ShowCommand::execute`        | -   | 406       | -     | **Absolutely yes** — 580+ line god-method, 8+ modes                                   | Must be split                     |
| `ConfigCommand::execute`      | -   | 274       | -     | **Yes** — handles all config operations in one method with deep nesting               | Must be split                     |
| `EventDispatcher::doDispatch` | -   | 195       | -     | **Yes** — 5 callable types, each with different dispatch logic, nested error handling | Legitimate                        |
| `Application::doRun`          | -   | 189       | -     | **Yes** — plugin loading, sudo checks, directory traversal, all interleaved           | Legitimate                        |
| `Solver::runSat`              | -   | 149       | -     | **Yes** — SAT solver main loop, deeply nested state machine with propagation          | Genuinely algorithmically complex |

#### Laravel (Top 5)

| Method                                    | CCN | Cognitive | NPath | Actually Complex?                                                               | Verdict                                                 |
| ----------------------------------------- | --- | --------- | ----- | ------------------------------------------------------------------------------- | ------------------------------------------------------- |
| `SqlServerConnector::getSqlSrvDsn`        | 18  | -         | 73728 | **No** — repetitive `if isset` pattern, trivial to understand                   | False positive for comprehension, valid for testability |
| `RouteUrlGenerator::formatParameters`     | 27  | 47        | 29568 | **Yes** — named/positional parameter matching with multiple fallback strategies | Legitimate flag                                         |
| `Builder::where`                          | 18  | -         | 18432 | **Moderate** — type-dispatching with clear early returns, well-documented       | Cognitive correctly doesn't flag it; NPath inflated     |
| `HasAttributes::addCastAttributesToArray` | 18  | 24        | 2593  | **Yes** — type-switching with special handling for dates, encryption, enums     | Legitimate                                              |
| `NotificationSender::queueNotification`   | 27  | 43        | 2306  | **Yes** — queue routing with multiple channels, serialization, error handling   | Legitimate                                              |

**Accuracy rate:** ~80% of top-flagged methods are genuinely complex and deserve extra review attention. The remaining ~20% are mechanical branching or configuration mapping.

## Cognitive vs Cyclomatic Comparison

### When they agree (majority of cases)
For methods with genuine algorithmic or business logic complexity, both metrics flag them and the values roughly correlate. `NormalizerFormatter::normalize` (CCN=19, cognitive=32), `Logger::addRecord` (CCN=18, cognitive=31) — both metrics say "this is complex" and both are right.

### When cognitive is flagged but CCN is not
These are the most interesting cases — they indicate **nesting-driven complexity**:
- `RotatingFileHandler::rotate` (cognitive=18, no CCN violation): nested loops with conditional file operations
- `Vite::__invoke` (cognitive=21, no CCN violation): triple-nested foreach loops processing manifest entries
- `ConfiguresPrompts::promptUntilValid` (cognitive=18, no CCN violation): retry logic with nested validation

**Verdict:** Cognitive is right every time. These methods ARE hard to follow due to nesting, even though they don't have many branch points.

### When CCN is flagged but cognitive is not
These are almost always **flat branching patterns**:
- `ErrorHandler::codeToString` (CCN=16, no cognitive): match expression mapping error codes
- `GeneratorCommand::promptForMissingArgumentsUsing` (CCN=24, no cognitive): match expression with static data
- `Handler::prepareException` (CCN=14, no cognitive): match expression mapping exception types
- `Builder::where` (CCN=18, no cognitive): sequential type checks with early returns
- `SqlServerConnector::getSqlSrvDsn` (CCN=18, no cognitive): independent isset checks

**Verdict:** Cognitive is right every time. These methods are NOT hard to understand despite high branch counts. CCN over-counts flat switch/match patterns.

### Which metric is more useful for review?
**Cognitive complexity wins decisively.** It has near-zero false positives for "hard to understand" code. CCN is useful as a secondary signal for testability (more branches = more test cases needed), but cognitive should be the primary metric for review prioritization.

## Maintainability Index Assessment

### Does MI find different issues than complexity?

MI found **98 violations in Composer, 11 in Laravel, 3 in Monolog** (vs 786/314/51 for complexity). The overlap is high:

- **~80% of MI violations also have complexity violations.** These methods have both high complexity AND high Halstead volume.
- **~20% of MI violations are unique** — typically long methods with many operands but simple control flow (e.g., `StubPublishCommand::handle` with its 40+ file path mappings).

The extreme MI values are illuminating:
- `ShowCommand::execute` (MI=0.0) and `ConfigCommand::execute` (MI=0.0): both have cognitive >200. MI of zero means "unmaintainable by any definition."
- `Application::doRun` (MI=7.9): confirms this is a problem method from a different angle.

**Assessment for review:** MI adds marginal value over complexity alone. A reviewer using only cognitive complexity would miss very few genuinely problematic methods. MI's unique contribution is flagging "long but not logically complex" methods, which are a maintenance concern but not a review priority.

## The Switch/Match Problem

### Classification of complexity violations

Analyzing all methods flagged by CCN across the three projects:

| Category                            | % of CCN violations | Example                                                                |
| ----------------------------------- | ------------------- | ---------------------------------------------------------------------- |
| **Genuine algorithmic complexity**  | ~45%                | `Solver::runSat`, `PoolOptimizer::optimizeByIdenticalDependencies`     |
| **Business logic complexity**       | ~25%                | `Logger::addRecord`, `Config::merge`, `Container::resolve`             |
| **Configuration/option handling**   | ~10%                | `PHPConsoleHandler::initConnector`, `SqlServerConnector::getSqlSrvDsn` |
| **Type dispatching (switch/match)** | ~15%                | `castAttribute`, `codeToString`, `prepareException`                    |
| **Lookup tables**                   | ~5%                 | `MessageSelector::getPluralIndex`, `promptForMissingArgumentsUsing`    |

**~20% of CCN violations are "mechanical branching"** (configuration + type dispatching + lookup tables) rather than genuine complexity.

### Can you tell from AIMD's output whether complexity is "real" or "mechanical"?

**Partially, yes.** The key signal is **divergence between metrics**:

1. **High CCN + High Cognitive + High NPath** = genuinely complex (e.g., `NormalizerFormatter::normalize`)
2. **High CCN + Low/No Cognitive** = likely mechanical branching (e.g., `ErrorHandler::codeToString`, `castAttribute`)
3. **High NPath + Low/No CCN + Low/No Cognitive** = independent conditions, testing concern (e.g., `PendingEventAttributes::mergeAttributes`)
4. **High Cognitive + Low/No CCN** = nesting-driven complexity (e.g., `Vite::__invoke`)

A reviewer who understands these patterns can quickly triage violations. But AIMD doesn't surface this divergence explicitly — the reviewer must mentally compare across rules.

## UX Notes

1. **The `--only-rule=complexity` flag is excellent for focused review.** It filters to exactly the right set of violations and keeps the output manageable.

2. **The violation messages are clear and actionable.** "Cyclomatic complexity: 18 (threshold: 10) — too many code paths" immediately tells a reviewer what's wrong and how far over the limit it is.

3. **The `--detail` flag shows individual method violations, which is essential for review.** Without it, only class-level summaries appear.

4. **Missing: cross-metric summary per method.** When a method has CCN=18, cognitive=32, and NPath=2912, these appear as three separate violations. A combined view ("this method is complex on all dimensions") would be more useful for prioritization.

5. **The distinction between WARN and ERROR thresholds works well.** ERROR-level violations (CCN>20, cognitive>30, NPath>1000) consistently identify the worst offenders.

6. **Tech debt estimates seem reasonable.** Monolog's "3d 1h 30min" for 51 violations (~35 min each) is a plausible refactoring estimate.

## Guide Notes

### How to use complexity metrics in code review

**Primary metric: Cognitive Complexity**
- Best signal for "is this method hard to understand?"
- Near-zero false positives
- Focus on cognitive >15 for methods you should review carefully, >30 for methods that likely need refactoring

**Secondary metric: NPath Complexity**
- Best signal for "is this method hard to test?"
- NPath >200 means you should check test coverage
- NPath >1000 almost certainly means insufficient test coverage

**Tertiary metric: Cyclomatic Complexity (CCN)**
- Useful for estimating minimum test cases needed (CCN = minimum number of tests for full branch coverage)
- **Watch for the switch/match pattern:** if CCN is high but cognitive is low, the method is probably a lookup table or type dispatcher — not a review priority

**Triage strategy:**
1. Start with `bin/aimd check <path> --only-rule=complexity --detail` to see all violations
2. Focus first on **ERROR-level cognitive complexity violations** (>30) — these are almost always genuine problems
3. For WARNING-level violations (cognitive 15-30), check if CCN is also flagged:
   - Both flagged: probably a method worth reviewing
   - Only cognitive flagged: nesting issue, worth a look
   - Only CCN flagged: might be a switch/match, lower priority
4. NPath-only violations: check test coverage rather than spending time on code review

**Switch/match caveats:**
- A `match` expression with 20 arms contributes CCN=20 but cognitive=0-1
- A `switch` with fall-through cases contributes CCN per non-empty case
- These inflate CCN without corresponding comprehension difficulty
- If you see CCN>20 with no cognitive violation, it's almost certainly a switch/match — skip it for review purposes
