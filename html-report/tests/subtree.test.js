import { describe, it, expect } from 'vitest';
import { getWorstSubNamespaces } from '../src/subtree.js';

describe('getWorstSubNamespaces', () => {
  it('returns child namespaces sorted by subtree health ASC', () => {
    const node = {
      name: 'Root',
      type: 'project',
      children: [
        { name: 'Good', type: 'namespace', metrics: { 'health.overall': 90 } },
        { name: 'Bad', type: 'namespace', metrics: { 'health.overall': 20 } },
        { name: 'Ok', type: 'namespace', metrics: { 'health.overall': 60 } },
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
        { name: 'A', type: 'namespace', metrics: { 'health.overall': 10 } },
        { name: 'B', type: 'namespace', metrics: { 'health.overall': 20 } },
        { name: 'C', type: 'namespace', metrics: { 'health.overall': 30 } },
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
        { name: 'ClassA', type: 'class', metrics: { 'health.overall': 10 } },
        { name: 'Sub', type: 'namespace', metrics: { 'health.overall': 50 } },
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
        { name: 'A', type: 'namespace', metrics: {} },
        { name: 'B', type: 'namespace', metrics: { 'health.overall': 50 } },
      ],
    };

    const worst = getWorstSubNamespaces(node);
    expect(worst).toHaveLength(1);
    expect(worst[0].name).toBe('B');
  });
});


it('keeps the published fractional namespace score despite differently weighted children', () => {
  const namespace = { name: 'Cx', type: 'namespace', metrics: { 'health.overall': 42.07, 'size.loc.sum': 1000 }, violationCountTotal: 5, children: [
    { type: 'class', metrics: { 'health.overall': 80, 'size.class-loc': 200 } },
    { type: 'class', metrics: { 'health.overall': 40, 'size.class-loc': 800 } },
  ] };
  expect(getWorstSubNamespaces({ children: [namespace] })).toEqual([namespace]);
  expect(namespace.metrics['health.overall']).toBe(42.07);
  expect(namespace.metrics['size.loc.sum']).toBe(1000);
  expect(namespace.violationCountTotal).toBe(5);
});
