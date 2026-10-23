import { defineConfig } from 'vite';
import { resolve } from 'path';
import { d3Imports } from './scripts/d3-imports.mjs';

const d3Modules = Object.keys(d3Imports());

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
      external: d3Modules,
      output: {
        globals: Object.fromEntries(d3Modules.map(module => [module, 'd3'])),
      },
    },
  },
  test: {
    include: ['tests/**/*.test.js'],
    environment: 'node',
  },
});
