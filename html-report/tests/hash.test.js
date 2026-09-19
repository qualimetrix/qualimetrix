import { describe, it, expect } from 'vitest';
import { parseHash, generateHash } from '../src/hash.js';

describe('parseHash', () => {
  it('parses namespace hash', () => {
    const result = parseHash('#ns:App/Payment');
    expect(result).toEqual({ type: 'namespace', path: 'App\\Payment', view: 'treemap' });
  });

  it('parses class hash', () => {
    const result = parseHash('#cl:App/Payment/Processor');
    expect(result).toEqual({ type: 'class', path: 'App\\Payment\\Processor', view: 'treemap' });
  });

  it('parses Martin diagram hash', () => {
    const result = parseHash('#md:App/Payment');
    expect(result).toEqual({ type: 'namespace', path: 'App\\Payment', view: 'martin' });
  });

  it('handles encoded special characters', () => {
    const result = parseHash('#ns:App%2FPayment');
    expect(result).not.toBeNull();
    expect(result.view).toBe('treemap');
  });

  it('returns null for empty hash', () => {
    expect(parseHash('')).toBeNull();
    expect(parseHash('#')).toBeNull();
  });

  it('parses Martin diagram root hash (empty path)', () => {
    const result = parseHash('#md:');
    expect(result).toEqual({ type: null, path: '', view: 'martin' });
  });

  it('returns null for malformed percent-encoding', () => {
    expect(parseHash('#ns:%E0%A4%A')).toBeNull();
  });

  it('returns null for invalid prefix', () => {
    expect(parseHash('#xx:App/Payment')).toBeNull();
  });

  it('returns null for missing colon', () => {
    expect(parseHash('#nsApp')).toBeNull();
  });
});

describe('generateHash', () => {
  it('generates namespace hash for treemap', () => {
    const node = { path: 'App\\Payment', type: 'namespace' };
    expect(generateHash(node)).toBe('#ns:App%2FPayment');
    expect(generateHash(node, 'treemap')).toBe('#ns:App%2FPayment');
  });

  it('generates class hash', () => {
    const node = { path: 'App\\Payment\\Processor', type: 'class' };
    expect(generateHash(node)).toBe('#cl:App%2FPayment%2FProcessor');
  });

  it('generates Martin diagram hash', () => {
    const node = { path: 'App\\Payment', type: 'namespace' };
    expect(generateHash(node, 'martin')).toBe('#md:App%2FPayment');
  });

  it('generates Martin diagram hash for project/root node', () => {
    const node = { path: '', type: 'project' };
    expect(generateHash(node, 'martin')).toBe('#md:');
  });

  it('returns empty for project node', () => {
    expect(generateHash({ path: '', type: 'project' })).toBe('');
  });

  it('returns empty for "other" pseudo-node', () => {
    expect(generateHash({ path: '', type: 'other' })).toBe('');
  });

  it('returns empty for null/undefined node', () => {
    expect(generateHash(null)).toBe('');
    expect(generateHash(undefined)).toBe('');
  });
});
