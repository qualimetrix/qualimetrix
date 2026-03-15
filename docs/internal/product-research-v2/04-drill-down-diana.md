# 04 — Drill Down Diana: Progressive Disclosure Workflow

**Persona:** Tech Lead planning a tech debt sprint
**Projects:** Laravel Framework (`benchmarks/vendor/laravel/framework/src`), PHPUnit (`benchmarks/vendor/phpunit/phpunit/src`)
**Focus:** Can I go from overview to sprint backlog using drill-down?

## Summary

The summary → namespace → class drill-down workflow is genuinely useful and produces actionable sprint backlogs. Namespace drill-down correctly scopes health scores to the selected namespace, violation lists, and worst-class rankings. The `--class` flag is a notable exception — it shows project-wide health scores rather than class-scoped ones, which dilutes the value of the deepest drill level. The `--format-opt=top=N` flag works for JSON but is silently ignored by the summary formatter, where the count is hardcoded at 3.

## Projects Overview

| Project           | Files | Violations         | Overall Health   |
| ----------------- | ----- | ------------------ | ---------------- |
| Laravel Framework | 1536  | 7489 (4784 errors) | 51.8% Acceptable |
| PHPUnit           | 995   | 1801 (502 errors)  | 73.5% Strong     |

**Tech debt:** Laravel: 320d 7h (617.5 min/kLOC) — PHPUnit: 86d 5h (494.1 min/kLOC)

## Drill-Down Walkthrough

### Laravel Framework

**Phase 1 — Project overview**

```
Health ████████████████░░░░░░░░░░░░░░ 51.8% Acceptable

  Complexity      ████████████████░░░░░░░░░░░░░░ 54.2% Acceptable
  Coupling        ████████░░░░░░░░░░░░░░░░░░░░░░ 26.1% Weak
                   ↳ CBO (avg): 7.6 — classes depend on too many others
  Typing          ██████░░░░░░░░░░░░░░░░░░░░░░░░ 18.5% Critical

Worst namespaces
  31.7 Illuminate\Validation\Concerns (1 classes, 107 violations) — low type safety, high complexity
  36.2 Illuminate\View\Compilers (3 classes, 118 violations) — low type safety, high coupling
  36.5 Illuminate\Database\Query\Grammars (6 classes, 69 violations) — low cohesion, low type safety

Worst classes
  33.5 Illuminate\Foundation\Application (15 violations) — low type safety, low cohesion
  34.1 Illuminate\Filesystem\Filesystem (12 violations) — low type safety, low cohesion
  34.6 Illuminate\Database\Eloquent\Model (16 violations) — low type safety, low cohesion
```

The hint line reads: `--namespace='Illuminate\Validation\Concerns' to drill down` — immediately actionable.

**Phase 2 — Namespace drill-down: `Illuminate\Validation\Concerns`**

```
Health [namespace: Illuminate\Validation\Concerns] ██████████░░░░░░░░░░░░░░░░░░░░ 31.7% Weak

  Complexity      ███░░░░░░░░░░░░░░░░░░░░░░░░░░░ 10.9% Critical
  Typing          █░░░░░░░░░░░░░░░░░░░░░░░░░░░░░  1.8% Critical
  Maintainability ██████████████████████░░░░░░░░ 73.0% Acceptable

Worst classes
  48.1 ValidatesAttributes (21 violations) — low type safety, high coupling
  57.2 FormatsMessages (11 violations) — low type safety
  67.3 ReplacesAttributes (65 violations) — low type safety
```

The health scores here reflect the namespace scope, not the project. Complexity 10.9% in this namespace vs 54.2% project-wide — the scoping works correctly.

**Phase 3 — Namespace drill-down: `Illuminate\View\Compilers` (second worst)**

```
Health [namespace: Illuminate\View\Compilers] █████████████░░░░░░░░░░░░░░░░░ 43.8% Weak

  Complexity      ████████████░░░░░░░░░░░░░░░░░░ 40.4% Weak
  Cohesion        ███████████░░░░░░░░░░░░░░░░░░░ 37.8% Weak
  Coupling        █████████████░░░░░░░░░░░░░░░░░ 41.9% Weak
  Typing          █████░░░░░░░░░░░░░░░░░░░░░░░░░ 16.6% Critical

Worst classes
  36.6 BladeCompiler (16 violations) — low type safety, low cohesion
```

Key class-level finding on BladeCompiler: `God Class detected (4/4 criteria): high WMC (112 >= 47), high LCOM (6 >= 3), low TCC (0.02 < 0.33), large size (1071 >= 300 LOC)`. This confirms the class is a genuine refactoring target, not a false positive.

**Phase 4 — Class drill-down: `Illuminate\View\Compilers\BladeCompiler`**

```
Health ████████████████░░░░░░░░░░░░░░ 51.8% Acceptable   ← PROJECT-WIDE, not class-scoped
```

The class-specific violations are shown correctly (16 violations: God Class, CBO 41, LCOM4 6, etc.), and per-rule debt is broken down:

```
code-smell.god-class     ~2h       (1 violation)
coupling.cbo             ~45min    (1 violation)
design.type-coverage     ~45min    (3 violations)
design.lcom              ~45min    (1 violation)
complexity.wmc           ~30min    (1 violation)
```

Total estimated debt for BladeCompiler: ~7h 40min.

**Phase 5 — JSON drill-down for sprint planning**

`--namespace='Illuminate\Validation\Concerns' --format=json` confirms that health scores in the JSON output reflect the namespace scope (complexity: 10.9%, typing: 1.8%). The `worstClasses` array includes per-class metrics (`wmc`, `cbo`, `methodCount`) and health scores. The `summary.techDebtMinutes` field gives 2220 min (~37h) for the Validation\Concerns namespace alone.

**Using `--format=json --format-opt=top=10`**, the full worst-class ranking is available:

| Rank | Class                 | Health | Violations | Key Issue                      |
| ---- | --------------------- | ------ | ---------- | ------------------------------ |
| 1    | Application           | 33.5%  | 15         | low type safety, low cohesion  |
| 2    | Filesystem            | 34.1%  | 12         | low type safety, low cohesion  |
| 3    | Model                 | 34.6%  | 16         | low type safety, low cohesion  |
| 4    | Collection (Eloquent) | 36.5%  | 11         | low type safety, low cohesion  |
| 5    | BladeCompiler         | 36.6%  | 16         | God Class, CBO 41              |
| 6    | Builder (Query)       | 36.9%  | 59         | cohesion 1.6%, 239 methods     |
| 7    | Collection (Support)  | 37.1%  | 15         | low type safety, high coupling |
| 8    | QueueServiceProvider  | 37.8%  | 11         | low type safety, low cohesion  |
| 9    | TestResponse          | 38.6%  | 17         | low type safety, low cohesion  |
| 10   | Container             | 38.8%  | 17         | low type safety, high coupling |

---

### PHPUnit

**Phase 1 — Project overview**

```
Health ██████████████████████░░░░░░░░ 73.5% Strong
  Typing          ██████████████████████████████ 100.0% Strong
  Coupling        ████████████░░░░░░░░░░░░░░░░░░ 39.0% Weak

Worst namespaces
  36.1 PHPUnit (1176 violations) — low type safety, hard to maintain

1801 violations | Tech debt: 86d 5h
```

The summary shows only one worst namespace: `PHPUnit` at 36.1%. This is misleading — see FINDING-1 below.

**Phase 2 — Namespace drill-down: `PHPUnit`**

```
Health [namespace: PHPUnit] ████████████████████████░░░░░░ 80.2% Strong
  Typing          ██████████████████████████████ 99.8% Strong
```

The health jumps from 36.1% (in the summary) to 80.2% (in the drill-down). This inconsistency exists because the summary uses a "direct membership" metric for the root namespace, while the drill-down is prefix-match inclusive of all sub-namespaces. The 36.1% comes from the root namespace entry having `typing: 0` and `maintainability: 0` — the root namespace has no classes directly in it (all classes live in sub-namespaces like `PHPUnit\Framework`, `PHPUnit\Metadata`, etc.).

Worst classes visible from the drill-down:
```
  50.8 PHPUnit\Framework\TestCase (25 violations) — low cohesion, high coupling
  53.0 PHPUnit\Metadata\Api\Requirements (11 violations) — high complexity, hard to maintain
  53.1 PHPUnit\Framework\Constraint\Constraint (6 violations) — low cohesion, high coupling
```

**Phase 3 — Namespace drill-down: `PHPUnit\Framework` (more targeted)**

```
Health [namespace: PHPUnit\Framework] ████████████████████████░░░░░░ 81.0% Strong
```

Correctly scoped. Worst classes here include `TestCase` at 50.8%.

**Phase 4 — Class drill-down: `PHPUnit\Framework\TestCase`**

```
Health ██████████████████████░░░░░░░░ 73.5% Strong   ← PROJECT-WIDE, not class-scoped
```

The 25 violations are listed correctly. Highlights:
- God Class (4/4 criteria): WMC 316, LCOM4 16, TCC 0.06, 2452 LOC
- CBO: 90 (max 20)
- `runBare()`: NPath 73728, cognitive complexity 40

**Phase 5 — Class drill-down: `PHPUnit\Metadata\Api\Requirements`**

Single massive method: `requirementsNotSatisfiedFor()` — cyclomatic 31, cognitive 64, NPath 1,023,517. MI: 30.3 (below 40 threshold — "hard to change safely"). Total debt: ~5h 30min. This is a textbook "one method needs a full rewrite" case.

## Findings

### HIGH

**FINDING-1: Misleading root namespace health in summary**
When a project uses a single top-level namespace with no classes directly in it (like PHPUnit), the summary shows the root namespace at 36.1% ("low type safety, hard to maintain"). Drilling into that namespace shows 80.2%. The root namespace entry has `typing: 0` and `maintainability: 0` because it aggregates zero direct-member classes. A user following the hint `--namespace=PHPUnit to drill down` immediately sees a contradiction. The suggested worst namespace is not actually the worst sub-namespace to investigate.

**FINDING-2: `--class` drill-down shows project-wide health scores**
When using `--class='Foo\Bar\Baz'`, the health bar at the top shows the project-wide scores, not the class scores. The class's own health scores (e.g., BladeCompiler's coupling 29.4%, cohesion 1.2%) are only visible inside the violation list. Contrast with `--namespace`, where the header reads `Health [namespace: X]` and the bars are namespace-scoped. The `--class` header just says `Health` with no qualification, and shows the project numbers. For sprint planning, this is confusing — you look at the header expecting "how bad is this class?" and get "how bad is the whole project?"

Expected output:
```
Health [class: Illuminate\View\Compilers\BladeCompiler] ████████░░░░░░░░░░░░░░░░░░░░░░ 36.6% Weak
```
Actual output:
```
Health ████████████████░░░░░░░░░░░░░░ 51.8% Acceptable
```

### MEDIUM

**FINDING-3: `--format-opt=top=N` silently ignored by summary formatter**
Running `--format-opt=top=5` or `--format-opt=top=10` with the default summary format produces identical output — always 3 worst namespaces and 3 worst classes. The count is hardcoded as `private const int MAX_WORST_OFFENDERS = 3` in `SummaryFormatter`. The `--format-opt=top=N` option works correctly for `--format=json`, expanding `worstClasses` and `worstNamespaces` to N entries. There is no error or warning when the option is passed to the summary formatter; it is silently ignored.

**FINDING-4: Violation count vs health ranking inconsistency**
`Illuminate\Database\Query\Builder` has 59 violations but appears at rank 6 in the worst-class list (health 36.9%), while classes with 15-16 violations are ranked higher (ranks 1-5). A tech lead scanning the summary list might assume the 3 shown worst classes have the highest violation counts, but `Builder` with nearly 4x the violations is hidden behind `+7 more`. When drilling into `Illuminate\Validation\Concerns`, `ReplacesAttributes` has 65 violations but ranks 3rd in the namespace (health 67.3%), not 1st — correctly, because violations are dominated by repetitive `long-parameter-list` warnings rather than deep structural problems. The distinction between "high violations" and "low health" is real, but not explained anywhere at the summary level.

**FINDING-5: No total debt estimate at class level in summary format**
When using `--class`, the per-rule debt breakdown is shown in the summary formatter output. However, there is no "Total: ~7h 40min" line aggregating these. You have to mentally add them. The namespace drill-down has the same gap — the total debt line (`107 violations | Tech debt: ...`) appears only in the overall summary, not in the per-rule breakdown block.

### LOW

**FINDING-6: Hint suggests `--namespace=PHPUnit` for a namespace with misleading health**
The CLI hint reads: `--namespace=PHPUnit to drill down`. After following it, the user sees 80.2% Strong — better than the 73.5% project average. The "worst namespace" hint leads to a namespace that looks *better* than the project average, because the 36.1% is an artifact of root-level aggregation (FINDING-1). The hint should either point to the worst *sub-namespace* or the misleading score should be corrected.

**FINDING-7: `--class` does not auto-imply `--detail`**
The CLAUDE.md notes "This should auto-enable --detail. Check if it does." It does not. Running `--class='X'` shows violations directly, but the same output would be shown with `--detail`. Given that `--class` is already a precision query (you're asking about one specific class), auto-enabling `--detail` is expected behavior that does not occur. The current behavior happens to produce useful output because individual class violation counts are low (16-25 violations), but for a class like `Builder` with 59 violations, truncation could occur.

**FINDING-8: PHPUnit summary shows 0 worst classes**
The project-level JSON for PHPUnit has `worstClasses: []`. The summary output correctly shows the worst namespace but shows no worst classes section at all. PHPUnit does have problematic classes (TestCase at 50.8%), but they do not appear in the summary. This appears to be a threshold issue — the default worst-class threshold may require a health score below a cutoff that no PHPUnit class crosses at the project level (all classes pass 50%). A tech lead looking at the PHPUnit summary gets zero class-level guidance without drilling into a namespace first.

## Sprint Planning Assessment

**Can you actually plan a sprint from this output?** Yes, with the namespace drill-down as the key step.

### Extracted 5-Class Sprint Backlog (Laravel)

Based on health, structural severity, and estimated debt:

| Priority | Class                                                | Health | Est. Debt | Primary Issue                |
| -------- | ---------------------------------------------------- | ------ | --------- | ---------------------------- |
| 1        | `Illuminate\View\Compilers\BladeCompiler`            | 36.6%  | ~7h 40min | God Class, LCOM4 6, CBO 41   |
| 2        | `Illuminate\Database\Query\Builder`                  | 36.9%  | unknown*  | 239 methods, cohesion 1.6%   |
| 3        | `Illuminate\Validation\Concerns\ValidatesAttributes` | 48.1%  | ~4h       | 133 methods, WMC 454, CBO 29 |
| 4        | `Illuminate\Validation\Concerns\FormatsMessages`     | 57.2%  | ~3h       | WMC 85, 6 long-param methods |
| 5        | `PHPUnit\Metadata\Api\Requirements`                  | 52.9%  | ~5h 30min | One method: NPath 1M, CCN 31 |

*Builder's per-rule debt breakdown requires a `--class` drill-down to retrieve; the violation count (59) suggests it is the highest-debt class in the project.

### What works well for sprint planning:
- The namespace health scores are correctly scoped — comparing two namespaces gives meaningful relative signal.
- Per-rule debt breakdown at class level gives granular estimates (e.g., "~2h god-class, ~45min type coverage").
- The `reason` field on worst offenders ("God Class detected (4/4 criteria)") is concrete enough to write a ticket.
- JSON with `--format-opt=top=10` gives a full ranked backlog in one command.

### What is missing for sprint planning:
- No way to sort worst classes by debt (currently sorted by health score only).
- No aggregated total debt at class level in summary format (must sum per-rule manually).
- `--class` header shows project health, not class health — forces a mental context switch.
- No "quick win" flag to surface classes with high debt but few violations (easy refactors first).
- The 59-violation `Builder` class is hidden at rank 6; a "sort by violations" or "sort by debt" option would surface it.

## What Works Well

1. **Namespace drill-down scoping is correct** — health scores at every level reflect the filtered scope, not the project.
2. **The `reason` field is sprint-ready** — "God Class detected (4/4 criteria)" is a ticket description, not just a number.
3. **Worst classes inside a namespace are accurate** — manually verified against raw metrics; the ranking is correct.
4. **Per-rule tech debt breakdown** is the most actionable output in the tool. The breakdown of `~2h god-class, ~45min cbo, ~45min type-coverage` directly informs sprint capacity planning.
5. **The hint line is contextually smart** — after a project analysis, it suggests the exact `--namespace=` command to drill into the worst offender.
6. **JSON format is complete** — all the data needed for a sprint planning script is present: health scores, violation counts, metrics (`wmc`, `cbo`, `methodCount`), and debt minutes per class.
7. **Two-project comparison** — Laravel's 617.5 min/kLOC vs PHPUnit's 494.1 min/kLOC is a meaningful benchmark signal that a tech lead can use to calibrate what "normal" debt density looks like.
