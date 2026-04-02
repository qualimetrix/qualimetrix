# Tool Improvement Proposals

Collected from self-analysis of `src/` with `bin/qmx check --preset=strict` (2026-03-28).
Each proposal follows the format: "If the tool could X, I would be able to Y."

**Completed proposals (removed from this document):** VO Constructor Exemption, Framework CBO
Distinction, Plain-Text Health Output, Per-File Violation Grouping, Refactoring Impact Score,
Duplication Content Hints, Full JSON Violation Output, Worst Contributors per Health Dimension,
NPath Severity Categories, Violation Density, Violation Clustering, Architectural Role Annotations,
Instability Scope Awareness.

---

## 1. LCOM4: `exclude_methods` Configuration

**If the tool could exclude specific methods from the LCOM4 graph**, users would filter out
~70 false-positive LCOM violations caused by interface-mandated methods.

**Rationale:** Methods like `getName()`, `getDescription()`, `priority()` are required by
implemented interfaces but access no shared state. LCOM4 correctly identifies them as
disconnected — but they cannot be extracted because the interface contract mandates them.
This is a conflict between the metric and the language's interface mechanism, not a metric bug.

**Current state:** `LcomClassData` already has a `statelessMethods` mechanism that merges
constant-returning methods into a virtual `__stateless__` node. However, this only covers
methods with empty body or single `return scalar/const`, missing methods that return
formatted strings, concatenations, etc.

**Proposed solution:** Add `exclude_methods: [getName, getDescription, ...]` option to
`LcomOptions`. Excluded methods are removed from the LCOM4 graph before calculation.
This is explicit and user-controlled — no heuristics that could weaken the metric's signal.

**Concrete examples:**
- 14 Rule classes with LCOM 4 (RuleInterface mandates `getName`, `getDescription`, etc.)
- 35 Collector classes (MetricCollectorInterface mandates `getName`, `provides`, etc.)
- 19 Formatter classes (FormatterInterface mandates `getName`, `getDefaultGroupBy`)
- 5 ConfigurationStage classes (ConfigurationStageInterface mandates `priority`, `name`)

---

## 2. Partial Scope Warning

**If the tool warned when analysis scope doesn't cover the full project**, users would
understand why coupling/instability metrics may be inaccurate.

**Rationale:** Analyzing only `src/Configuration/` means afferent couplings from `Analysis/`
and `Infrastructure/` are invisible, making instability = 1.00 for all namespaces.
`ComposerDiscoveryStage` already reads `composer.json` autoload paths — comparing them
against the analyzed paths would detect partial scope.

**Proposed solution:** When `composer.json` exists and the analyzed paths don't cover all
autoload entries, emit a warning: "Analyzing a subset of the project. Coupling and
instability metrics may be inaccurate. For reliable results, analyze the full project."
When `composer.json` is not found, emit a similar warning about running from the project root.
