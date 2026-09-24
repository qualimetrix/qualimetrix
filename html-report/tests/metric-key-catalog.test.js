import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadCatalog, isKeyShaped, staleLiterals } from '../scripts/metric-key-catalog.mjs';
// './parseAst' is rollup's documented subpath export (see its package.json
// "exports" map); 'rollup/dist/parseAst.js' reached the same file through
// the catch-all "./dist/*" entry, which is an escape hatch rollup makes no
// promise about, not a published surface.
import { parseAst } from 'rollup/parseAst';
import { walk } from 'estree-walker';

// Regression guard for the hand-written metric-key literals in src/*.js
// (`'size.loc.sum'`, `'health.overall'`, ...): a hardcoded key that drifts
// from MetricName.php — a typo, a rename the JS side missed — is a silent
// dashboard breakage (metric shows as 0/blank), not a build or type error.
// loadCatalog() re-reads the PHP sources on every run, so the set asserted
// against is derived rather than stored: there is no generated file to keep
// fresh, and no way for this guard to pass against a stale copy of the
// vocabulary. Keeping it as a test preserves the hand-written runtime bundle
// while still detecting drift.

const __dirname = dirname(fileURLToPath(import.meta.url));
const SRC_DIR = resolve(__dirname, '..', 'src');

// Dotted string literals in src/ that are not metric keys. Empty today; a
// literal belongs here only with the reason it is not a metric key.
const NOT_METRIC_KEYS = new Set([]);

function collectSrcLiterals() {
  const found = [];
  // Recursive: a literal in a future subdirectory of src/ is still read.
  for (const name of readdirSync(SRC_DIR, { recursive: true }).filter((n) => String(n).endsWith('.js'))) {
    const path = join(SRC_DIR, String(name));
    const source = readFileSync(path, 'utf8');
    const ast = parseAst(source, { ecmaVersion: 2023, sourceType: 'module' });
    walk(ast, {
      enter(node) {
        if (node.type === 'Literal' && typeof node.value === 'string' && isKeyShaped(node.value)) {
          const line = source.slice(0, node.start).split('\n').length;
          found.push({ file: String(name), line, key: node.value });
        }
      },
    });
  }
  return found;
}

describe('metric-key catalog', () => {
  it('every key-shaped string literal in src/ is a real MetricName catalog key', () => {
    const catalog = loadCatalog();
    const literals = collectSrcLiterals();

    expect(literals.length).toBeGreaterThan(0);

    const stale = staleLiterals(literals, catalog, NOT_METRIC_KEYS);
    expect(stale, stale.map((l) => `${l.file}:${l.line} '${l.key}': ${l.reason}`).join('\n')).toEqual([]);
  });

  it('fails a literal whose whole family was renamed on the PHP side instead of dropping it', () => {
    const renamed = {
      baseKeys: new Set(['class-cohesion.lcom']),
      suffixes: new Set(['max']),
      familyPrefixes: new Set(['class-cohesion']),
    };

    expect(isKeyShaped('cohesion.lcom.max')).toBe(true);
    expect(staleLiterals([{ key: 'cohesion.lcom.max' }], renamed)).toEqual([
      { key: 'cohesion.lcom.max', reason: "family 'cohesion' is not in the catalog" },
    ]);
  });

  it('accepts a key of a known family and an allow-listed dotted literal', () => {
    const catalog = { baseKeys: new Set(['size.loc']), suffixes: new Set(['sum']), familyPrefixes: new Set(['size']) };

    expect(staleLiterals([{ key: 'size.loc.sum' }, { key: 'zoom.end' }], catalog, new Set(['zoom.end']))).toEqual([]);
  });
});
