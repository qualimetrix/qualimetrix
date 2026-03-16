# Investigating Architecture with AIMD

This guide walks you through practical workflows for analyzing PHP project architecture using AI Mess Detector. It is based on real-world analysis of projects like Doctrine ORM, Laravel, Symfony Console, Composer, PHP-Parser, Guzzle, Monolog, and Flysystem.

---

## The Drill-Down Workflow

The most effective way to investigate a codebase is the **summary-to-detail drill-down**. Think of it like a map: start with the continent, then zoom into countries, then cities.

### Step 1: Get the Big Picture

```bash
bin/aimd check src/
```

The summary view shows overall health scores across five dimensions (complexity, cohesion, coupling, typing, maintainability), the worst namespaces, and the worst classes. This tells you where to look first.

!!! tip
    Health score labels (Strong / Good / Acceptable / Weak / Critical) communicate severity at a glance. But compare the **numeric scores**, not just labels -- six projects scoring "Acceptable" might range from 45 to 68.

### Step 2: Drill Into Problem Namespaces

```bash
bin/aimd check src/ --namespace=App\\Domain\\Order
```

The namespace view shows per-namespace health, child namespaces, and the worst classes within that scope. Look for namespaces with health below 50 (Weak or Critical).

!!! note
    You may see two scores: a **rolled-up** score (including sub-namespaces) and a **direct** score (only classes directly in that namespace). A namespace with rolled-up 79.7% but direct 39.5% means the direct classes are problematic, while sub-namespaces are fine.

### Step 3: Investigate Specific Classes

```bash
bin/aimd check src/ --class=App\\Domain\\Order\\OrderService --detail
```

The `--detail` flag shows individual method-level violations. Look at the "Technical debt by rule" breakdown to decide what **type** of refactoring to prioritize first.

### Step 4: Verify with Source Code

AIMD gives line numbers for every violation. Open the flagged methods and read the actual code. A method with cognitive complexity 107 at line 342 is something you can jump to immediately.

---

## Analyzing Coupling and Dependencies

Coupling analysis reveals how tightly your classes and namespaces are interconnected.

### Recommended Workflow

1. **Start with the coupling dimension:**

    ```bash
    bin/aimd check src/ --only-rule=coupling
    ```

2. **Check circular dependencies separately** -- these are the highest-priority coupling issues:

    ```bash
    bin/aimd check src/ --only-rule=architecture.circular-dependency
    ```

3. **Drill into the worst namespaces** and look at instability (I), abstractness (A), and distance from the main sequence (D):

    ```bash
    bin/aimd check src/ --namespace=App\\Infrastructure
    ```

4. **Focus on CBO errors** (threshold 20) over warnings (threshold 14). CBO errors indicate classes that likely need decomposition.

### Interpreting Coupling Metrics

**CBO (Coupling Between Objects)** counts all classes coupled to a given class -- both those it depends on (efferent, Ce) and those that depend on it (afferent, Ca).

!!! warning
    High CBO on an interface does not mean bad design. An interface with CBO=45 where Ca=44 (many dependents) and Ce=1 is a **successful abstraction point**. Only high efferent coupling (Ce) on interfaces is concerning.

**Instability** at the class level is often noise. A leaf concrete class with I=1.00 (nobody depends on it) is architecturally correct. Focus on **namespace-level instability** instead -- that is where the metric becomes actionable.

**ClassRank** (PageRank applied to the dependency graph) is useful as a **discovery tool**, not a strict violation. The highest-ranked classes are your architectural center. Cross-reference with CBO and LCOM to determine whether high centrality is healthy (core abstraction) or problematic (god object).

!!! note
    ClassRank values are project-size-dependent. In a 1500-class project, even the most central class may have a low absolute rank (0.03) compared to a 130-class project (0.10). Do not compare ClassRank across projects of different sizes.

**Distance from main sequence** requires namespace configuration when analyzing vendor/third-party code. Without it, you may get zero results:

```bash
bin/aimd check vendor/doctrine/orm/src/ \
  --rule-opt='coupling.distance:include_namespaces=Doctrine\ORM'
```

### Circular Dependencies

Small cycles (2-3 classes) are the most actionable. A cycle like `HelperSet <-> HelperInterface` points to a specific bidirectional dependency you can break. Large cycles (100+ classes) typically flow through a central hub (like an EntityManager) and require architectural refactoring rather than a quick fix.

---

## Analyzing Complexity

Complexity metrics help you find methods that are hard to understand, hard to test, or both.

### Which Metric to Use When

**Cognitive Complexity** is the best single metric for "is this method hard to understand?" It penalizes nesting depth, which correlates strongly with comprehension difficulty. Near-zero false positives.

- Focus on cognitive > 15 for methods you should review carefully
- Cognitive > 30 almost always needs refactoring
- Cognitive > 100 is a god-method that **must** be split

**NPath Complexity** is the best signal for "is this method hard to test?" It counts the number of unique execution paths.

- NPath > 200 means you should check test coverage
- NPath > 1000 almost certainly means insufficient test coverage
- NPath-only violations (no cognitive flag) signal "hard to test exhaustively" rather than "hard to read"

**Cyclomatic Complexity (CCN)** estimates the minimum number of tests needed for full branch coverage. Watch for the switch/match pattern: if CCN is high but cognitive is low, the method is probably a lookup table or type dispatcher -- not a real complexity problem.

### Triage Strategy

```bash
bin/aimd check src/ --only-rule=complexity --detail
```

1. Focus first on **ERROR-level cognitive complexity violations** (> 30)
2. For WARNING-level violations (15-30), check if CCN is also flagged:
    - Both flagged: probably a method worth investigating
    - Only cognitive flagged: nesting issue, worth a look
    - Only CCN flagged: likely a switch/match, lower priority
3. NPath-only violations: check test coverage rather than spending time on code review

### Reading the Divergence Between Metrics

The relationship between metrics tells a story:

| Pattern                                | What It Means                                                   |
| -------------------------------------- | --------------------------------------------------------------- |
| High CCN + High Cognitive + High NPath | Genuinely complex. Investigate immediately.                     |
| High CCN + Low/No Cognitive            | Mechanical branching (switch/match). Low priority for review.   |
| High NPath + Low CCN + Low Cognitive   | Independent conditions. Testing concern, not comprehension.     |
| High Cognitive + Low CCN               | Nesting-driven complexity. Hard to follow despite few branches. |

!!! tip
    A `match` expression with 20 arms contributes CCN=20 but cognitive=0-1. These inflate CCN without corresponding comprehension difficulty. If you see CCN > 20 with no cognitive violation, it is almost certainly a switch/match -- skip it for review purposes.

---

## Analyzing Cohesion and Class Design

Cohesion metrics help you find classes that try to do too much and should be split.

### Start with God Class Detection

```bash
bin/aimd check src/ --only-rule=code-smell.god-class
```

God class detection is the most actionable cohesion-related rule. For each finding:

- **4/4 criteria match** (high WMC, high LCOM, low TCC, large size) is almost always a genuine god class
- **3/4 criteria match** needs manual review -- check if the class follows a known pattern (factory, visitor, formatter) where low cohesion is acceptable
- If **TCC >= 0.5**, the class is measurably cohesive and may be flagged incorrectly on the LCOM axis

### Using LCOM4 for Splitting Decisions

LCOM4 counts the number of connected components in the method-field graph. A class with LCOM4=3 can likely be split into 3 focused classes.

- **LCOM4 of 2-5**: the class probably has that many distinct responsibilities. Examine which methods share which fields to find natural split points
- **LCOM4 > 10**: usually indicates a pattern class (factory, formatter, handler) where high LCOM is acceptable by design

### When to Ignore TCC

TCC (Tight Class Cohesion) measures what proportion of method pairs access the same properties. It is meaningful only for classes with 4+ non-constructor methods and 2+ properties. On tiny value objects, AST nodes, events, or DTOs, TCC=0 is expected and not a problem.

!!! note
    Static utility classes (like `Str` or `Arr`) are skipped from TCC measurement (all methods are static, so there are no instance method pairs to compare). The health formula assigns a neutral value of 0.5 to these classes.

### Data Class Detection

The data class rule has a higher false-positive rate than god class detection, especially on:

- Interfaces (100% WOC by definition)
- Exception classes (simple by design)
- Small service classes with clean APIs

Use `excludeReadonly: true` and `excludePromotedOnly: true` for codebases with PHP 8.2+ DTOs. For exception classes and interfaces, suppress with `@aimd-ignore code-smell.data-class`.

---

## Interpreting Code Smells

Not all code smell findings are equally urgent. Here is a prioritization guide based on signal-to-noise ratio.

### Act Immediately (High Signal)

- **`eval`** -- always investigate. Even legitimate uses should be documented
- **`goto`** -- rare and usually refactorable
- **`count-in-loop`** -- easy fix, real performance impact
- **`constructor-overinjection`** -- signals design problems
- **`sensitive-parameter`** -- easy, high-value security improvement

### Configure for Your Context

- **`long-parameter-list`** -- default 4/6 is reasonable; consider 5/8 for legacy code
- **`god-class`** -- review WARNING-level (3/4 criteria) findings quarterly
- **`boolean-argument`** -- expect ~50% false positives in framework code; significantly lower in application code

### Suppress or Disable Selectively

- **`debug-code`** -- suppress in files that intentionally provide dump/debug API
- **`data-class`** -- suppress for exception classes and DTOs
- **`identical-subexpression`** -- exclude generated files via `--exclude` or baseline
- **`empty-catch`** -- suppress for chain-of-responsibility patterns; always add a comment explaining why

### Monitor but Do Not Block CI

- **`superglobals`** -- informational in framework code, actionable in application code
- **`error-suppression`** -- often necessary for file operations; review case-by-case
- **`exit`** -- legitimate in CLI entry points; problematic in library code

### Security Rules

- Enable **`sensitive-parameter`** unconditionally -- high signal, low noise
- **`hardcoded-credentials`** needs manual triage -- expect false positives in translation and config files
- Injection rules (`sql-injection`, `xss`, `command-injection`) are supplementary -- do not rely on them as your primary security scanning tool

---

## Cross-Project Comparison

When comparing health scores across multiple projects, keep these caveats in mind.

### What to Trust

- **Typing scores** are the most reliable discriminator. They are based on straightforward counting (typed declarations / total declarations) with no complex aggregation
- **Coupling scores** discriminate well and match intuition. Small focused libraries score high; large interconnected frameworks score low
- **Overall health** is a reasonable composite signal

### What to Be Skeptical Of

- **Complexity scores for small projects** (< 100 classes) may be dominated by a single namespace with a few god classes. A score of 0 does not necessarily mean "critically complex codebase"
- **Maintainability scores** have minimal range across projects and are better suited for within-project tracking over time
- **Violation counts** can be inflated by domain-specific false positives (e.g., 782 identical-subexpression violations in parser code). Always check the per-rule violation breakdown to identify outlier rules

### Comparison Checklist

1. Compare **health scores** (numbers), not labels -- labels can cluster too much
2. For complexity, also check **per-method CCN average** alongside the health score
3. If a project has < 100 classes, treat complexity and coupling scores with extra caution
4. Check whether a single rule dominates the violation count
5. For framework code, consider disabling type-coverage rules to focus on structural issues

---

## Automated Analysis with JSON

For CI pipelines or AI-assisted architecture reviews, the JSON output provides structured data.

### Recommended Workflow

```bash
# Step 1: Overview scan
bin/aimd check src/ --format=json --workers=0

# Step 2: Deep dive into a specific namespace
bin/aimd check src/ --namespace=App\\Domain --format=json --workers=0

# Step 3: Raw metrics for custom analysis
bin/aimd check src/ --format=metrics-json --workers=0
```

### Most Useful JSON Fields

| Field                                            | Purpose                                                           |
| ------------------------------------------------ | ----------------------------------------------------------------- |
| `health.*`                                       | Quick project health assessment across 5 dimensions               |
| `worstNamespaces[].healthScores`                 | Identifies the worst dimension per namespace                      |
| `worstClasses[].metrics.{cbo, tcc, wmc, mi.avg}` | Class-level diagnosis                                             |
| `violationsMeta.byRule`                          | Violation distribution without needing all individual violations  |
| `health.overall.directScore`                     | Distinguishes "this namespace is bad" from "its children are bad" |

!!! tip
    The base JSON caps violations at 50. Use `--namespace` for a specific scope to get all violations without truncation.

### Stable Core vs. Volatile Periphery

Use instability and abstractness from the metrics JSON to map your architecture:

| Pattern        | Meaning                                                                     |
| -------------- | --------------------------------------------------------------------------- |
| Low I, high A  | **Stable abstract core** -- interfaces and contracts. Healthy.              |
| Low I, low A   | **Zone of pain** -- stable but concrete. Hard to change.                    |
| High I, low A  | **Volatile periphery** -- concrete implementations. Expected for leaf code. |
| High I, high A | **Zone of uselessness** -- abstract but nobody depends on it.               |

---

## Common Pitfalls

**Do not start with `--detail` on the whole project.** It is overwhelming. Start with the summary and drill down.

**Do not treat violation count as a priority metric.** A class with 65 "long-parameter-list" warnings is less urgent than a class with 5 complexity errors.

**Do not ignore health score dimensions.** A class scoring "Strong" on complexity but "Critical" on cohesion needs a different refactoring approach than one that is "Critical" on complexity.

**Do not compare ClassRank values across projects** of different sizes. PageRank dilutes as the graph grows.

**Do not rely solely on overall health.** Drill into the per-dimension scores. A project with "Acceptable" overall health might have "Critical" complexity hidden by "Strong" typing.
