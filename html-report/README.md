# HTML Report

The browser program that renders `--format=html`: a D3.js treemap
visualization built with Vite, one npm project living at the repository root
alongside `website/`, `benchmarks/` and `finding-gate/` — none of them are
PSR-4 roots.

## What ships to consumers

`HtmlFormatter` reads exactly four files from this directory at runtime, and
`git archive` ships exactly those four to a composer consumer, nothing else:

- `report.html`
- `report.css`
- `dist/report.min.js`
- `dist/d3.min.js`

Everything else here (`src/`, `tests/`, `scripts/`, `package.json`,
`package-lock.json`, `vite.config.js`, `dev.html`) is held out of the composer
package by the `export-ignore` rows in `.gitattributes`. The two `dist/*.js`
files are tracked and rebuilt by `composer build:js`, not generated at
install time.

## Build and test

```bash
composer install:js  # npm ci, once, from this directory's lockfile
composer build:js     # rebuild dist/report.min.js and dist/d3.min.js
composer test:js      # vitest — part of composer check:code
```

## The two-way tie to the PHP sources

The relationship with `src/` runs in both directions:

- `src/Reporting/Formatter/Html/HtmlFormatter.php` reads the four assets
  above at runtime, resolving this directory as a fixed hop from its own
  location.
- The viewer tests read the PHP metric catalog through the bounded existing
  constant/case patterns in `scripts/metric-key-catalog.mjs`. JavaScript is
  parsed by Rollup, with estree-walker visiting metric literals in both
  `src/` and `tests/`, plus static metric and finding property reads.
- `scripts/generate-html-payload-fixture.php` measures a temporary project
  copied from `tests/Reporting/Fixtures/HtmlPayload/` using the real CLI. It
  captures a baseline, adds the authored growth source with one byte
  placeholder replaced, then extracts `report-data` with PHP's native HTML
  parser. `html-report/tests/fixtures/payload.json` retains the full bags and
  records; only `project.generatedAt` and `project.qmxVersion` are normalized.
  `HtmlPayloadFixtureFreshnessTest` compares this artifact with a new native
  measurement. Vitest reads the committed JSON without starting PHP.

```bash
php scripts/generate-html-payload-fixture.php
php scripts/generate-html-payload-fixture.php --check
```

The AST census covers dotted literals and non-call property reads directly
under a `.metrics` receiver, including optional chaining, bracket strings
and unshadowed module `const` strings or string concatenations. It does not
follow arbitrary aliases, runtime selectors, shadowed constants, function
calls or general JavaScript data flow. Finding property reads cover the
viewer's `v` and `finding` receivers; removed finding spellings are caught
on any receiver. Test-only non-metric literals have exact file/key reasons.
The DOM regressions execute the viewer on the real payload with linkedom;
these cover placement, table sorting, independent message/advice/status,
class area weights, Martin points and treemap tooltips. Layout dimensions
are supplied explicitly because linkedom does not perform browser layout.

Both hops are hardcoded distances to the repository root rather than
configuration, so a directory move on either side requires updating the hop,
not a path string.

## Baseline verdicts

The HTML payload and detail viewer retain acceptedLevel for both breached and
not-compared findings. baselineVerdict and nullable baselineReason distinguish
those states; an accepted cap alone never labels a finding as a breach. The
project-level unused-entry warning remains visible after Git projection.

## Declaration navigation

A class node carries `id` equal to its canonical declaration `subject`; `path`
retains the logical name for display. Hash links, search results, treemap
selection and Martin points address the id, so two declarations of one name
remain independently selectable. Class bags include the graph metrics of their
logical name; namespace and project records carry no source file or line.
