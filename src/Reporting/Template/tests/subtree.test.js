import { describe, it, expect } from 'vitest';
import { computeSubtreeMetrics, getWorstSubNamespaces } from '../src/subtree.js';

describe('computeSubtreeMetrics', () => {
  it('sets _subtree for a single leaf node', () => {
    const node = {
      name: 'Foo',
      type: 'class',
      metrics: { 'health.overall': 75, 'loc.sum': 100 },
      violationCountTotal: 3,
    };

    computeSubtreeMetrics(node);

    expect(node._subtree.loc).toBe(100);
    expect(node._subtree.violationCount).toBe(3);
    expect(node._subtree.metrics['health.overall']).toBe(75);
  });

  it('computes LOC-weighted average for flat namespace', () => {
    const node = {
      name: 'App',
      type: 'namespace',
      metrics: {},
      children: [
        { name: 'A', type: 'class', metrics: { 'health.overall': 80, 'loc.sum': 200 }, violationCountTotal: 1 },
        { name: 'B', type: 'class', metrics: { 'health.overall': 40, 'loc.sum': 800 }, violationCountTotal: 4 },
      ],
    };

    computeSubtreeMetrics(node);

    expect(node._subtree.loc).toBe(1000);
    expect(node._subtree.violationCount).toBe(5);
    // Weighted: (80*200 + 40*800) / 1000 = (16000 + 32000) / 1000 = 48
    expect(node._subtree.metrics['health.overall']).toBe(48);
  });

  it('computes nested hierarchy correctly', () => {
    const tree = {
      name: '<project>',
      type: 'project',
      metrics: {},
      children: [
        {
          name: 'App',
          type: 'namespace',
          metrics: {},
          children: [
            { name: 'Foo', type: 'class', metrics: { 'health.overall': 100, 'loc.sum': 500 }, violationCountTotal: 0 },
            {
              name: 'Sub',
              type: 'namespace',
              metrics: {},
              children: [
                { name: 'Bar', type: 'class', metrics: { 'health.overall': 20, 'loc.sum': 500 }, violationCountTotal: 10 },
              ],
            },
          ],
        },
      ],
    };

    computeSubtreeMetrics(tree);

    // Sub namespace: only Bar → health=20, loc=500
    const sub = tree.children[0].children[1];
    expect(sub._subtree.metrics['health.overall']).toBe(20);
    expect(sub._subtree.loc).toBe(500);

    // App namespace: Foo(100*500) + Sub(20*500) = 60000 / 1000 = 60
    const app = tree.children[0];
    expect(app._subtree.metrics['health.overall']).toBe(60);
    expect(app._subtree.loc).toBe(1000);
    expect(app._subtree.violationCount).toBe(10);

    // Project: same as App (single child)
    expect(tree._subtree.metrics['health.overall']).toBe(60);
  });

  it('handles missing health scores gracefully', () => {
    const node = {
      name: 'App',
      type: 'namespace',
      metrics: {},
      children: [
        { name: 'A', type: 'class', metrics: { 'loc.sum': 100 }, violationCountTotal: 0 },
        { name: 'B', type: 'class', metrics: { 'health.overall': 50, 'loc.sum': 100 }, violationCountTotal: 0 },
      ],
    };

    computeSubtreeMetrics(node);

    // Only B contributes to health.overall → 50
    expect(node._subtree.metrics['health.overall']).toBe(50);
    expect(node._subtree.loc).toBe(200);
  });

  it('handles zero LOC children', () => {
    const node = {
      name: 'App',
      type: 'namespace',
      metrics: {},
      children: [
        { name: 'A', type: 'class', metrics: { 'health.overall': 30, 'loc.sum': 0 }, violationCountTotal: 0 },
        { name: 'B', type: 'class', metrics: { 'health.overall': 70, 'loc.sum': 100 }, violationCountTotal: 0 },
      ],
    };

    computeSubtreeMetrics(node);

    // A has 0 LOC so doesn't contribute to weighted average
    expect(node._subtree.metrics['health.overall']).toBe(70);
  });

  it('weighted average: 100 LOC health=30 + 900 LOC health=90 ≈ 84', () => {
    const node = {
      name: 'Root',
      type: 'namespace',
      metrics: {},
      children: [
        { name: 'Small', type: 'class', metrics: { 'health.overall': 30, 'loc.sum': 100 }, violationCountTotal: 0 },
        { name: 'Large', type: 'class', metrics: { 'health.overall': 90, 'loc.sum': 900 }, violationCountTotal: 0 },
      ],
    };

    computeSubtreeMetrics(node);

    // (30*100 + 90*900) / 1000 = (3000 + 81000) / 1000 = 84
    expect(node._subtree.metrics['health.overall']).toBe(84);
  });
});

describe('getWorstSubNamespaces', () => {
  it('returns child namespaces sorted by subtree health ASC', () => {
    const node = {
      name: 'Root',
      type: 'project',
      children: [
        { name: 'Good', type: 'namespace', _subtree: { metrics: { 'health.overall': 90 }, loc: 500, violationCount: 0 } },
        { name: 'Bad', type: 'namespace', _subtree: { metrics: { 'health.overall': 20 }, loc: 300, violationCount: 5 } },
        { name: 'Ok', type: 'namespace', _subtree: { metrics: { 'health.overall': 60 }, loc: 200, violationCount: 2 } },
      ],
    };

    const worst = getWorstSubNamespaces(node, 3);
    expect(worst).toHaveLength(3);
    expect(worst[0].name).toBe('Bad');
    expect(worst[1].name).toBe('Ok');
    expect(worst[2].name).toBe('Good');
  });

  it('respects limit', () => {
    const node = {
      name: 'Root',
      type: 'project',
      children: [
        { name: 'A', type: 'namespace', _subtree: { metrics: { 'health.overall': 10 }, loc: 100, violationCount: 0 } },
        { name: 'B', type: 'namespace', _subtree: { metrics: { 'health.overall': 20 }, loc: 100, violationCount: 0 } },
        { name: 'C', type: 'namespace', _subtree: { metrics: { 'health.overall': 30 }, loc: 100, violationCount: 0 } },
      ],
    };

    const worst = getWorstSubNamespaces(node, 2);
    expect(worst).toHaveLength(2);
    expect(worst[0].name).toBe('A');
    expect(worst[1].name).toBe('B');
  });

  it('returns empty array when no children', () => {
    const node = { name: 'Leaf', type: 'class', metrics: {} };
    expect(getWorstSubNamespaces(node)).toEqual([]);
  });

  it('excludes non-namespace children', () => {
    const node = {
      name: 'Root',
      type: 'namespace',
      children: [
        { name: 'ClassA', type: 'class', _subtree: { metrics: { 'health.overall': 10 }, loc: 100, violationCount: 0 } },
        { name: 'Sub', type: 'namespace', _subtree: { metrics: { 'health.overall': 50 }, loc: 200, violationCount: 1 } },
      ],
    };

    const worst = getWorstSubNamespaces(node);
    expect(worst).toHaveLength(1);
    expect(worst[0].name).toBe('Sub');
  });

  it('excludes namespaces without the requested metric', () => {
    const node = {
      name: 'Root',
      type: 'project',
      children: [
        { name: 'A', type: 'namespace', _subtree: { metrics: {}, loc: 100, violationCount: 0 } },
        { name: 'B', type: 'namespace', _subtree: { metrics: { 'health.overall': 50 }, loc: 100, violationCount: 0 } },
      ],
    };

    const worst = getWorstSubNamespaces(node);
    expect(worst).toHaveLength(1);
    expect(worst[0].name).toBe('B');
  });
});
