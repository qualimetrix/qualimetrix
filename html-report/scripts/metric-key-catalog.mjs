// Shared reader for the HTML report's hand-written metric-key literals.
//
// Used by tests/metric-key-catalog.test.js (regression guard, run by
// `vitest`), the only remaining consumer: it imports loadCatalog(),
// isCatalogMember() and isFamilyShaped() and does its own AST walk over
// src/*.js. The literal-collection functions that used to live here
// (collectCodeLiterals, collectCommentLiterals, scanFile, collectAll, and the
// listJsFiles/TEMPLATE_ROOT/SRC_DIR/TESTS_DIR plumbing that fed them) had
// exactly one caller, scripts/collect-metric-keys.mjs, and were deleted with
// it rather than kept as unreachable exported API — the ADR for that
// deletion argues an unexecuted script is worth less than the absence of a
// file that looks like a guard and is not one; dead exports are the same
// shape, so they went the same way. git history holds them if the
// investigative view is ever revived.
//
// Catalog source: MetricName.php constants + AggregationStrategy.php suffixes
// + HealthDecompositionCatalog.php dimension keys, read as text with a targeted
// regex — not a PHP parse. This mirrors only the *shape* of those three
// files (a constant/case list); it does not execute or type-check PHP.
import { readFileSync } from 'node:fs';
import { fromRoot } from './repo-root.mjs';

const METRIC_NAME_PHP = fromRoot(
  'src/Analysis/Evidence/Measurement/Contract/MetricName.php',
);
const AGGREGATION_STRATEGY_PHP = fromRoot(
  'src/Analysis/Evidence/Measurement/Contract/AggregationStrategy.php',
);
const HEALTH_DECOMPOSITION_CATALOG_PHP = fromRoot(
  'src/Analysis/Evidence/ComputedMetrics/Health/Metadata/HealthDecompositionCatalog.php',
);

/**
 * Loads the metric-key catalog from the PHP artifacts above.
 *
 * Method: regex extraction of `= '...';` string values from
 * `public const string NAME = '...';` lines (MetricName), `case Name = '...';`
 * lines (AggregationStrategy), and `'health.xxx' =>` array-key lines
 * (HealthDecompositionCatalog). Does not resolve `MetricName::CONST` references
 * used as PHP array values elsewhere — those are inputs *of* the health
 * decomposition, not catalog entries themselves, so they are out of scope
 * here.
 */
export function loadCatalog() {
  const metricNameSource = readFileSync(METRIC_NAME_PHP, 'utf8');
  const baseKeys = new Set(
    [...metricNameSource.matchAll(/public const string [A-Z0-9_]+ = '([^']+)';/g)].map(
      (m) => m[1],
    ),
  );

  const aggregationSource = readFileSync(AGGREGATION_STRATEGY_PHP, 'utf8');
  const suffixes = new Set(
    [...aggregationSource.matchAll(/case [A-Za-z0-9]+ = '([^']+)';/g)].map((m) => m[1]),
  );

  const healthSource = readFileSync(HEALTH_DECOMPOSITION_CATALOG_PHP, 'utf8');
  const healthKeys = new Set(
    [...healthSource.matchAll(/'(health\.[a-z-]+)' => /g)].map((m) => m[1]),
  );

  for (const key of healthKeys) {
    baseKeys.add(key);
  }

  const familyPrefixes = new Set([...baseKeys].map((key) => key.split('.')[0]));

  return { baseKeys, suffixes, familyPrefixes };
}

/**
 * True when `literal` is exactly a catalog key, or a catalog key with one
 * trailing `.<aggregation-suffix>` segment appended.
 */
export function isCatalogMember(literal, catalog) {
  if (catalog.baseKeys.has(literal)) {
    return true;
  }
  const lastDot = literal.lastIndexOf('.');
  if (lastDot === -1) {
    return false;
  }
  const base = literal.slice(0, lastDot);
  const suffix = literal.slice(lastDot + 1);
  return catalog.suffixes.has(suffix) && catalog.baseKeys.has(base);
}

const FAMILY_SHAPED = /^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/;

/**
 * True when `literal` has the `family.rest` shape and `family` is a known
 * catalog family prefix (complexity, coupling, size, health, ...). This is
 * the population/guard net: wide enough to catch a hand-typed typo of a real
 * key, narrow enough that unrelated dotted strings (CSS classes, event
 * names) are not swept in.
 */
export function isFamilyShaped(literal, catalog) {
  if (!FAMILY_SHAPED.test(literal)) {
    return false;
  }
  return catalog.familyPrefixes.has(literal.split('.')[0]);
}
