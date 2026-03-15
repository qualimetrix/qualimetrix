# Spec: Reporting UX Redesign

**Status**: Draft v4 (post-review round 3)
**Goal**: Make AI Mess Detector's CLI output friendly to all user personas — developers, CI pipelines, AI agents, and tech leads — by introducing progressive disclosure, human-readable explanations, and a summary-first default.

---

## Problem Statement

The current default output (`--format=text`) is a flat list of violations in GCC-style format:

```
src/Service/UserService.php:42: error[complexity.cyclomatic.method]: Cyclomatic complexity is 15...
src/Repository/UserRepo.php:15: warning[size.method-count]: Method count is 32...
... (hundreds of lines)
12 error(s), 35 warning(s) in 47 file(s)
```

**Problems:**
1. **No overview** — the user sees individual violations but not the big picture (where is the codebase healthy vs unhealthy?)
2. **Hidden health scores** — 6 computed health scores (0–100) exist but are only visible in the HTML report
3. **Jargon-heavy** — messages use metric abbreviations (CCN, TCC, LCOM, CBO) that most developers don't know
4. **No progressive disclosure** — no way to start with a summary and drill down
5. **No worst offenders** — no ranking of which namespaces/classes need the most attention
6. **AI agents get too much or too little** — the flat text is hard to parse; the JSON format lacks health context; the metrics-json dumps everything (context window killer)
7. **Hints are JS-only** — human-readable metric interpretations ("Simple", "God class territory") exist in `hints.js` but aren't available to CLI formatters

---

## User Personas & Scenarios

### 1. Developer — First Run
**Goal**: "Where are my problems?"
**Current experience**: Wall of violations, no context, no prioritization.
**Desired experience**: Health overview → worst areas → "run X for details".

### 2. Developer — Pre-commit Check
**Goal**: "Did I make things worse?"
**Current experience**: Same wall, even with `--analyze=git:staged`.
**Desired experience**: Compact summary of changed files' health + new violations only.

### 3. CI Pipeline
**Goal**: Gate-keeper — fail if quality drops below threshold.
**Current experience**: Exit code works, but log output is noisy.
**Desired experience**: Brief summary in CI log + structured output for reporting tools.

### 4. AI Agent
**Goal**: Understand code health issues to plan refactoring.
**Current experience**: Must choose between unparseable text or context-killing full JSON dump.
**Desired experience**: Structured summary (health + top issues + worst offenders) that fits in context window. Agent can drill down into specific namespaces/classes if needed.

### 5. Tech Lead / Architect
**Goal**: Periodic health check, identify where to invest refactoring effort.
**Current experience**: Must generate HTML report and open in browser.
**Desired experience**: CLI summary with worst namespaces, health trends, tech debt breakdown.

### 6. New Team Member (Onboarding)
**Goal**: Understand codebase quality without knowing metric theory.
**Current experience**: Incomprehensible abbreviations and numbers.
**Desired experience**: Plain language explanations — "too many methods", "methods don't share data", "depends on too many classes".

### 7. PR Reviewer
**Goal**: "What changed in this PR? Did quality get worse?"
**Current experience**: Same total violation list, no delta information.
**Desired experience**: "3 new issues introduced, 1 fixed" with focus on changed code only.

### 8. Consultant / Auditor
**Goal**: Quickly assess unfamiliar codebase health for stakeholder report.
**Current experience**: Must generate HTML report, no quick CLI overview.
**Desired experience**: Summary suitable for pasting into Slack/email, worst hotspots ranked.

---

## Design Principles

1. **Summary first, details on demand** — default output fits in one terminal screen
2. **Standard metric names + plain language explanations** — "Cyclomatic complexity: 15 — too many code paths", not invented synonyms
3. **Same data, different views** — all formatters share a single data source (no hints duplication)
4. **Respect context budgets** — AI-friendly output is concise by design, not a separate format
5. **Familiar patterns** — output structure mirrors the HTML report's information hierarchy
6. **Graceful degradation** — empty sections are omitted, not shown empty; output adapts to terminal width

---

## Proposed Formatter Architecture

### Current State

```
text          — flat violation list (GCC-style), one line per violation
text-verbose  — grouped by file, with metric values and debt breakdown
json          — PHPMD-compatible violation list
html          — interactive treemap report
checkstyle    — XML for Jenkins/SonarQube
sarif         — for GitHub Code Scanning / IDEs
gitlab        — GitLab Code Quality JSON
github        — GitHub Actions annotations
metrics-json  — full metric dump for all symbols
```

### Proposed State

```
summary       — NEW, default. Health overview + worst offenders + top issues
text          — flat violation list (current text behavior). --detail adds grouping + context
json          — machine-readable summary (same structure as summary, but JSON)
html          — interactive treemap report (unchanged)
checkstyle    — XML for Jenkins/SonarQube (unchanged)
sarif         — for GitHub Code Scanning / IDEs (unchanged)
gitlab        — GitLab Code Quality JSON (unchanged)
github        — GitHub Actions annotations (unchanged)
metrics-json  — full metric dump for all symbols (unchanged)
```

**Key changes:**
- `summary` becomes the new default format
- `text` absorbs `text-verbose` functionality via `--detail` flag (one formatter, two modes)
- `json` outputs the summary structure (health + worst offenders + violations)
- `text-verbose` deprecated (shows warning, suggests `--format=text --detail`)

---

## Summary Formatter — Detailed Design

### Default Output (no flags)

```
AI Mess Detector — 342 files analyzed, 2.3s

Health:
  Overall              72/100  ██████████████░░░░░░  Good
  Complexity           65/100  █████████████░░░░░░░  Good
  Cohesion             45/100  █████████░░░░░░░░░░░  ⚠ Needs attention
    TCC                    0.15  (good: above 0.5) — methods share few common fields
    LCOM4                  4.2   (good: 1 or less) — class has 4 unrelated method groups
  Coupling             80/100  ████████████████░░░░  Good
  Type safety          90/100  ██████████████████░░  Good
  Maintainability      58/100  ████████████░░░░░░░░  ⚠ Needs attention
    Maintainability index  42    (good: above 65) — code is hard to change safely

Worst namespaces:
  App\Payment          31/100  — low cohesion, high complexity
  App\Legacy\Import    38/100  — very high coupling, low type safety
  App\Controller       44/100  — high complexity, many large classes

Worst classes:
  App\Service\PaymentService       28/100  — 32 methods, high coupling (18 dependencies)
  App\Import\CsvImporter           33/100  — very complex (avg 25 code paths), low type safety
  App\Controller\ApiController     39/100  — 14 code paths, depends on 16 classes

47 violations (12 errors, 35 warnings) | Tech debt: ~4h 30m

  --detail                                show full violation list
  --format=html -o report.html            interactive report
  --namespace=App\\Payment                drill into namespace
  --class=App\\Service\\PaymentService    drill into class
```

### Design Decisions

**Health bars**: Show decomposition (contributing metrics with explanations) for scores ≤ the dimension's warning threshold. Each health dimension has its own warning threshold from `ComputedMetricDefaults`:
- complexity, cohesion, coupling: warning = 50
- typing: warning = 80
- maintainability: warning = 65
- overall: warning = 50

In the example above, Cohesion (45 ≤ 50) and Maintainability (58 ≤ 65) show decomposition. Complexity (65 > 50) does not. Implementation should allow changing this behavior later (e.g., always show / never show, via config or flag).

**Metric names in decomposition**: Use standard abbreviations (TCC, LCOM4, CCN) as labels, with plain language explanation after the dash. This serves both audiences: experts recognize the abbreviation, newcomers read the explanation. Avoids inventing confusing synonyms.

**Worst offenders**: Ranked by `health.overall` score (lowest first). No deduplication — if both a namespace and its child appear in top-N, both are shown (they represent different granularities of the same problem, and the user can drill into either). Shows `+N more` indicator when there are more offenders than displayed.

**Worst offender reason generation**: Show the 2 worst health dimensions for the offender. Template: take the 2 dimensions with lowest scores relative to their warning thresholds, map to human labels: complexity→"high complexity", cohesion→"low cohesion", coupling→"high coupling", typing→"low type safety", maintainability→"hard to maintain". If only 1 dimension is bad, show only 1. Append notable metric values when they exceed the threshold by ≥ 30% (e.g., "32 methods" when threshold is 20 → 60% over, "18 dependencies" when threshold is 13 → 38% over).

**Violation count**: Single summary line, not individual violations. The summary formatter's job is overview, not details.

**Tech debt**: Separate from the violation count line, prefixed with `~` to signal approximation. Tech debt is computed by the existing `RemediationTimeRegistry` — each rule defines its remediation time per violation.

**Hints at bottom**: Contextual, actionable, with ready-made commands:
- Always: `--detail` for violations, `--format=html` for interactive report
- When worst offenders exist: `--namespace=X` with actual worst namespace name
- When `--analyze=git:staged`: suggest full analysis for health scores
- On first run (no config file found): suggest `aimd init` to customize thresholds

**Duration**: Shown in the header line — for a CLI tool, execution speed is a feature.

### Top-N Defaults

| Surface              | Namespaces | Classes | Configurable via     |
| -------------------- | ---------- | ------- | -------------------- |
| Summary (terminal)   | 3          | 3       | `--format-opt=top=N` |
| Namespace drill-down | sub-ns: 3  | 5       | `--format-opt=top=N` |
| JSON                 | 10         | 10      | `--format-opt=top=N` |

All lists show `+N more` when truncated (e.g., `+12 more namespaces`).

### Namespace Drill-down (`--namespace=App\Payment`)

```
AI Mess Detector — App\Payment (4 classes, 12 files)

Health:
  Overall              31/100  ████████░░░░░░░░░░░░  ✗ Poor
  Complexity           28/100  ██████░░░░░░░░░░░░░░  ✗ Poor
    Cyclomatic (avg)       22    (good: below 4) — too many code paths per method
    Cognitive (avg)        18    (good: below 5) — deeply nested, hard to follow
  Cohesion             25/100  █████░░░░░░░░░░░░░░░  ✗ Poor
    TCC                    0.08  (good: above 0.5) — methods operate on different data
    LCOM4                  6     (good: 1 or less) — class has 6 unrelated method groups
  Coupling             52/100  ██████████░░░░░░░░░░  ⚠ Needs attention
  Type safety          35/100  ███████░░░░░░░░░░░░░  ✗ Poor
  Maintainability      22/100  ████░░░░░░░░░░░░░░░░  ✗ Poor

Worst sub-namespaces:
  App\Payment\Gateway    25/100  — very high coupling, low type safety

Worst classes:
  PaymentService          18/100  — 32 methods, very complex, low cohesion
  RefundProcessor         35/100  — high coupling (14 deps), no type declarations
  InvoiceGenerator        42/100  — deeply nested logic, hard to maintain
  +2 more classes

12 violations (5 errors, 7 warnings) | Tech debt: ~1h 45m

  --detail                                show violations in this namespace
  --class=App\\Payment\\PaymentService    drill into class
```

`--namespace` uses prefix matching: `--namespace=App\Payment` matches `App\Payment`, `App\Payment\Gateway`, etc. This is consistent with how `--disable-rule` prefix matching works.

### Class Drill-down (`--class=App\Payment\PaymentService`)

```
AI Mess Detector — App\Service\PaymentService (src/Service/PaymentService.php)

Health:
  Overall              28/100  ██████░░░░░░░░░░░░░░  ✗ Poor
  Complexity           22/100  ████░░░░░░░░░░░░░░░░  ✗ Poor
    Cyclomatic (avg)       22    (good: below 4) — too many code paths per method
    Cognitive (avg)        35    (good: below 5) — deeply nested, hard to follow
    NPath (max)            1250  (good: below 200) — explosive number of execution paths
  Cohesion              8/100  ██░░░░░░░░░░░░░░░░░░  ✗ Poor
    TCC                    0.04  (good: above 0.5) — methods share almost no common fields
    LCOM4                  8     (good: 1 or less) — class has 8 unrelated method groups
  Coupling             35/100  ███████░░░░░░░░░░░░░  ✗ Poor
    CBO                    18    (good: below 7) — depends on too many classes
  Type safety          20/100  ████░░░░░░░░░░░░░░░░  ✗ Poor
  Maintainability      15/100  ███░░░░░░░░░░░░░░░░░  ✗ Poor

Metrics:
  Methods: 32 | Properties: 14 | LOC: 850 | WMC: 142

5 violations (3 errors, 2 warnings) | Tech debt: ~45m

  --detail    show violations for this class
```

`--class` uses exact match on the fully qualified class name.

`--namespace` and `--class` are **mutually exclusive** — specifying both produces an error: `Cannot use --namespace and --class together. Use one at a time.`

### Edge Cases

**Zero violations (clean codebase):**
```
AI Mess Detector — 342 files analyzed, 2.3s

Health:
  Overall              91/100  ██████████████████░░  Excellent
  Complexity           88/100  ██████████████████░░  Good
  Cohesion             85/100  █████████████████░░░  Good
  Coupling             92/100  ██████████████████░░  Good
  Type safety          95/100  ███████████████████░  Good
  Maintainability      90/100  ██████████████████░░  Good

No violations found.
```

No worst offenders section (nothing is below threshold). No hints section (nothing to act on).

**Single file analysis (`aimd check src/Controller/ApiController.php`):**
Summary adapts gracefully — sections with no meaningful data are omitted:
- No "Worst namespaces" section (single file)
- No "Worst classes" section if there's only one class
- Health scores shown for the class(es) in the file
- If no classes found (procedural file), show only violations without health

**Partial analysis (`--analyze=git:staged` or `--report=git:...`):**
```
AI Mess Detector — 8 files analyzed (staged changes only), 0.4s

⚠ Health scores unavailable in partial analysis mode
  Run full analysis for project health overview

12 violations (3 errors, 9 warnings) | Tech debt: ~1h 15m

  --detail    show full violation list
```

Project-level health scores are **not shown** in partial analysis mode — they would be statistically meaningless (8 of 342 files). Only violation summary is displayed. Worst offenders are also omitted.

**Missing health data (collectors disabled or insufficient data):**
Health dimensions with no contributing data are omitted from the display. If all health scores are unavailable (e.g., all relevant collectors disabled), the Health section is replaced with:
```
Health: insufficient data (some metric collectors may be disabled)
```

**Large codebase (10k+ files):**
No special behavior needed — worst offenders are already top-N. Terminal width: health bars adapt to available width (minimum 80 columns assumed; at 80 cols bars are 10 chars, at 120+ bars are 20 chars).

**Terminal width < 80:**
Health bars are hidden; only score numbers and labels are shown:
```
  Cohesion  45/100  ⚠ Needs attention
```

---

## Text Formatter — Redesign

### Default mode (no `--detail`)

```
src/Service/UserService.php:42: error[complexity.cyclomatic.method]: Cyclomatic complexity is 15, exceeds threshold of 10. Consider extracting methods or simplifying conditions (UserService::calculate)
```

Unchanged from current behavior. This format is kept for grep-ability and CI log parsing.

### Detail mode (`--detail`)

Replaces `text-verbose` as a separate formatter. Activated by `--detail` flag on the check command.

Groups violations by file (default) or by rule/severity (`--group-by=rule`). Adds metric values and debt breakdown.

```
src/Service/UserService.php (3 violations)
  ERROR  :42  UserService::calculate
    Cyclomatic complexity: 15 (max 10) — too many code paths  [complexity.cyclomatic.method]
  WARNING :58  UserService::processPayment
    NPath complexity: 450 (max 200) — too many execution paths  [complexity.npath.method]
  WARNING :12  UserService
    Method count: 22 (max 20) — consider splitting class  [size.method-count]

src/Repository/UserRepo.php (1 violation)
  ...

Technical debt by rule:
  complexity.cyclomatic    ~1h 30m  (18 violations)
  size.method-count        ~45m     (5 violations)
  ...
```

**Key changes from current `text-verbose`:**
- Violation message format: "Metric name: value (max threshold) — explanation"
- Violation code moves to the end in brackets — it's a reference, not the headline
- Standard metric names used (Cyclomatic complexity, not invented synonyms)
- Debt breakdown per rule at the bottom

**Note:** `--detail` also works with `--namespace=X` to show violations scoped to that namespace.

**Note:** `-v` is NOT used as a short flag because Symfony Console reserves `-v/-vv/-vvv` for output verbosity levels (`VERBOSITY_VERBOSE`, etc.).

---

## JSON Formatter — Redesign

The `--format=json` output mirrors the summary structure:

```json
{
  "summary": {
    "filesAnalyzed": 342,
    "filesSkipped": 5,
    "duration": 2.34,
    "violationCount": 47,
    "errorCount": 12,
    "warningCount": 35,
    "techDebtMinutes": 270,
    "classCount": 185,
    "namespaceCount": 42
  },
  "health": {
    "overall": {
      "score": 72,
      "label": "Good",
      "threshold": { "warning": 50, "error": 30 }
    },
    "complexity": {
      "score": 65,
      "label": "Good",
      "threshold": { "warning": 50, "error": 25 },
      "decomposition": [
        {
          "metric": "ccn.avg",
          "humanName": "Cyclomatic complexity (avg)",
          "value": 8.2,
          "good": "below 4",
          "direction": "lower_is_better"
        },
        {
          "metric": "cognitive.avg",
          "humanName": "Cognitive complexity (avg)",
          "value": 6.1,
          "good": "below 5",
          "direction": "lower_is_better"
        }
      ]
    },
    "cohesion": {
      "score": 45,
      "label": "Needs attention",
      "threshold": { "warning": 50, "error": 25 },
      "decomposition": [
        {
          "metric": "tcc",
          "humanName": "Tight Class Cohesion",
          "value": 0.15,
          "good": "above 0.5",
          "direction": "higher_is_better"
        },
        {
          "metric": "lcom",
          "humanName": "LCOM4",
          "value": 4.2,
          "good": "1 or less",
          "direction": "lower_is_better"
        }
      ]
    }
  },
  "worstNamespaces": [
    {
      "symbolPath": "App\\Payment",
      "healthOverall": 31,
      "label": "Poor",
      "reason": "low cohesion, high complexity",
      "classCount": 4,
      "violationCount": 12,
      "healthScores": {
        "complexity": 28,
        "cohesion": 25,
        "coupling": 52,
        "typing": 35,
        "maintainability": 22
      }
    }
  ],
  "worstClasses": [
    {
      "symbolPath": "App\\Payment\\PaymentService",
      "file": "src/Payment/PaymentService.php",
      "healthOverall": 28,
      "label": "Poor",
      "reason": "32 methods, high coupling (18 dependencies)",
      "metrics": {
        "methodCount": 32,
        "cbo": 18,
        "ccn.avg": 22,
        "tcc": 0.08
      },
      "healthScores": {
        "complexity": 12,
        "cohesion": 8,
        "coupling": 35,
        "typing": 20,
        "maintainability": 15
      }
    }
  ],
  "violations": [
    {
      "file": "src/Service/UserService.php",
      "line": 42,
      "symbol": "UserService::calculate",
      "namespace": "App\\Service",
      "rule": "complexity.cyclomatic",
      "code": "complexity.cyclomatic.method",
      "severity": "error",
      "message": "Cyclomatic complexity: 15 (max 10) — too many code paths",
      "metricValue": 15,
      "threshold": 10
    }
  ]
}
```

**Design decisions:**
- `violations` array is **limited to top 50** by default (sorted by severity, then by how much threshold is exceeded). Full list via `--format-opt=violations=all`. Suppress via `--format-opt=violations=0`. This keeps the JSON AI-friendly and context-window-safe.
- `worstNamespaces` and `worstClasses` are top 10 (configurable via `--format-opt=top=N`)
- `healthScores` per offender — allows machine consumers to understand *why* without a second query
- `threshold` as a separate field on violations — enables programmatic comparison without message parsing
- `namespace` field on violations — enables grouping without parsing `symbol` or `file`
- `symbolPath` on offenders — stable identifier for drill-down queries
- Metric keys use internal names (`ccn`, `tcc`, `cbo`) — stable identifiers for machine consumers
- Health `decomposition` is always included in JSON (unlike terminal where it's conditional) — machine consumers can decide what to show

**Partial analysis:** When using `--analyze=git:staged` or `--report=git:...`, `health` is `null` and `worstNamespaces`/`worstClasses` are empty arrays.

### Message selection per formatter

Each formatter explicitly chooses which message to use:

| Formatter         | Message field used       | Rationale                                          |
| ----------------- | ------------------------ | -------------------------------------------------- |
| summary           | `humanMessage`           | Human-readable, explanation-focused                |
| text (default)    | `technicalMessage`       | Backward-compatible, grep-friendly                 |
| text (`--detail`) | `humanMessage`           | Grouped view benefits from explanations            |
| json              | `humanMessage`           | Summary-oriented output                            |
| html              | both (display + tooltip) | Interactive, can show both                         |
| checkstyle        | `technicalMessage`       | XML standard format, no change                     |
| sarif             | `technicalMessage`       | SARIF standard format, no change                   |
| gitlab            | `technicalMessage`       | **Critical: fingerprint is computed from message** |
| github            | `technicalMessage`       | GitHub annotation format, no change                |
| metrics-json      | N/A                      | No violations in metrics dump                      |

---

## Human-Readable Metric Names & Descriptions

### MetricHintProvider (new PHP class)

Centralizes all human-readable metadata for metrics. Single source of truth for all formatters (including HTML report, replacing `hints.js`).

**Data per metric:**

| Field              | Example (for TCC)                                  | Purpose                   |
| ------------------ | -------------------------------------------------- | ------------------------- |
| `standardName`     | "Tight Class Cohesion"                             | Full standard name        |
| `abbreviation`     | "TCC"                                              | Short label for display   |
| `direction`        | `higher_is_better`                                 | For "good: above/below X" |
| `goodValue`        | "above 0.5"                                        | Plain language ideal      |
| `ranges`           | `[0 => "Low", 0.29 => "Moderate", 0.49 => "Good"]` | Interpretation labels     |
| `shortExplanation` | "methods share few common fields"                  | Context for the number    |

### Naming Strategy

Use **standard metric names** (abbreviations) as labels, paired with **plain language explanations** after the dash. This serves both audiences: experts recognize the abbreviation, newcomers read the explanation.

**Example**: `TCC  0.15  (good: above 0.5) — methods share few common fields`

Not: ~~"Method connectivity  0.15"~~ (invented synonym that helps neither audience)

### Metric Display Names (complete mapping)

| Metric Key         | Display Label    | Good Value | Short Explanation (bad)                   | Short Explanation (good)       |
| ------------------ | ---------------- | ---------- | ----------------------------------------- | ------------------------------ |
| `ccn`              | Cyclomatic       | below 4    | too many code paths                       | manageable branching           |
| `ccn.avg`          | Cyclomatic (avg) | below 4    | too many code paths per method            | manageable branching           |
| `cognitive`        | Cognitive        | below 5    | deeply nested, hard to follow             | straightforward control flow   |
| `cognitive.avg`    | Cognitive (avg)  | below 5    | deeply nested, hard to follow             | straightforward control flow   |
| `npath`            | NPath            | below 200  | explosive number of execution paths       | few execution paths            |
| `tcc`              | TCC              | above 0.5  | methods share few common fields           | methods share common fields    |
| `lcc`              | LCC              | above 0.5  | methods are loosely connected             | methods are well connected     |
| `lcom`             | LCOM4            | 1 or less  | class has {value} unrelated method groups | class is cohesive              |
| `wmc`              | WMC              | below 20   | total method complexity is high           | total complexity is manageable |
| `cbo`              | CBO              | below 7    | depends on too many classes               | well-isolated                  |
| `cbo.avg`          | CBO (avg)        | below 7    | classes depend on too many others         | reasonable coupling            |
| `instability`      | Instability      | 0.3 – 0.7  | package is highly unstable                | balanced stability             |
| `abstractness`     | Abstractness     | 0.3 – 0.7  | package is too abstract/concrete          | balanced abstraction           |
| `distance`         | Distance         | below 0.3  | poor balance of abstraction and stability | well-balanced design           |
| `classRank`        | ClassRank        | below 0.02 | coupling hotspot, many depend on this     | peripheral, low risk           |
| `dit`              | DIT              | below 3    | deep inheritance, fragile hierarchy       | normal inheritance             |
| `noc`              | NOC              | below 5    | too many direct subclasses                | normal subclass count          |
| `rfc`              | RFC              | below 50   | too many callable methods                 | reasonable method reach        |
| `methodCount`      | Methods          | below 20   | too many methods                          | focused class                  |
| `propertyCount`    | Properties       | below 10   | too many properties                       | reasonable state               |
| `classCount.sum`   | Classes          | below 10   | too many classes in namespace             | focused namespace              |
| `mi`               | MI               | above 65   | code is hard to change safely             | code is maintainable           |
| `mi.avg`           | MI (avg)         | above 65   | code is hard to change safely             | code is maintainable           |
| `typeCoverage.pct` | Type coverage    | above 80%  | missing type declarations                 | well-typed code                |
| `loc`              | LOC              | —          | —                                         | —                              |
| `lloc`             | LLOC             | —          | —                                         | —                              |
| `cloc`             | CLOC             | —          | —                                         | —                              |

### Health Dimension Labels (for worst offender reasons)

| Dimension       | Bad Label        | Good Label       |
| --------------- | ---------------- | ---------------- |
| complexity      | high complexity  | low complexity   |
| cohesion        | low cohesion     | good cohesion    |
| coupling        | high coupling    | low coupling     |
| typing          | low type safety  | good type safety |
| maintainability | hard to maintain | maintainable     |

---

## Human-Readable Violation Messages

### Current State

Rules generate messages via `sprintf()`:
```
"Cyclomatic complexity is 15, exceeds threshold of 10. Consider extracting methods or simplifying conditions"
"CBO (Coupling Between Objects) is 18 (Ca=5, Ce=13), exceeds threshold of 13. Reduce dependencies to lower coupling"
```

### Proposed Change

Each rule provides two message formats:

1. **`humanMessage`** — for summary, detail mode, and JSON:
   ```
   "Cyclomatic complexity: 15 (max 10) — too many code paths"
   "CBO: 18 (max 13) — depends on too many classes"
   "Method count: 32 (max 20) — consider splitting class"
   ```

2. **`technicalMessage`** — current behavior, for text (default mode), checkstyle, sarif, gitlab, github:
   ```
   "Cyclomatic complexity is 15, exceeds threshold of 10. Consider extracting methods or simplifying conditions"
   "CBO (Coupling Between Objects) is 18 (Ca=5, Ce=13), exceeds threshold of 13. Reduce dependencies to lower coupling"
   ```

The `Violation` VO stores both messages. Formatters choose which to display based on context (see message selection table above).

**Critical**: The `technicalMessage` is the **fingerprint source** for GitLab Code Quality. Changing it would break MR history. `humanMessage` is a new addition that does not affect existing formatters.

**Memory consideration**: Both strings are short (< 200 bytes). With 1000 violations, overhead is ~200 KB — negligible vs. the AST cache and token storage for duplication detection.

---

## CLI Changes

### New Options

| Option          | Description                                                                        |
| --------------- | ---------------------------------------------------------------------------------- |
| `--namespace=X` | Drill into a specific namespace. Works with summary, text, and json formatters.    |
| `--class=X`     | Drill into a specific class (FQCN). Works with summary, text, and json formatters. |
| `--detail`      | Add more detail to current view (see below).                                       |

`--namespace` and `--class` are **mutually exclusive**. Using both produces an error.

**`--namespace` matching**: Boundary-aware prefix match on namespace segments (separated by `\`). `App\Payment` matches `App\Payment` and `App\Payment\Gateway`, but NOT `App\PaymentGateway`. This is consistent with how `--disable-rule` prefix matching works on `.`-separated segments.

**`--class` matching**: Exact match on fully qualified class name.

**`--detail` behavior by formatter:**
| Formatter | Without `--detail`            | With `--detail`                                  |
| --------- | ----------------------------- | ------------------------------------------------ |
| summary   | Health + worst offenders only | Adds grouped violation list below summary        |
| text      | Flat one-line-per-violation   | Grouped by file + metric values + debt breakdown |
| json      | Top 50 violations             | All violations (`violations=all` implied)        |

When `--detail` is used with summary, violations are grouped by file, use `humanMessage` format, and respect `--namespace`/`--class` scoping. The full list is shown (no top-50 cap — the user explicitly asked for detail).

**Note on `-v`:** The short flag `-v` is NOT used because Symfony Console reserves `-v/-vv/-vvv` for output verbosity levels (`OutputInterface::VERBOSITY_VERBOSE`, `VERY_VERBOSE`, `DEBUG`).

### Changed Defaults

| Before                                                   | After                                     |
| -------------------------------------------------------- | ----------------------------------------- |
| Default format: `text`                                   | Default format: `summary`                 |
| `--format=json` outputs PHPMD-compatible flat violations | `--format=json` outputs summary structure |

### Removed

| Before                  | After                                                                                                            |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `--format=text-verbose` | Use `--format=text --detail` instead. Shows deprecation warning for one major version if `text-verbose` is used. |

### Unchanged

- `--format=text` output format (GCC-style, same as before)
- Exit codes (0/1/2 based on severity)
- `--fail-on` behavior
- All CI-focused formats (checkstyle, sarif, gitlab, github)
- `--format=metrics-json`
- `--analyze`, `--report`, `--baseline`, `--group-by`, and all other options

---

## Report VO — Extension

### Current Fields
```
violations, filesAnalyzed, filesSkipped, duration, errorCount, warningCount, metrics
```

### Added Fields
```php
public readonly array $healthScores;       // array<string, HealthScore>
public readonly array $worstNamespaces;    // list<WorstOffender>
public readonly array $worstClasses;       // list<WorstOffender>
public readonly int $techDebtMinutes;      // total remediation time
```

**HealthScore VO:**
```php
class HealthScore {
    public string $name;          // "complexity", "cohesion", etc.
    public float $score;          // 0-100
    public string $label;         // "Excellent", "Good", "Moderate", "Needs attention", "Poor"
    public float $warningThreshold;  // from ComputedMetricDefaults
    public float $errorThreshold;
    public array $decomposition;  // list<DecompositionItem>
}
```

**WorstOffender VO:**
```php
class WorstOffender {
    public SymbolPath $symbolPath;
    public ?string $file;            // file path (for classes), null for namespaces
    public float $healthOverall;
    public string $label;
    public string $reason;           // human-readable: "low cohesion, high complexity"
    public int $violationCount;      // total violations in this symbol (NOT capped by JSON top-50)
    public int $classCount;          // number of classes (for namespaces), 0 for classes
    public array $metrics;           // associative array of notable metrics
    public array $healthScores;      // per-dimension scores
}
```

**SummaryEnricher** (new class, named to avoid conflict with existing `ReportBuilder`) computes health scores, worst offenders, and tech debt from `MetricRepositoryInterface` + violations. All formatters consume the enriched `Report`.

**Integration point:** `SummaryEnricher` runs in the pipeline **after** `ReportBuilder::build()` produces the base `Report`. It takes the base `Report` + `MetricRepositoryInterface` and returns a new enriched `Report` with health scores, worst offenders, and tech debt filled in. It does NOT modify `ReportBuilder` internals. When `MetricRepositoryInterface` is not available (e.g., metrics-only collectors disabled), `SummaryEnricher` returns the base `Report` with empty health/offender arrays.

**Note:** The existing `Report` is a `final readonly class` with constructor promotion. Adding fields changes the constructor signature. This is acceptable as a breaking change in beta. All existing `Report` construction sites must be updated (primarily `ReportBuilder::build()` and tests).

---

## Data Flow

```
MetricRepository + Violations
        │
        ▼
   SummaryEnricher ──── MetricHintProvider (standard names, ranges, explanations)
        │
        ▼
   Report (enriched: health, worst offenders, debt)
        │
        ├──► SummaryFormatter  → terminal (default)
        ├──► TextFormatter     → terminal (flat list / --detail grouped)
        ├──► JsonFormatter     → stdout (summary structure)
        ├──► HtmlFormatter     → file (treemap, uses same MetricHintProvider data)
        └──► other formatters  → unchanged (use technicalMessage only)
```

---

## Terminal Rendering

### Health Bars
- Unicode block characters by default: `████████░░░░░░░░░░░░`
- Bar width adapts to terminal width (minimum 80 columns; at 80 cols bars are 10 chars, at 120+ bars are 20 chars)
- At terminal width < 80: bars hidden, only scores and labels shown
- Colors: green (score > warning threshold), yellow (error < score ≤ warning), red (score ≤ error threshold)
- `--no-ansi` disables colors but keeps Unicode bars
- Non-Unicode fallback via `AIMD_ASCII=1` env var: `[########............]`

### Score Labels

Labels are derived from each dimension's **own warning and error thresholds** (from `ComputedMetricDefaults`), not from a fixed range table. This means the same numeric score can have different labels in different dimensions.

**Label rules:**
| Condition            | Label             | Color  |
| -------------------- | ----------------- | ------ |
| score > warning + 20 | Excellent         | green  |
| score > warning      | Good              | green  |
| score > error        | ⚠ Needs attention | yellow |
| score ≤ error        | ✗ Poor            | red    |

**Per-dimension thresholds (from `ComputedMetricDefaults`):**
| Dimension       | Warning | Error | Example: score=58                    |
| --------------- | ------- | ----- | ------------------------------------ |
| complexity      | 50      | 25    | Good (58 > 50)                       |
| cohesion        | 50      | 25    | Good (58 > 50)                       |
| coupling        | 50      | 25    | Good (58 > 50)                       |
| typing          | 80      | 50    | ⚠ Needs attention (58 ≤ 80, 58 > 50) |
| maintainability | 65      | 50    | ⚠ Needs attention (58 ≤ 65, 58 > 50) |
| overall         | 50      | 30    | Good (58 > 50)                       |

**Decomposition trigger:** Show decomposition when score ≤ warning threshold. Same rule as label assignment.

**TTY / pipe detection:** When output is piped (`> file`, `| grep`), ANSI colors and Unicode bars are automatically disabled (standard Symfony Console TTY detection). `--no-ansi` forces this behavior even on TTY.

---

## Migration & Backward Compatibility

This is a **breaking change** (acceptable in beta):
1. Default format changes from `text` to `summary`
2. `--format=json` output structure changes completely (no longer PHPMD-compatible)
3. `--format=text-verbose` deprecated → shows warning, suggests `--format=text --detail`
4. `Report` VO constructor signature changes (new fields: `healthScores`, `worstNamespaces`, `worstClasses`, `techDebtMinutes`)
5. `Violation` VO constructor signature changes (new fields: `humanMessage`, `threshold`)

**Not breaking:**
- `--format=text` output unchanged (same GCC-style format)
- Exit codes unchanged (0/1/2 based on severity)
- `--fail-on` behavior unchanged
- All CI-focused formats (checkstyle, sarif, gitlab, github) unchanged — same messages, same fingerprints
- `--format=metrics-json` unchanged

---

## Implementation Phases

### Phase 1: Data Infrastructure
- `MetricHintProvider` class in `src/Reporting/` (single source of truth for metric metadata)
- `HealthScore`, `WorstOffender`, `DecompositionItem` VOs in `src/Reporting/`
- `SummaryEnricher` — computes enriched Report from metrics + violations
- Extend `Report` VO with new fields
- Human message templates in rules (`humanMessage` alongside existing `message`)

### Phase 2: Summary Formatter
- `SummaryFormatter` — health bars, worst offenders, hints
- `--namespace=X` and `--class=X` drill-down support
- Edge cases: zero violations, single file, partial analysis, missing metrics
- Adaptive terminal width
- Register as default format

### Phase 3: Text Formatter Consolidation
- Merge `TextVerboseFormatter` into `TextFormatter` with `--detail` flag
- New violation message format in detail mode
- Debt breakdown in detail mode
- Deprecation warning for `--format=text-verbose`

### Phase 4: JSON Formatter Redesign
- Output summary structure instead of PHPMD-style flat list
- Include health, worst offenders, decomposition
- `threshold` and `namespace` fields on violations
- Top-50 violation limit with `--format-opt=violations=all` override

### Phase 5: HTML Report Sync
- Replace `hints.js` with data from `MetricHintProvider` (embedded as JSON in HTML)
- Remove duplicated hint definitions from JS

---

## Resolved Questions

1. **Worst offender ranking**: By `health.overall` score — it's transparent and explainable. No deduplication — if parent and child namespace both appear, both are shown.

2. **Top-N defaults**: Summary terminal: 3/3. JSON: 10/10. Drill-down: 3 sub-namespaces, 5 classes. All configurable via `--format-opt=top=N`. Always show `+N more` indicator.

3. **Color scheme**: Unicode by default, colors via ANSI. `--no-ansi` disables colors only. `AIMD_ASCII=1` for pure ASCII environments. Terminal < 80 cols: bars hidden automatically.

4. **Health score for files**: No. Namespace and class are the meaningful units.

5. **Recommendation strings**: Not a separate system. The `humanMessage` includes a short actionable suffix when natural ("too many code paths", "consider splitting class").

6. **`-v` vs `--detail`**: `--detail` is used instead of `-v` because Symfony Console reserves `-v/-vv/-vvv` for verbosity.

7. **`--namespace` vs `--class`**: Mutually exclusive. `--namespace` = prefix match, `--class` = exact match.

8. **Partial analysis health**: Not shown — statistically meaningless on a subset of files.

9. **JSON violation limit**: Top 50 by default, configurable via `--format-opt=violations=all|0|N`.

---

## Out of Scope

- **Delta mode** (comparing current vs previous run) — requires a mechanism for "previous state" (baseline with metrics, or dual-pass analysis). Separate spec needed. The existing `--report=git:...` is a violation filter, not a comparison tool.
- **Trend tracking** (score over time) — requires persistent storage, separate feature
- **AI advisor** (LLM-generated recommendations) — orthogonal feature
- **IDE integration changes** — SARIF already works; summary is for CLI
- **New metrics** — this spec is about presentation, not measurement
- **Git churn-based ranking** — interesting but requires git history analysis
- **Letter grades (A/B/C/D)** — numbers provide better granularity; can be layered on later
- **i18n** — all strings are English; localization is a separate initiative
- **`--format=markdown`** — useful for PR comments but not blocking; can be added incrementally
