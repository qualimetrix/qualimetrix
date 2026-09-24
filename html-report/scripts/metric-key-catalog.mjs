// Shared reader for the HTML report's hand-written metric-key literals.
//
// Used by tests/metric-key-catalog.test.js (regression guard, run by
// `vitest`), the only remaining consumer: it imports loadCatalog(),
// isKeyShaped() and staleLiterals() and does its own AST walk over src/. The literal-collection functions that used to live here
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

  // Each source is read by a pattern, not parsed: a pattern that stops
  // matching would otherwise hand back an empty set and every literal would be
  // judged against nothing. Refuse that here, per source.
  for (const [source, found] of [
    [METRIC_NAME_PHP, baseKeys.size - healthKeys.size],
    [AGGREGATION_STRATEGY_PHP, suffixes.size],
    [HEALTH_DECOMPOSITION_CATALOG_PHP, healthKeys.size],
  ]) {
    if (found <= 0) {
      throw new Error(`metric-key catalog: no key read from ${source}; its declaration shape changed`);
    }
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

const KEY_SHAPED = /^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/;

/**
 * True when `literal` has the dotted `family.rest` shape of a metric key.
 *
 * The population deliberately does not consult the catalog: a population
 * drawn from the catalog's own families shrinks exactly when a family is
 * renamed on the PHP side, and the stale literals it should catch fall out of
 * it instead of failing. A dotted literal that is not a metric key at all has
 * to be named in the caller's allow-list rather than skipped by shape.
 */
export function isKeyShaped(literal) {
  return KEY_SHAPED.test(literal);
}

/**
 * The key-shaped literals that are not catalog keys, each with the reason:
 * an unknown family (renamed or removed on the PHP side, or a dotted literal
 * that needs allow-listing) or an unknown key inside a known family.
 *
 * @param {{key: string}[]} literals
 * @param {{baseKeys: Set<string>, suffixes: Set<string>, familyPrefixes: Set<string>}} catalog
 * @param {Set<string>} [allowed] dotted literals that are not metric keys
 * @returns {{key: string, reason: string}[]}
 */
export function staleLiterals(literals, catalog, allowed = new Set()) {
  return literals
    .filter((literal) => !allowed.has(literal.key) && !isCatalogMember(literal.key, catalog))
    .map((literal) => ({
      ...literal,
      reason: catalog.familyPrefixes.has(literal.key.split('.')[0])
        ? 'not a key of its family'
        : `family '${literal.key.split('.')[0]}' is not in the catalog`,
    }));
}
