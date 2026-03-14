# Spec: Interactive HTML Report

**Status:** Draft
**Created:** 2026-03-10
**Updated:** 2026-03-14
**Phase:** 3.4 (extends Phase 3 roadmap)
**Depends on:** [Computed Metrics](SPEC_COMPUTED_METRICS.md) (health scores as primary data layer)

---

## Problem

AIMD produces text, JSON, and CI-oriented formats (SARIF, Checkstyle, GitLab). None of them answer the question "where
are the worst parts of my codebase?" visually. Developers and tech leads need a bird's-eye view for:

- Sprint planning: "which subsystems need the most attention?"
- Architecture reviews: "how healthy is each domain?"
- Stakeholder communication: "here's our technical debt map"

phpmetrics offers HTML reports but they are static tables with basic charts and no drill-down. No PHP tool provides
interactive, explorable visualizations with health scores.

---

## Feature Overview

A self-contained HTML file (`--format=html`) with:

1. **Treemap** — primary visualization, namespace hierarchy colored by health score
2. **Category breakdown** — bar chart showing per-category health scores
3. **Metric detail** — raw metric values for a selected namespace/class
4. **Violation list** — filterable table of violations (if any)
5. **Worst offenders** — "Top 10 worst classes" table for quick prioritization
6. **Drill-down navigation** — click to explore from project → namespace → class

All data embedded as JSON in the HTML file. JS library inlined for offline support. No external dependencies at runtime.

---

## User Experience

### Navigation Flow

Split layout: treemap (~70vh top) + detail panel (~30vh bottom), both visible without scrolling.

```
┌──────────────────────────────────────────────────────────┐
│  Project > App\Payment            [metric: ▼ overall]  🔍│
├──────────────────────────────────────────────────────────┤
│  ┌─ Treemap ────────────────────────────────────────────┐│
│  │ Processing (red) │ Gateway │ Invoice (green)         ││
│  │ ┌───────────┐    │(yellow) │ ┌──────────────────┐    ││
│  │ │ █ error   │    │         │ │                  │    ││
│  │ └───────────┘    │         │ └──────────────────┘    ││
│  └──────────────────────────────────────────────────────┘│
├──────────────────────────────────────────────────────────┤
│  ┌─ Health ───────┐ ┌─ Worst Classes ──────────────────┐│
│  │ Complexity:  42│ │ PaymentProcessor  │ 28 │ CCN 18  ││
│  │ Cohesion:    35│ │ RefundHandler     │ 65 │ CCN 6   ││
│  │ Coupling:    55│ │ TransactionLogger │ 82 │ CCN 3   ││
│  │ Typing:      78│ └──────────────────────────────────┘│
│  │ Maint.:      58│ ┌─ Violations (12) ────────────────┐│
│  │ Overall:     51│ │ complexity.cyclomatic │ 5 │ ████ ││
│  └────────────────┘ │ coupling.cbo          │ 4 │ ███  ││
│  Debt: 4h 20m       │ code-smell.god-class  │ 3 │ ██   ││
│                      └─────────────────────────────────┘│
├──────────────────────────────────────────────────────────┤
│  Generated 2026-03-10 │ AIMD 1.0.0                      │
└──────────────────────────────────────────────────────────┘
```

Health categories match the actual computed metric defaults: **complexity**, **cohesion**, **coupling**, **typing**,
**maintainability**, and **overall**.

### Treemap Semantics

| Dimension            | What it represents                          | Source                           |
| -------------------- | ------------------------------------------- | -------------------------------- |
| **Rectangle size**   | Lines of code (`loc.sum`)                   | Raw metric                       |
| **Rectangle color**  | Overall health score (blue-white-red scale) | Computed metric `health.overall` |
| **Rectangle border** | Error-severity violations (red 2px border)  | Binary: has errors / doesn't     |
| **Label**            | Short namespace/class name                  | SymbolPath                       |
| **Tooltip**          | Full name, LOC, health score, top issues    | Multiple sources                 |

**Color scale:** 0–30 = red, 30–60 = neutral, 60–100 = blue. Continuous diverging gradient. Blue-white-red palette is
color-blind friendly (unlike green-yellow-red which is problematic for deuteranopia, ~8% of men).

The neutral point of the diverging scale adapts to the background via CSS custom property: `--color-neutral: #ffffff`
(light mode) / `--color-neutral: #2d2d2d` (dark mode). This avoids white rectangles blending into a dark background.

**Tiling algorithm:** D3 squarify (produces near-square rectangles, easiest to compare visually).

**Visibility threshold:** rectangles smaller than 400px² (20×20) at the current zoom level are not rendered
individually. Instead, they are aggregated into an "Other (N namespaces)" visual group that expands on click. This is a
purely visual optimization — the underlying JSON data is not affected. This solves both readability (30–50 tiny
rectangles become unreadable) and rendering performance for 1000+ leaf nodes. The threshold is recalculated on window
resize. Note: the "Other" group's combined LOC may make it visually large — this is correct behavior (it represents real
code), but the label should clarify it's an aggregate.

**Zero-LOC nodes:** interfaces, abstract classes, or enums with `loc.sum = 0` are excluded from the treemap (D3 ignores
zero-weight leaves). They still appear in the detail panel's class list and metrics table.

### Controls

- **Metric selector**: switch treemap color between health.overall, health.complexity, health.coupling, etc.
  Switching triggers an animated color transition so the user can visually track changes.
- **Breadcrumb**: `Project > App\Payment > Processing` — click any level to navigate back
- **Search**: highlight by namespace or class name
- **Sort toggle** (in list views): sort by health score, LOC, violation count
- **Worst offenders** (on project/namespace level): flat table of the 10 worst classes sorted by health.overall ASC,
  showing class name, health score, LOC, violation count, and top violation rule. Answers "what to refactor first?"
  without requiring manual drill-down into each namespace.

---

## Data Model

### Violation Semantics

Each tree node contains **only its own direct violations** — no roll-up. Method-level violations (e.g.,
`App\Payment\PaymentProcessor::process` with CCN 24) are attached to their parent class node, since class is the leaf
level in the treemap.

Each node also carries a **`violationCountTotal`** field: the recursive sum of all violations in the subtree. This
powers UI elements (badges, sorting, worst offenders) without duplicating violation data in the JSON.

**Violation JSON schema:**

```json
{
  "ruleName": "complexity.cyclomatic",
  "violationCode": "complexity.cyclomatic.error",
  "message": "Cyclomatic complexity is 24 (threshold: 20)",
  "severity": "error",
  "metricValue": 24,
  "symbolPath": "App\\Payment\\PaymentProcessor::process",
  "file": "src/Payment/PaymentProcessor.php",
  "line": 45
}
```

File paths are relativized to `basePath` (same as other formatters). Only these fields are serialized — `Location`,
`RuleLevel`, and `relatedLocations` are omitted to reduce JSON size.

### Tree Structure

All data is embedded as a single JSON object in the HTML file:

```json
{
  "project": {
    "name": "my-app",
    "generatedAt": "2026-03-10T14:30:00Z",
    "aimdVersion": "1.0.0",
    "command": "bin/aimd check src/ --format=html --output=report.html",
    "partialAnalysis": false
  },
  "tree": {
    "name": "<project>",
    "path": "",
    "type": "project",
    "metrics": {
      "loc.sum": 15000,
      "health.overall": 62
    },
    "violations": [],
    "violationCountTotal": 47,
    "children": [
      {
        "name": "App",
        "path": "App",
        "type": "namespace",
        "metrics": {},
        "violations": [],
        "violationCountTotal": 35,
        "children": [
          {
            "name": "Payment",
            "path": "App\\Payment",
            "type": "namespace",
            "metrics": {},
            "violations": [],
            "violationCountTotal": 12,
            "children": [
              {
                "name": "PaymentProcessor",
                "path": "App\\Payment\\PaymentProcessor",
                "type": "class",
                "metrics": {},
                "violations": [
                  {
                    "ruleName": "coupling.cbo",
                    "violationCode": "coupling.cbo.error",
                    "message": "CBO is 14 (threshold: 12)",
                    "severity": "error",
                    "metricValue": 14,
                    "symbolPath": "App\\Payment\\PaymentProcessor",
                    "file": "src/Payment/PaymentProcessor.php",
                    "line": 10
                  },
                  {
                    "ruleName": "complexity.cyclomatic",
                    "violationCode": "complexity.cyclomatic.error",
                    "message": "Cyclomatic complexity of process() is 24 (threshold: 20)",
                    "severity": "error",
                    "metricValue": 24,
                    "symbolPath": "App\\Payment\\PaymentProcessor::process",
                    "file": "src/Payment/PaymentProcessor.php",
                    "line": 45
                  }
                ],
                "violationCountTotal": 2
              }
            ]
          }
        ]
      }
    ]
  },
  "summary": {
    "totalFiles": 120,
    "totalClasses": 95,
    "totalViolations": 47,
    "totalDebtMinutes": 260,
    "healthScores": {
      "health.overall": 62,
      "health.complexity": 45,
      "health.cohesion": 50,
      "health.coupling": 71,
      "health.typing": 78,
      "health.maintainability": 58
    }
  },
  "computedMetricDefinitions": {
    "health.complexity": {
      "description": "Complexity health score (0-100, higher is better)",
      "scale": [0, 100],
      "inverted": true
    },
    "health.cohesion": { "..." : "..." },
    "health.coupling": { "..." : "..." },
    "health.typing": { "..." : "..." },
    "health.maintainability": { "..." : "..." },
    "health.overall": {
      "description": "Overall health score (0-100, higher is better)",
      "scale": [0, 100],
      "inverted": true
    }
  }
}
```

The tree structure mirrors the namespace hierarchy with a **virtual root node** (`<project>`) that contains all
top-level namespaces. This handles projects with multiple root namespaces (e.g., `App` + `Domain` + `Infrastructure`).
The root node is not visible in the treemap but appears in the breadcrumb.

Classes are leaf nodes. Each node has its own metrics, direct violations, and recursive violation count.

### Procedural Files

PHP files without classes or namespaces (procedural code) are grouped under a synthetic `(no namespace)` node at the
root level. Files with a namespace but no classes are attached to their namespace node. Function-level violations are
attached to the `(no namespace)` or namespace node directly.

### Partial Analysis Mode

When `--analyze=git:staged` or similar partial modes are used, `project.partialAnalysis` is set to `true`. The JS
application renders a **warning banner**: "Partial analysis: only N files analyzed. Health scores and aggregated metrics
may be incomplete." The report is still generated — the user decides whether to trust partial data.

### Tech Debt

`HtmlFormatter` computes tech debt from violations using `DebtCalculator` directly (it receives `Report` which contains
violations). The `summary.totalDebtMinutes` and per-node debt are calculated during tree building. This avoids requiring
`DebtSummary` as a dependency of `Report`.

---

## Technical Approach

### JS Library

**D3.js** (d3-hierarchy, d3-selection, d3-scale, d3-color, d3-interpolate, d3-transition). Reasons:

- Treemap is D3's core strength
- No dependencies
- Can be bundled as a single minified script (~45KB for required modules)
- Full control over appearance and interaction

Alternative considered: ECharts (simpler API but heavier ~800KB, less treemap customization).

### Self-contained HTML with Inline JS

D3 modules are inlined into the HTML file (~45KB minified). This ensures:

- Works offline
- Works in air-gapped CI environments (corporate networks)
- Shareable as a single file (email, Slack, CI artifacts)
- No CDN dependency (URLs can break, privacy concerns)

phpmetrics, SonarQube reports, and coverage reporters all use the same approach.

### Security: XSS Prevention

All data is embedded via `<script type="application/json" id="report-data">` and parsed with
`JSON.parse(document.getElementById('report-data').textContent)`. This prevents XSS through malicious class names,
violation messages, or file paths that could contain `</script>` or other HTML.

The PHP formatter must JSON-encode with `JSON_HEX_TAG` flag to escape `<` and `>` in string values, preventing
`</script>` injection even inside the JSON block.

All DOM rendering uses `textContent` or D3's `.text()` — never `.innerHTML` with user data.

### HTML Structure

Single self-contained file with a **split layout**: treemap occupies the top ~70vh, detail panel is sticky at the
bottom ~30vh. Both are visible without scrolling. This avoids the need to scroll down after clicking a treemap node.

```html
<!DOCTYPE html>
<html>
<head>
    <style>/* All CSS inlined */</style>
</head>
<body>
<div id="app">
    <header>
        <nav id="breadcrumb"></nav>
        <div id="controls">
            <select id="metric-selector"></select>
            <input id="search" type="text" placeholder="Search...">
        </div>
    </header>
    <div id="partial-warning" style="display:none">
        <!-- Shown when project.partialAnalysis is true -->
    </div>
    <main id="split-layout">
        <div id="treemap"></div>
        <div id="detail-panel">
            <div id="health-bars"></div>
            <div id="worst-offenders"></div>
            <div id="metrics-table"></div>
            <div id="violations-table"></div>
        </div>
    </main>
    <footer id="report-footer">
        <!-- Generated at, AIMD version, command -->
    </footer>
</div>
<script type="application/json" id="report-data">/* JSON embedded by PHP formatter */</script>
<script>/* D3.js inlined (minified) */</script>
<script>
    const DATA = JSON.parse(document.getElementById('report-data').textContent);
    // Application code
</script>
</body>
</html>
```

### URL Hash Navigation

Deep linking via URL hash with type prefix to distinguish namespaces from classes:

```
#ns:App/Payment              → drill-down into namespace App\Payment
#cl:App/Payment/Processor    → select class App\Payment\Processor
```

Rules:
- Namespace `\` separators are replaced with `/` in the hash
- Standard `encodeURIComponent` for special characters in segment names
- The "Other" visual pseudo-node has no URL hash (it is a rendering artifact, not a data node)
- Browser back/forward works via `hashchange` event listener
- On page load, the hash is parsed and the treemap navigates to the target node

### PHP Formatter

```
src/Reporting/Formatter/HtmlFormatter.php
```

Implements `FormatterInterface`. The `format(Report $report, FormatterContext $context): string` method:

1. Build the tree structure from `$report->metrics` (`MetricRepositoryInterface`):
    - Iterate `$report->metrics->getNamespaces()` to get flat namespace list
    - Parse namespace strings into a hierarchy by splitting on `\` and creating intermediate nodes
    - Intermediate nodes without own metrics aggregate from children
    - Attach class nodes as leaves via `$report->metrics->all(SymbolType::Class_)`
    - Procedural files (no namespace) grouped under `(no namespace)` synthetic node
2. Attach violations from `$report->violations` to tree nodes by matching `SymbolPath`
3. Compute `violationCountTotal` bottom-up and debt via `DebtCalculator`
4. Serialize to JSON with `JSON_HEX_TAG` flag
5. Read template files and inline JSON + JS + CSS into the HTML skeleton
6. Return the complete HTML string

### JS Build Pipeline

The JS/CSS code uses **Vite** as the build tool, providing:
- **Dev server with HMR** — live preview of the treemap during development
- **Production build** — bundles ES modules into a single IIFE file
- **vitest** — unit tests for pure JS logic (shared Vite config)

```
src/Reporting/Template/
├── src/                    # ES modules (source code)
│   ├── main.js             # Entry point, D3 rendering, drill-down
│   ├── tree.js             # Tree traversal, "Other" aggregation
│   ├── color.js            # Color scale, metric selector
│   ├── hash.js             # URL hash parse/generate
│   ├── search.js           # Search/highlight logic
│   └── detail.js           # Detail panel updates
├── tests/                  # vitest unit tests
│   ├── tree.test.js
│   ├── color.test.js
│   ├── hash.test.js
│   └── search.test.js
├── report.html             # HTML skeleton with placeholders (used by PHP formatter)
├── report.css              # Plain CSS with custom properties (no preprocessor)
├── dev.html                # Dev-only entry point with test fixture data (not shipped)
├── dist/                   # Build output (committed to repo)
│   ├── report.min.js       # Bundled application code (IIFE)
│   └── d3.min.js           # D3 custom bundle (~45KB)
├── vite.config.js          # Vite + vitest config
├── package.json            # Dependencies: vite, vitest, d3 modules
└── package-lock.json
```

**Development workflow:**
1. `cd src/Reporting/Template && npm install` (first time only)
2. `npm run dev` — Vite serves `dev.html` with test data at `localhost:5173`, HMR on JS/CSS changes
3. `npm test` — vitest runs unit tests for pure logic (tree traversal, color mapping, URL hash, search)
4. `npm run build` — produces `dist/report.min.js` + `dist/d3.min.js`

**What gets tested (unit, no browser):**
- URL hash parse/generate (`#ns:App/Payment` ↔ tree node lookup)
- Color scale mapping (health score → RGB)
- Visibility threshold logic (which nodes aggregate into "Other")
- "Other" node aggregation (LOC summing, label generation)
- Search matching and filtering
- Worst offenders sorting
- Tree traversal helpers

**What requires manual/visual verification:**
- D3 rendering, animations, drill-down
- CSS layout, dark mode, responsive behavior
- Tooltip positioning, label readability

**Build artifacts committed to git:** `dist/report.min.js` and `dist/d3.min.js` are committed so that PHP runtime does
not depend on Node.js. The PHP formatter reads from `dist/` via `__DIR__` relative paths (works in both filesystem and
PHAR contexts, since `__DIR__` resolves correctly inside PHAR archives).

**Rebuilding D3 bundle:** `npm run build:d3` (defined in `package.json`). Runs only when D3 version changes. Exact
module list and versions are locked in `package-lock.json`.

---

## Output: Generic `--output` Option

Instead of making HtmlFormatter a special case, add a generic `--output=<path>` option to the CLI that works with any
format:

```bash
# HTML to file (most common usage)
bin/aimd check src/ --format=html --output=report.html

# JSON to file (useful in CI)
bin/aimd check src/ --format=json --output=report.json

# HTML to stdout (piping, less common but valid for CI)
bin/aimd check src/ --format=html
```

- `ResultPresenter` handles `--output`: writes formatter output to file instead of `OutputInterface`
- Atomic write: write to `$path.tmp.$$`, then `rename()` (consistent with cache write pattern)
- If stdout is a TTY and format is `html`, emit a warning: "HTML output is best saved to a file. Use --output=report.html"
- Default for all formats: stdout (consistent behavior)

This benefits all formats, not just HTML.

### Command in Metadata

The `project.command` field contains the full CLI invocation. This may include file paths or configuration paths. Users
sharing reports externally should be aware that this information is included. A `--no-metadata` option to strip command
and paths is a candidate for Future Enhancements if requested.

---

## Implementation Plan

### Phase A: Data Layer + JS Scaffold

1. Set up JS build pipeline: Vite + vitest, `package.json`, `vite.config.js`, `dev.html` with test fixture
2. Build tree structure from `MetricRepositoryInterface`
    - Virtual root node containing all top-level namespaces
    - Parse flat namespace list into hierarchy by splitting on `\`, creating intermediate nodes
    - Attach class nodes as leaves; group procedural files under `(no namespace)`
    - Attach metrics and violations to each node; method-level violations roll up to parent class
    - Compute `violationCountTotal` bottom-up
    - Compute debt per node via `DebtCalculator`
3. JSON serialization of the tree (with `JSON_HEX_TAG`)
4. `HtmlFormatter` scaffold — produces valid HTML with embedded JSON via `<script type="application/json">`
5. Generic `--output` option in `ResultPresenter`
6. D3 custom bundle build script (`npm run build:d3`)

### Phase B: Treemap Visualization

1. D3 treemap rendering with LOC-based sizing (squarify tiling)
2. Blue-white-red diverging color scale from health.overall
3. Visibility threshold: < 400px² nodes grouped into "Other (N)" (recalculated on resize)
4. Violation border: red 2px border on nodes with error-severity violations
5. Click-to-drill-down navigation
6. Breadcrumb with virtual root
7. Tooltip on hover
8. URL hash navigation with type prefix (`#ns:`, `#cl:`) and `hashchange` listener

### Phase C: Detail Panel

1. Health score bars (horizontal bar chart per category: complexity, cohesion, coupling, typing, maintainability)
2. "Top 10 worst classes" table (on project/namespace levels)
3. Metrics table, grouped by availability:
    - **Health Scores** — computed metrics (health.overall, health.complexity, etc.)
    - **Aggregated Metrics** — avg/sum of child metrics (ccn.avg, loc.sum, etc.)
    - **Direct Metrics** — metrics that exist only at this level (e.g., namespace-level metrics)
    - Only show metrics that actually exist for the current node; do not pad with zeros
4. Violations table (sortable, grouped by rule) — shows only direct violations for the selected node
5. Tech debt summary (total remediation time)
6. Footer: generation date, AIMD version, command used

### Phase D: Polish

1. Metric selector (switch treemap coloring) with animated color transitions
2. Search/highlight
3. Responsive layout (1280px+ screens)
4. Dark mode (via `prefers-color-scheme`, with adapted neutral point in color scale)
5. Warning banner for partial analysis mode
6. TTY warning for HTML-to-stdout
7. Print styles

---

## Design Decisions

### Why treemap over other visualizations?

| Visualization | Pros                                                                                                     | Cons                                        |
| ------------- | -------------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| **Treemap**   | Shows hierarchy + magnitude + quality simultaneously. Worst areas visually dominate. Natural drill-down. | Less intuitive than charts for exact values |
| Sunburst      | Beautiful, shows hierarchy well                                                                          | Poor use of space, hard to compare areas    |
| Bubble chart  | Good for 3 dimensions                                                                                    | No hierarchy, loses namespace structure     |
| Table         | Precise, sortable                                                                                        | No visual "where's the problem" moment      |

Treemap is the standard for "find the biggest/worst thing in a hierarchy" — exactly our use case.

### Why self-contained HTML?

- No server needed — open in any browser
- Easy to share (email, Slack, CI artifacts)
- Works offline and in air-gapped environments
- CI can archive it as a build artifact
- phpmetrics uses the same approach (proven pattern)

### Why D3 over a simpler library?

- Treemap with drill-down animation is a first-class D3 feature
- Full control over color scales, labels, interactions
- No framework lock-in (vanilla JS)
- Well-documented, widely used

### Why blue-white-red over green-yellow-red?

Color-blind accessibility. ~8% of men have deuteranopia (red-green color blindness). Blue-white-red (diverging) palette
is universally accessible and standard in data visualization (used by matplotlib, D3, Tableau).

---

## Definition of Done

### Formatter

- [ ] `HtmlFormatter` implements `FormatterInterface`
- [ ] Builds namespace tree from metric repository with virtual root node
- [ ] Parses flat namespace list into hierarchy, creates intermediate nodes
- [ ] Handles multiple root namespaces gracefully
- [ ] Groups procedural files under `(no namespace)` synthetic node
- [ ] Method-level violations attached to parent class node
- [ ] Each node has `violationCountTotal` (recursive count)
- [ ] Embeds JSON via `<script type="application/json">` with `JSON_HEX_TAG`
- [ ] Built JS artifacts (`dist/`) loaded via `__DIR__` (PHAR-compatible)
- [ ] Produces valid self-contained HTML
- [ ] `--format=html` registered in CLI
- [ ] Generic `--output` option in `ResultPresenter` with atomic writes
- [ ] TTY warning when `--format=html` without `--output`
- [ ] Unit tests for tree building and JSON structure

### Treemap

- [ ] D3 treemap renders namespace hierarchy (squarify tiling)
- [ ] Rectangle size = LOC (zero-LOC nodes excluded from treemap, shown in detail panel)
- [ ] Rectangle color = health.overall score (blue-white-red diverging gradient)
- [ ] Visibility threshold: rectangles < 400px² aggregated into "Other (N)" node (recalculated on resize)
- [ ] Violation border: red border on nodes with error-severity violations
- [ ] Click to drill down into namespace
- [ ] Breadcrumb navigation (with virtual root)
- [ ] Tooltip with namespace name, LOC, health score
- [ ] URL hash navigation with type prefix (`#ns:`, `#cl:`) and `hashchange` support

### Detail Panel

- [ ] Split layout: treemap ~70vh top, detail panel ~30vh bottom (no scrolling needed)
- [ ] Health score bars for each category (complexity, cohesion, coupling, typing, maintainability)
- [ ] "Top 10 worst classes" table on project/namespace levels
- [ ] Metrics table grouped by type (health / aggregated / direct), only showing available metrics
- [ ] Violations table showing only direct violations (sortable by severity, rule)
- [ ] Tech debt summary (remediation time, computed from violations)
- [ ] Updates on navigation (click on treemap node)

### Controls

- [ ] Metric selector: switch treemap color between health scores (with animated transition)
- [ ] Search: highlight namespaces by name

### Visual

- [ ] Clean, professional design (not a dev prototype)
- [ ] Responsive (works on 1280px+ screens)
- [ ] Blue-white-red diverging color palette (color-blind accessible)
- [ ] Neutral point adapts to background via CSS custom property (dark mode compatibility)
- [ ] Fallback without health.overall: use `clamp(mi.avg, 0, 100)` for color scale
- [ ] Dark mode via `prefers-color-scheme`
- [ ] Warning banner for partial analysis mode
- [ ] Footer: generation date, AIMD version, command used

### JS Build Pipeline

- [ ] Vite config with IIFE build output
- [ ] `dev.html` with realistic test fixture data for HMR development
- [ ] ES module structure: main, tree, color, hash, search, detail
- [ ] vitest unit tests for pure logic (tree, color, hash, search)
- [ ] D3 custom bundle build script (`npm run build:d3`)
- [ ] `dist/report.min.js` and `dist/d3.min.js` committed to repo
- [ ] `npm test` passes, `npm run build` produces valid output

### Documentation

- [ ] Update `src/Reporting/README.md`
- [ ] Website docs: new page for HTML reports
- [ ] Update `CHANGELOG.md`
- [ ] Screenshots in documentation

---

## Resolved Questions

1. **Fallback without computed metrics** — health scores (health.*) are always computed by default (independent pipeline
   from rules). If `health.overall` is absent on a node, fall back to `clamp(mi.avg, 0, 100)` — MI is always available
   via Halstead collector, and clamping matches the 0–100 health score scale. This is a rare edge case since health.*
   defaults are always active.

2. **File size for large projects** — 1–2MB is acceptable for a single HTML file. The real concern is rendering
   performance, which is addressed by the visibility threshold (rectangles < 400px² are aggregated into "Other" nodes
   and not rendered until drill-down).

3. **Class-level detail** — class is the leaf node. Method-level metrics would double the data volume and add
   complexity with marginal value at the overview level. Method-level drill-down is a candidate for Future Enhancements.

4. **D3 custom bundle** — use only: d3-hierarchy, d3-selection, d3-scale, d3-color, d3-interpolate, d3-transition
   (~45KB minified). Built via `npm run build:d3`, committed to `dist/`. Versions locked in `package-lock.json`.

8. **JS build pipeline** — Vite for dev server (HMR) + production bundling, vitest for unit tests. JS source in
   ES modules (`src/`), built artifacts committed to `dist/`. PHP reads from `dist/` — no Node.js dependency at
   runtime. Dev workflow: `npm run dev` for live preview, `npm test` for logic tests, `npm run build` for production.

5. **Violation roll-up** — each node stores only its direct violations + a `violationCountTotal` recursive count.
   Method-level violations are attached to their parent class node. No duplication of violation data in the JSON tree.

6. **URL hash format** — type-prefixed paths: `#ns:App/Payment` for namespaces, `#cl:App/Payment/Processor` for
   classes. Backslash `\` → `/`, standard `encodeURIComponent` for special characters. The "Other" visual group has
   no URL hash.

7. **Partial analysis** — `--analyze=git:staged` produces incomplete metrics. HTML report is still generated with a
   warning banner. `project.partialAnalysis` flag in JSON data enables the banner in JS.

---

## Future Enhancements (out of scope)

- **"Size by" selector** — switch treemap rectangle size between LOC (default), violation count, or class count.
  Useful for specific analyses ("where are the most violations?") but risks disorienting users (small class with many
  violations dominates the view). Default LOC semantics ("big modules look big") should remain the primary mode.
- **Method-level drill-down** — clicking a class shows method-level metrics. Doubles data volume; value is limited
  at the overview level. Better suited for a dedicated "class detail" view.
- **`--no-metadata`** — strip command and file paths from the report for privacy when sharing externally.
- **Export** — "Download as PNG/SVG" button for treemap (presentations)
- **Comparison mode** — load two reports and diff them (before/after refactoring)
- **Trend sparklines** — inline trend charts when historical data is available (depends on Phase 3.3 Trend Analysis)
- **Bivariate color map** — encode two metrics simultaneously (e.g., complexity on hue, coupling on lightness).
  Requires a 2D legend and is hard to read without training. Not recommended for v1.
