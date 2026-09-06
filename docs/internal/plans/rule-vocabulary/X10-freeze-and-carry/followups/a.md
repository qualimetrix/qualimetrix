# Followup for FOLLOWUPS.md — package A of X10-freeze-and-carry

## X10 (2026-09-06) — six discriminators frozen, pinned, mutation-proven; per-rule cost for the other two named, not decided

### What landed

Six `private const string OCCURRENCE_KIND` constants, one per family named by
`01-freeze-kind.md`'s table, each equal to today's channel spelling and not
reading `NAME`/`code`/`ruleName`:

- `CircularDependencyRule::OCCURRENCE_KIND = 'architecture.circular-dependency'`
- `IdenticalSubExpressionRule::OCCURRENCE_KIND = 'code-smell.identical-subexpression'`
- `CodeDuplicationRule::OCCURRENCE_KIND = 'duplication.code-duplication'`
- `HardcodedCredentialsRule::OCCURRENCE_KIND = 'security.hardcoded-credentials'`
- `SensitiveParameterRule::OCCURRENCE_KIND = 'security.sensitive-parameter'`
- `LayerViolationFinding::OCCURRENCE_KIND = 'architecture.layer-violation'`

Each family's `OccurrenceKey::semantic()` call site now reads its own
`OCCURRENCE_KIND` instead of `self::NAME` (five families) or `$this->ruleName`
(`LayerViolationFinding`). No new files. `ruleName`/`code` on the finding are
untouched — they still carry `NAME`/`$this->ruleName` as before.

Scaffold-pricing table on disk first, as the plan required:
`enumeration-occurrence-pin-scaffolds.tsv` (same directory), covering all
eight families named in `01-freeze-kind.md`'s "восемь, а не шесть" section,
with the required "how obtained / what it doesn't see" header.

One new `#[Test]` pin per frozen family, added to each family's existing
`Unit/*Test.php` (no new test files):

- `CircularDependencyRuleTest::itKeysOccurrenceToTheFrozenChannelSpellingNotToName`
- `IdenticalSubExpressionRuleTest::itKeysOccurrenceToTheFrozenChannelSpellingNotToName`
- `CodeDuplicationRuleTest::itKeysOccurrenceToTheFrozenChannelSpellingNotToName`
- `HardcodedCredentialsRuleTest::itKeysOccurrenceToTheFrozenChannelSpellingNotToName`
- `SensitiveParameterRuleTest::itKeysOccurrenceToTheFrozenChannelSpellingNotToName`
- `LayerViolationRuleTest::itKeysOccurrenceToTheFrozenChannelSpellingNotToRuleName`

Each drives the family's own production path (`->analyze()` for the five
rules, direct `new LayerViolationFinding(...)->toFindings()` for the sixth —
`LayerViolationFinding`'s constructor is public and the test file already used
this exact direct-construction pattern) and asserts `occurrenceKey?->value`
against `OccurrenceKey::semantic()` called with a raw string literal typed by
hand in the test — never against `self::NAME` or `Class::OCCURRENCE_KIND` by
reference, per the plan's "not a second-kind pin" requirement.

### Deviation from the plan, named up front: the DoD's mutation-2 wording
assumes `NAME` ≠ `OCCURRENCE_KIND` at test time — it isn't, until P4

`01-freeze-kind.md`'s DoD says each pin must redden under two mutations: (1)
editing the frozen constant's literal, and (2) "swapping the call-site
argument back to `self::NAME`". For the five rule families, `OCCURRENCE_KIND`
is defined equal to `NAME`'s current literal, by construction (that is the
whole point of the freeze: no hash movement today). `self::` resolves at
compile time to the class where the code is written — a subclass overriding
`NAME` would not reach the inherited `analyze()`'s `self::NAME`, and PHP has
no runtime API to redefine a class constant. So swapping the call site's
argument from `self::OCCURRENCE_KIND` back to `self::NAME`, with `NAME`
untouched, is a **runtime no-op today**: both resolve to the identical
string, so no occurrence-reading pin can redden from that edit alone, ever,
until `NAME` and `OCCURRENCE_KIND` actually diverge (i.e. after the channel
is renamed in a later package). I raised this with the advisor before writing
any pin; the resolution below is its ruling, not my unilateral call.

**Decision taken:** verify the intent behind mutation 2 (that the call site
is actually wired to the frozen constant, not to `NAME`) as a two-run control
per rule, applied only to the pin method via `--filter`, both runs done and
reverted for every one of the five rules:

- **(2a)** edit the rule's own `NAME` line to a different literal (e.g.
  `public const string NAME = 'architecture.circular-dependency-x';` written
  directly — the interface constant `CircularDependencyPreparationInterface::PRODUCER_RULE_NAME`
  was never touched), call site left reading `self::OCCURRENCE_KIND` →
  pin must stay **GREEN**. This is the freeze actually doing its job: a
  channel rename no longer moves `occurrence`.
- **(2b)** same `NAME` edit, plus the call site reverted to
  `OccurrenceKey::semantic(self::NAME, ...)` → pin must go **RED**. This is
  what "swap back to `self::NAME`" means once the two diverge, which is
  exactly the situation the freeze exists to survive.

(2a) green is what makes (2b) red meaningful — without (2a), (2b) is
indistinguishable from a restatement of mutation 1.

`LayerViolationFinding` did **not** need this two-step form: `$ruleName` is a
constructor parameter there, not a class constant, so the pin itself
constructs two `LayerViolationFinding` instances from the *same* dependency
and evidence but *different* `ruleName` arguments (`LayerViolationRule::NAME`
and an arbitrary `'not-the-real-channel-code'`), and asserts `code` differs
while `occurrenceKey->value` is identical. Reverting that family's call site
to `$this->ruleName` reddens this pin directly, today, with no `NAME` edit
needed, because the two constructed instances already carry different
`ruleName` values by design of the test.

### Mutation verification actually run (both mutations, per pin, then reverted)

All commands below were run from the repo root; every mutation was applied
with `perl -pi -e`, the filtered test was run, and the edit was reverted
before moving to the next mutation. `git diff --stat -- src/ tests/` was
checked clean of stray edits after each revert and confirmed clean at the
end (the six production files plus their six test files, only).

**Mutation 1 — edit the `OCCURRENCE_KIND` literal (all six families, applied
together, verified together, then reverted together):**

```
perl -pi -e "s/OCCURRENCE_KIND = 'architecture.circular-dependency';/OCCURRENCE_KIND = 'architecture.circular-dependency-MUTATED';/" src/Analysis/Evidence/CircularDependency/CircularDependencyRule.php
perl -pi -e "s/OCCURRENCE_KIND = 'code-smell.identical-subexpression';/OCCURRENCE_KIND = 'code-smell.identical-subexpression-MUTATED';/" src/Analysis/Evidence/CodeSmell/IdenticalSubExpressionRule.php
perl -pi -e "s/OCCURRENCE_KIND = 'duplication.code-duplication';/OCCURRENCE_KIND = 'duplication.code-duplication-MUTATED';/" src/Analysis/Evidence/Duplication/CodeDuplicationRule.php
perl -pi -e "s/OCCURRENCE_KIND = 'security.hardcoded-credentials';/OCCURRENCE_KIND = 'security.hardcoded-credentials-MUTATED';/" src/Analysis/Evidence/Security/HardcodedCredentialsRule.php
perl -pi -e "s/OCCURRENCE_KIND = 'security.sensitive-parameter';/OCCURRENCE_KIND = 'security.sensitive-parameter-MUTATED';/" src/Analysis/Evidence/Security/SensitiveParameterRule.php
perl -pi -e "s/OCCURRENCE_KIND = 'architecture.layer-violation';/OCCURRENCE_KIND = 'architecture.layer-violation-MUTATED';/" src/Analysis/Policy/Architecture/LayerViolation/LayerViolationFinding.php
vendor/bin/phpunit --filter itKeysOccurrenceToTheFrozenChannelSpellingNotToName tests/Analysis/Evidence/CircularDependency/Unit/CircularDependencyRuleTest.php tests/Analysis/Evidence/CodeSmell/Unit/IdenticalSubExpressionRuleTest.php tests/Analysis/Evidence/Duplication/Unit/CodeDuplicationRuleTest.php tests/Analysis/Evidence/Security/Unit/HardcodedCredentialsRuleTest.php tests/Analysis/Evidence/Security/Unit/SensitiveParameterRuleTest.php
vendor/bin/phpunit --filter itKeysOccurrenceToTheFrozenChannelSpellingNotToRuleName tests/Analysis/Policy/Architecture/Unit/LayerViolationRuleTest.php
```

Result: **all six RED** (`Tests: 5, Assertions: 10, Failures: 5` for the
first command, `Tests: 1, Assertions: 3, Failures: 1` for the second — the
`LayerViolationFinding` pin's third assertion, the raw-literal check, is the
one that fails). Both mutations were reverted; `git diff` on all six
production files came back empty afterward.

**Mutation 2 for `LayerViolationFinding` — call site back to `$this->ruleName`:**

```
perl -pi -e "s/occurrenceKey: OccurrenceKey::semantic\(self::OCCURRENCE_KIND, \\\$evidence\)/occurrenceKey: OccurrenceKey::semantic(\\\$this->ruleName, \\\$evidence)/" src/Analysis/Policy/Architecture/LayerViolation/LayerViolationFinding.php
vendor/bin/phpunit --filter itKeysOccurrenceToTheFrozenChannelSpellingNotToRuleName tests/Analysis/Policy/Architecture/Unit/LayerViolationRuleTest.php
```

Result: **RED** (`Failed asserting that two strings are identical` on the
`assertSame($withRealName->occurrenceKey?->value, $withDifferentName->occurrenceKey?->value)`
line). Reverted; `git diff` on the file came back to exactly the frozen-state
diff (constant added, call site reading `self::OCCURRENCE_KIND`).

**Mutation 2 as the (2a)/(2b) control, per rule family, run and reverted one
family at a time:**

| family                       | (2a): `NAME` renamed, call site intact | (2b): `NAME` renamed **and** call site reverted to `self::NAME` |
| ---------------------------- | -------------------------------------- | --------------------------------------------------------------- |
| `CircularDependencyRule`     | GREEN (`OK (1 test, 2 assertions)`)    | RED (`d7f9a1db989f5adb` ≠ `39044f2f2b248f45`)                   |
| `IdenticalSubExpressionRule` | GREEN                                  | RED (`98a5809048710e33` ≠ `7d6d1185a0216d1f`)                   |
| `CodeDuplicationRule`        | GREEN                                  | RED (`0632ac5c15e06d6c` ≠ `96ccf8e140fdd3c4`)                   |
| `HardcodedCredentialsRule`   | GREEN                                  | RED (`7ef5d616006f56c6` ≠ `82943f8b7a6d7500`)                   |
| `SensitiveParameterRule`     | GREEN                                  | RED (`e8dd3ca7ce69f1a0` ≠ `d8c7c1c77a1276d8`)                   |

Reproduce for one family, e.g. `CircularDependencyRule` (same shape for the
other four, substituting the family's own `NAME`/`OCCURRENCE_KIND` literals
and call-site fragment — see the TSV/this file's earlier commands for the
exact literals):

```
perl -pi -e "s/public const string NAME = CircularDependencyPreparationInterface::PRODUCER_RULE_NAME;/public const string NAME = 'architecture.circular-dependency-x';/" src/Analysis/Evidence/CircularDependency/CircularDependencyRule.php
vendor/bin/phpunit --filter itKeysOccurrenceToTheFrozenChannelSpellingNotToName tests/Analysis/Evidence/CircularDependency/Unit/CircularDependencyRuleTest.php   # (2a): GREEN
perl -pi -e "s/occurrenceKey: OccurrenceKey::semantic\(self::OCCURRENCE_KIND, \[/occurrenceKey: OccurrenceKey::semantic(self::NAME, [/" src/Analysis/Evidence/CircularDependency/CircularDependencyRule.php
vendor/bin/phpunit --filter itKeysOccurrenceToTheFrozenChannelSpellingNotToName tests/Analysis/Evidence/CircularDependency/Unit/CircularDependencyRuleTest.php   # (2b): RED
# revert both edits
```

Both edits were reverted after each family; `git diff --stat -- src/ tests/`
was checked clean of stray mutation residue before moving to the next
family, and again at the very end of the package.

### The two extra families (`CodeSmellFinding`/`SecurityPatternFinding`) — factory-level pin already exists, per-rule pin cost named, not decided

Per `01-freeze-kind.md`'s "восемь, а не шесть": these two already carry a
separate discriminator (`$smellType`/`$patternType`, not `NAME`/`ruleName`),
so they get no new constant — but they had no wiring pin either. I verified,
by mutation, that their **existing** tests already function as one:

```
perl -pi -e "s/\\\$occurrenceKey = OccurrenceKey::semantic\(\\\$smellType, \[/\\\$occurrenceKey = OccurrenceKey::semantic(\\\$ruleName, [/" src/Analysis/Evidence/CodeSmell/CodeSmellFinding.php
vendor/bin/phpunit tests/Analysis/Evidence/CodeSmell/Unit/CodeSmellFindingTest.php   # RED: Tests: 21, Failures: 5
# reverted; re-ran green: OK (21 tests, 90 assertions)

perl -pi -e "s/\\\$occurrenceKey = OccurrenceKey::semantic\(\\\$patternType, \[/\\\$occurrenceKey = OccurrenceKey::semantic(\\\$ruleName, [/" src/Analysis/Evidence/Security/SecurityPatternFinding.php
vendor/bin/phpunit tests/Analysis/Evidence/Security/Unit/SecurityPatternFindingTest.php   # RED: Tests: 21, Failures: 5
# reverted; re-ran green: OK (21 tests, 92 assertions)
```

`CodeSmellFindingTest`/`SecurityPatternFindingTest` already pass a `ruleName`
distinct from `smellType`/`patternType` in their fixtures
('code-smell.example' / 'example', 'security.example' / 'example') and assert
`occurrenceKey` against `semantic()` called with the raw `'example'` literal —
so they already satisfy the "real path, raw literal, not a second-kind pin"
shape at the **shared factory** call site, with no edit needed. No production
file outside the six was touched to land this — only mutated and reverted for
verification, as shown above.

**What that factory-level pin does not prove, and what closing the gap would
cost (the number the orchestrator asked for, not a decision):** it proves
`CodeSmellFinding::toFinding()`/`SecurityPatternFinding::toFinding()` read
`$smellType`/`$patternType` for their own call site — it does not prove that
any individual rule subclass's own `static::SMELL_TYPE` constant (late-static-
bound, so genuinely independent per subclass, unlike the `self::NAME` case
above) is actually what gets passed as `$smellType` for that rule specifically.
Counted directly from the tree:

- **CodeSmell**: 9 concrete `AbstractCodeSmellRule` subclasses
  (`BooleanArgumentRule`, `CountInLoopRule`, `DebugCodeRule`,
  `EmptyCatchRule`, `ErrorSuppressionRule`, `EvalRule`, `ExitRule`,
  `GotoRule`, `SuperglobalsRule`). Only `BooleanArgumentRuleTest` currently
  reads `occurrenceKey` (1 mention); the other 8 test files read 0. A
  per-rule pin needs 8 new/extended test methods, each needing its own
  smell-triggering fixture, across 8 files outside this package's set.
- **Security pattern**: 3 concrete `AbstractSecurityPatternRule` subclasses
  (`CommandInjectionRule`, `SqlInjectionRule`, `XssRule`).
  `CommandInjectionRuleTest` (1 mention) and `SqlInjectionRuleTest` (2
  mentions) already read `occurrenceKey`; `XssRuleTest` reads 0. A per-rule
  pin needs at least 1 new test method, plus auditing whether the two
  existing mentions already assert the raw-literal form or only stability.

Full detail and the "how obtained" method are in
`enumeration-occurrence-pin-scaffolds.tsv`, rows 7-8. Per the plan's own
allowance ("если таблица цены покажет, что два последних семейства
непропорционально дороги, они выносятся явной строкой FOLLOWUPS"): **12
per-rule pin files sit outside this package's file set** (only the six named
production files plus their tests were mine to touch), so I did not add
them. This paragraph is that explicit line. The orchestrator decides whether
the per-rule gap is worth 9 (CodeSmell, cost 8 files) + 3 (Security, cost 1-3
files) more test methods, or whether the factory-level pin is enough given
`static::SMELL_TYPE`/`static::PATTERN_TYPE` renames are comparatively rare
and reviewable by eye per rule.

### Guard and scope checks

- `tests/Analysis/Finding/Integration/RuleIdentifierLiteralGuardTest.php` —
  run alone immediately after the six constants landed, and again after all
  pins landed: **green both times** (`OK (3 tests, 317 assertions)`). All six
  constants sit in files owned by their own capability, as the plan expected;
  no allow-list edit was needed or made.
- `vendor/bin/phpstan analyse` on exactly the six changed production files:
  **`[OK] No errors]`**.
- `git diff --stat -- src/ tests/` at the end of the package: six production
  files (9, 9, 9, 9, 9, 11 lines changed respectively) and six test files
  (29, 29, 38, 27, 27, 45 lines added respectively) — no other file under
  `src/`/`tests/` touched by this package. (Other in-progress packages of
  this same X10 session — `BaselineWriter.php`,
  `OutputConfigurator.php`, `ClassCountRuleTest.php`, `bin/qmx` — were
  already modified on this branch before this package started and were left
  untouched.)
- `tests/Analysis/Finding/` (whole directory) plus all six touched
  `Unit/*Test.php` files: **green**, `954 tests, 4236 assertions`.

### Files touched

- `src/Analysis/Evidence/CircularDependency/CircularDependencyRule.php`
- `src/Analysis/Evidence/CodeSmell/IdenticalSubExpressionRule.php`
- `src/Analysis/Evidence/Duplication/CodeDuplicationRule.php`
- `src/Analysis/Evidence/Security/HardcodedCredentialsRule.php`
- `src/Analysis/Evidence/Security/SensitiveParameterRule.php`
- `src/Analysis/Policy/Architecture/LayerViolation/LayerViolationFinding.php`
- `tests/Analysis/Evidence/CircularDependency/Unit/CircularDependencyRuleTest.php`
- `tests/Analysis/Evidence/CodeSmell/Unit/IdenticalSubExpressionRuleTest.php`
- `tests/Analysis/Evidence/Duplication/Unit/CodeDuplicationRuleTest.php`
- `tests/Analysis/Evidence/Security/Unit/HardcodedCredentialsRuleTest.php`
- `tests/Analysis/Evidence/Security/Unit/SensitiveParameterRuleTest.php`
- `tests/Analysis/Policy/Architecture/Unit/LayerViolationRuleTest.php`
- `docs/internal/plans/rule-vocabulary/X10-freeze-and-carry/enumeration-occurrence-pin-scaffolds.tsv`
  — new, the scaffold-pricing table.
- `docs/internal/plans/rule-vocabulary/X10-freeze-and-carry/followups/a.md`
  — this file.

`src/Analysis/Evidence/CodeSmell/CodeSmellFinding.php` and
`src/Analysis/Evidence/Security/SecurityPatternFinding.php` were mutated and
reverted for verification only — no persisted change. No per-rule
`CodeSmell`/`Security` pattern files were touched.

### Status vs Definition of Done (package A, per `01-freeze-kind.md`)

- Six constants, equal to today's spelling, not reading `NAME`/`code`, no new
  files — **done**.
- Scaffold-pricing table on disk, covering all eight families, header states
  method and blind spot — **done**.
- Pins cover eight families: six frozen families get a new dedicated pin;
  the other two already had a factory-level pin, verified by mutation here —
  **done at the factory level**; the per-rule sub-question for those two is
  named above as an explicit open item for the orchestrator, not decided —
  **as the plan's own escape hatch allows**.
- Each pin verified by two mutations, both reddening — **done**, with the
  documented reshaping of mutation 2 into a (2a)/(2b) control for the five
  `self::NAME` rules (named as a deviation above; `LayerViolationFinding`
  needed no reshaping).
- `composer test`, `composer phpstan` — not run in full by this package
  (that is the orchestrator's per `00-overview.md`); the scoped equivalents
  I could run without another package's in-flight changes as a confound —
  `tests/Analysis/Finding/` plus the six touched `Unit/*Test.php` files, and
  `phpstan` on exactly the six changed files — are both green, reported
  above.
- Literal guard run separately, verdict named — **green**, reported above.
