import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  build: {
    lib: {
      entry: resolve(__dirname, 'src/main.js'),
      name: 'AimdReport',
      fileName: () => 'report.min.js',
      formats: ['iife'],
    },
    outDir: 'dist',
    emptyOutDir: false,
    minify: 'esbuild',
    rollupOptions: {
      external: [
        'd3-hierarchy',
        'd3-selection',
        'd3-scale',
        'd3-color',
        'd3-interpolate',
        'd3-transition',
      ],
      output: {
        globals: {
          'd3-hierarchy': 'd3',
          'd3-selection': 'd3',
          'd3-scale': 'd3',
          'd3-color': 'd3',
          'd3-interpolate': 'd3',
          'd3-transition': 'd3',
        },
      },
    },
  },
  test: {
    include: ['tests/**/*.test.js'],
    environment: 'node',
  },
});
