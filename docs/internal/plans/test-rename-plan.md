# Plan v2: Test Method Naming Standardization (Agent-Driven)

**Status:** Reviewed and revised — ready for execution in the next session
**Review history:**
- v1 (scripted tooling): triple review by Claude / Gemini / Codex → "Needs rework" — superseded
- v2 (agent-driven, this document): single prompt-review by Claude → 10/11 PASS, 1 PARTIAL, 9 MEDIUM/LOW finds applied
**Replaces:** v1 (scripted tooling). Findings preserved as ADR material if needed at execution time.
**Scope:** One-shot rename of ~2954 `testXxx` → `itXxx` + `#[Test]` across 201 files

## Goal & Definition of Done

All ~2954 `testXxx` methods in `tests/**/*Test.php` (and in test-helper / abstract test base classes) are renamed to `itXxx`, marked with the `#[Test]` attribute. Cross-references (`#[Depends('testXxx')]`, `#[DependsExternal(C::class, 'testXxx')]`, `#[DependsUsingDeepClone]`, `#[DependsUsingShallowClone]`, and legacy `@depends testXxx` docblock) are synchronously updated.

**Invariant:** `vendor/bin/phpunit --list-tests-text | wc -l` returns the **same number** before and after the refactor. Method count alone is insufficient — data providers fan out per method, so we measure resolved test cases.

`composer check` is green.

**Out of scope:**
- `@dataProvider` docblock → `#[DataProvider]` attribute migration (separate)
- Removing `@test` docblocks (harmless, leave them)
- Touching fixture classes inside test files (non-test classes)
- Touching data-provider methods (`provideXxx`, etc. — names do not start with `test[A-Z]`)
- Touching method bodies for anything other than: internal `$this->testXxx()` self-references and `parent::testXxx()` calls

## Architecture

No scripts. Orchestrator + N parallel Sonnet subagents.

```
Orchestrator (this session, Opus)
├── Phase 0: Partition planner
│       — finds abstract test bases + descendants → groups them together
│       — finds cross-file #[Depends*] refs → keeps source + target in one batch
│       — partitions remaining files by tests/ subtree (cohesive batches of 10–15 files)
│
├── Phase 1: Spawn N subagents (Sonnet 4.6) in parallel
│       — each receives the canonical prompt (below) + its file list + per-class stoplists
│       — each returns a per-file mapping { (fqn, oldName) → newName } for verification
│
├── Phase 2: Cross-batch fixup pass (one Sonnet subagent or orchestrator inline)
│       — accumulate all mappings from Phase 1
│       — sweep all touched files for unresolved #[Depends*] / @depends pointing at testXxx
│       — apply remaining string updates using the global mapping
│
├── Phase 3: Verification
│       — vendor/bin/phpunit --list-tests-text wc -l: must match pre-refactor count
│       — composer check: must pass
│       — grep -rE 'function test[A-Z]' tests/ : must hit only string literals inside fixture classes / heredoc / 'testXxx' data values
│
└── Phase 4: One atomic commit + CLAUDE.md convention section
```

## Workflow (5 phases, mapped above)

1. **Partition planning** — orchestrator inline (no subagent). Reads `tests/`, identifies inheritance chains, builds batch list.
2. **Parallel rename** — spawn 13–20 subagents in one orchestrator message (`Agent` tool, batched).
3. **Fixup pass** — single sequential subagent or inline, depending on volume of cross-batch refs.
4. **Verification** — orchestrator inline.
5. **Commit** — orchestrator inline.

## Canonical subagent prompt

The single most important artifact in this plan. Every subagent receives this verbatim, parameterized only with `{files}` and `{stoplist}`.

```
You are renaming PHPUnit test methods in a Qualimetrix subdirectory.

INPUT
- Files to process: {files}  // 10–15 absolute paths
- Stoplist (names you must NEVER use as `to`):
  {stoplist}  // PHPUnit final methods + magic methods + reserved keywords

TASK
For each file:

1. READ THE FILE IN FULL. Understand what the file contains. A test file may contain:
   - the primary TestCase subclass (these are the test methods to rename)
   - fixture classes (NOT test methods — DO NOT TOUCH)
   - test helper classes (often marked `@mixin TestCase` — TREAT AS TEST CONTEXT)
   - heredoc/nowdoc PHP source strings used as fixtures for the parser under test (DO NOT TOUCH content of strings)

2. IDENTIFY TEST METHODS to rename:
   - Public, non-abstract methods named matching /^test[A-Z]\w*/
   - Located inside a class that extends `PHPUnit\Framework\TestCase` (directly or via chain
     including `KernelTestCase`, `WebTestCase`, project's `Abstract*Test`, etc.)
   - OR inside a class that uses `@mixin \PHPUnit\Framework\TestCase` and is referenced
     from real test classes
   - OR inside a trait that is `use`d by an identified test class

   If a test method is **abstract in a parent class in your batch**, rename both the abstract
   declaration AND every concrete implementation symmetrically — they must agree on the new name.

   DO NOT rename:
   - Methods inside fixture classes that happen to be in the same file
   - Methods that match `/^test[A-Z]/` but are inside a class implementing a project interface
     (e.g. RuleInterface fixtures) — these satisfy a contract, not a test convention
   - Methods named exactly `test` (no suffix) — out of scope for this pass
   - `function test()` declarations inside heredoc/nowdoc strings — these are PHP source code
     being analyzed by the test, not actual methods
   - Lifecycle methods annotated with `@before`, `@after`, `@beforeClass`, `@afterClass`,
     `#[Before]`, `#[After]`, `#[BeforeClass]`, `#[AfterClass]` — these are setup/teardown
     hooks regardless of name pattern. (The orchestrator's stoplist also covers `setUp`,
     `tearDown`, `setUpBeforeClass`, `tearDownAfterClass`, `assertPreConditions`,
     `assertPostConditions`, `onNotSuccessfulTest`.)

3. GENERATE NEW NAMES:
   - Convention: `itXxx` (BDD-style, camelCase, starts with `it`)
   - Example: `testReturnsZeroForEmptyInput` → `itReturnsZeroForEmptyInput`
   - Example: `testThrowsWhenPathDoesNotExist` → `itThrowsWhenPathDoesNotExist`
   - Strip the redundant `it` prefix from `testIt…` only: `testItHandlesX` → `itHandlesX`
   - For other BDD prefixes already on the original name, keep them — `testIsValidInput` →
     `itIsValidInput`, `testHasErrors` → `itHasErrors`, `testCanProcess` → `itCanProcess`,
     `testShouldFailOnEmptyInput` → `itShouldFailOnEmptyInput` are all acceptable (do not
     strip `Is`/`Has`/`Can`/`Should`/`When`/`Will`)
   - Use the method's docblock + body (first 5–10 lines) **for context only** to ground the
     new name in actual behavior. **Do not modify the docblock prose** — only rewrite
     `@depends testXxx` annotations (see §4)
   - NEVER pick a name in the stoplist
   - NEVER pick a name already used by another method in the same class (test or non-test)
   - Within one class, all renames must produce unique names

4. APPLY RENAMES via the Edit tool:
   - Change the method name itself
   - Add `#[Test]` attribute on the line immediately above the method (`public function …`)
     if no `#[Test]` attribute already exists for that method
   - If you add `#[Test]` to any method in a file, ensure the file's top contains
     `use PHPUnit\Framework\Attributes\Test;` — add it if missing (alongside other `use`
     statements, sorted naturally)
   - Update **self-reference call sites** in the same class:
     `$this->testXxx()`, `self::testXxx()`, `static::testXxx()`, `parent::testXxx()`
     → all become the renamed form. (`parent::` only when the parent is in your batch and
     you renamed its method too; otherwise record in `crossBatchRefs`.)
   - Update `#[Depends('testXxx')]`, `#[DependsUsingDeepClone('testXxx')]`,
     `#[DependsUsingShallowClone('testXxx')]` to point at the new name
   - Update `#[DependsExternal(Class::class, 'testXxx')]` if the referenced class is also in
     your file list; otherwise record in `crossBatchRefs`
   - Update legacy `@depends testXxx` docblock (if any) to `@depends itXxx`

5. DO NOT:
   - Rename anything other than test methods (no refactor, no style fixes, no docblock prose
     rewrites)
   - Touch method bodies beyond the `$this->/self::/static::/parent::testXxx()`
     exact-string replacements listed in §4
   - Touch heredoc/nowdoc string contents (these are PHP source fixtures, not real code)
   - Touch ANY string literal containing `testXxx` — they are TEST DATA referring to symbols
     under analysis (e.g. `SymbolPath::forMethod('App\\Foo', 'Bar', 'testCalculate')`,
     `MetricRepository::get(SymbolPath::forMethod(..., 'testFoo'))`, Violation message
     fixtures), NEVER self-references. **Only** the following string forms get rewritten:
     `#[Depends('testXxx')]`, `#[DependsUsingDeepClone('testXxx')]`,
     `#[DependsUsingShallowClone('testXxx')]`, `#[DependsExternal(C::class, 'testXxx')]`,
     and the docblock `@depends testXxx` annotation
   - Add new tests, remove tests, modify assertions
   - Touch `phpunit.xml.dist`, `composer.json`, or anything outside the file list

6. REPORT BACK in this exact JSON format at the very end of your response:
   ```json
   {
     "renames": [
       { "file": "<path>", "fqn": "<Class FQN>", "from": "testFoo", "to": "itFoo" }
     ],
     "crossBatchRefs": [
       {
         "file": "<path>",
         "line": N,
         "refKind": "Depends | DependsExternal | DependsUsingDeepClone | DependsUsingShallowClone | @depends | parent::",
         "refersTo": { "fqn": "<other class>", "from": "testBar" }
       }
     ],
     "addedTestImport": ["<path>", "..."],
     "warnings": [
       { "file": "<path>", "issue": "describe anything unusual you found" }
     ]
   }
   ```
   `addedTestImport` lists files where you added the `use PHPUnit\Framework\Attributes\Test;`
   import (for the orchestrator's audit).

VERIFICATION BEFORE YOU REPORT:
- After all Edit calls, re-read each modified file and confirm:
  * No remaining `function test[A-Z]` declarations inside identified test classes/traits
  * Every renamed method has either an existing `#[Test]` attribute or a newly-added one
  * Every `#[Depends*]` and `@depends` in your touched files points at a name that EXISTS in
    that file (either renamed-by-you, original non-test name, or recorded in crossBatchRefs)
- If you find a violation you cannot fix, list it under `warnings`.

CONTEXT NOTES
- Project: Qualimetrix, a PHP CLI static analyzer
- Test framework: PHPUnit 12 with strict flags (failOnRisky, failOnWarning, failOnDeprecation)
- Style: PSR-12 + PER-CS 2.0, camelCase identifiers throughout
- The `#[Test]` attribute import is `use PHPUnit\Framework\Attributes\Test;` — add it if needed
- Do not run `composer test` or any shell tools beyond what you need to inspect files. The
  orchestrator runs the full validation at the end.
```

## Edge cases (and how the prompt handles each)

| Case                                                                               | Coverage in prompt                                                                                                                                         |
| ---------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Fixture class in same file                                                         | §2 "DO NOT rename: Methods inside fixture classes that happen to be in the same file" + class-implementing-interface heuristic                             |
| `@mixin TestCase` helper                                                           | §2 "OR inside a class that uses `@mixin \PHPUnit\Framework\TestCase`"                                                                                      |
| Trait test methods                                                                 | §2 "OR inside a trait that is `use`d by an identified test class"                                                                                          |
| Heredoc/nowdoc `function test()` strings                                           | §2 last bullet + §5 "Touch heredoc/nowdoc string contents" prohibition                                                                                     |
| String literal `'testCalculate'` as data                                           | §5 explicit `SymbolPath::forMethod(...)` example                                                                                                           |
| `#[DependsExternal]`, `#[DependsUsingDeepClone]`, `#[DependsUsingShallowClone]`    | §4 explicit handling                                                                                                                                       |
| Legacy `@depends` docblock                                                         | §4 last bullet                                                                                                                                             |
| Method named exactly `test`                                                        | §2 "Methods named exactly `test` (no suffix) — out of scope"                                                                                               |
| Inheritance chain coordination                                                     | Partition planner places parent + children in same batch (orchestrator responsibility), prompt §4 handles cross-batch fallback via `crossBatchRefs` report |
| Per-class collision                                                                | §3 "Within one class, all renames must produce unique names"                                                                                               |
| Stoplist (PHPUnit finals)                                                          | §3 "NEVER pick a name in the stoplist"                                                                                                                     |
| Awkward `itItXxx`                                                                  | §3 explicit guidance to strip redundant prefix                                                                                                             |
| `#[Test]` already present                                                          | §4 "if no `#[Test]` attribute already exists for that method"                                                                                              |
| Lifecycle hooks (`@before`/`@after`/`#[Before]`/`#[After]`/`setUp`/`tearDown`/...) | §2 last DO NOT bullet — explicit allowlist, AND orchestrator stoplist includes lifecycle method names                                                      |
| Abstract `test*` declaration in parent + concrete impl in child                    | §2 "If a test method is abstract in a parent class in your batch, rename both…symmetrically"                                                               |
| `self::testXxx()`, `static::testXxx()` self-references                             | §4 "self::testXxx(), static::testXxx()" enumerated alongside `$this->` and `parent::`                                                                      |
| `use PHPUnit\Framework\Attributes\Test;` import missing                            | §4 sub-bullet "ensure the file's top contains `use …\Test;` — add it if missing"                                                                           |
| Docblock prose accidentally rewritten                                              | §3 "**for context only**…**Do not modify the docblock prose**" + §5 prohibition                                                                            |
| Test data string literals (`'testCalculate'`)                                      | §5 explicit form-limited allowlist; only `#[Depends*]` and `@depends` strings rewrite                                                                      |

## Partition planner (orchestrator inline logic)

Before spawning subagents:

1. **Find abstract test bases:** `grep -rln "^abstract class .*Test " tests/` → list of abstract FQNs.
2. **Find descendants of each abstract base:** for each abstract `AbstractFooTest`, `grep -rln "extends AbstractFooTest" tests/`.
3. **Find cross-file `#[Depends*]` refs:** `grep -rn "Depends" tests/ | grep -E "#\[Depends(External|UsingDeepClone|UsingShallowClone)?\("` → pairs of (source file, target file).
4. **Group:** abstract base + descendants in one batch. Cross-Depends pairs in one batch. Remaining files partitioned by `tests/` subtree (cohesive ~10–15 files per batch).
5. **Stoplist generation:** static list of PHPUnit 12 final methods (curated once, ~100 entries from `PHPUnit\Framework\TestCase` + `PHPUnit\Framework\Assert` + `PHPUnit\Framework\Constraint\Constraint`). Plus magic methods (`__construct`, `__toString`, etc.) plus reserved keywords.

## Cross-batch fixup pass

After Phase 1, orchestrator accumulates all per-subagent `renames` lists into a single global mapping. Then:

- Re-grep all touched files for `#[Depends*]` and `@depends` strings pointing at any `testXxx` name from the global mapping.
- For each hit, single subagent (or inline) updates the string to the corresponding `itXxx`.
- This handles the case where subagent A renamed `AbstractTest::testFoo` → `itFoo`, but subagent B's file had `#[DependsExternal(AbstractTest::class, 'testFoo')]` it could not resolve at the time.

## Verification

| Check                                  | Method                                                                                                                                                                              |
| -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Test count unchanged                   | `vendor/bin/phpunit --list-tests-text \| wc -l` before and after must match                                                                                                         |
| No remaining `testXxx` in test classes | `grep -rE 'function test[A-Z]' tests/` returns only heredoc/string-literal hits (manually verifiable list, expected ~25 in CodeSmell visitor tests where fixtures contain PHP code) |
| `composer check` green                 | run it                                                                                                                                                                              |
| Manual spot-check                      | 10 random renamed tests — name makes sense, body unchanged                                                                                                                          |

## Risks

| Risk                                                    | Mitigation                                                                                                                                           |
| ------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| Subagent ignores prompt and refactors unrelated code    | Prompt §5 explicit "DO NOT"; orchestrator inspects diff size per batch as sanity (expect ~30-50 lines changed per file, not 200+)                    |
| Subagent picks a stoplist-violating name                | Verification pass after Phase 1: cross-check every `to` against stoplist; orchestrator surfaces violations                                           |
| Cross-batch `#[Depends]` left stale                     | Phase 2 fixup pass handles by design                                                                                                                 |
| Test count drifts                                       | Pre/post `phpunit --list-tests-text wc -l`, atomic rollback via `git restore tests/`                                                                 |
| Subagent context overflow on large files                | Largest test file is ~10K lines; Sonnet 4.6 context is sufficient. If subagent hits limit, partition planner pre-splits that file into its own batch |
| LLM nondeterminism causes inconsistent style            | Style guide explicit (`itXxx`, BDD); spot-check 20 names manually before committing                                                                  |
| Subagent commits / pushes / runs tests                  | Prompt §5: "Do not run `composer test`". Subagent only has Edit + Read; no Bash.                                                                     |
| `composer.json` autoload-dev breaks if new files appear | Subagents do not create new files; only modify existing                                                                                              |

## Cost estimate

- ~13–20 subagents × ~50K input tokens + ~20K output tokens each (Sonnet 4.6)
- Total: ~1M input + ~400K output on Sonnet
- ≈ **$10–15** on Sonnet 4.6 pricing
- Orchestration overhead on Opus: ~50K total (Phase 0 planning + Phase 3 verification + Phase 4 commit) ≈ $4
- **Grand total: ~$15–20**, runs in ~1–2 hours of agent wall time

## Sequence summary

1. (this session) Plan v2 written, prompt drafted, single prompt-review by Claude reviewer, revisions applied, final version pinned in this file
2. (next session) Orchestrator reads this file, runs Phase 0 partitioning, spawns Phase 1 subagents, runs Phase 2 fixup, Phase 3 verification, Phase 4 commit

## Non-goals

(See "Out of scope" in Goal section.)
