import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadCatalog, isCatalogMember, isFamilyShaped } from '../scripts/metric-key-catalog.mjs';
import { parseAst } from 'rollup/dist/parseAst.js';
import { walk } from 'estree-walker';

// Regression guard for the hand-written metric-key literals in src/*.js
// (`'size.loc.sum'`, `'health.overall'`, ...): a hardcoded key that drifts
// from MetricName.php — a typo, a rename the JS side missed — is a silent
// dashboard breakage (metric shows as 0/blank), not a build or type error.
// This test re-derives every family-shaped literal the same way
// src/Reporting/Template/scripts/collect-metric-keys.mjs does and asserts each
// one is a real catalog member from
// finding-gate/enumeration-js-metric-keys.tsv. Keeping this as a test guard
// preserves the hand-written runtime bundle while still detecting vocabulary
// drift.

const __dirname = dirname(fileURLToPath(import.meta.url));
const SRC_DIR = resolve(__dirname, '..', 'src');

function collectSrcLiterals(catalog) {
  const found = [];
  for (const name of readdirSync(SRC_DIR).filter((n) => n.endsWith('.js'))) {
    const path = join(SRC_DIR, name);
    const source = readFileSync(path, 'utf8');
    const ast = parseAst(source, { ecmaVersion: 2023, sourceType: 'module' });
    walk(ast, {
      enter(node) {
        if (node.type === 'Literal' && typeof node.value === 'string' && isFamilyShaped(node.value, catalog)) {
          const line = source.slice(0, node.start).split('\n').length;
          found.push({ file: name, line, key: node.value });
        }
      },
    });
  }
  return found;
}

describe('metric-key catalog', () => {
  it('every family-shaped string literal in src/*.js is a real MetricName catalog key', () => {
    const catalog = loadCatalog();
    const literals = collectSrcLiterals(catalog);

    expect(literals.length).toBeGreaterThan(0);

    const unknown = literals.filter((l) => !isCatalogMember(l.key, catalog));
    expect(unknown, unknown.map((l) => `${l.file}:${l.line} '${l.key}'`).join('\n')).toEqual([]);
  });
});
