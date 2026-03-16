# 01 — Refactor Rick

**Persona:** Senior PHP developer looking for refactoring targets
**Projects:** Doctrine ORM, Laravel Framework
**Date:** 2026-03-16

## Executive Summary

AIMD is genuinely useful for identifying refactoring targets. For Doctrine ORM, the tool correctly identified all major problem classes (UnitOfWork, SqlWalker, ClassMetadata, EntityManager, BasicEntityPersister) with accurate diagnostic detail. For Laravel, the tool correctly identifies god classes and coupling hotspots but is heavily skewed by type coverage violations — Laravel's lack of native type hints dominates the output, making it harder to separate structural problems from stylistic ones. The drill-down workflow (summary -> namespace -> class -> detail) works well and is intuitive. The main risk is false prioritization: classes like MessageSelector and ReplacesAttributes appear in "worst offenders" due to mechanical patterns (locale lookup tables, uniform callback signatures) rather than genuine design problems.

## Findings

### Finding R1: MessageSelector false alarm — lookup table inflates CCN
**Severity:** MEDIUM
**Category:** misleading-metric

`Illuminate\Translation\MessageSelector::getPluralIndex` reports CCN=334 and appears as the #2 worst class overall (health score 37.5). In reality, this method is a giant `switch` statement mapping ~300 locale codes to pluralization categories. It is essentially a lookup table — not genuinely complex code that needs refactoring.

A senior developer seeing "CCN: 334" would immediately investigate, spend 5 minutes reading the code, and dismiss it. The tool wastes investigation time here. This is a well-known limitation of cyclomatic complexity (switch statements), but the tool should at least not rank it as #2 worst class when most of its "complexity" is a flat switch.

**Possible mitigation:** Consider adding a heuristic or note when a class's complexity is dominated by a single large switch/match statement. Or weigh cognitive complexity more heavily than cyclomatic in the health score — cognitive complexity for this class is only 52, which is much more reasonable.

### Finding R2: ReplacesAttributes false alarm — uniform callback signatures
**Severity:** MEDIUM
**Category:** false-positive

`Illuminate\Validation\Concerns\ReplacesAttributes` has 65 violations, 61 of which are "long-parameter-list" warnings. Every method has the signature `($message, $attribute, $rule, $parameters)` — this is a framework convention for validation rule message replacement. The tool treats each method independently and flags the same pattern 61 times.

This creates significant noise. The namespace `Illuminate\Validation\Concerns` appears as the #1 worst namespace largely because of this repetition.

**Possible mitigation:** When a class has many methods with the exact same parameter signature (trait/interface contract pattern), consider collapsing repeated violations into a single finding with a count.

### Finding R3: Laravel type coverage dominates everything
**Severity:** HIGH
**Category:** misleading-metric

Laravel Framework has 7443 violations total, with 4804 errors. A huge portion of these are type-coverage violations (parameter, return, property type coverage). This is factually correct — Laravel has ~30% parameter coverage and ~6% return type coverage. But it drowns out structural problems.

When I look at the "worst classes" list, the ranking is heavily influenced by typing scores. `Illuminate\Foundation\Application` (health 35.4) ranks worse than `Illuminate\Database\Query\Builder` (health 40.4), partly because Application has lower type coverage. But from a refactoring perspective, Query\Builder (4776 LOC, 239 methods, WMC=492) is a far more urgent target.

For a developer looking for refactoring targets, type coverage is useful context but should not be the primary sorting criterion for "worst offenders." A developer can't easily refactor Laravel's type coverage without breaking backward compatibility.

**Expected behavior:** Either (a) allow filtering/disabling type coverage from the health score, or (b) provide separate "structural health" vs "typing health" rankings.

### Finding R4: Doctrine ORM worst offenders are spot-on
**Severity:** N/A
**Category:** good-catch

The tool correctly identifies all the classes that any experienced Doctrine developer would flag:

1. **UnitOfWork** (health 67.0, 39 violations) — CBO=66, 63 methods, 27 properties, `computeChangeSet` with cognitive complexity 107. This is THE most notorious class in Doctrine ORM. Correct.
2. **SqlWalker** (health 61.8, 39 violations) — CBO=115, 73 methods, WMC=397. Correctly identified as a coupling hotspot and complexity monster.
3. **ClassMetadata** (health 61.4, 30 violations) — 40 properties, CBO=96, god class 4/4 criteria. Correct — this is the "kitchen sink" of Doctrine metadata.
4. **EntityManager** (health 57.2, 13 violations) — LCOM=6, god class 4/4. Correctly identified as having too many responsibilities.
5. **BasicEntityPersister** (health 71.6, 48 violations) — WMC=286, many complex SQL-building methods. Correct.

The ordering is reasonable too — SqlWalker and UnitOfWork rightfully appear as the worst.

### Finding R5: Str class CBO=191 is surprising but correct
**Severity:** LOW
**Category:** ux-issue

`Illuminate\Support\Str` reports CBO=191, which seems absurdly high for a utility class with ~15 imports. The reason is that CBO is bidirectional — it counts classes that Str depends on AND classes that depend on Str. Since Str is used everywhere in Laravel, this is technically correct.

However, for a developer investigating refactoring targets, CBO=191 on a utility class is confusing. The tool doesn't distinguish between "this class is a coupling problem" (depends on too many things) and "this class is a coupling magnet" (too many things depend on it). The ClassRank metric partially addresses this, but it's not shown prominently for Str.

**Suggestion:** When CBO is high but afferent coupling dominates (many dependents, few dependencies), the violation message should say "191 classes depend on this (coupling magnet)" rather than "depends on too many classes."

### Finding R6: Health score 100% for Cohesion on Str is wrong
**Severity:** LOW
**Category:** misleading-metric

`Illuminate\Support\Str` gets Cohesion=100% (Strong). This is a 2136-line class with 101 static methods that share no state — it's essentially a dumping ground for string utilities. The 100% cohesion score is because all methods are static and share no fields, so TCC/LCC is not applicable (or reports 1.0 by convention for "all methods are independent"). From a design perspective, this class has ZERO cohesion — it's a grab-bag. But the metrics say otherwise.

This is a fundamental limitation of TCC/LCC for static utility classes. Not a tool bug, but worth noting for users.

### Finding R7: Drill-down workflow is intuitive and effective
**Severity:** N/A
**Category:** good-catch

The summary -> namespace -> class -> detail workflow works very well. Key observations:

1. The summary gives a correct high-level picture (Doctrine: strong typing but weak complexity/coupling; Laravel: critical typing, weak coupling)
2. Namespace drill-down correctly narrows focus
3. Class-level detail with `--detail` gives actionable method-level findings
4. The "Technical debt by rule" breakdown at class level is particularly useful for prioritization
5. Health score labels (Strong/Acceptable/Weak/Critical) are immediately meaningful

### Finding R8: Namespace score "direct" vs "roll-up" is confusing
**Severity:** MEDIUM
**Category:** ux-issue

When drilling into `Doctrine\ORM\Query`, the output shows:
```
Health [namespace: Doctrine\ORM\Query] 79.7% Strong (direct: 39.5%)
```

The "direct" score (39.5%) matches what appeared in the summary as the namespace health. But the rolled-up score (79.7% Strong) is much higher. This is confusing: the namespace was ranked as the worst (39.5) in the summary, but when I drill in, it says "Strong" at 79.7%.

The developer's mental model is: "I'm looking at the worst namespace at 39.5 — let me investigate." Then they see 79.7% Strong and think the tool contradicts itself. The explanation is that sub-namespaces (AST, Functions) are mostly fine, and only the direct classes are problematic. But this should be explained better in the output.

### Finding R9: No way to see "only structural issues" (exclude type coverage)
**Severity:** HIGH
**Category:** ux-issue

For Laravel, I want to focus on structural refactoring targets (complexity, cohesion, coupling) and ignore type coverage. There's no `--disable-rule=design.type-coverage` option that also adjusts the health score. Even if I disable the rule, the typing dimension in the health score might still pull down the overall score.

For a project like Laravel where adding type hints is impractical (BC breaks), the entire typing dimension is noise. I'd want `--disable-dimension=typing` or similar.

### Finding R10: Circular dependency of 177 classes is not actionable
**Severity:** LOW
**Category:** ux-issue

Doctrine shows:
```
Circular dependency (177 classes): SQLFilter -> EntityManagerInterface -> FilterCollection -> SQLFilter
```

A 177-class cycle starting from SQLFilter is technically true (Doctrine's EntityManager is a hub that connects everything). But reporting it as a single finding is not actionable — a developer can't "break" a 177-class cycle. Smaller, more targeted cycle reports (like the 2-class Orx -> Andx -> Orx) are much more useful.

The tool should perhaps cap cycle reporting or categorize cycles by size (small/medium/large) with different advice.

## Practical Value Assessment

### Doctrine ORM
- **Worst offenders accuracy: 5/5 matched real problems** — UnitOfWork, SqlWalker, ClassMetadata, EntityManager, BasicEntityPersister are all genuine refactoring targets
- **Missed known problems:** None significant. The tool found everything I'd expect
- **False alarms:** Minimal. Some AST node classes (ArithmeticExpression, ConditionalPrimary) flagged for low cohesion, but these are simple data objects — not really a problem. The class-count rule flagging 62 classes in `Query\AST` is noise (AST nodes are inherently numerous)

### Laravel Framework
- **Worst offenders accuracy: 6/8 matched real problems** — Query\Builder, Eloquent\Builder, Model, Application, Router, Container are all genuine god classes
- **Missed known problems:** None — every class I checked that I knew was problematic got flagged
- **False alarms:** MessageSelector (#2 worst class is a lookup table), ReplacesAttributes (65 violations for a uniform callback signature). Both are technically correct but misleading for prioritization. Type coverage violations dominate the output, making structural problems harder to find

## UX Notes

**What worked well:**
- The drill-down workflow is natural: summary gives the map, `--namespace` narrows, `--class --detail` gives specifics
- Health score labels (Strong/Acceptable/Weak/Critical) immediately communicate severity
- The "worst offenders" hints at the bottom of the output (telling me what to type next) are excellent
- Analysis speed is good: 1.7s for Doctrine (453 files), 5.8s for Laravel (1536 files)
- The sub-dimension hints in summary (e.g., "CBO (avg): 8.1 (target: below 7)") are very useful
- Tech debt estimates per class give a concrete prioritization metric

**What was inconvenient:**
- No way to filter out type-coverage violations from the ranking
- The "direct" vs "roll-up" namespace score is confusing on first encounter
- Long-parameter-list violations are too noisy when a class has many methods with the same signature
- For large classes like SqlWalker (39 violations), the `--detail` output is very long — would benefit from grouping by severity or category
- Can't combine `--namespace` and `--class` filters (e.g., "show me worst classes in Database namespace only")

## Guide Notes

**Workflow patterns that worked well:**
1. Start with summary to get the overall picture and identify dominant problem dimensions
2. Use "worst namespaces" to identify problem areas, then drill down with `--namespace=...`
3. From namespace view, pick the worst classes and use `--class=... --detail`
4. Read actual source code of flagged methods to verify — the tool gives line numbers which makes this fast
5. Use the "Technical debt by rule" breakdown to decide what TYPE of refactoring to do first

**Interpretation tips:**
- High CBO on a utility/helper class usually means many OTHER classes depend on it, not that it depends on too many things. This is not necessarily a problem
- Cyclomatic complexity on switch/match-heavy code (locale maps, parser dispatchers, SQL grammar builders) will be inflated. Check cognitive complexity for a more meaningful picture
- "God Class" detection is the most actionable finding — if all 4 criteria match (high WMC, high LCOM, low TCC, large size), refactoring is almost certainly warranted
- Type coverage violations are informative for new projects but may be noise for legacy frameworks with BC constraints
- LCOM4 > 5 strongly correlates with classes that have multiple unrelated responsibilities — these are the best refactoring candidates

**Common mistakes / what NOT to start with:**
- Don't start with the `--detail` flag on the whole project — it's overwhelming. Start with summary
- Don't treat violation COUNT as a priority metric — a class with 65 "long-parameter-list" warnings is less urgent than a class with 5 complexity errors
- Don't ignore the health score dimensions — a class scoring "Strong" on complexity but "Critical" on cohesion needs a different refactoring approach than one that's "Critical" on complexity
- For framework code, consider disabling type-coverage rules to focus on structural issues
