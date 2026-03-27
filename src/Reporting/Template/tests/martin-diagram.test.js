import { describe, it, expect } from 'vitest';
import {
  collectNamespacesWithMetrics,
  canDrillDown,
  createRadiusScale,
  createDistanceColorScale,
} from '../src/martin-diagram.js';

describe('collectNamespacesWithMetrics', () => {
  it('collects namespace children with both instability and abstractness', () => {
    const node = {
      children: [
        { type: 'namespace', name: 'A', metrics: { instability: 0.5, abstractness: 0.3, distance: 0.2, 'loc.sum': 100 } },
        { type: 'namespace', name: 'B', metrics: { instability: 0.8, abstractness: 0.1, distance: 0.1, 'loc.sum': 200 } },
      ],
    };

    const result = collectNamespacesWithMetrics(node);
    expect(result).toHaveLength(2);
    expect(result[0].instability).toBe(0.5);
    expect(result[0].abstractness).toBe(0.3);
    expect(result[0].distance).toBe(0.2);
    expect(result[0].loc).toBe(100);
    expect(result[1].node.name).toBe('B');
  });

  it('excludes class children', () => {
    const node = {
      children: [
        { type: 'class', name: 'Foo', metrics: { instability: 0.5, abstractness: 0.3 } },
        { type: 'namespace', name: 'A', metrics: { instability: 0.5, abstractness: 0.3 } },
      ],
    };

    const result = collectNamespacesWithMetrics(node);
    expect(result).toHaveLength(1);
    expect(result[0].node.name).toBe('A');
  });

  it('excludes namespaces without instability', () => {
    const node = {
      children: [
        { type: 'namespace', name: 'A', metrics: { abstractness: 0.3, 'loc.sum': 100 } },
      ],
    };

    expect(collectNamespacesWithMetrics(node)).toHaveLength(0);
  });

  it('excludes namespaces without abstractness', () => {
    const node = {
      children: [
        { type: 'namespace', name: 'A', metrics: { instability: 0.5, 'loc.sum': 100 } },
      ],
    };

    expect(collectNamespacesWithMetrics(node)).toHaveLength(0);
  });

  it('computes distance when not provided', () => {
    const node = {
      children: [
        { type: 'namespace', name: 'A', metrics: { instability: 0.3, abstractness: 0.5 } },
      ],
    };

    const result = collectNamespacesWithMetrics(node);
    expect(result[0].distance).toBeCloseTo(0.2);
  });

  it('returns empty array for node without children', () => {
    expect(collectNamespacesWithMetrics({})).toEqual([]);
    expect(collectNamespacesWithMetrics({ children: [] })).toEqual([]);
  });

  it('excludes namespaces with NaN instability', () => {
    const node = {
      children: [
        { type: 'namespace', name: 'A', metrics: { instability: NaN, abstractness: 0.3 } },
      ],
    };
    expect(collectNamespacesWithMetrics(node)).toHaveLength(0);
  });

  it('excludes namespaces with Infinity abstractness', () => {
    const node = {
      children: [
        { type: 'namespace', name: 'A', metrics: { instability: 0.5, abstractness: Infinity } },
      ],
    };
    expect(collectNamespacesWithMetrics(node)).toHaveLength(0);
  });

  it('defaults LOC to 0 when not present', () => {
    const node = {
      children: [
        { type: 'namespace', name: 'A', metrics: { instability: 0.5, abstractness: 0.3 } },
      ],
    };

    const result = collectNamespacesWithMetrics(node);
    expect(result[0].loc).toBe(0);
  });
});

describe('canDrillDown', () => {
  it('returns true when namespace has child namespaces with metrics', () => {
    const node = {
      children: [
        {
          type: 'namespace', name: 'Sub',
          metrics: { instability: 0.5, abstractness: 0.3 },
          children: [
            { type: 'namespace', name: 'SubSub', metrics: { instability: 0.2, abstractness: 0.8 } },
          ],
        },
      ],
    };

    // The child "Sub" can drill down because it has namespace children with metrics
    expect(canDrillDown(node.children[0])).toBe(true);
  });

  it('returns false for leaf namespace (only class children)', () => {
    const node = {
      type: 'namespace', name: 'Leaf',
      metrics: { instability: 0.5, abstractness: 0.3 },
      children: [
        { type: 'class', name: 'Foo', metrics: {} },
      ],
    };

    expect(canDrillDown(node)).toBe(false);
  });

  it('returns false for namespace without children', () => {
    expect(canDrillDown({ children: [] })).toBe(false);
    expect(canDrillDown({})).toBe(false);
  });
});

describe('createRadiusScale', () => {
  it('returns min radius for empty array', () => {
    const scale = createRadiusScale([]);
    expect(scale(100)).toBe(4);
  });

  it('returns mid radius for single value', () => {
    const scale = createRadiusScale([500]);
    expect(scale(500)).toBe(17); // (4 + 30) / 2
  });

  it('maps min LOC to min radius', () => {
    const scale = createRadiusScale([10, 100, 1000]);
    expect(scale(10)).toBe(4);
  });

  it('maps max LOC to max radius', () => {
    const scale = createRadiusScale([10, 100, 1000]);
    expect(scale(1000)).toBe(30);
  });

  it('produces intermediate values', () => {
    const scale = createRadiusScale([0, 1000]);
    const mid = scale(500);
    expect(mid).toBeGreaterThan(4);
    expect(mid).toBeLessThan(30);
  });
});

describe('createDistanceColorScale', () => {
  it('returns green for distance 0', () => {
    const scale = createDistanceColorScale();
    expect(scale(0)).toMatch(/rgb/);
  });

  it('returns red for distance 0.5+', () => {
    const scale = createDistanceColorScale();
    const color = scale(0.5);
    // Should be close to #dc3545 = rgb(220, 53, 69)
    expect(color).toMatch(/rgb/);
  });

  it('returns different colors for different distances', () => {
    const scale = createDistanceColorScale();
    const c0 = scale(0);
    const c1 = scale(0.5);
    expect(c0).not.toBe(c1);
  });
});
