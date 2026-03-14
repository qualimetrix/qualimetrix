import { describe, it, expect } from 'vitest';
import { createColorScale, getHealthColor, _interpolate, _parseColor } from '../src/color.js';

describe('createColorScale', () => {
  const scale = createColorScale('#ffffff');

  it('returns red-ish for score 0', () => {
    const color = scale(0);
    expect(color).toMatch(/^rgb\(/);
    // Should be close to rgb(220, 60, 60)
    const [r, g, b] = parseRgb(color);
    expect(r).toBeGreaterThan(180);
    expect(g).toBeLessThan(100);
  });

  it('returns blue-ish for score 100', () => {
    const color = scale(100);
    const [r, g, b] = parseRgb(color);
    expect(b).toBeGreaterThan(180);
    expect(r).toBeLessThan(100);
  });

  it('returns near-neutral for score 45', () => {
    const color = scale(45);
    const [r, g, b] = parseRgb(color);
    // Should be close to white
    expect(r).toBeGreaterThan(180);
    expect(g).toBeGreaterThan(180);
    expect(b).toBeGreaterThan(180);
  });

  it('handles null score', () => {
    expect(scale(null)).toBe('#888888');
  });

  it('handles NaN score', () => {
    expect(scale(NaN)).toBe('#888888');
  });

  it('clamps values outside 0-100', () => {
    expect(scale(-10)).toBe(scale(0));
    expect(scale(150)).toBe(scale(100));
  });
});

describe('getHealthColor', () => {
  const scale = createColorScale('#ffffff');

  it('uses specified metric', () => {
    const node = { metrics: { 'health.overall': 80, 'health.complexity': 30 } };
    const color1 = getHealthColor(node, 'health.overall', scale);
    const color2 = getHealthColor(node, 'health.complexity', scale);
    expect(color1).not.toBe(color2);
  });

  it('falls back to mi.avg when health.overall is missing', () => {
    const node = { metrics: { 'mi.avg': 75 } };
    const color = getHealthColor(node, 'health.overall', scale);
    expect(color).not.toBe('#888888');
  });

  it('returns grey for missing metric', () => {
    const node = { metrics: {} };
    const color = getHealthColor(node, 'health.overall', scale);
    expect(color).toBe('#888888');
  });
});

describe('parseColor', () => {
  it('parses hex colors', () => {
    expect(_parseColor('#ff0000')).toEqual([255, 0, 0]);
    expect(_parseColor('#00ff00')).toEqual([0, 255, 0]);
    expect(_parseColor('#ffffff')).toEqual([255, 255, 255]);
  });

  it('parses without hash', () => {
    expect(_parseColor('ff0000')).toEqual([255, 0, 0]);
  });
});

describe('interpolate', () => {
  it('returns start color at t=0', () => {
    expect(_interpolate([255, 0, 0], [0, 0, 255], 0)).toBe('rgb(255, 0, 0)');
  });

  it('returns end color at t=1', () => {
    expect(_interpolate([255, 0, 0], [0, 0, 255], 1)).toBe('rgb(0, 0, 255)');
  });

  it('returns midpoint at t=0.5', () => {
    const result = _interpolate([0, 0, 0], [200, 200, 200], 0.5);
    expect(result).toBe('rgb(100, 100, 100)');
  });
});

function parseRgb(str) {
  const match = str.match(/rgb\((\d+),\s*(\d+),\s*(\d+)\)/);
  return match ? [parseInt(match[1]), parseInt(match[2]), parseInt(match[3])] : [0, 0, 0];
}
