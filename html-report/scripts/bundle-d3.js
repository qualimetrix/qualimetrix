import { build } from 'vite';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';
import { d3Imports } from './d3-imports.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));

import { writeFileSync, unlinkSync } from 'fs';

const entry = resolve(__dirname, '_d3-entry.js');
const exports = Object.entries(d3Imports())
  .map(([module, names]) => `export { ${names.join(', ')} } from '${module}';`)
  .join('\n');
writeFileSync(entry, `${exports}\n`);

await build({
  configFile: false,
  build: {
    lib: {
      entry,
      name: 'd3',
      fileName: () => 'd3.min.js',
      formats: ['iife'],
    },
    outDir: resolve(__dirname, '..', 'dist'),
    emptyOutDir: false,
    minify: 'esbuild',
  },
});

unlinkSync(entry);
