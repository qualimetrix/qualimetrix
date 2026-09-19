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
- This directory's **tests** read PHP: `tests/metric-key-catalog.test.js` calls
  `scripts/metric-key-catalog.mjs`, which parses three files under
  `src/Analysis/` (via `scripts/repo-root.mjs`) to check every metric-key
  literal in `src/*.js` against the real catalog. This runs under
  `composer test:js`, not under `composer build:js` — `npm run build` is
  `vite build` plus `node scripts/bundle-d3.js`, and neither imports
  `metric-key-catalog.mjs`.

Both hops are hardcoded distances to the repository root rather than
configuration, so a directory move on either side requires updating the hop,
not a path string.
