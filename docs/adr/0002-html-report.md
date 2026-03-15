# 0002. Interactive HTML Report

**Date:** 2026-03-14
**Status:** Accepted

## Context

AIMD produces text, JSON, and CI-oriented formats but no visual overview answering "where are the worst parts of my codebase?" phpmetrics offers HTML reports but with static tables and no drill-down. An interactive, explorable visualization with health scores is needed for sprint planning, architecture reviews, and stakeholder communication.

## Decision

**Self-contained HTML file** (`--format=html`) with D3.js treemap:

1. **Treemap visualization** — namespace hierarchy, rectangle size = LOC, color = health score (blue-white-red diverging scale). D3 squarify tiling. Click to drill down. Blue-white-red palette chosen for color-blind accessibility (~8% deuteranopia prevalence).

2. **Self-contained file** — all CSS, JS, and data embedded as a single HTML file. D3 modules inlined (~53KB). No external dependencies at runtime. Works offline, in air-gapped environments, shareable as single file.

3. **Split layout** — treemap (~70vh top) + detail panel (~30vh bottom), both visible without scrolling. Detail panel shows health bars, worst offenders, metrics table, violations.

4. **JS build pipeline** — Vite for dev server (HMR) + production bundling, vitest for unit tests. ES modules in `src/`, built artifacts committed to `dist/`. PHP reads from `dist/` — no Node.js dependency at runtime.

5. **Small node aggregation** — rectangles below visibility threshold aggregated into "Other (N)" visual group. Purely visual optimization, not affecting JSON data.

6. **URL hash navigation** — type-prefixed paths (`#ns:App/Payment`, `#cl:App/Payment/Processor`). Browser back/forward works via `hashchange` listener.

7. **XSS prevention** — data embedded via `<script type="application/json">` parsed with `JSON.parse()`. PHP uses `JSON_HEX_TAG`. JS uses `textContent` for DOM rendering.

8. **Metric hints** — PHP `MetricHintProvider` is the single source of truth. Ranges, labels, health decomposition, and format templates are embedded as JSON in the report. JS `initHints()` populates Maps from embedded data at startup (no hardcoded hint data in JS).

9. **Hierarchical roll-up** — subtree metrics (worst sub-namespaces) computed entirely on JS side from flat namespace data. Core metric system uses flat aggregation (correct for rules/violations); hierarchical is a presentation concern.

**Alternatives considered:**
- ECharts — simpler API but 800KB, less treemap customization
- Server-rendered dashboard — rejected (offline requirement, CI artifact use case)
- Green-yellow-red color scale — rejected (color-blind inaccessible)

## Consequences

- `dist/report.min.js` and `dist/d3.min.js` committed to git — PHP runtime doesn't depend on Node.js
- JS changes require `npm run build` before they take effect in PHP tests
- Report file size scales with codebase (~1-2MB for large projects), acceptable for single-file distribution
- Class is the leaf node — method-level drill-down is a future enhancement
- `HtmlFormatter` depends on `MetricHintProvider` for hint embedding
