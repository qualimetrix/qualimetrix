# Session 03: metric and rule results on legal but uncommon code

Issues: #161 items 2 and 3, #165. Branch: `fix/metric-and-rule-edge-cases`.
Read `00-overview.md` first. #161 item 1 (health coupling coverage) is out
of scope (see the overview).

## Files this session owns

| File                                                                                             | Item                                        |
| ------------------------------------------------------------------------------------------------ | ------------------------------------------- |
| `src/Analysis/Evidence/Coupling/Rfc/RfcClassFacts.php`                                           | RFC                                         |
| `src/Analysis/Evidence/Coupling/Rfc/RfcDeclarationFacts.php`                                     | RFC                                         |
| `src/Analysis/Evidence/Coupling/Rfc/RfcEffectiveMembers.php`                                     | RFC                                         |
| `src/Analysis/Evidence/Coupling/Rfc/RfcResponseResolver.php`                                     | RFC, only if needed                         |
| `tests/Analysis/Evidence/Coupling/Unit/Rfc/RfcVisitorTest.php`                                   | RFC                                         |
| `tests/Analysis/Evidence/Coupling/Unit/Rfc/RfcResponseFormsTest.php`                             | RFC                                         |
| `src/Analysis/Evidence/Coupling/README.md`                                                       | RFC, if it defines M                        |
| `website/docs/rules/coupling.md`, `website/docs/rules/coupling.ru.md`                            | RFC                                         |
| `src/Analysis/Evidence/Complexity/Npath/NpathComplexityVisitor.php`                              | NPath                                       |
| `tests/Analysis/Evidence/Complexity/Unit/Npath/NpathComplexityCollectorTest.php`                 | NPath                                       |
| `tests/Analysis/Evidence/Measurement/Integration/Aggregation/GoldenFileAggregationTest.php`      | NPath, only if a pinned NPath value changes |
| `src/Analysis/Evidence/Complexity/README.md`                                                     | NPath, if it states the switch formula      |
| `website/docs/rules/complexity.md`, `website/docs/rules/complexity.ru.md`                        | NPath                                       |
| `src/Analysis/Evidence/CodeSmell/ControlFlow/ChainOfAttempts.php`                                | #165                                        |
| `src/Analysis/Evidence/CodeSmell/ControlFlow/AttemptWork.php`                                    | #165, only if needed                        |
| `tests/Analysis/Evidence/CodeSmell/Unit/ChainOfAttemptsTest.php`                                 | #165                                        |
| `tests/Analysis/Evidence/CodeSmell/Unit/CodeSmellVisitorTest.php`                                | #165                                        |
| `src/Analysis/Evidence/CodeSmell/README.md`                                                      | #165                                        |
| `finding-gate/cases/detectors/src/ec03/Guards.php` (docblock of `precededGuard` only)            | #165                                        |
| `website/docs/rules/code-smell.md`, `website/docs/rules/code-smell.ru.md`                        | #165                                        |
| `docs/adr/0102-detector-verdicts-and-finding-identity.md`, `docs/adr/README.md` (shared with 04) | #165                                        |
| `docs/internal/modular-architecture-manifest.json` (shared)                                      | #165, only for the class below              |
| `CHANGELOG.md` (shared)                                                                          | all                                         |

A new class is allowed in one place only: if `composer selfcheck` reports a
complexity finding on `ChainOfAttempts` after the change, extract the new
check into one new internal class under
`src/Analysis/Evidence/CodeSmell/ControlFlow/` and register it in the manifest
(overview rule 2). Anywhere else a new class is a stop condition.

## Item RFC (#161 item 2) — enum built-in methods counted as external

**Facts.** `RfcEffectiveMembers::methods()` builds M from declared method
bodies, trait-imported methods and property hooks. An enum's `cases()` and,
for a backed enum, `from()` and `tryFrom()` are not in M, so
`self::from(...)` inside the enum resolves to the enum
(`RfcResponseResolver::receiver()`), finds no member and is counted as an
external response (`rfc-own 3`, `rfc-external 3` for the issue's fixture).
`RfcClassFacts::fromNode()` emits a `declaration` row with `kind`
(`Stmt_Enum` for enums) but not whether the enum is backed.
`RfcDeclarationFacts` filters members against the repository's callables, so
members without a declared body must bypass that filter.

**Decision.** These methods are members of the enum: PHP reports the enum as
their declaring class (`ReflectionMethod::getDeclaringClass()`), unlike an
inherited method. RFC's M is "the methods of the class" (Chidamber and Kemerer),
so they belong to M:
- every enum: `cases`;
- a backed enum (`enum X: string|int`): `cases`, `from`, `tryFrom`.

Consequences, all intended: every enum's `rfc-own` grows by 1 (pure) or 3
(backed); a call to these methods from inside the enum (`self::`, `static::`
or the enum's own name as receiver) is an own response, no longer external;
a call to another enum's `from()` stays external. `coupling.rfc` feeds no rule
and no health formula (`grep -rn "coupling\.rfc\|COUPLING_RFC" src` lists only
the collectors, `MetricName` and `MetricHintCatalog`), so findings and
self-analysis do not move. The gate corpus has no enum.

**Changes.**
- `RfcClassFacts::fromNode()`: the `declaration` row carries whether the
  class-like is a backed enum (a scalar, e.g. `'backed' => bool`).
- `RfcDeclarationFacts`: reads that row and exposes the implicit member names
  (lower-cased like every other member key: `cases`, `from`, `tryfrom`),
  outside the callable filter.
- `RfcEffectiveMembers::methods()`: adds the implicit members to M with the
  declaration itself as `source`.
- Every reader of a `methods()` entry that looks up the member's own rows
  (responses, visibility, `abstract`) must accept an implicit member: public,
  not abstract, no responses. Find them with
  `grep -rn "methods(" src/Analysis/Evidence/Coupling/Rfc` and read each.
- Docblocks that define M name the implicit enum members.

**Tests.**
- `RfcVisitorTest`: the backed-enum case (`Status: string`, own 2, external 1)
  becomes own 5, external 1; update it and say why in the data label or name.
- `RfcResponseFormsTest` F24 (a pure enum, own 1) becomes own 2; update it.
- Add (in `RfcVisitorTest`): the issue's `Suit` fixture gives `rfc-own 6`,
  `rfc-external 0`; `Suit::from()` written with the enum's own name inside the
  enum is own; a class calling `Suit::from()` counts it as external.

**Docs.** `website/docs/rules/coupling.md` RFC definitions (near
"rfc-own = |M|", lines ~330–336) and the `.ru.md` mirror: M includes an enum's
`cases()` and, for a backed enum, `from()`/`tryFrom()`. This is the
definition, not a deviation; no deviation block.

## Item NPath (#161 item 3) — `switch` without `default`

**Facts.** `NpathComplexityVisitor::calculateSwitchNpath()` sums the cases and
adds nothing when `default` is missing, so `switch ($x) { case 1: $x++; break; }`
scores 1 while the equivalent `if` scores 2. Nejmeh's paper is behind a
paywall. Its switch row as quoted by Checkstyle's documentation is
`NP(expr) + NP(default-range) + Σ NP(case-range)`. The two reference
implementations disagree: Checkstyle's `NPathComplexityCheck` scores the
default-less switch 2, pdepend (the engine behind PHPMD) scores it 1. The `if`
row's `+ 1` is the NPath of the absent `else` range: an empty range counts one
path.

**Decision.** Count the no-match path: an absent `default` is an empty
default range with NPath 1, exactly as an absent `else` is. `match` keeps
adding nothing for a missing default arm, because an unmatched `match`
throws.

**Change.** `calculateSwitchNpath()` adds 1 when no case of the switch is a
default (`$case->cond === null`). The class docblock's switch line states the
term. The NPath factor breakdown follows automatically; check one rule-level
factor test (`NpathComplexityRuleTest`) still passes.

**Effect on this repository.** Three switches in `src/` have no default
(`RfcDeclarationFacts::__construct`, `HookInstallCommand::clearExistingHook`,
`HookUninstallCommand::doExecute`; NPath today 14, 20 and 36, threshold 200).
Their values rise; no finding is expected. If `composer selfcheck` or
`composer directives:audit` turns red because of this, stop and report
(overview rule 6). The gate corpus's only switch has a default.

**Tests (NpathComplexityCollectorTest).** A one-case switch without default
scores 2, the same as the equivalent `if`; with a default it scores 2; two cases
without default score 3; the existing tests with defaults keep their values; a
`match` without a default arm keeps its value. If `composer check` shows a
pinned NPath value elsewhere (for example `GoldenFileAggregationTest`), verify
the new value by hand and update it, and list it in the report.

**Docs.** `website/docs/rules/complexity.md` NPath "Implementation notes"
(around line 394) and the `.ru.md` mirror: a `switch` without `default` counts
one path for "no case matched", as an `if` without `else` does; Checkstyle
counts it the same way, pdepend/PHPMD do not. The existing sentence that a
simple `match` agrees with the equivalent `switch` becomes true only for a
`switch` **with** a default; say so. `src/Analysis/Evidence/Complexity/README.md`
states the switch formula; update it.

## Item #165 — empty-catch exempts "prepare, guard, then work"

**Facts.** `ChainOfAttempts::holdsExit()` walks the `try` statements; any
earlier statement containing `AttemptWork` becomes `$priorWork`, and an `if`
branch whose exit is a bare `continue` then satisfies `endsAttemptAfterWork()`.
So `$y = $this->prepare($x); if ($y === null) { continue; } $this->work($y);`
is exempted as a chain of attempts, although the `continue` is an early guard
and the swallowed failures are those of `work()`. `ChainOfAttemptsTest`
documents this in the provider row "preceded guard remains a known
indistinguishable shape" (expected 1). The gate corpus contains the same shape
as `finding-gate/cases/detectors/src/ec03/Guards.php::precededGuard`, with a
docblock calling it "residual".

**Decision.** An `if` whose branch ends with a bare `continue` or `break` (no
`AttemptWork` inside the branch before the exit), and which is followed later in
the same `try` body by a statement containing `AttemptWork`, is an early guard,
not the end of an attempt. It does not make the `try` an attempt, whatever
precedes it. `return` branches are unchanged: the existing exempt row
`check($x); if (valid()) { return; } collect();` must stay exempt, so an early
`return` guard after a preparatory call remains a documented residue.

Shapes that must keep their current result (all rows of
`provideChainAttemptShapes` except the one flipped below), in particular:
- `$r = $o?->parse($x); return $r;` — exempt;
- `if ($x) { work($x); continue; }` — exempt (work inside the branch);
- `if (work($x)) { continue; }` as the last statement — exempt (nothing
  follows the `if`);
- `$r = prepare($x); if ($r !== null) { continue; }` as the last statement,
  with a fallback after the `try` — exempt (add this row; it is the genuine
  "found, skip the fallback" chain).

**Change.** In `ChainOfAttempts` (the `if`-branch path, `ifBranchHoldsExit()`
/ `branchHoldsExit()`, or `holdsExit()` where it knows the remaining
statements), implement the rule. Reuse `AttemptWork` to decide what "work" is.

**Tests.**
- `ChainOfAttemptsTest`: flip the "preceded guard" row to expected 0 and
  relabel it as a guard; add the same shape with `break` → 0; add the
  "found, skip the fallback" row → 1.
- `CodeSmellVisitorTest`: the issue's two methods (`guarded`, `prepared`) in
  one class both produce an empty-catch entry (the visitor is where the
  exemption is applied to real AST).
- Run `tests/Analysis/Evidence/CodeSmell/Unit/ControlFlowSmellsTest.php` once
  after the change; it must stay green unchanged.

**Docs.**
- `website/docs/rules/code-smell.md` (gap sentence around lines 223–225),
  `website/docs/rules/code-smell.ru.md` (around 224–226) and `src/Analysis/Evidence/CodeSmell/README.md`
  (around 68–69): replace the sentence with the new rule and keep one sentence
  for the `return` residue.
- `Guards.php::precededGuard` docblock: say the shape is reported; keep the
  docblock on the same number of lines and change nothing else in the file.
- ADR 0102: append `## Amendment, <date>: an early continue or break guard is not an attempt`
  (overview rule 7), stating the new rule and the `return` residue; add the
  `amended <date>: ...` clause to 0102's line in `docs/adr/README.md`.

## CHANGELOG (`### Fixed`)

- RFC counts an enum's built-in `cases()`, `from()` and `tryFrom()` as its own methods, not as external responses.
- NPath counts the no-match path of a `switch` without `default`, as it does for an `if` without `else`.
- `code-smell.empty-catch` reports a loop whose `try` skips with a guard on a prepared value before the work.

## Session gate

After the overview ladder steps 1–5:

1. `composer docs:check` once.
2. `composer check:gate` once (you edited a file under `finding-gate/`).
3. `composer gate ...` once (overview, "The finding gate"). **Expected gate
   differences:** in case `detectors`, one new `code-smell.empty-catch` record
   for `Guards::precededGuard`, and whatever counts, rankings and summaries
   derive from that one record. Nothing for RFC or NPath.
4. `composer check` once (overview step 7).

`benchmark:check` is not run locally; the pull request's Benchmark job runs it.
If it fails, report the projects and values; do not run `benchmark:update`.

## Definition of Done

- The RFC, NPath and empty-catch behaviours are pinned by the tests named above.
- `bin/qmx check . --working-dir=<scratch with the #165 fixture> --no-cache --workers=0 --only-rule=code-smell.empty-catch`
  reports both `guarded` and `prepared`.
- `--format=metrics` on the issue's `Suit` fixture gives `coupling.rfc-own: 6`,
  `coupling.rfc-external: 0`; on `switch ($x) { case 1: $x++; break; }` it
  gives `complexity.npath: 2`.
- The printed exit code of the final `composer check` is 0.
