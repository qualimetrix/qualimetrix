import { describe, it, expect } from 'vitest';
import { parseHash, generateHash } from '../src/hash.js';

describe('parseHash', () => {
  it('parses namespace hash', () => {
    const result = parseHash('#ns:App/Payment');
    expect(result).toEqual({ type: 'namespace', path: 'App\\Payment' });
  });

  it('parses class hash', () => {
    const result = parseHash('#cl:App/Payment/Processor');
    expect(result).toEqual({ type: 'class', path: 'App\\Payment\\Processor' });
  });

  it('handles encoded special characters', () => {
    const result = parseHash('#ns:App%2FPayment');
    expect(result).not.toBeNull();
  });

  it('returns null for empty hash', () => {
    expect(parseHash('')).toBeNull();
    expect(parseHash('#')).toBeNull();
  });

  it('returns null for invalid prefix', () => {
    expect(parseHash('#xx:App/Payment')).toBeNull();
  });

  it('returns null for missing colon', () => {
    expect(parseHash('#nsApp')).toBeNull();
  });
});

describe('generateHash', () => {
  it('generates namespace hash', () => {
    const node = { path: 'App\\Payment', type: 'namespace' };
    expect(generateHash(node)).toBe('#ns:App%2FPayment');
  });

  it('generates class hash', () => {
    const node = { path: 'App\\Payment\\Processor', type: 'class' };
    expect(generateHash(node)).toBe('#cl:App%2FPayment%2FProcessor');
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
