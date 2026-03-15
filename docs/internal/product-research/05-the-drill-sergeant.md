# 05 — The Drill Sergeant

**Persona**: Tech Lead identifying top 3 refactoring targets for sprint planning
**Date**: 2026-03-15
**Projects tested**: Doctrine ORM (453 files), Laravel Framework (1536 files)

---

## Executive Summary

The summary-first workflow is effective for the "busy Tech Lead" scenario. Within 60 seconds, I could identify the top 3 refactoring targets for Doctrine ORM with confidence. The drill-down via `--namespace` and `--class` filters works smoothly and narrows violations correctly. However, several UX issues reduce sprint-planning readiness: health scores don't change when filtering (confusing), the `--detail` output for a full project is 3800+ lines (unusable without piping), and Laravel OOMs at 128MB default memory limit. The tech debt estimates are useful for prioritization but feel inflated (65 days for Doctrine ORM is hard to act on). Overall, the tool gets a Tech Lead 80% of the way to a sprint backlog in under 5 minutes.

---

## 1. Workflow Test

### Step 1: Overview (Doctrine ORM)

```
bin/aimd check benchmarks/vendor/doctrine/orm/src --no-cache --workers=0 \
  --disable-rule=architecture.circular-dependency --disable-rule=duplication.code-duplication
```

Output (3.9s):

```
AI Mess Detector — 453 files analyzed, 3.9s

Health ██████████████████████░░░░░░░░ 73.4% Excellent

  Complexity      ██████████████░░░░░░░░░░░░░░░░ 46.5% Needs attention
                   ↳ Cyclomatic (avg): 16.9 (target: below 4) — too many code paths per method
                   ↳ Cognitive (avg): 15.3 (target: below 5) — deeply nested, hard to follow
  Cohesion        ██████████████████████████░░░░ 86.8% Excellent
  Cohesion        ██████████████████████████░░░░ 86.8% Excellent
  Coupling        ██████████████████████░░░░░░░░ 73.2% Excellent
  Typing          ██████████████████████████████ 99.6% Good
  Maintainability ██████████████████████░░░░░░░░ 74.4% Good

Worst namespaces
  45.2 Doctrine\ORM\Query (16 classes, 196 violations) — high coupling, high complexity

Worst classes
  45.8 Doctrine\ORM\Mapping\ClassMetadataFactory (20 violations) — high coupling, low cohesion
  48.0 Doctrine\ORM\Mapping\AssociationMapping (7 violations) — high coupling, low cohesion
  48.6 Doctrine\ORM\EntityManager (14 violations) — high coupling, low cohesion

1153 violations (380 errors, 773 warnings) | Tech debt: 65d 35min

Hints: --detail to see all violations | --namespace='Doctrine\ORM\Query' to drill down
       | --format=html -o report.html for full report
```

**Verdict**: Immediately actionable. In 4 seconds I know:
- Complexity is the weak area (46.5%)
- `Doctrine\ORM\Query` is the worst namespace (196 violations)
- `ClassMetadataFactory` is the worst class (20 violations, "God Class")
- Total tech debt: 65 days

The hints line at the bottom is excellent — it tells me exactly what to type next.

### Step 2: Namespace Drill-Down

```
bin/aimd check benchmarks/vendor/doctrine/orm/src --no-cache --workers=0 \
  --namespace="Doctrine\ORM\Query" ...
```

Output shows the same health header but narrows violations to 196 (from 1153). The worst classes list disappears because no class within `Doctrine\ORM\Query` made the top 3.

### Step 3: Class Drill-Down

```
bin/aimd check benchmarks/vendor/doctrine/orm/src --no-cache --workers=0 \
  --class="Doctrine\ORM\Mapping\ClassMetadataFactory" ...
```

Shows 20 violations for this single class. Adding `--detail` gives the full violation list:

```
  ERROR :53  ClassMetadataFactory
    God Class detected (4/4 criteria): high WMC (133 >= 47), high LCOM (4 >= 3),
    low TCC (0.00 < 0.33), large size (693 >= 300 LOC)  [code-smell.god-class]

  ERROR :135  ClassMetadataFactory::doLoadMetadata
    Cognitive complexity: 43 (max 30)  [complexity.cognitive.method]

  ERROR :135  ClassMetadataFactory::doLoadMetadata
    Cyclomatic complexity: 27 (max 20)  [complexity.cyclomatic.method]

  ERROR :135  ClassMetadataFactory::doLoadMetadata
    NPath complexity: 917280 (max 1000)  [complexity.npath.method]
```

Plus a per-rule tech debt breakdown:

```
Technical debt by rule:
  code-smell.god-class                     ~2h       (1 violation)
  complexity.cognitive                     ~2h       (4 violations)
  complexity.cyclomatic                    ~1h 30min (3 violations)
  ...
```

**Verdict**: The `--class` + `--detail` combination is the "sprint ticket writer" — gives exact line numbers, method names, and specific metrics. I could paste this into a Jira ticket.

---

## 2. Drill-Down Experience

### What Works

- **Progressive disclosure**: Summary -> namespace -> class is intuitive
- **Hints at the bottom**: The tool tells you the next command to run. This is the single best UX feature for drill-down
- **Violation count narrows correctly**: 1153 -> 196 (namespace) -> 20 (class)
- **Filter label in header**: `[namespace: Doctrine\ORM\Query]` makes it clear what's filtered

### Issues

1. **Health scores don't change when filtering** (HIGH). When I filter to `--namespace="Doctrine\ORM\Query"`, the top health section still shows the project-wide scores (73.4% overall). This is deeply confusing. A Tech Lead filtering to a namespace expects to see that namespace's health, not the project's. The violation count narrows (196 vs 1153), but the health dashboard doesn't. This undermines the drill-down story.

2. **No class-level violations shown without `--detail`** (MEDIUM). When using `--class="ClassMetadataFactory"` without `--detail`, the output shows only the health dashboard and "20 violations". The hint says `--detail to see all violations`, but a Tech Lead would expect the class drill-down to automatically show violations — that's the whole point of drilling down to a class.

3. **Namespace filter on `Doctrine\ORM` shows identical output to unfiltered** (LOW). The root namespace contains everything, so filtering to it is a no-op. This is technically correct but could be confusing if a user tries to "drill into" the root namespace.

---

## 3. Worst Offenders Assessment

### Doctrine ORM

| Rank | Target                           | Score | Violations | Intuitive?                                    |
| ---- | -------------------------------- | ----- | ---------- | --------------------------------------------- |
| 1    | `Doctrine\ORM\Query` (namespace) | 45.2  | 196        | Yes — the DQL parser is notoriously complex   |
| 2    | `ClassMetadataFactory` (class)   | 45.8  | 20         | Yes — mapping hydration is a known pain point |
| 3    | `AssociationMapping` (class)     | 48.0  | 7          | Yes — association configuration is complex    |
| 4    | `EntityManager` (class)          | 48.6  | 14         | Yes — god object by nature (facade)           |

**Verdict**: These are exactly the classes a Doctrine contributor would point to. The ranking is credible.

### Laravel Framework

| Rank | Target                                    | Score | Violations | Intuitive?                                     |
| ---- | ----------------------------------------- | ----- | ---------- | ---------------------------------------------- |
| 1    | `Illuminate\Validation\Concerns` (ns)     | 32.1  | 107        | Yes — `ValidatesAttributes` is a massive trait |
| 2    | `Illuminate\Database\Query\Grammars` (ns) | 36.5  | 70         | Yes — SQL grammar builders are complex         |
| 3    | `Illuminate\View\Compilers` (ns)          | 36.5  | 118        | Yes — Blade compiler is notoriously complex    |

**Verdict**: Again, these match known pain points. The tool correctly identifies that Laravel's biggest issue is type coverage (18.5% — Laravel historically lacks type hints), which is exactly right.

### Cross-Project Comparison

| Metric     | Doctrine ORM      | Laravel      |
| ---------- | ----------------- | ------------ |
| Health     | 73.4% "Excellent" | 63.3% "Good" |
| Complexity | 46.5%             | 54.2%        |
| Typing     | 99.6%             | 18.5%        |
| Tech Debt  | 65d               | 313d         |
| Violations | 1153              | 7360         |

**Verdict**: The comparison is immediately readable. Doctrine is more complex per-method but has excellent typing. Laravel has poor typing but better average complexity. A CTO could look at these two reports side-by-side and make decisions. The health percentage is the single most useful comparison metric.

---

## 4. Sprint Planning Readiness

### Can I Build a Backlog?

**Yes**, but with manual work. Here's the backlog I would create from the Doctrine output:

1. **Refactor `ClassMetadataFactory::doLoadMetadata`** (ERROR, 8h)
   - CCN 27, Cognitive 43, NPath 917K
   - Extract sub-methods for different mapping types
   - Line 135, 693 LOC class

2. **Split `Doctrine\ORM\Query` namespace** (WARNING, 2-3 sprints)
   - 16 classes, 196 violations, score 45.2
   - Parser, Lexer, AST deserve separate namespaces

3. **Reduce `EntityManager` coupling** (WARNING, 1 sprint)
   - CBO 37 (max 20), 14 violations
   - Extract specialized managers (UnitOfWorkManager, etc.)

### What's Missing for Sprint Planning

- **Effort estimates per class/namespace**: The tech debt total (65d) is useful, but I need per-target estimates. The `--detail --class` output gives per-rule breakdown, which is close but not quite "story points"
- **Suggested refactoring strategies**: The tool says "God Class detected" but doesn't suggest "extract class" or "extract method". The human message hints are good ("depends on too many classes") but a "suggested action" column would close the loop
- **Priority matrix**: The tool ranks by score, but a Tech Lead needs impact vs. effort. A class with score 45 and 7 violations might be easier to fix than one with score 46 and 20 violations

---

## 5. `--detail` Flag Evaluation

### Output Volume

- Doctrine ORM: **3825 lines** with `--detail`
- This is unusable in a terminal without piping to `less` or a file
- The summary-only output (without `--detail`) is 20 lines — perfect for terminal

### What `--detail` Adds

1. **Per-file violation list**: Every file, every violation, with line numbers
2. **Tech debt by rule**: Breakdown showing where debt concentrates

The tech debt by rule breakdown is the most useful part:
```
  coupling.instability  ~8d 5h 30min (139 violations)
  computed.health       ~8d 45min    (259 violations)
  complexity.cyclomatic ~7d 3h 30min (119 violations)
```

### Verdict

`--detail` is useful when scoped (via `--class` or tight `--namespace`). On a full project, it's information overload. The tool should perhaps warn or paginate when `--detail` would produce >100 violations.

---

## 6. Issues Found

### Issue 1: Health Scores Don't Change When Filtering (HIGH)

**Impact**: Undermines the entire drill-down experience. When a Tech Lead filters to `--namespace="Doctrine\ORM\Query"`, they expect to see that namespace's health scores, not the project-wide ones.

**Current behavior**: The header always shows project-wide health regardless of `--namespace`/`--class` filter.

**Expected behavior**: Either (a) show the filtered namespace/class health scores, or (b) show both project-wide and filtered scores side-by-side.

### Issue 2: Laravel OOMs at Default Memory Limit (HIGH)

**Impact**: A Tech Lead running `bin/aimd check` on a large project gets a PHP fatal error with a multi-KB stack trace. First impressions are critical.

```
PHP Fatal error: Allowed memory size of 134217728 bytes exhausted
```

**Workaround**: `php -d memory_limit=512M bin/aimd check ...`

**Suggestion**: Either increase the default memory limit in `bin/aimd` (many similar tools use 256M or 512M), or catch the OOM and display a helpful message suggesting `--workers=0` or increased memory.

### Issue 3: Class Filter Should Auto-Enable Detail (MEDIUM)

**Impact**: When a Tech Lead drills down to `--class="ClassName"`, they expect to see violations. Getting only a summary with "20 violations" and a hint to add `--detail` is one unnecessary step.

**Suggestion**: Auto-enable `--detail` when `--class` filter is used, or show a condensed violation list (top 5-10) even without `--detail`.

### Issue 4: Worst Offenders Show Only Top 3 (LOW)

**Impact**: For sprint planning, you typically want top 5-10 targets. The summary shows only 1 worst namespace and 3 worst classes. This is fine for a quick overview but limiting for planning.

**Suggestion**: Allow `--top=N` to control how many worst offenders are shown.

### Issue 5: Tech Debt Totals Feel Inflated (LOW)

**Impact**: "65 days" for Doctrine ORM and "313 days" for Laravel are technically correct per the remediation model but hard to translate to sprint capacity. A team might dismiss these as unrealistic.

**Suggestion**: Consider showing relative comparisons ("top 10% of PHP projects by complexity") rather than absolute day counts.

### Issue 6: `--detail` on Full Project is Unwieldy (LOW)

**Impact**: 3825 lines of output for Doctrine, likely 10K+ for Laravel. No pagination, no truncation warning.

**Suggestion**: When `--detail` would produce >200 violations, warn and suggest `--namespace` or `--class` filter, or `--format=html -o report.html`.

---

## 7. What Works Well

1. **Summary-first default output is excellent**. 20 lines, 4 seconds, immediately actionable. This is the right default.

2. **Hints line is the killer feature**. Telling the user exactly what to type next (`--namespace='Doctrine\ORM\Query'`) eliminates guesswork and teaches the tool's workflow.

3. **Health scores with bar charts are presentation-ready**. You could screenshot the summary output and show it at sprint planning. The colored bars and percentage labels are clear.

4. **Worst offenders ranking matches intuition**. For both Doctrine and Laravel, the identified targets are exactly what experienced developers would point to.

5. **Cross-project comparison is natural**. The health percentage makes it trivial to say "Doctrine is healthier than Laravel" (73.4% vs 63.3%).

6. **Per-class `--detail` output is ticket-ready**. Line numbers, method names, specific metrics — this is exactly what goes into a refactoring ticket.

7. **Metric explanations in parentheses**. "CBO: 37 (max 20) — depends on too many classes" is immediately understandable without consulting docs.

8. **Tech debt by rule breakdown** at the end of `--detail` output is excellent for identifying systemic patterns ("our biggest debt category is instability, not complexity").

---

## 8. Recommendations

### Quick Wins (1-2 days each)

1. **Auto-enable `--detail` for `--class` filter** — drilling to a class inherently means "show me everything about it"
2. **Increase default memory limit** to 256M or 512M in `bin/aimd` — prevents OOM on projects like Laravel
3. **Add `--top=N` option** for worst offenders count — default 3 for namespaces, 3 for classes, but allow overriding

### Medium-Term (sprint)

4. **Scope health scores to the active filter** — when `--namespace` or `--class` is used, show the filtered entity's health, not project-wide. This is the most impactful UX improvement for the drill-down workflow
5. **Truncation/warning for large `--detail` output** — suggest narrowing scope when violations > 200

### Longer-Term

6. **"Suggested action" per violation** — "God Class -> consider Extract Class", "high CBO -> introduce Facade"
7. **Sprint-ready export** — `--format=backlog` producing a markdown checklist with effort estimates per target, sorted by impact-to-effort ratio
