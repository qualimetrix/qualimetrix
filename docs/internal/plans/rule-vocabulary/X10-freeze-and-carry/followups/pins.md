# Followup for FOLLOWUPS.md — closing the per-rule gap named in followups/a.md

## X10 (2026-09-06) — per-rule occurrence pin for all 9 code-smell + 3 security-pattern rules

### Context

`followups/a.md` (package A) froze `occurrence` for six families and, for the
remaining two (`CodeSmellFinding`'s `$smellType`, `SecurityPatternFinding`'s
`$patternType`), verified only a **factory-level** pin: `CodeSmellFindingTest`
and `SecurityPatternFindingTest` prove the two Finding VOs read
`$smellType`/`$patternType` at their own call site, but not that any given
concrete rule's own `SMELL_TYPE`/`PATTERN_TYPE` constant is what reaches that
call site for that rule specifically. Package A named the cost
(8 files for CodeSmell, 1-3 for Security) and left the decision to the
orchestrator. This package closes that gap.

### Enumeration (method, not estimate)

`enumeration-smell-type-pins.tsv` (same directory) has the full method and
its blind spots. Summary: two greps —
`extends AbstractCodeSmellRule` (9 hits) and `extends AbstractSecurityPatternRule`
(3 hits) — each followed by reading the one shared `analyze()` call site to
confirm the constant actually reaches `OccurrenceKey::semantic()`. Total: **12
rules**, matching package A's count exactly (9 + 3, not the orchestrator's
"8 plus up to 3" estimate — the code-smell side is 9, not 8).

Excluded, and why: `HardcodedCredentialsRule`/`SensitiveParameterRule`
(already frozen and pinned in package A, out of this package's file set by
the task's own instruction); the five `CodeSmell` rules that extend
`AbstractRule` directly instead of `AbstractCodeSmellRule`
(`ConstructorOverinjectionRule`, `LongParameterListRule`,
`IdenticalSubExpressionRule` — frozen in package A —, `UnreachableCodeRule`,
`UnusedPrivateRule`) — none of these construct a `CodeSmellFinding`, so a
`SMELL_TYPE`-shaped pin does not apply to them.

### What landed

Of the 12, 2 already carried a literal-valued occurrence pin reading their
own production `analyze()` output (found, not assumed — verified by reading
the test file, then by mutation):

- `BooleanArgumentRuleTest::smellDetectedProducesFinding` — already asserts
  `occurrenceKey?->value` against `OccurrenceKey::semantic('boolean_argument', [...])`
  with the type spelled as a raw string literal.
- `CommandInjectionRuleTest::itCreatesFindingForSingleFinding` — same shape,
  literal `'command_injection'`.

Note: `SqlInjectionRuleTest` already read `occurrenceKey` twice, but only via
`itGroupsOnlySemanticPatternEvidenceRatherThanLinesOrRawContext`, which
compares two findings from the *same* rule against each other
(`assertSame($findings[0]->occurrenceKey?->value, $findings[1]->occurrenceKey?->value)`)
— a `PATTERN_TYPE` edit moves both findings together, so that test cannot
redden from it. This does not count as a per-rule pin; it was left in place
(still a valid, different assertion) and a real pin was added alongside it.

The other 10 lacked any occurrence assertion. One `#[Test]` pin was added to
each family's existing `Unit/*Test.php` (no new test files), each driving the
rule's own `analyze()` and asserting `occurrenceKey?->value` against
`OccurrenceKey::semantic()` called with the type spelled as a raw string
literal — never against `static::SMELL_TYPE`/`static::PATTERN_TYPE` by
reference, for the same reason package A's pins never reference
`self::NAME`/`OCCURRENCE_KIND`: a pin that reads the constant it is meant to
guard cannot redden when that constant changes.

New pin methods, all named `itKeysOccurrenceToItsOwnSmellType` /
`itKeysOccurrenceToItsOwnPatternType`:

- `EvalRuleTest`, `DebugCodeRuleTest`, `CountInLoopRuleTest`, `GotoRuleTest`,
  `EmptyCatchRuleTest`, `ErrorSuppressionRuleTest`, `ExitRuleTest`,
  `SuperglobalsRuleTest` (code-smell, 8 files)
- `XssRuleTest`, `SqlInjectionRuleTest` (security-pattern, 2 files)

### Mutation verification (all 12, run and reverted)

For each rule, its own `SMELL_TYPE`/`PATTERN_TYPE` literal was mutated
in-place (e.g. `'eval'` → `'eval_MUT'`), the rule's pin test was run filtered,
observed red, then the file was restored from a `.bak` copy taken before the
edit. `git diff --stat` on `src/` was empty before and after every run.

Because both the metric-bag lookup key (`codeSmell.{$type}` /
`security.{$type}`) and the occurrence discriminator are built from the same
`static::SMELL_TYPE`/`static::PATTERN_TYPE` value, mutating it makes the
rule look for an entry key that the test fixture no longer provides — the
finding count drops from 1 to 0, and the pin's `assertCount(1, ...)` fails
before it even reaches the `occurrenceKey` assertion. This is still a valid
mutation-catch (the rule's own production code, run end to end, disagrees
with the test) — it is a stronger failure than a diverged string, not a
weaker one, and it exercises the exact call site (`analyze()` → `entries("...{$type}")`
→ `CodeSmellFinding`/`SecurityPatternFinding::fromEntry()->toFinding()`) that
carries the discriminator into `occurrence`.

| rule                   | mutated literal                               | result (filtered)                                      |
| ---------------------- | --------------------------------------------- | ------------------------------------------------------ |
| `BooleanArgumentRule`  | `boolean_argument` → `boolean_argument_MUT`   | RED (whole file: 14 failures, incl. existing pin)      |
| `EvalRule`             | `eval` → `eval_MUT`                           | RED (`itKeysOccurrenceToItsOwnSmellType`)              |
| `DebugCodeRule`        | `debug_code` → `debug_code_MUT`               | RED (`itKeysOccurrenceToItsOwnSmellType`)              |
| `CountInLoopRule`      | `count_in_loop` → `count_in_loop_MUT`         | RED (`itKeysOccurrenceToItsOwnSmellType`)              |
| `GotoRule`             | `goto` → `goto_MUT`                           | RED (`itKeysOccurrenceToItsOwnSmellType`)              |
| `EmptyCatchRule`       | `empty_catch` → `empty_catch_MUT`             | RED (`itKeysOccurrenceToItsOwnSmellType`)              |
| `ErrorSuppressionRule` | `error_suppression` → `error_suppression_MUT` | RED (`itKeysOccurrenceToItsOwnSmellType`)              |
| `ExitRule`             | `exit` → `exit_MUT`                           | RED (`itKeysOccurrenceToItsOwnSmellType`)              |
| `SuperglobalsRule`     | `superglobals` → `superglobals_MUT`           | RED (`itKeysOccurrenceToItsOwnSmellType`)              |
| `CommandInjectionRule` | `command_injection` → `command_injection_MUT` | RED (`itCreatesFindingForSingleFinding`, existing pin) |
| `XssRule`              | `xss` → `xss_MUT`                             | RED (`itKeysOccurrenceToItsOwnPatternType`)            |
| `SqlInjectionRule`     | `sql_injection` → `sql_injection_MUT`         | RED (`itKeysOccurrenceToItsOwnPatternType`)            |

Reproduce for one rule, e.g. `EvalRule` (same shape for the other 11,
substituting the rule's own file and literal):

```
cp src/Analysis/Evidence/CodeSmell/EvalRule.php /tmp/EvalRule.php.bak
perl -pi -e "s/protected const string SMELL_TYPE = 'eval';/protected const string SMELL_TYPE = 'eval_MUT';/" src/Analysis/Evidence/CodeSmell/EvalRule.php
vendor/bin/phpunit --filter itKeysOccurrenceToItsOwnSmellType tests/Analysis/Evidence/CodeSmell/Unit/EvalRuleTest.php   # RED
cp /tmp/EvalRule.php.bak src/Analysis/Evidence/CodeSmell/EvalRule.php
```

After all 12 mutation runs: `git status --porcelain -- src/` empty (the only
pre-existing `src/` diff on this branch, `Baseline*`/`BaselineFormatVersion.php`,
belongs to a different in-flight package on this same X10 branch and was
never touched here).

### Verification

- Point tests, all 12 rule test files together:
  `vendor/bin/phpunit tests/Analysis/Evidence/CodeSmell/Unit/{BooleanArgument,Eval,DebugCode,CountInLoop,Goto,EmptyCatch,ErrorSuppression,Exit,Superglobals}RuleTest.php tests/Analysis/Evidence/Security/Unit/{CommandInjection,Xss,SqlInjection}RuleTest.php`
  — `OK (100 tests, 243 assertions)`.
- Full directory sweep: `vendor/bin/phpunit tests/Analysis/Evidence/CodeSmell tests/Analysis/Evidence/Security`
  — `OK (989 tests, 1947 assertions)`.
- `composer check` (full) was **not** run by this package — per the global
  workflow, full aggregate gates are the orchestrator's; this package ran
  scoped tests only, as package A did for the same reason (another package
  on this branch has independent in-flight `src/` changes as a confound).

### Files touched

- `tests/Analysis/Evidence/CodeSmell/Unit/EvalRuleTest.php`
- `tests/Analysis/Evidence/CodeSmell/Unit/DebugCodeRuleTest.php`
- `tests/Analysis/Evidence/CodeSmell/Unit/CountInLoopRuleTest.php`
- `tests/Analysis/Evidence/CodeSmell/Unit/GotoRuleTest.php`
- `tests/Analysis/Evidence/CodeSmell/Unit/EmptyCatchRuleTest.php`
- `tests/Analysis/Evidence/CodeSmell/Unit/ErrorSuppressionRuleTest.php`
- `tests/Analysis/Evidence/CodeSmell/Unit/ExitRuleTest.php`
- `tests/Analysis/Evidence/CodeSmell/Unit/SuperglobalsRuleTest.php`
- `tests/Analysis/Evidence/Security/Unit/XssRuleTest.php`
- `tests/Analysis/Evidence/Security/Unit/SqlInjectionRuleTest.php`
- `docs/internal/plans/rule-vocabulary/X10-freeze-and-carry/enumeration-smell-type-pins.tsv`
  — new, the enumeration table.
- `docs/internal/plans/rule-vocabulary/X10-freeze-and-carry/followups/pins.md`
  — this file.

`BooleanArgumentRule.php`, `EvalRule.php`, `DebugCodeRule.php`,
`CountInLoopRule.php`, `GotoRule.php`, `EmptyCatchRule.php`,
`ErrorSuppressionRule.php`, `ExitRule.php`, `SuperglobalsRule.php`,
`CommandInjectionRule.php`, `XssRule.php`, `SqlInjectionRule.php` were
mutated and reverted for verification only — no persisted change; `src/` was
not otherwise touched by this package.

### Status vs task

- Enumeration by code, on disk before any pin was written, with a
  "how obtained / what it doesn't see" header — **done**
  (`enumeration-smell-type-pins.tsv`).
- Per-rule pin, reading occurrence off a finding produced by the rule's own
  production `analyze()`, for all 12 rules — **done**: 2 already existed
  (verified sufficient by mutation, left as-is), 10 added.
- Each pin verified by mutation (own `SMELL_TYPE`/`PATTERN_TYPE` edited,
  pin reddens, mutation reverted) — **done**, table above.
- `src/` untouched by this package outside verification mutations, all
  reverted — **done**.
- Full validation (`composer check`) — **not run**; orchestrator's per the
  global workflow, scoped point tests run instead as stated above.
