// Writes finding-gate/enumeration-js-metric-keys.tsv:
// every family-shaped string literal in src/Reporting/Template/{src,tests}/*.js,
// separated into code/comment/test buckets, checked against the MetricName
// catalog. Run by hand (`node scripts/collect-metric-keys.mjs`) — this is a
// one-off population script, not part of `npm test` or `npm run build`.
import { writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { collectAll } from './metric-key-catalog.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const OUT_FILE = resolve(
  __dirname,
  '..', '..', '..', '..',
  'finding-gate/enumeration-js-metric-keys.tsv',
);

const HEADER = `# method: rollup's bundled AST parser (rollup/dist/parseAst.js, the same
# parser vite's build pipeline runs on this template) walks each src/Reporting/Template
# src/*.js and tests/*.js file for string Literal nodes whose value has the
# "family.rest" shape, where "family" is a first-segment prefix drawn from the
# MetricName.php catalog (complexity, coupling, cohesion, design, size,
# maintainability, security, code-smell, health). Comments are found by
# masking every AST-identified string/template-literal source range with
# spaces, then regex-scanning what remains for // and /* */ regions.
# catalog: MetricName.php public const string values (82) + AggregationStrategy.php
# enum case values as aggregation suffixes (sum, avg, max, min, count, p95, p5)
# + HealthDimensionCatalog.php dimension keys (6, health.*).
# what this does not see: computed/concatenated keys ("size." + metric),
# template-literal interpolation values, any literal whose first segment is
# not one of the known family prefixes above (so a key entirely without a
# recognized family, or a typo in the family segment itself, is invisible),
# and any file outside src/Reporting/Template/{src,tests}/*.js (report.html,
# dev.html, dist/*.js are not scanned).
bucket\tfile\tline\tkey\tin_catalog
`;

const { rows } = collectAll();
const lines = rows.map((row) => `${row.bucket}\t${row.file}\t${row.line}\t${row.key}\t${row.inCatalog ? 'yes' : 'no'}`);
writeFileSync(OUT_FILE, HEADER + lines.join('\n') + '\n');

console.log(`Wrote ${rows.length} rows to ${OUT_FILE}`);
const byBucket = {};
for (const row of rows) {
  byBucket[row.bucket] = (byBucket[row.bucket] ?? 0) + 1;
}
console.log(byBucket);
const notInCatalog = rows.filter((row) => !row.inCatalog);
if (notInCatalog.length > 0) {
  console.log(`${notInCatalog.length} family-shaped literal(s) NOT in catalog:`);
  for (const row of notInCatalog) {
    console.log(`  ${row.file}:${row.line} [${row.bucket}] ${row.key}`);
  }
}
