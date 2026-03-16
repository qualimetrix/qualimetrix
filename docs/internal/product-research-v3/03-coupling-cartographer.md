# 03 — Coupling Cartographer

**Persona:** Software architect analyzing coupling and dependencies
**Projects:** Symfony Console, Doctrine ORM, Laravel Framework
**Date:** 2026-03-16

## Executive Summary

AIMD's coupling analysis is **architecturally insightful** -- it correctly identifies the most tightly coupled classes in all three projects (Doctrine `UnitOfWork` CBO=66, Laravel `Collection` CBO=231, Symfony `Application` CBO=61), and the instability metric aligns well with actual dependency direction. However, the tooling has several friction points:

1. **Distance rule is silently broken for non-project code** (no namespace auto-detection for vendor paths) -- HIGH severity.
2. **Class-level instability creates excessive noise** (leaf classes flagged for being "unstable" when that's architecturally correct) -- MEDIUM severity.
3. **ClassRank thresholds are too permissive for large projects** -- only 4 violations in Laravel (1536 classes) vs 4 in Symfony (132 classes) -- MEDIUM severity.
4. **CBO for interfaces is misleading** -- bidirectional CBO counts afferent coupling for interfaces, making core interfaces appear "too coupled" when they're healthy -- MEDIUM severity.
5. **Circular dependency detection works well** and finds real architectural issues.

Overall coupling score ranking: Symfony (47.7%) > Doctrine (39.2%) > Laravel (26.1%). This makes architectural sense -- Laravel's sprawling cross-cutting design naturally has higher coupling than Symfony's focused component.

## Findings

### Finding CC1: Distance Rule Silently Produces Zero Results for Vendor Code
**Severity:** HIGH
**Category:** metric-gap

The distance from main sequence rule (`coupling.distance`) uses `ProjectNamespaceResolverInterface` to auto-detect project namespaces from `composer.json`. When analyzing vendor code (e.g., `benchmarks/vendor/doctrine/orm/src`), the resolver does not recognize `Doctrine\ORM` as a project namespace, so the rule silently skips all namespaces and reports zero violations.

**Evidence:** Running `--only-rule=coupling.distance` against all three projects produced "No violations found" for all of them. When explicitly passing `--rule-opt='coupling.distance:include_namespaces=Doctrine\ORM'`, the rule immediately found 18 violations including:
- `Doctrine\ORM\Decorator`: D=1.00 (maximum distance, this is a real problem)
- `Doctrine\ORM\Cache\Exception`: D=0.86
- `Doctrine\ORM\Query\AST`: D=0.85

The raw metrics JSON confirms distance values exist (computed by the collector), but the rule filters them out. No warning, no log entry, no indication that the rule is skipping everything.

**Recommendation:** When the distance rule analyzes 0 namespaces (all filtered out), emit a warning: "No project namespaces detected. Use --rule-opt='coupling.distance:include_namespaces=...' to specify." Alternatively, fall back to all namespaces when no composer.json PSR-4 mapping matches.

### Finding CC2: Class-Level Instability Creates Excessive False Positives
**Severity:** MEDIUM
**Category:** false-positive

The instability rule at class level (`coupling.instability.class`) flags leaf classes like `GithubActionReporter`, `NullOutput`, `ConsoleLogger`, `ErrorListener` for having instability=1.00. These are *supposed to be unstable* -- they're concrete implementations that depend on abstractions, with nobody depending on them.

In Robert Martin's original metric framework, instability is meaningful at the *package* level (where it interacts with abstractness to form the distance metric). At the class level, I=1.00 simply means "leaf node in the dependency graph," which is perfectly healthy for concrete classes.

**Evidence:**
- Symfony Console: 13 of 17 class-level instability violations are for classes with Ca=0 (nobody depends on them). These are leaf classes by design.
- Doctrine ORM: ~100+ instability violations, vast majority for leaf classes.
- The namespace-level instability violations are much more useful (e.g., `Symfony\Component\Console\Tester` I=1.00 at namespace level is a genuine insight).

**Recommendation:** Consider one of:
1. Remove class-level instability violations entirely (keep only namespace-level)
2. Only flag class-level instability when Ca > 0 (someone depends on it AND it's unstable)
3. Raise the class-level threshold significantly (e.g., 0.95 -> no class-level violation unless error threshold)

### Finding CC3: ClassRank Thresholds Don't Scale with Project Size
**Severity:** MEDIUM
**Category:** formula-issue

ClassRank uses PageRank (sum of ranks = 1.0 for the project). With default thresholds (warning: 0.02, error: 0.05), Symfony Console (132 classes) triggers 11 ClassRank violations, while Laravel (1536 classes) triggers only 4.

This is a mathematical artifact: in a larger graph, PageRank gets diluted across more nodes. A class in a 1500-class project needs to be dramatically more central than in a 130-class project to exceed the same threshold.

**Evidence:**
- Symfony Console top ClassRank: `ExceptionInterface` = 0.102, `InvalidArgumentException` = 0.061
- Doctrine ORM top ClassRank: `SqlWalker` = 0.071, `Node` = 0.058
- Laravel top ClassRank: `Collection` = 0.035, `Arr` = 0.024, `Macroable` = 0.023, `Str` = 0.023

Laravel's most central class (`Collection`, CBO=231) only gets ClassRank 0.035 because the rank is spread across 1536 classes.

**Recommendation:** Scale thresholds by project size, e.g., `threshold = base / sqrt(classCount)` or use percentile-based thresholds (e.g., flag top 1% of classes). Document the scaling behavior in the rule description.

### Finding CC4: CBO for Interfaces Is Misleading
**Severity:** MEDIUM
**Category:** false-positive

AIMD uses bidirectional CBO (Ca + Ce), which means interfaces like `OutputInterface` (Ca=44, Ce=1, CBO=45) are flagged with "depends on too many classes." The message is misleading -- the interface doesn't *depend on* 45 classes; 44 classes *depend on it*. High afferent coupling for a core interface is a sign of good architecture, not a problem.

**Evidence:**
- `Symfony\Component\Console\Output\OutputInterface`: CBO=45 (ERROR, threshold 20). Ca=44, Ce=1.
- `Symfony\Component\Console\Input\InputInterface`: CBO=27 (ERROR, threshold 20). Ca=26, Ce=1.
- `Symfony\Component\Console\Exception\ExceptionInterface`: Ca=9, Ce=1, CBO=10 (no violation, but similar pattern).

The violation message says "depends on too many classes" but the real metric is "is coupled to too many classes." For interfaces, the coupling direction matters enormously.

**Recommendation:** Differentiate the message for high-Ca vs high-Ce classes:
- High Ce: "depends on too many classes (Ce=X)" -- genuine code smell
- High Ca: "too many classes depend on this (Ca=X)" -- consider if this is a healthy abstraction point
- Or: suppress CBO violations for interfaces/abstract classes where Ce is below threshold

### Finding CC5: Circular Dependency Detection Is Accurate and Useful
**Severity:** N/A
**Category:** good-catch

The circular dependency detection correctly identifies real architectural issues:

**Symfony Console (5 cycles):**
- `HelperSet <-> HelperInterface`: real bidirectional dependency (HelperInterface defines `setHelperSet`)
- `SymfonyQuestionHelper <-> SymfonyStyle`: real coupling (SymfonyStyle uses QuestionHelper, QuestionHelper accepts SymfonyStyle)
- Large 24-class cycle through `Command -> Application`: expected in a CLI framework

**Doctrine ORM (4 cycles):**
- `SQLFilter <-> EntityManagerInterface -> FilterCollection -> SQLFilter` (177 classes in cycle): this is the well-known Doctrine EM coupling
- `Orx <-> Andx`: real mutual dependency in query expression classes

**Laravel (17 cycles):**
- `View <-> Factory`: classic Laravel bidirectional coupling
- `DatabaseJob <-> DatabaseQueue`: job-queue circular dependency
- `Response <-> AuthorizationException`: response knows about auth exceptions
- 328-class cycle through `ResourceCollection` ecosystem: extensive cross-cutting

The cycle sizes (2-328 classes) and the classes involved match known architectural characteristics of these frameworks.

### Finding CC6: Instability and Abstractness at Namespace Level Are Architecturally Accurate
**Severity:** N/A
**Category:** good-catch

The namespace-level instability metrics align perfectly with architectural intent:

**Symfony Console (selected namespaces):**
| Namespace | I    | A    | D    | Interpretation                                                                            |
| --------- | ---- | ---- | ---- | ----------------------------------------------------------------------------------------- |
| Exception | 0.13 | 0.11 | 0.76 | Stable, low abstractness -- zone of pain (correct: exception classes are concrete+stable) |
| Input     | 0.19 | 0.40 | 0.41 | Stable, moderate abstractness -- near main sequence                                       |
| Output    | 0.13 | 0.30 | 0.58 | Very stable, some abstractness                                                            |
| Tester    | 1.00 | 0.00 | 0.00 | Maximally unstable -- correct for test utilities                                          |
| Helper    | 0.79 | 0.13 | 0.09 | Very unstable, low abstractness -- near main sequence                                     |
| Formatter | 0.20 | 0.38 | 0.43 | Stable, moderate abstractness                                                             |

The `Exception` namespace sits in the "zone of pain" (D=0.76) -- stable but not abstract. This is a known architectural pattern for exception hierarchies. The distance rule would flag this if it worked (see CC1).

### Finding CC7: Coupling Health Score Uses Only CBO Average
**Severity:** LOW
**Category:** metric-gap

The coupling health score decomposition shows only `CBO (avg)` as the single component. Instability, distance, and ClassRank don't contribute to the health score despite being computed. This means a project with terrible instability distribution or many circular dependencies can still get an acceptable coupling health score if average CBO is reasonable.

**Evidence:** Laravel coupling health score is 26.1% ("Weak") -- driven entirely by CBO avg=7.6. The 17 circular dependencies and 486 instability violations don't factor in.

### Finding CC8: No Way to See Which Classes a High-CBO Class Depends On
**Severity:** LOW
**Category:** missing-insight

When AIMD reports "CBO: 66 (threshold: 20) -- depends on too many classes" for `UnitOfWork`, there's no way to see *which* 66 classes it depends on. The metrics JSON shows Ca/Ce counts but not the actual dependency list. For a user trying to reduce coupling, knowing the dependency list is essential.

**Recommendation:** Add a `--format-opt=show-dependencies` or similar option that lists afferent/efferent dependencies for classes exceeding CBO thresholds. Alternatively, expose this in the HTML report.

## Coupling Analysis by Project

### Symfony Console
- **Coupling health:** 47.7% (Weak)
- **Top CBO classes:** Application (61), Command (49), OutputInterface (45), InvalidArgumentException (29), InputInterface (27), SymfonyStyle (24)
- **Circular deps:** 5 cycles (2 small bidirectional, 1 large framework cycle)
- **Instability:** Exception namespace (I=0.13) and Input namespace (I=0.19) are correctly identified as stable foundations. Tester namespace (I=1.00) correctly identified as maximally unstable.
- **ClassRank top:** ExceptionInterface (0.102), InvalidArgumentException (0.061), OutputFormatterStyleInterface (0.055), OutputFormatterInterface (0.053) -- all are legitimate central abstractions.
- **Assessment:** CBO and ClassRank correctly identify the architectural center (I/O interfaces, Command base class). Instability distribution is healthy -- stable abstractions at the bottom, unstable concrete classes at the top.

### Doctrine ORM
- **Coupling health:** 39.2% (Weak)
- **Top CBO classes:** SqlWalker (115), Parser (103), ClassMetadata (96), AST\Node (82), UnitOfWork (66), EntityManagerInterface (64)
- **Circular deps:** 4 cycles including the massive 177-class SQLFilter cycle through EntityManagerInterface
- **Instability:** Mapping namespace (I=0.48) is semi-stable (correct: it's a core data structure). Tools namespace (I=0.92) is highly unstable (correct: CLI tools depend on everything).
- **ClassRank top:** SqlWalker (0.071), Node (0.058) -- both are central to the DQL query engine, correctly identified.
- **Distance (with explicit include):** Decorator namespace D=1.00 (Ca=0, Ce=20 -- pure consumer, 100% abstract -- both extremes), Cache\Exception D=0.86.
- **Assessment:** The known tight coupling (UnitOfWork-EntityManager-Persister triangle) is well-detected. UnitOfWork is correctly identified as a god class with CBO=66, 63 methods, WMC=478. The EntityManager is also flagged (LCOM=6, CBO=36).

### Laravel Framework
- **Coupling health:** 26.1% (Weak)
- **Top CBO classes:** Collection (231), Str (191), Arr (186), Application (114), ArtisanServiceProvider (110), Command (99), Model (99), Container (80)
- **Circular deps:** 17 cycles including View-Factory, DatabaseJob-DatabaseQueue, Response-AuthorizationException, and a massive 328-class resource collection cycle.
- **Instability:** Most namespaces are highly unstable due to Laravel's concrete-heavy, facade-driven architecture.
- **ClassRank top:** Collection (0.035), Arr (0.024), Macroable (0.023), Str (0.023) -- only 4 violations due to threshold dilution (CC3).
- **Assessment:** The CBO numbers reveal Laravel's utility-class-heavy design. `Collection` with CBO=231 means virtually every Laravel class touches Collection. The lack of ClassRank violations despite massive CBO values exposes the threshold scaling issue.

## ClassRank Assessment

ClassRank (PageRank applied to the class dependency graph) is **conceptually useful but practically limited** in its current form:

**Strengths:**
- In Symfony Console, it correctly identifies core interfaces (ExceptionInterface, OutputFormatterInterface) as the most "important" classes -- these are indeed the architectural foundation.
- In Doctrine, it identifies SqlWalker and AST\Node as central -- correct for the DQL engine.

**Weaknesses:**
- Threshold doesn't scale with project size (CC3), making it almost useless for large projects like Laravel.
- The metric doesn't distinguish between "important abstraction" (good centrality) and "god object" (bad centrality). Both get flagged the same way.
- Low absolute values (0.01-0.10 range) make the metric hard to interpret without context.

**Verdict:** ClassRank adds value as a *discovery tool* ("what are the most central classes?") but the violation threshold needs work. Consider renaming the violation message from "coupling hotspot" to something more neutral like "high architectural centrality" and adding context about whether the class is abstract (healthy) or concrete (concerning).

## UX Notes

1. **Distance rule silent failure is the biggest UX problem.** When analyzing non-project code, users get zero distance violations with no warning. This should be fixed with a diagnostic message.

2. **Instability noise at class level.** 45 instability violations in Symfony Console, most for leaf classes. Users will learn to ignore instability violations, which defeats the purpose. Namespace-level instability is much more actionable.

3. **The `--namespace` drill-down works well** for coupling analysis. Being able to see per-namespace coupling scores and then drill into specific namespaces is a good workflow.

4. **The `--class` drill-down for coupling is excellent.** Seeing UnitOfWork's full violation profile (CBO, god class, WMC, method complexity) in one view gives a complete picture.

5. **CBO violation message "depends on too many classes" is misleading for bidirectional CBO.** Consider: "coupled to too many classes (Ca=X afferent, Ce=Y efferent)" to disambiguate.

6. **Tech debt by rule breakdown is useful** for prioritization. Seeing "coupling.instability ~8d 5h 30min (139 violations)" immediately tells you that instability is the largest coupling concern.

## Guide Notes

### Recommended Workflow for Coupling Architecture Review

1. **Start with the summary view** (`--only-rule=coupling`) to see the coupling health score and worst namespaces.

2. **Check circular dependencies** (`--circular-deps --only-rule=architecture.circular-dependency`) separately. These are the highest-priority coupling issues.

3. **Drill into worst namespaces** (`--namespace=...`) to understand coupling at the package level. Look at instability (I), abstractness (A), and distance (D) to assess package design.

4. **For distance analysis of non-project code**, always pass `--rule-opt='coupling.distance:include_namespaces=...'` explicitly.

5. **Focus on CBO errors** (threshold 20) over CBO warnings (threshold 14). CBO errors indicate classes that likely need decomposition.

6. **Ignore class-level instability violations for leaf classes** (Ca=0). These are architecturally normal. Focus on namespace-level instability instead.

7. **Use ClassRank as a discovery tool**, not a strict violation. The highest-ranked classes are the architectural center -- they may be healthy abstractions or problematic god objects. Cross-reference with CBO and LCOM to distinguish.

### Interpretation Pitfalls

- **High CBO on interfaces != bad design.** An interface with CBO=45 mostly from afferent coupling (Ca=44) is a successful abstraction point. Only high efferent coupling (Ce) on interfaces is concerning.
- **I=1.00 at class level != bad design.** Leaf concrete classes should be unstable. It's the *stable concrete class* (I=0.0, A=0.0) that's in the "zone of pain."
- **Distance requires namespace configuration** for vendor code analysis. Without it, you'll get zero violations.
- **ClassRank values are project-size-dependent.** Don't compare ClassRank values across projects of different sizes.
