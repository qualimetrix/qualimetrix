# 04 — Cohesion Detective

**Persona:** Tech lead evaluating class design via cohesion metrics
**Projects:** PHP-Parser, Doctrine DBAL, Guzzle
**Date:** 2026-03-16

## Executive Summary

AIMD's cohesion analysis is practically useful but has several areas for improvement. God class detection is the strongest feature -- ~75% true positive rate across projects, with particularly accurate results on Doctrine DBAL's Platform classes. Data class detection has a high false-positive rate (~60%) due to flagging interfaces, abstract classes, and intentional service classes with simple methods. LCOM4 and TCC metrics show a paradoxical disagreement for certain class patterns (e.g., PrettyPrinter\Standard: TCC=1.00 but LCOM4=116), which undermines trust in the metrics. WMC analysis is accurate but needs better context in its messaging.

**Key numbers:**
- God class: 29 detections, ~22 true positives (~75% precision)
- Data class: 33 detections, ~13 useful warnings (~40% precision)
- LCOM: 45 violations, ~30 pointing to real issues (~67% precision)
- WMC: 43 violations, ~38 accurate (~88% precision)

## Findings

### Finding CD1: Data class rule flags interfaces and abstract classes
**Severity:** HIGH
**Category:** false-positive

The data class rule flags `NodeVisitor` (an interface with 4 abstract methods), `NodeVisitorAbstract` (abstract stub class), `NodeTraverserInterface`, `Driver\Connection` (interface), `Driver\Result` (interface), `SQL\Parser\Visitor` (interface), and `ClientInterface`. These are pure interface contracts -- they *should* have high public surface and low complexity. That is their entire purpose.

**Evidence:** `PhpParser\NodeVisitor` is an interface with 4 method signatures and no implementation at all. `Doctrine\DBAL\Driver\Connection` and `Driver\Result` are likewise pure interfaces.

**Recommendation:** Exclude interfaces and abstract classes from data class detection by default, or add an `excludeInterfaces` option (default: true). This alone would eliminate ~8 of the 33 detections (~25% of false positives).

### Finding CD2: TCC and LCOM4 paradox on class hierarchies
**Severity:** HIGH
**Category:** metric-gap

`PhpParser\PrettyPrinter\Standard` has TCC=1.00 and LCC=1.00, yet LCOM4=116 and was flagged as a god class. This is paradoxical: if all methods are tightly connected (TCC=1.00), LCOM4 should be 1. The explanation is that TCC measures *direct* property-access connections while LCOM4 counts connected components in the method-field graph. In this class, 186 protected methods all call `$this->p()` or `$this->pStmts()` (inherited from parent), which creates a shared connection through parent-class fields. TCC sees them as connected (they share the same fields via inherited methods), but LCOM4 may count them differently.

However, the god class violation output says LCOM4=116 while the metrics JSON key is `lcom=116`. If TCC=1.00 (all methods connected), the class should not be flagged by the TCC criterion but is still flagged because WMC (334), LCOM (116), and LOC (1217) all exceed thresholds. This is a 3/4 criteria match but arguably incorrect -- a class with TCC=1.00 is cohesive by definition.

**Recommendation:** Consider adding TCC as a "veto" in god class detection: if TCC >= 0.5, skip the LCOM criterion or require 4/4 criteria. A class that is measurably cohesive by TCC should not be called a god class on the LCOM axis alone.

### Finding CD3: BuilderFactory god class detection is a false positive
**Severity:** MEDIUM
**Category:** false-positive

`PhpParser\BuilderFactory` is flagged as a god class (LCOM=23, TCC=0.00, LOC=363). Looking at the source, it is a pure factory with 28 public methods that create builders. Each method is a one-liner returning `new Builder\*()`. The class has zero properties and almost no shared state. LCOM4=23 is correct (23 disconnected methods), but this is a well-designed factory pattern, not a god class. The methods are intentionally independent -- they do not *need* to share state.

The god class detection correctly identifies the *symptoms* (low cohesion, many methods, large size) but misdiagnoses the *intent*. Factory classes are a known pattern where low cohesion is acceptable.

**Recommendation:** Document this pattern in user-facing docs. Consider adding a `excludeFactories` heuristic (e.g., skip classes where >80% of methods are factory methods returning different types with no shared fields).

### Finding CD4: Doctrine Platform classes are correctly identified
**Severity:** LOW
**Category:** useful-detection

All 7 Doctrine DBAL platform classes (AbstractPlatform, MySQLPlatform, PostgreSQLPlatform, etc.) are flagged as god classes with 4/4 criteria. These are genuine god classes: 800-2400 LOC, WMC 90-318, LCOM 40-77, TCC=0.00. Each class generates SQL for dozens of unrelated concerns (types, indexes, schemas, expressions, DDL). The Doctrine team themselves have acknowledged this as technical debt. This is AIMD's strongest result.

### Finding CD5: Data class flagging of well-designed service classes
**Severity:** MEDIUM
**Category:** false-positive

`PhpParser\NodeFinder` is flagged as a data class (WOC=100%, WMC=8). It has 4 public methods: `find()`, `findInstanceOf()`, `findFirst()`, `findFirstInstanceOf()`. This is a focused service class with a clear single responsibility. The rule interprets "all methods are public and simple" as "data class", but this is just good API design. Similarly, `PhpParser\ParserFactory` (5 methods, WMC=5) is a small factory -- not a data class.

**Recommendation:** Raise the `wmcThreshold` default from 10 to 15, or require a minimum number of getter/setter methods to trigger. A class with 4 methods and no properties is not a data class.

### Finding CD6: Column god class detection is borderline correct
**Severity:** LOW
**Category:** threshold-issue

`Doctrine\DBAL\Schema\Column` is flagged as a god class (3/4 criteria: WMC=53, TCC=0.18, LOC=390). Looking at the code, it has 12 properties with getter/setter pairs, plus `toArray()`, `edit()`, and `setOptions()`. This is essentially a value object with builder-style setters. WMC=53 barely exceeds the 47 threshold -- most of it comes from simple getter/setter pairs each contributing CCN=1. This is borderline: the class *is* large but not genuinely complex.

The root cause is that WMC counts all methods equally. A class with 30 trivial getters/setters (each CCN=1) gets WMC=30, which is the same as a class with 10 methods averaging CCN=3. The god class rule could benefit from considering *average* method complexity alongside total WMC.

### Finding CD7: Guzzle StreamHandler correctly identified
**Severity:** LOW
**Category:** useful-detection

`GuzzleHttp\Handler\StreamHandler` is flagged as a god class (WMC=109, LCOM=8, LOC=612). This is accurate: the class handles HTTP streaming, SSL verification, proxy configuration, debugging, progress tracking, content decoding, DNS resolution, and timeout management -- 8 distinct responsibilities matching LCOM4=8. This class would genuinely benefit from being split.

### Finding CD8: 163 of 260 PHP-Parser classes have TCC=0.00
**Severity:** MEDIUM
**Category:** threshold-issue

In PHP-Parser, 63% of classes have TCC=0.00. This is because PHP-Parser's AST node classes have no properties that methods share -- each `getSubNodeNames()` method returns a static array, and `getType()` returns a static string. These classes have 2-3 methods that each access different (or no) properties. TCC=0.00 is technically correct but not actionable: these are tiny value objects (3-10 lines of real code) where cohesion metrics are meaningless.

The cohesion health score (48.8% "Weak" for PHP-Parser) is dragged down by hundreds of small node classes that should not be evaluated for cohesion at all.

**Recommendation:** Add a minimum method count threshold for TCC/LCC reporting (e.g., skip classes with <4 non-constructor methods). This would prevent tiny classes from polluting the cohesion score.

### Finding CD9: WMC violation message lacks context
**Severity:** LOW
**Category:** ux-issue

WMC violations say "total method complexity is high" but do not indicate *why* it matters. For `Guzzle\Cookie\SetCookie` (WMC=93), the message does not differentiate between "many complex methods" and "many simple methods". Adding average CCN per method to the message (e.g., "WMC: 93 across 25 methods, avg CCN 3.7") would help users decide whether to split the class or simplify individual methods.

### Finding CD10: Data class rule should exclude classes with zero properties
**Severity:** MEDIUM
**Category:** false-positive

Several data class detections target classes with no properties at all (e.g., `PhpParser\NodeFinder`, `PhpParser\ParserFactory`, various token emulators). A "data class" by definition holds data. A class with only methods and no fields is a service, not a data class. The WOC metric (Weight of a Class = % of public methods) measures public surface, but without properties, a high WOC just means "all methods are public".

**Recommendation:** Skip data class detection for classes with zero properties (excluding constants).

## God Class Analysis

### PHP-Parser (4 detections)
| Class                  | Criteria             | True Positive? | Notes                                                                                    |
| ---------------------- | -------------------- | -------------- | ---------------------------------------------------------------------------------------- |
| BuilderFactory         | 3/4 (LCOM, TCC, LOC) | **No**         | Factory pattern, intentionally low cohesion                                              |
| ParserAbstract         | 3/4 (WMC, LCOM, LOC) | **Yes**        | Parser state machine, genuinely complex                                                  |
| PrettyPrinter\Standard | 3/4 (WMC, LCOM, LOC) | **Borderline** | 188 methods but all do the same thing (print nodes). High LCOM misleading given TCC=1.00 |
| PrettyPrinterAbstract  | 4/4                  | **Yes**        | Multiple responsibilities: printing, formatting, diffing                                 |

**True positive rate: ~50-75%** (2 definite, 1 borderline, 1 false positive)

**Notable misses:** None obvious. PHP-Parser is well-designed with focused classes.

### Doctrine DBAL (23 detections)
| Category                                          | Count | True Positive?                                                                |
| ------------------------------------------------- | ----- | ----------------------------------------------------------------------------- |
| Platform classes (Abstract + 6 drivers)           | 7     | **All true** -- genuine god classes                                           |
| SchemaManager classes (Abstract + 6 drivers)      | 8     | **Mostly true** -- each mixes DDL parsing, schema introspection, type mapping |
| Schema model classes (Column, Table, Index, etc.) | 6     | **Borderline** -- large value objects, not really "god classes"               |
| Other (Connection, QueryBuilder)                  | 2     | **Both true** -- Connection has transaction, query, caching concerns          |

**True positive rate: ~75-80%** (17-19 true of 23)

### Guzzle (2 detections)
| Class | Criteria | True Positive? |
|---|---|---|
| Client | 3/4 (WMC, TCC, LOC) | **Borderline** | Large but focused HTTP client |
| StreamHandler | 3/4 (WMC, LCOM, LOC) | **Yes** | Genuinely handles too many concerns |

**True positive rate: ~50-75%**

## Data Class Analysis

### PHP-Parser (20 detections)
- **~5 useful:** Builder classes (ClassConst, FunctionLike) that could expose more behavior
- **~7 false positives on interfaces/abstracts:** NodeVisitor, NodeVisitorAbstract, NodeTraverserInterface, etc.
- **~8 false positives on small services:** NodeFinder, ParserFactory, FindingVisitor, token emulators

**Usefulness: ~25%.** Most detections point to either interface contracts or tiny focused classes.

### Doctrine DBAL (11 detections)
- **~4 useful:** Statement, FetchUtils, ObjectSet could encapsulate more logic
- **~4 false positives on interfaces:** Driver\Connection, Driver\Result, SQL\Parser\Visitor
- **~3 borderline:** CompositeExpression, ConvertParameters -- these are algorithms, not data classes

**Usefulness: ~36%.**

### Guzzle (2 detections)
- Both are interfaces (ClientInterface, CookieJarInterface). **0% useful.**

### Modern PHP and readonly classes
The `excludeReadonly` option helps for PHP 8.2+ DTOs but does not address the larger issue: in modern PHP, data classes are often *intentional* (value objects, DTOs, events). The message "Consider encapsulating behavior or using a DTO pattern" is contradictory -- if it IS a DTO, the tool should not flag it. The `excludePromotedOnly` option helps but many legitimate value objects use traditional property declarations.

## Cohesion Metrics (TCC/LCC/LCOM4)

### Do the numbers correlate with actual design quality?

**TCC/LCC -- Mixed results:**
- TCC=0.00 on 63% of PHP-Parser classes is technically correct but not useful. These are tiny node classes.
- TCC=1.00 on `PrettyPrinter\Standard` (186 methods) contradicts LCOM4=116. The disagreement is confusing.
- TCC=0.00 on all Doctrine Platform classes correctly signals low cohesion.
- TCC works best on medium-sized classes (10-50 methods) with some properties.

**LCOM4 -- Generally accurate:**
- LCOM4=8 on StreamHandler correctly identifies 8 responsibility groups.
- LCOM4=23 on BuilderFactory correctly identifies 23 independent methods (but this is intentional).
- LCOM4=116 on PrettyPrinter\Standard is technically correct but misleading -- 116 is not "116 responsibilities", it is 116 independent print methods that happen to not share fields directly.
- LCOM4 works best when values are 2-10. Values >20 often indicate a structural pattern (factory, visitor, formatter) rather than a design problem.

**Practical guidance:**
- LCOM4 values of 2-5 usually indicate a class that could be split into 2-5 focused classes.
- LCOM4 > 10 usually indicates a pattern class (factory, formatter, handler) where high LCOM is acceptable.
- TCC < 0.33 on classes with 5+ properties is a strong signal of poor cohesion.
- TCC = 0.00 on classes with 0-1 properties is meaningless noise.

## WMC Analysis

WMC detection is the most accurate of the cohesion-related rules (~88% true positive rate).

| Project       | Detections | True Positives | Notes                                                           |
| ------------- | ---------- | -------------- | --------------------------------------------------------------- |
| PHP-Parser    | 8          | 7              | Only PrettyPrinter\Standard is borderline (many simple methods) |
| Doctrine DBAL | 29         | 26             | 3 borderline (Column, Schema, TableEditor -- value objects)     |
| Guzzle        | 6          | 5              | Utils is borderline (utility class)                             |

WMC correctly identifies complex classes, but the threshold (50 for warning, 80 for error) does not distinguish between "many simple methods" and "fewer complex methods". A class with 50 methods of CCN=1 (WMC=50) is very different from a class with 10 methods averaging CCN=5 (WMC=50).

## UX Notes

1. **The god class message is excellent.** Showing "3/4 criteria: high WMC (74 >= 47), low TCC (0.00 < 0.33), large size (467 >= 300 LOC)" gives the user all the context needed to evaluate the detection.

2. **The data class message is misleading.** "Consider encapsulating behavior or using a DTO pattern" gives contradictory advice. If a class IS a DTO, what should the user do? The message should say "If this is intentional, suppress with @aimd-ignore or use excludeReadonly/excludePromotedOnly options."

3. **LCOM violation could suggest next steps.** "LCOM4: 8 -- class has 8 unrelated method groups" could add: "Consider splitting into separate classes, one per responsibility group."

4. **The cohesion health score is too easily dragged down** by small classes with TCC=0.00. PHP-Parser shows 48.8% "Weak" cohesion, but the codebase is actually well-designed. The score reflects the 163 tiny node classes with TCC=0, not real design problems.

## Guide Notes

### How to use cohesion metrics for class design review

**Start with god class detection** (`--only-rule=code-smell.god-class`). This is the most actionable rule. For each detection:
- Check if it is a known pattern (factory, formatter, visitor) where low cohesion is acceptable.
- If TCC >= 0.5, the class may be flagged incorrectly. Focus on WMC and LOC instead.
- If LCOM4 > 10, ask: "Are the method groups truly unrelated, or do they share a theme?"
- 4/4 criteria matches are almost always genuine god classes. 3/4 matches need manual review.

**Use LCOM4 for splitting decisions.** A class with LCOM4=3 can likely be split into 3 focused classes. Examine which methods share which fields to determine the natural split points. Use `--format=html` to visualize the class hierarchy.

**Ignore TCC on small classes.** TCC is meaningful only for classes with 4+ non-constructor methods and 2+ properties. On tiny value objects (AST nodes, events, DTOs), TCC=0 is expected and not a problem.

**Data class detection is best used selectively.** Disable it for codebases heavy on interfaces and DTOs, or use `excludeReadonly: true` and `excludePromotedOnly: true` in configuration. Focus on classes that are detected as data classes but have 10+ properties -- those are the ones most likely to benefit from behavior encapsulation.

**WMC is most useful as a triage metric.** Sort classes by WMC descending and focus on the top 10. For each, check if high WMC comes from many simple methods (acceptable) or a few very complex methods (needs refactoring).
