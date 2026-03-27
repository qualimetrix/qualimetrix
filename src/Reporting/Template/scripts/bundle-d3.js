import { build } from 'vite';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));

// Create a temporary entry that re-exports only what we need from D3
import { writeFileSync, unlinkSync } from 'fs';

const entry = resolve(__dirname, '_d3-entry.js');
writeFileSync(entry, `
export { hierarchy, treemap, treemapSquarify } from 'd3-hierarchy';
export { select, selectAll, create } from 'd3-selection';
export { scaleLinear, scaleSqrt, scaleDiverging } from 'd3-scale';
export { rgb, hsl } from 'd3-color';
export { interpolateRgb } from 'd3-interpolate';
export { transition } from 'd3-transition';
export { axisBottom, axisLeft } from 'd3-axis';
export { format } from 'd3-format';
export { line } from 'd3-shape';
`);

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
