import { afterEach, describe, expect, it } from 'vitest';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { d3Imports } from '../scripts/d3-imports.mjs';

const scratchDirectories = [];

function sourceDirectory(files) {
  const directory = mkdtempSync(join(tmpdir(), 'qmx-d3-imports-'));
  scratchDirectories.push(directory);
  for (const [name, contents] of Object.entries(files)) {
    writeFileSync(join(directory, name), contents);
  }
  return directory;
}

afterEach(() => {
  for (const directory of scratchDirectories.splice(0)) {
    rmSync(directory, { recursive: true });
  }
});

describe('D3 imports', () => {
  it('collects sorted source names from multiline and aliased named imports', () => {
    const directory = sourceDirectory({
      './viewer.js': `
        import {
          treemap as layout,
          hierarchy,
        } from 'd3-hierarchy';
        import { select } from 'd3-selection';
        import ignored from 'viewer-support';
      `,
      './other.js': "import { treemapSquarify } from 'd3-hierarchy';",
      './ignored.txt': "import defaultExport from 'd3-format';",
    });

    expect(d3Imports(directory)).toEqual({
      'd3-hierarchy': ['hierarchy', 'treemap', 'treemapSquarify'],
      'd3-selection': ['select'],
    });
  });

  it.each([
    ["import scale from 'd3-scale';", 'default'],
    ["import * as shape from 'd3-shape';", 'namespace'],
  ])('refuses a %s D3 import', (source, kind) => {
    const directory = sourceDirectory({ './viewer.js': source });

    expect(() => d3Imports(directory)).toThrow(`D3 ${kind} imports are not supported`);
  });

  it('matches the complete viewer D3 surface', () => {
    expect(d3Imports()).toEqual({
      'd3-axis': ['axisBottom', 'axisLeft'],
      'd3-format': ['format'],
      'd3-hierarchy': ['hierarchy', 'treemap', 'treemapSquarify'],
      'd3-scale': ['scaleLinear', 'scaleSqrt'],
      'd3-selection': ['select'],
      'd3-shape': ['line'],
    });
  });
});
