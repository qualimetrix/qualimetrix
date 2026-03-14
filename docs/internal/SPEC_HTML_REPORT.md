# Spec: Interactive HTML Report

**Status:** Draft
**Created:** 2026-03-10
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
2. **Category breakdown** — radar/bar chart showing per-category health scores
3. **Metric detail** — raw metric values for a selected namespace/class
4. **Violation list** — filterable table of violations (if any)
5. **Drill-down navigation** — click to explore from project → namespace → class

All data embedded as JSON in the HTML file. JS library inlined for offline support. No external dependencies at runtime.

---

## User Experience

### Navigation Flow

```
┌─────────────────────────────────────────────────────┐
│  Project Overview (Treemap)                          │
│  ┌──────────────┬───────────┬──────────────────────┐ │
│  │              │           │                      │ │
│  │  App\Payment │ App\Auth  │    App\User          │ │
│  │  (red)       │ (green)   │    (yellow)          │ │
│  │              │           │                      │ │
│  ├──────────────┴───────────┤                      │ │
│  │  App\Infra (orange)      │                      │ │
│  └──────────────────────────┴──────────────────────┘ │
│                                                      │
│  Total debt: 4h 20m  │  Overall: 62  │  47 issues   │
│  [Complexity: 45] [Coupling: 71] [Cohesion: 58] ... │
└─────────────────────────────────────────────────────┘
         │ click on App\Payment
         ▼
┌─────────────────────────────────────────────────────┐
│  ← Back │ Project > App\Payment                      │
│                                                      │
│  ┌─ Treemap (sub-namespaces/classes) ──────────────┐ │
│  │ Processing (red) │ Gateway │ Invoice (green)    │ │
│  └─────────────────────────────────────────────────┘ │
│                                                      │
│  ┌─ Health Scores ─────────────────────────────────┐ │
│  │ Complexity: ████████░░ 42                       │ │
│  │ Cohesion:   ██████░░░░ 35                       │ │
│  │ Coupling:   ███████░░░ 55                       │ │
│  │ Design:     █████████░ 78                       │ │
│  │ Maint.:     ███████░░░ 58                       │ │
│  └─────────────────────────────────────────────────┘ │
│                                                      │
│  ┌─ Violations (12) ──────────────────────────────┐  │
│  │ complexity.cyclomatic  │ 5  │ ████░            │  │
│  │ coupling.cbo           │ 4  │ ███░             │  │
│  │ code-smell.god-class   │ 3  │ ██░              │  │
│  └────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────┘
         │ click on Processing
         ▼
┌─────────────────────────────────────────────────────┐
│  ← Back │ Project > App\Payment > Processing         │
│                                                      │
│  ┌─ Classes ───────────────────────────────────────┐ │
│  │ PaymentProcessor   │ Overall: 28 │ CCN avg: 18 │ │
│  │ RefundHandler      │ Overall: 65 │ CCN avg: 6  │ │
│  │ TransactionLogger  │ Overall: 82 │ CCN avg: 3  │ │
│  └─────────────────────────────────────────────────┘ │
│                                                      │
│  ┌─ Raw Metrics ───────────────────────────────────┐ │
│  │ ccn.avg: 9.2    │ cognitive.avg: 14.1           │ │
│  │ tcc.avg: 0.21   │ lcom.avg: 3.4                │ │
│  │ cbo.avg: 8.7    │ mi.avg: 42                   │ │
│  │ loc.sum: 1847   │ classCount: 5                │ │
│  └─────────────────────────────────────────────────┘ │
│                                                      │
│  ┌─ Violations ────────────────────────────────────┐ │
│  │ PaymentProcessor:process  │ CCN 24 (error ≥20) │ │
│  │ PaymentProcessor          │ CBO 14 (error ≥12) │ │
│  │ ...                                             │ │
│  └─────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

### Treemap Semantics

| Dimension           | What it represents                          | Source                           |
| ------------------- | ------------------------------------------- | -------------------------------- |
| **Rectangle size**  | Lines of code (`loc.sum`)                   | Raw metric                       |
| **Rectangle color** | Overall health score (blue-white-red scale) | Computed metric `health.overall` |
| **Label**           | Short namespace/class name                  | SymbolPath                       |
| **Tooltip**         | Full name, LOC, health score, top issues    | Multiple sources                 |

Color scale: 0–30 = red, 30–60 = neutral, 60–100 = blue. Continuous diverging gradient. Blue-white-red palette is
color-blind friendly (unlike green-yellow-red which is problematic for deuteranopia, ~8% of men).

### Controls

- **Metric selector**: switch treemap color between health.overall, health.complexity, health.coupling, etc.
- **Breadcrumb**: `Project > App\Payment > Processing` — click any level to navigate back
- **Search**: highlight by namespace or class name
- **Sort toggle** (in list views): sort by health score, LOC, violation count

---

## Data Model

All data is embedded as a single JSON object in the HTML file:

```json
{
  "project": {
    "name": "my-app",
    "generatedAt": "2026-03-10T14:30:00Z",
    "aimdVersion": "1.0.0"
  },
  "tree": {
    "name": "<project>",
    "path": "",
    "type": "project",
    "metrics": {
      "loc.sum": 15000,
      "health.overall": 62,
      ...
    },
    "violations": [],
    "children": [
      {
        "name": "App",
        "path": "App",
        "type": "namespace",
        "metrics": {
          ...
        },
        "violations": [
          ...
        ],
        "children": [
          {
            "name": "Payment",
            "path": "App\\Payment",
            "type": "namespace",
            "metrics": {
              ...
            },
            "violations": [
              ...
            ],
            "children": [
              {
                "name": "PaymentProcessor",
                "path": "App\\Payment\\PaymentProcessor",
                "type": "class",
                "metrics": {
                  ...
                },
                "violations": [
                  ...
                ]
              }
            ]
          }
        ]
      },
      {
        "name": "Domain",
        "path": "Domain",
        "type": "namespace",
        "metrics": {
          ...
        },
        "violations": [
          ...
        ],
        "children": [
          ...
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
      "health.complexity": 45
    }
  },
  "computedMetricDefinitions": {
    "health.overall": {
      "description": "...",
      "scale": [
        0,
        100
      ],
      "inverted": true
    },
    "health.complexity": {
      "description": "...",
      "scale": [
        0,
        100
      ],
      "inverted": true
    }
  }
}
```

The tree structure mirrors the namespace hierarchy with a **virtual root node** (`<project>`) that contains all
top-level namespaces. This handles projects with multiple root namespaces (e.g., `App` + `Domain` + `Infrastructure`).
The root node is not visible in the treemap but appears in the breadcrumb.

Classes are leaf nodes. Each node has its own metrics and violations.

---

## Technical Approach

### JS Library

**D3.js** (d3-hierarchy + d3-treemap modules). Reasons:

- Treemap is D3's core strength
- No dependencies
- Can be bundled as a single minified script (~30KB for required modules)
- Full control over appearance and interaction

Alternative considered: ECharts (simpler API but heavier ~800KB, less treemap customization).

### Self-contained HTML with Inline JS

D3 modules are inlined into the HTML file (~30KB minified). This ensures:

- Works offline
- Works in air-gapped CI environments (corporate networks)
- Shareable as a single file (email, Slack, CI artifacts)
- No CDN dependency (URLs can break, privacy concerns)

phpmetrics, SonarQube reports, and coverage reporters all use the same approach.

### HTML Structure

Single self-contained file:

```html
<!DOCTYPE html>
<html>
<head>
    <style>/* All CSS inlined */</style>
</head>
<body>
<div id="app">
    <nav id="breadcrumb"></nav>
    <div id="controls">
        <select id="metric-selector"></select>
        <input id="search" type="text" placeholder="Search...">
    </div>
    <div id="treemap"></div>
    <div id="detail-panel">
        <div id="health-bars"></div>
        <div id="metrics-table"></div>
        <div id="violations-table"></div>
    </div>
</div>
<script>/* D3.js inlined (minified) */</script>
<script>
    const DATA = /* JSON embedded by PHP formatter */;
    // Application code
</script>
</body>
</html>
```

### PHP Formatter

```
src/Reporting/Formatter/HtmlFormatter.php
```

Implements `FormatterInterface`. Responsibilities:

1. Build the tree structure from `MetricRepositoryInterface` (namespace hierarchy with virtual root)
2. Attach metrics and violations to each node
3. Serialize to JSON
4. Embed JSON + JS + CSS into the HTML template
5. Write to output

### Template Location

```
src/Reporting/Template/
├── report.html          # HTML skeleton with placeholders
├── report.css           # Styles (inlined during build)
├── report.js            # D3 app code (inlined during build)
└── vendor/
    └── d3.min.js        # D3 library (custom bundle, ~30KB, inlined during build)
```

The formatter reads these files and produces the final HTML. For development, files are separate. The formatter
concatenates them at runtime.

---

## Output: Generic `--output` Option

Instead of making HtmlFormatter a special case, add a generic `--output=<path>` option to the CLI that works with any
format:

```bash
# HTML to file (most common usage)
bin/aimd check src/ --format=html --output=report.html

# JSON to file (useful in CI)
bin/aimd check src/ --format=json --output=report.json

# HTML to stdout (piping, less common)
bin/aimd check src/ --format=html
```

- All formatters write to `OutputInterface` as usual
- When `--output` is specified, Infrastructure redirects output to a file
- Default for all formats: stdout (consistent behavior)
- For `--format=html`, the practical usage is almost always with `--output`

This benefits all formats, not just HTML.

---

## Implementation Plan

### Phase A: Data Layer (depends on Computed Metrics spec)

1. Build tree structure from `MetricRepositoryInterface`
    - Virtual root node containing all top-level namespaces
    - Namespace hierarchy from SymbolPaths
    - Attach metrics and violations to each node
2. JSON serialization of the tree
3. `HtmlFormatter` scaffold — produces valid HTML with embedded JSON
4. Generic `--output` option in CLI

### Phase B: Treemap Visualization

1. D3 treemap rendering with LOC-based sizing
2. Blue-white-red diverging color scale from health.overall
3. Click-to-drill-down navigation
4. Breadcrumb with virtual root
5. Tooltip on hover
6. URL hash navigation (`#App/Payment/Processing` for deep linking)

### Phase C: Detail Panel

1. Health score bars (horizontal bar chart per category)
2. Raw metrics table
3. Violations table (sortable, grouped by rule)
4. Tech debt summary (total remediation time)

### Phase D: Polish

1. Metric selector (switch treemap coloring)
2. Search/highlight
3. Responsive layout (1280px+ screens)
4. Dark mode (via `prefers-color-scheme`)
5. Print styles

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
- [ ] Handles multiple root namespaces gracefully
- [ ] Embeds metrics and violations per node
- [ ] Produces valid self-contained HTML
- [ ] `--format=html` registered in CLI
- [ ] Generic `--output` option for all formats
- [ ] Unit tests for tree building and JSON structure

### Treemap

- [ ] D3 treemap renders namespace hierarchy
- [ ] Rectangle size = LOC
- [ ] Rectangle color = health.overall score (blue-white-red diverging gradient)
- [ ] Click to drill down into namespace
- [ ] Breadcrumb navigation (with virtual root)
- [ ] Tooltip with namespace name, LOC, health score
- [ ] URL hash navigation for deep linking

### Detail Panel

- [ ] Health score bars for each category
- [ ] Raw metrics table
- [ ] Violations table (sortable by severity, rule)
- [ ] Tech debt summary (remediation time)
- [ ] Updates on navigation (click on treemap node)

### Controls

- [ ] Metric selector: switch treemap color between health scores
- [ ] Search: highlight namespaces by name

### Visual

- [ ] Clean, professional design (not a dev prototype)
- [ ] Responsive (works on 1280px+ screens)
- [ ] Blue-white-red diverging color palette (color-blind accessible)
- [ ] Graceful degradation without computed metrics (fallback to MI or violation density)
- [ ] Dark mode via `prefers-color-scheme`

### Documentation

- [ ] Update `src/Reporting/README.md`
- [ ] Website docs: new page for HTML reports
- [ ] Update `CHANGELOG.md`
- [ ] Screenshots in documentation

---

## Open Questions

1. **Fallback without computed metrics** — if computed metrics are not configured, the treemap should still work.
   Fallback to MI avg for color? Violation density? Both as options in metric selector?

2. **File size for large projects** — for 1000+ classes, embedded JSON could reach 1-2MB. Acceptable for a single HTML
   file? Or should we consider truncating small namespaces?

3. **Class-level detail** — should clicking a class show method-level metrics? This adds depth but also complexity.
   Start with class as the leaf node?

4. **D3 custom bundle** — full D3 is ~250KB minified. We need only d3-hierarchy, d3-selection, d3-scale, d3-color,
   d3-interpolate (~30KB). Document the exact modules and how to rebuild the bundle.

---

## Future Enhancements (out of scope)

- **Export** — "Download as PNG/SVG" button for treemap (presentations)
- **Comparison mode** — load two reports and diff them (before/after refactoring)
- **Trend sparklines** — inline trend charts when historical data is available (depends on Phase 3.3 Trend Analysis)
