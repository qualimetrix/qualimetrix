// Shared reader for the HTML report's hand-written metric-key literals.
//
// Used by scripts/collect-metric-keys.mjs (population, run by hand) and by
// tests/metric-key-catalog.test.js (regression guard, run by `vitest`).
//
// Catalog source: MetricName.php constants + AggregationStrategy.php suffixes
// + HealthDecompositionCatalog.php dimension keys, read as text with a targeted
// regex — not a PHP parse. This mirrors only the *shape* of those three
// files (a constant/case list); it does not execute or type-check PHP.
import { readFileSync, readdirSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { fromRoot } from './repo-root.mjs';
import { parseAst } from 'rollup/dist/parseAst.js';
import { walk } from 'estree-walker';

const __dirname = dirname(fileURLToPath(import.meta.url));

const METRIC_NAME_PHP = fromRoot(
  'src/Analysis/Evidence/Measurement/Contract/MetricName.php',
);
const AGGREGATION_STRATEGY_PHP = fromRoot(
  'src/Analysis/Evidence/Measurement/Contract/AggregationStrategy.php',
);
const HEALTH_DECOMPOSITION_CATALOG_PHP = fromRoot(
  'src/Analysis/Evidence/ComputedMetrics/Health/Metadata/HealthDecompositionCatalog.php',
);

const TEMPLATE_ROOT = resolve(__dirname, '..');
const SRC_DIR = join(TEMPLATE_ROOT, 'src');
const TESTS_DIR = join(TEMPLATE_ROOT, 'tests');

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

/**
 * Parses `source` and returns every string Literal node whose value is
 * family-shaped, as `{ value, line }`.
 *
 * Blind spots (does not see): computed/concatenated keys
 * (`'size.' + metric`), template-literal interpolation
 * (`` `size.${x}` ``), and any value produced at runtime rather than
 * written as a source literal.
 */
function collectCodeLiterals(source, catalog) {
  const ast = parseAst(source, { ecmaVersion: 2023, sourceType: 'module' });
  const found = [];
  walk(ast, {
    enter(node) {
      if (node.type === 'Literal' && typeof node.value === 'string' && isFamilyShaped(node.value, catalog)) {
        const line = source.slice(0, node.start).split('\n').length;
        found.push({ value: node.value, line });
      }
    },
  });
  return found;
}

/**
 * Masks every string/template-literal source range identified by the AST
 * with spaces (newlines kept, for line-number accuracy), then regex-scans
 * what remains for `//` and `/* *‍/` comment bodies.
 *
 * Blind spot: does not handle regex literals specially — a `/.../ ` regex
 * containing a `//`-shaped sequence could misfire, though none exist in this
 * template's source at time of writing (verified by grep).
 */
function collectCommentLiterals(source, catalog) {
  const ast = parseAst(source, { ecmaVersion: 2023, sourceType: 'module' });
  const chars = [...source];
  walk(ast, {
    enter(node) {
      if (
        (node.type === 'Literal' && typeof node.value === 'string') ||
        node.type === 'TemplateElement'
      ) {
        for (let i = node.start; i < node.end; i += 1) {
          if (chars[i] !== '\n') {
            chars[i] = ' ';
          }
        }
      }
    },
  });
  const masked = chars.join('');

  const found = [];
  const commentPattern = /\/\/[^\n]*|\/\*[\s\S]*?\*\//g;
  let match;
  while ((match = commentPattern.exec(masked)) !== null) {
    const commentText = match[0];
    const literalPattern = /[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+/g;
    let literalMatch;
    while ((literalMatch = literalPattern.exec(commentText)) !== null) {
      if (isFamilyShaped(literalMatch[0], catalog)) {
        const line = masked.slice(0, match.index + literalMatch.index).split('\n').length;
        found.push({ value: literalMatch[0], line });
      }
    }
  }
  return found;
}

/**
 * Scans one `.js` file for family-shaped string literals, split into a
 * `code` bucket (string/template Literal nodes) and a `comment` bucket.
 */
export function scanFile(filePath) {
  const catalog = loadCatalog();
  const source = readFileSync(filePath, 'utf8');
  return {
    code: collectCodeLiterals(source, catalog),
    comments: collectCommentLiterals(source, catalog),
  };
}

function listJsFiles(dir) {
  return readdirSync(dir)
    .filter((name) => name.endsWith('.js'))
    .map((name) => join(dir, name));
}

/**
 * Scans the whole template: `src/*.js` as the `code`/`comment` buckets,
 * `tests/*.js` as the `test` bucket (code and comments merged — test
 * fixtures are not shipped, so the code/comment split does not matter for
 * them the way it does for `src/`).
 */
export function collectAll() {
  const catalog = loadCatalog();
  const rows = [];

  for (const file of listJsFiles(SRC_DIR)) {
    const source = readFileSync(file, 'utf8');
    const relative = 'src/' + file.slice(SRC_DIR.length + 1);
    for (const hit of collectCodeLiterals(source, catalog)) {
      rows.push({ bucket: 'code', file: relative, line: hit.line, key: hit.value, inCatalog: isCatalogMember(hit.value, catalog) });
    }
    for (const hit of collectCommentLiterals(source, catalog)) {
      rows.push({ bucket: 'comment', file: relative, line: hit.line, key: hit.value, inCatalog: isCatalogMember(hit.value, catalog) });
    }
  }

  for (const file of listJsFiles(TESTS_DIR)) {
    const source = readFileSync(file, 'utf8');
    const relative = 'tests/' + file.slice(TESTS_DIR.length + 1);
    for (const hit of collectCodeLiterals(source, catalog)) {
      rows.push({ bucket: 'test', file: relative, line: hit.line, key: hit.value, inCatalog: isCatalogMember(hit.value, catalog) });
    }
    for (const hit of collectCommentLiterals(source, catalog)) {
      rows.push({ bucket: 'test-comment', file: relative, line: hit.line, key: hit.value, inCatalog: isCatalogMember(hit.value, catalog) });
    }
  }

  rows.sort((a, b) => a.file.localeCompare(b.file) || a.line - b.line);
  return { catalog, rows };
}
