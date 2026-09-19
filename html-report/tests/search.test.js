import { describe, it, expect } from 'vitest';
import { buildIndex, filterNodes } from '../src/search.js';

describe('buildIndex', () => {
  it('builds flat index from tree', () => {
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
            { name: 'Processor', path: 'App\\Processor', type: 'class' },
          ],
        },
      ],
    };

    const index = buildIndex(tree);
    expect(index).toHaveLength(3);
    expect(index.map(e => e.name)).toContain('App');
    expect(index.map(e => e.name)).toContain('Processor');
  });

  it('excludes "other" pseudo-nodes', () => {
    const tree = {
      name: 'root',
      path: '',
      type: 'project',
      children: [
        { name: 'Other (5 items)', path: '', type: 'other' },
      ],
    };

    const index = buildIndex(tree);
    expect(index).toHaveLength(1); // Only root
  });
});

describe('filterNodes', () => {
  const index = [
    { name: 'PaymentProcessor', path: 'App\\Payment\\PaymentProcessor', type: 'class' },
    { name: 'Payment', path: 'App\\Payment', type: 'namespace' },
    { name: 'RefundHandler', path: 'App\\Payment\\RefundHandler', type: 'class' },
    { name: 'UserService', path: 'App\\User\\UserService', type: 'class' },
  ];

  it('matches by name substring', () => {
    const results = filterNodes(index, 'payment');
    expect(results.length).toBeGreaterThanOrEqual(2);
    expect(results.map(r => r.name)).toContain('Payment');
    expect(results.map(r => r.name)).toContain('PaymentProcessor');
  });

  it('is case insensitive', () => {
    const results = filterNodes(index, 'PAYMENT');
    expect(results.length).toBeGreaterThanOrEqual(2);
  });

  it('exact name match ranks first', () => {
    const results = filterNodes(index, 'Payment');
    expect(results[0].name).toBe('Payment');
  });

  it('matches by path', () => {
    const results = filterNodes(index, 'User');
    expect(results.map(r => r.name)).toContain('UserService');
  });

  it('limits results to 20', () => {
    const bigIndex = Array.from({ length: 50 }, (_, i) => ({
      name: `Class${i}`,
      path: `App\\Class${i}`,
      type: 'class',
    }));
    const results = filterNodes(bigIndex, 'Class');
    expect(results.length).toBeLessThanOrEqual(20);
  });
});
