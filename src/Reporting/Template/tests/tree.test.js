import { describe, it, expect } from 'vitest';
import { findNode, collectLeaves, getWorstOffenders, aggregateSmallNodes, getLoc } from '../src/tree.js';

describe('findNode', () => {
  const tree = {
    name: '<project>',
    path: '',
    type: 'project',
    children: [
      {
        name: 'App',
        path: 'App',
        type: 'namespace',
        children: [
          {
            name: 'Payment',
            path: 'App\\Payment',
            type: 'namespace',
            children: [
              { name: 'Processor', path: 'App\\Payment\\Processor', type: 'class', metrics: {} },
            ],
          },
        ],
      },
    ],
  };

  it('finds root node', () => {
    expect(findNode(tree, '')).toBe(tree);
  });

  it('finds nested namespace', () => {
    const node = findNode(tree, 'App\\Payment');
    expect(node).not.toBeNull();
    expect(node.name).toBe('Payment');
  });

  it('finds class node', () => {
    const node = findNode(tree, 'App\\Payment\\Processor');
    expect(node).not.toBeNull();
    expect(node.type).toBe('class');
  });

  it('returns null for non-existent path', () => {
    expect(findNode(tree, 'NonExistent')).toBeNull();
  });
});

describe('collectLeaves', () => {
  it('returns self for leaf node', () => {
    const leaf = { name: 'A', type: 'class', metrics: {} };
    expect(collectLeaves(leaf)).toEqual([leaf]);
  });

  it('collects leaves from nested tree', () => {
    const tree = {
      name: 'root',
      type: 'namespace',
      children: [
        { name: 'A', type: 'class', metrics: {} },
        {
          name: 'sub',
          type: 'namespace',
          children: [
            { name: 'B', type: 'class', metrics: {} },
          ],
        },
      ],
    };
    const leaves = collectLeaves(tree);
    expect(leaves).toHaveLength(2);
    expect(leaves.map(l => l.name)).toEqual(['A', 'B']);
  });
});

describe('getWorstOffenders', () => {
  const tree = {
    name: 'root',
    type: 'namespace',
    children: [
      { name: 'A', type: 'class', metrics: { 'health.overall': 30 }, violationCountTotal: 5 },
      { name: 'B', type: 'class', metrics: { 'health.overall': 80 }, violationCountTotal: 1 },
      { name: 'C', type: 'class', metrics: { 'health.overall': 10 }, violationCountTotal: 8 },
      { name: 'D', type: 'class', metrics: { 'health.overall': 50 }, violationCountTotal: 3 },
    ],
  };

  it('returns worst N classes sorted ASC by health score', () => {
    const worst = getWorstOffenders(tree, 2);
    expect(worst).toHaveLength(2);
    expect(worst[0].name).toBe('C');
    expect(worst[1].name).toBe('A');
  });

  it('excludes non-class nodes', () => {
    const mixed = {
      name: 'root',
      type: 'namespace',
      children: [
        { name: 'Sub', type: 'namespace', metrics: { 'health.overall': 5 }, children: [] },
        { name: 'A', type: 'class', metrics: { 'health.overall': 30 }, violationCountTotal: 1 },
      ],
    };
    const worst = getWorstOffenders(mixed);
    expect(worst).toHaveLength(1);
    expect(worst[0].name).toBe('A');
  });
});

describe('aggregateSmallNodes', () => {
  it('returns empty array for empty input', () => {
    expect(aggregateSmallNodes([], 1000, 100)).toEqual([]);
  });

  it('does not aggregate when all nodes are visible', () => {
    const children = [
      { name: 'A', metrics: { 'loc.sum': 500 }, violationCountTotal: 0 },
      { name: 'B', metrics: { 'loc.sum': 500 }, violationCountTotal: 0 },
    ];
    const result = aggregateSmallNodes(children, 10000, 1000);
    expect(result).toEqual(children);
  });

  it('aggregates nodes below visibility threshold', () => {
    const children = [
      { name: 'Big', metrics: { 'loc.sum': 900 }, violationCountTotal: 0 },
      { name: 'Tiny1', metrics: { 'loc.sum': 5 }, violationCountTotal: 1 },
      { name: 'Tiny2', metrics: { 'loc.sum': 5 }, violationCountTotal: 2 },
      { name: 'Tiny3', metrics: { 'loc.sum': 5 }, violationCountTotal: 0 },
    ];
    // Total area = 1000px², total LOC = 915
    // Big: (900/915)*1000 ≈ 983px² > 400 → visible
    // Each tiny: (5/915)*1000 ≈ 5.5px² < 400 → aggregated
    const result = aggregateSmallNodes(children, 1000, 915);
    expect(result).toHaveLength(2); // Big + Other

    const other = result.find(n => n._isOther);
    expect(other).toBeDefined();
    expect(other.name).toBe('Other (3 items)');
    expect(other.children).toHaveLength(3);
    expect(other.violationCountTotal).toBe(3);
  });

  it('does not aggregate single small node', () => {
    const children = [
      { name: 'Big', metrics: { 'loc.sum': 900 }, violationCountTotal: 0 },
      { name: 'Small', metrics: { 'loc.sum': 10 }, violationCountTotal: 1 },
    ];
    const result = aggregateSmallNodes(children, 1000, 910);
    expect(result).toEqual(children);
  });
});

describe('getLoc', () => {
  it('returns loc.sum from metrics', () => {
    expect(getLoc({ metrics: { 'loc.sum': 100 } })).toBe(100);
  });

  it('returns 0 for missing metrics', () => {
    expect(getLoc({})).toBe(0);
    expect(getLoc({ metrics: {} })).toBe(0);
  });

  it('returns 0 for zero or negative LOC', () => {
    expect(getLoc({ metrics: { 'loc.sum': 0 } })).toBe(0);
    expect(getLoc({ metrics: { 'loc.sum': -5 } })).toBe(0);
  });
});
