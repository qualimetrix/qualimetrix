import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadCatalog, isKeyShaped, staleLiterals } from '../scripts/metric-key-catalog.mjs';
import { payloadReads } from '../scripts/payload-readers.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');
// Exact file/key exceptions: negative input cases, a renamed mock catalog and
// DOM/rule selectors. They are not metric keys and never exempt production.
const NOT_METRIC_KEYS = new Map([
  ['tests/hints.test.js', new Set(['unknown.metric', 'health.unknown'])],
  ['tests/metric-key-catalog.test.js', new Set(['class-cohesion.lcom', 'zoom.end', 'unknown.metric', 'health.unknown', 'circle.md-dot', 'architecture.layer-violation'])],
  ['tests/payload-contract.test.js', new Set(['circle.md-dot', 'architecture.layer-violation'])],
]);

function sources() {
  const found = [];
  for (const area of ['src', 'tests']) {
    for (const name of readdirSync(join(ROOT, area), { recursive: true }).filter(name => String(name).endsWith('.js'))) {
      const file = area + '/' + name;
      found.push({ file, ...payloadReads(readFileSync(join(ROOT, file), 'utf8')) });
    }
  }
  return found;
}

function payloadKeys() {
  const payload = JSON.parse(readFileSync(join(__dirname, 'fixtures/payload.json'), 'utf8'));
  const metrics = new Set();
  const findings = new Set();
  function visit(node) {
    for (const key of Object.keys(node.metrics)) metrics.add(key);
    for (const finding of node.violations) for (const key of Object.keys(finding)) findings.add(key);
    for (const child of node.children || []) visit(child);
  }
  visit(payload.tree);
  return { metricKeys: metrics, findingKeys: findings };
}

const READS = sources();

describe('metric-key catalog', () => {
  it('every metric literal and static property in src/ and tests/ is a catalog key', () => {
    const catalog = loadCatalog();
    const rows = READS;
    const stale = [];
    for (const row of rows) {
      for (const read of staleLiterals([...row.literals, ...row.metrics], catalog, NOT_METRIC_KEYS.get(row.file))) {
        stale.push({ file: row.file, ...read });
      }
    }
    expect(rows.flatMap(row => row.literals).length).toBeGreaterThan(0);
    expect(stale, stale.map(read => `${read.file}:${read.line} '${read.key}': ${read.reason}`).join('\n')).toEqual([]);
  });

  it('static viewer metric and finding reads exist in the native payload', () => {
    const keys = payloadKeys();
    expect(keys.metricKeys.size).toBeGreaterThan(0);
    expect(keys.findingKeys.size).toBeGreaterThan(0);
    const stale = READS.filter(row => row.file.startsWith('src/')).flatMap(row => [
      ...row.metrics.filter(read => !keys.metricKeys.has(read.key)).map(read => ({ file: row.file, kind: 'metric', ...read })),
      ...row.findings.filter(read => !keys.findingKeys.has(read.key)).map(read => ({ file: row.file, kind: 'finding', ...read })),
    ]);
    expect(stale).toEqual([]);
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
