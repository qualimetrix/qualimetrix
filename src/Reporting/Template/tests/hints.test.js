import { describe, it, expect, beforeAll } from 'vitest';
import {
  initHints,
  getMetricHint,
  getHealthHint,
  METRIC_HINTS,
  HEALTH_DECOMPOSITION,
  resolveBaseKey,
  matchRange,
} from '../src/hints.js';

// ---------------------------------------------------------------------------
// Fixture: matches MetricHintProvider::exportForHtml() output
// ---------------------------------------------------------------------------

const hintsFixture = {
  metricHints: {
    ccn: {
      label: 'Cyclomatic Complexity', // Matches PHP HTML_LABELS override
      ranges: [
        { max: 4, text: 'Simple, easy to test' },
        { max: 10, text: 'Moderate complexity' },
        { max: 20, text: 'Complex, consider refactoring' },
        { max: 50, text: 'Very complex, hard to maintain' },
        { above: true, text: 'Extremely complex' },
      ],
      formatTemplate: null,
    },
    cognitive: {
      label: 'Cognitive Complexity',
      ranges: [
        { max: 5, text: 'Simple, easy to understand' },
        { max: 15, text: 'Moderate complexity' },
        { max: 30, text: 'Complex, hard to follow' },
        { above: true, text: 'Very hard to follow' },
      ],
      formatTemplate: null,
    },
    npath: {
      label: 'NPath Complexity',
      ranges: [
        { max: 20, text: 'Simple, few execution paths' },
        { max: 200, text: 'Moderate path count' },
        { max: 1000, text: 'Many execution paths' },
        { above: true, text: 'Explosive path count' },
      ],
      formatTemplate: null,
    },
    lcom: {
      label: 'LCOM4',
      ranges: [
        { max: 1, text: 'Cohesive — single responsibility' },
        { max: 3, text: 'Moderate cohesion' },
        { max: 5, text: 'Low cohesion, consider splitting' },
        { above: true, text: 'Very low cohesion' },
      ],
      formatTemplate: '{value} disconnected group{plural}',
    },
    tcc: {
      label: 'Tight Class Cohesion',
      ranges: [
        { max: 0.29, text: 'Low method interconnection' },
        { max: 0.49, text: 'Moderate cohesion' },
        { above: true, text: 'Good cohesion' },
      ],
      formatTemplate: null,
    },
    lcc: {
      label: 'Loose Class Cohesion',
      ranges: [
        { max: 0.29, text: 'Low cohesion (incl. transitive)' },
        { max: 0.49, text: 'Moderate cohesion' },
        { above: true, text: 'Good cohesion' },
      ],
      formatTemplate: null,
    },
    wmc: {
      label: 'Weighted Methods per Class',
      ranges: [
        { max: 20, text: 'Manageable class' },
        { max: 50, text: 'Large class' },
        { max: 80, text: 'Very large class' },
        { above: true, text: 'Excessive — consider splitting' },
      ],
      formatTemplate: null,
    },
    cbo: {
      label: 'Coupling Between Objects',
      ranges: [
        { max: 7, text: 'Normal coupling' },
        { max: 14, text: 'Moderate coupling' },
        { max: 20, text: 'High coupling' },
        { above: true, text: 'Very high coupling' },
      ],
      formatTemplate: null,
    },
    instability: {
      label: 'Instability',
      ranges: [
        { max: 0.09, text: 'Maximally stable' },
        { max: 0.29, text: 'Stable' },
        { max: 0.7, text: 'Balanced' },
        { max: 0.9, text: 'Unstable' },
        { above: true, text: 'Maximally unstable' },
      ],
      formatTemplate: null,
    },
    abstractness: {
      label: 'Abstractness',
      ranges: [
        { max: 0.09, text: 'All concrete' },
        { max: 0.5, text: 'Mostly concrete' },
        { max: 0.9, text: 'Mostly abstract' },
        { above: true, text: 'All abstract' },
      ],
      formatTemplate: null,
    },
    distance: {
      label: 'Distance',
      ranges: [
        { max: 0.1, text: 'On main sequence' },
        { max: 0.3, text: 'Acceptable balance' },
        { above: true, text: 'Off balance' },
      ],
      formatTemplate: null,
    },
    classRank: {
      label: 'ClassRank',
      ranges: [
        { max: 0.009, text: 'Peripheral class' },
        { max: 0.02, text: 'Moderate importance' },
        { max: 0.05, text: 'Important hub' },
        { above: true, text: 'Critical coupling point' },
      ],
      formatTemplate: null,
    },
    dit: {
      label: 'Depth of Inheritance Tree',
      ranges: [
        { max: 0, text: 'Root class' },
        { max: 3, text: 'Normal depth' },
        { max: 6, text: 'Deep hierarchy' },
        { above: true, text: 'Fragile hierarchy' },
      ],
      formatTemplate: null,
    },
    noc: {
      label: 'Number of Children',
      ranges: [
        { max: 0, text: 'Leaf class' },
        { max: 5, text: 'Normal inheritance' },
        { max: 10, text: 'Many subclasses' },
        { above: true, text: 'Heavy base class' },
      ],
      formatTemplate: null,
    },
    rfc: {
      label: 'Response for a Class',
      ranges: [
        { max: 20, text: 'Simple interface' },
        { max: 50, text: 'Moderate interface' },
        { max: 100, text: 'Complex interface' },
        { above: true, text: 'Very complex interface' },
      ],
      formatTemplate: null,
    },
    methodCount: {
      label: 'Method Count',
      ranges: [
        { max: 10, text: 'Focused class' },
        { max: 20, text: 'Large class' },
        { max: 30, text: 'Very large class' },
        { above: true, text: 'God Class territory' },
      ],
      formatTemplate: null,
    },
    propertyCount: {
      label: 'Property Count',
      ranges: [
        { max: 10, text: 'Normal' },
        { max: 15, text: 'Large' },
        { max: 20, text: 'Heavy' },
        { above: true, text: 'Excessive' },
      ],
      formatTemplate: null,
    },
    'classCount.sum': {
      label: 'Class Count',
      ranges: [
        { max: 10, text: 'Focused namespace' },
        { max: 15, text: 'Moderate namespace' },
        { max: 25, text: 'Large namespace' },
        { above: true, text: 'Bloated namespace' },
      ],
      formatTemplate: null,
    },
    mi: {
      label: 'Maintainability Index',
      ranges: [
        { max: 19, text: 'Critical — very hard to maintain' },
        { max: 39, text: 'Poor — refactoring recommended' },
        { max: 64, text: 'Moderate — could benefit from simplification' },
        { max: 84, text: 'Good maintainability' },
        { above: true, text: 'Excellent maintainability' },
      ],
      formatTemplate: null,
    },
    'typeCoverage.pct': {
      label: 'Type coverage',
      ranges: [
        { max: 49, text: 'Low type coverage' },
        { max: 79, text: 'Moderate type coverage' },
        { above: true, text: 'Good type coverage' },
      ],
      formatTemplate: null,
    },
    'typeCoverage.param': {
      label: 'Parameter Type Coverage',
      ranges: [
        { max: 49, text: 'Low coverage' },
        { max: 79, text: 'Moderate coverage' },
        { above: true, text: 'Good coverage' },
      ],
      formatTemplate: null,
    },
    'typeCoverage.return': {
      label: 'Return Type Coverage',
      ranges: [
        { max: 49, text: 'Low coverage' },
        { max: 79, text: 'Moderate coverage' },
        { above: true, text: 'Good coverage' },
      ],
      formatTemplate: null,
    },
    'typeCoverage.property': {
      label: 'Property Type Coverage',
      ranges: [
        { max: 49, text: 'Low coverage' },
        { max: 79, text: 'Moderate coverage' },
        { above: true, text: 'Good coverage' },
      ],
      formatTemplate: null,
    },
  },
  healthDecomposition: {
    'health.complexity': {
      inputs: [
        { key: 'ccn.avg', altKey: 'ccn', label: 'CCN avg', ideal: '1-3', direction: 'lower' },
        { key: 'cognitive.avg', altKey: 'cognitive', label: 'Cognitive avg', ideal: '0-4', direction: 'lower' },
        { key: 'ccn.p95', altKey: null, label: 'CCN p95', ideal: '≤25', direction: 'lower' },
        { key: 'cognitive.p95', altKey: null, label: 'Cognitive p95', ideal: '≤20', direction: 'lower' },
      ],
    },
    'health.cohesion': {
      inputs: [
        { key: 'tcc.avg', altKey: 'tcc', label: 'TCC', ideal: '1.0', direction: 'higher' },
        { key: 'lcom.avg', altKey: 'lcom', label: 'LCOM', ideal: '1', direction: 'lower' },
      ],
    },
    'health.coupling': {
      inputs: [
        { key: 'cbo.avg', altKey: 'cbo', label: 'CBO', ideal: '0-7', direction: 'lower' },
        { key: 'distance.avg', altKey: 'distance', label: 'Distance', ideal: '0.0', direction: 'lower' },
      ],
    },
    'health.typing': {
      inputs: [
        { key: 'typeCoverage.pct', altKey: null, label: 'Coverage', ideal: '100%', direction: 'higher' },
      ],
    },
    'health.maintainability': {
      inputs: [
        { key: 'mi.avg', altKey: 'mi', label: 'MI', ideal: '85+', direction: 'higher' },
      ],
    },
    'health.overall': {
      inputs: [],
    },
  },
};

// Initialize hints before all tests
beforeAll(() => {
  initHints(hintsFixture);
});

// ---------------------------------------------------------------------------
// resolveBaseKey
// ---------------------------------------------------------------------------

describe('resolveBaseKey', () => {
  it('returns exact match', () => {
    expect(resolveBaseKey('ccn')).toBe('ccn');
    expect(resolveBaseKey('lcom')).toBe('lcom');
    expect(resolveBaseKey('tcc')).toBe('tcc');
  });

  it('strips .avg suffix', () => {
    expect(resolveBaseKey('ccn.avg')).toBe('ccn');
    expect(resolveBaseKey('mi.avg')).toBe('mi');
  });

  it('strips .max suffix', () => {
    expect(resolveBaseKey('ccn.max')).toBe('ccn');
    expect(resolveBaseKey('lcom.max')).toBe('lcom');
  });

  it('strips .min suffix', () => {
    expect(resolveBaseKey('mi.min')).toBe('mi');
  });

  it('preserves dotted keys that are exact matches', () => {
    expect(resolveBaseKey('classCount.sum')).toBe('classCount.sum');
    expect(resolveBaseKey('typeCoverage.pct')).toBe('typeCoverage.pct');
  });

  it('returns original key when no match', () => {
    expect(resolveBaseKey('unknown.metric')).toBe('unknown.metric');
    expect(resolveBaseKey('lloc')).toBe('lloc');
  });
});

// ---------------------------------------------------------------------------
// matchRange
// ---------------------------------------------------------------------------

describe('matchRange', () => {
  const ranges = [
    { max: 4, text: 'low' },
    { max: 10, text: 'moderate' },
    { above: true, text: 'high' },
  ];

  it('matches first range', () => {
    expect(matchRange(ranges, 0)).toBe('low');
    expect(matchRange(ranges, 4)).toBe('low');
  });

  it('matches middle range', () => {
    expect(matchRange(ranges, 5)).toBe('moderate');
    expect(matchRange(ranges, 10)).toBe('moderate');
  });

  it('matches above range', () => {
    expect(matchRange(ranges, 11)).toBe('high');
    expect(matchRange(ranges, 1000)).toBe('high');
  });

  it('returns null for empty ranges', () => {
    expect(matchRange([], 5)).toBeNull();
  });
});

// ---------------------------------------------------------------------------
// getMetricHint
// ---------------------------------------------------------------------------

describe('getMetricHint', () => {
  // Edge cases
  it('returns null for null value', () => {
    expect(getMetricHint('ccn', null)).toBeNull();
  });

  it('returns null for undefined value', () => {
    expect(getMetricHint('ccn', undefined)).toBeNull();
  });

  it('returns null for non-number value', () => {
    expect(getMetricHint('ccn', 'high')).toBeNull();
  });

  it('returns null for NaN', () => {
    expect(getMetricHint('ccn', NaN)).toBeNull();
  });

  it('returns null for Infinity', () => {
    expect(getMetricHint('ccn', Infinity)).toBeNull();
  });

  it('returns null for unknown metric', () => {
    expect(getMetricHint('unknown', 5)).toBeNull();
  });

  it('returns null for informational metrics without hints', () => {
    expect(getMetricHint('lloc', 100)).toBeNull();
    expect(getMetricHint('ca', 5)).toBeNull();
    expect(getMetricHint('halstead.volume', 200)).toBeNull();
  });

  // Complexity
  it('hints for ccn ranges', () => {
    expect(getMetricHint('ccn', 1)).toBe('Simple, easy to test');
    expect(getMetricHint('ccn', 4)).toBe('Simple, easy to test');
    expect(getMetricHint('ccn', 5)).toBe('Moderate complexity');
    expect(getMetricHint('ccn', 10)).toBe('Moderate complexity');
    expect(getMetricHint('ccn', 15)).toBe('Complex, consider refactoring');
    expect(getMetricHint('ccn', 25)).toBe('Very complex, hard to maintain');
    expect(getMetricHint('ccn', 100)).toBe('Extremely complex');
  });

  it('hints for ccn.avg (aggregated)', () => {
    expect(getMetricHint('ccn.avg', 3)).toBe('Simple, easy to test');
    expect(getMetricHint('ccn.max', 25)).toBe('Very complex, hard to maintain');
  });

  it('hints for cognitive complexity', () => {
    expect(getMetricHint('cognitive', 0)).toBe('Simple, easy to understand');
    expect(getMetricHint('cognitive', 5)).toBe('Simple, easy to understand');
    expect(getMetricHint('cognitive', 6)).toBe('Moderate complexity');
    expect(getMetricHint('cognitive.avg', 20)).toBe('Complex, hard to follow');
    expect(getMetricHint('cognitive.max', 50)).toBe('Very hard to follow');
  });

  it('hints for npath', () => {
    expect(getMetricHint('npath', 1)).toBe('Simple, few execution paths');
    expect(getMetricHint('npath.max', 500)).toBe('Many execution paths');
    expect(getMetricHint('npath', 10000)).toBe('Explosive path count');
  });

  // Cohesion
  it('hints for lcom', () => {
    expect(getMetricHint('lcom', 1)).toBe('1 disconnected group');
    expect(getMetricHint('lcom', 2)).toBe('2 disconnected groups');
    expect(getMetricHint('lcom', 7)).toBe('7 disconnected groups');
  });

  it('hints for lcom with format (group count) on exact key', () => {
    expect(getMetricHint('lcom', 1)).toBe('1 disconnected group');
    expect(getMetricHint('lcom', 7)).toBe('7 disconnected groups');
  });

  it('hints for lcom.avg uses range text, not format', () => {
    expect(getMetricHint('lcom.avg', 2.5)).toBe('Moderate cohesion');
    expect(getMetricHint('lcom.avg', 3.5)).toBe('Low cohesion, consider splitting');
    expect(getMetricHint('lcom.max', 6)).toBe('Very low cohesion');
  });

  it('hints for tcc', () => {
    expect(getMetricHint('tcc', 0.1)).toBe('Low method interconnection');
    expect(getMetricHint('tcc', 0.4)).toBe('Moderate cohesion');
    expect(getMetricHint('tcc', 0.8)).toBe('Good cohesion');
  });

  it('hints for wmc', () => {
    expect(getMetricHint('wmc', 10)).toBe('Manageable class');
    expect(getMetricHint('wmc', 30)).toBe('Large class');
    expect(getMetricHint('wmc.max', 90)).toBe('Excessive — consider splitting');
  });

  // Coupling
  it('hints for cbo', () => {
    expect(getMetricHint('cbo', 5)).toBe('Normal coupling');
    expect(getMetricHint('cbo', 10)).toBe('Moderate coupling');
    expect(getMetricHint('cbo.avg', 18)).toBe('High coupling');
    expect(getMetricHint('cbo.max', 25)).toBe('Very high coupling');
  });

  it('hints for instability', () => {
    expect(getMetricHint('instability', 0.0)).toBe('Maximally stable');
    expect(getMetricHint('instability', 0.5)).toBe('Balanced');
    expect(getMetricHint('instability', 1.0)).toBe('Maximally unstable');
  });

  it('hints for distance', () => {
    expect(getMetricHint('distance', 0.05)).toBe('On main sequence');
    expect(getMetricHint('distance', 0.2)).toBe('Acceptable balance');
    expect(getMetricHint('distance.avg', 0.5)).toBe('Off balance');
  });

  it('hints for classRank', () => {
    expect(getMetricHint('classRank', 0.005)).toBe('Peripheral class');
    expect(getMetricHint('classRank.max', 0.06)).toBe('Critical coupling point');
  });

  // Design
  it('hints for dit', () => {
    expect(getMetricHint('dit', 0)).toBe('Root class');
    expect(getMetricHint('dit', 2)).toBe('Normal depth');
    expect(getMetricHint('dit.max', 5)).toBe('Deep hierarchy');
    expect(getMetricHint('dit', 8)).toBe('Fragile hierarchy');
  });

  it('hints for noc', () => {
    expect(getMetricHint('noc', 0)).toBe('Leaf class');
    expect(getMetricHint('noc', 3)).toBe('Normal inheritance');
    expect(getMetricHint('noc.max', 15)).toBe('Heavy base class');
  });

  // Size
  it('hints for methodCount', () => {
    expect(getMetricHint('methodCount', 5)).toBe('Focused class');
    expect(getMetricHint('methodCount.max', 25)).toBe('Very large class');
    expect(getMetricHint('methodCount', 50)).toBe('God Class territory');
  });

  it('hints for propertyCount', () => {
    expect(getMetricHint('propertyCount', 5)).toBe('Normal');
    expect(getMetricHint('propertyCount.max', 25)).toBe('Excessive');
  });

  it('hints for classCount.sum', () => {
    expect(getMetricHint('classCount.sum', 5)).toBe('Focused namespace');
    expect(getMetricHint('classCount.sum', 30)).toBe('Bloated namespace');
  });

  // Maintainability
  it('hints for mi', () => {
    expect(getMetricHint('mi', 10)).toBe('Critical — very hard to maintain');
    expect(getMetricHint('mi.avg', 75)).toBe('Good maintainability');
    expect(getMetricHint('mi.min', 90)).toBe('Excellent maintainability');
  });

  // Type coverage
  it('hints for typeCoverage.pct', () => {
    expect(getMetricHint('typeCoverage.pct', 30)).toBe('Low type coverage');
    expect(getMetricHint('typeCoverage.pct', 60)).toBe('Moderate type coverage');
    expect(getMetricHint('typeCoverage.pct', 95)).toBe('Good type coverage');
  });

  it('hints for rfc', () => {
    expect(getMetricHint('rfc', 10)).toBe('Simple interface');
    expect(getMetricHint('rfc.avg', 60)).toBe('Complex interface');
  });

  // Value = 0
  it('handles zero values', () => {
    expect(getMetricHint('ccn', 0)).toBe('Simple, easy to test');
    expect(getMetricHint('dit', 0)).toBe('Root class');
    expect(getMetricHint('noc', 0)).toBe('Leaf class');
  });

  // Negative values (shouldn't happen but handle gracefully)
  it('handles negative values', () => {
    expect(getMetricHint('ccn', -1)).toBe('Simple, easy to test');
  });
});

// ---------------------------------------------------------------------------
// getHealthHint
// ---------------------------------------------------------------------------

describe('getHealthHint', () => {
  it('returns null for null node', () => {
    expect(getHealthHint('health.complexity', null)).toBeNull();
  });

  it('returns null for node without metrics', () => {
    expect(getHealthHint('health.complexity', {})).toBeNull();
    expect(getHealthHint('health.complexity', { metrics: null })).toBeNull();
  });

  it('returns null for missing health metric', () => {
    expect(getHealthHint('health.complexity', { metrics: {} })).toBeNull();
  });

  it('returns null for unknown health key', () => {
    expect(getHealthHint('health.unknown', { metrics: { 'health.unknown': 50 } })).toBeNull();
  });

  it('decomposes health.complexity', () => {
    const node = {
      metrics: {
        'health.complexity': 72,
        'ccn.avg': 8,
        'cognitive.avg': 12,
      },
    };
    const result = getHealthHint('health.complexity', node);
    expect(result).not.toBeNull();
    expect(result.text).toBe('Complexity: 72 / 100');
    expect(result.details).toHaveLength(2);
    expect(result.details[0]).toContain('CCN avg = 8');
    expect(result.details[0]).toContain('moderate complexity');
    expect(result.details[1]).toContain('Cognitive avg = 12');
  });

  it('decomposes health.cohesion with class-level keys', () => {
    const node = {
      metrics: {
        'health.cohesion': 45,
        'tcc': 0.2,
        'lcom': 4,
      },
    };
    const result = getHealthHint('health.cohesion', node);
    expect(result).not.toBeNull();
    expect(result.text).toBe('Cohesion: 45 / 100');
    expect(result.details).toHaveLength(2);
    expect(result.details[0]).toContain('TCC = 0.20');
    expect(result.details[0]).toContain('low method interconnection');
    expect(result.details[1]).toContain('LCOM = 4');
  });

  it('decomposes health.cohesion with namespace-level alt keys', () => {
    const node = {
      metrics: {
        'health.cohesion': 60,
        'tcc.avg': 0.45,
        'lcom.avg': 2.5,
      },
    };
    const result = getHealthHint('health.cohesion', node);
    expect(result).not.toBeNull();
    expect(result.details).toHaveLength(2);
    expect(result.details[0]).toContain('TCC = 0.45');
    expect(result.details[1]).toContain('LCOM = 2.50');
  });

  it('decomposes health.coupling', () => {
    const node = {
      metrics: {
        'health.coupling': 30,
        'cbo': 18,
        'distance': 0.4,
      },
    };
    const result = getHealthHint('health.coupling', node);
    expect(result).not.toBeNull();
    expect(result.text).toBe('Coupling: 30 / 100');
    expect(result.details[0]).toContain('CBO = 18');
    expect(result.details[0]).toContain('high coupling');
  });

  it('decomposes health.typing', () => {
    const node = {
      metrics: {
        'health.typing': 80,
        'typeCoverage.pct': 85,
      },
    };
    const result = getHealthHint('health.typing', node);
    expect(result).not.toBeNull();
    expect(result.text).toBe('Typing: 80 / 100');
    expect(result.details).toHaveLength(1);
    expect(result.details[0]).toContain('Coverage = 85');
  });

  it('decomposes health.maintainability', () => {
    const node = {
      metrics: {
        'health.maintainability': 90,
        'mi.avg': 88,
      },
    };
    const result = getHealthHint('health.maintainability', node);
    expect(result).not.toBeNull();
    expect(result.text).toBe('Maintainability: 90 / 100');
    expect(result.details[0]).toContain('MI = 88');
    expect(result.details[0]).toContain('excellent maintainability');
  });

  it('decomposes health.overall with weakest area', () => {
    const node = {
      metrics: {
        'health.overall': 35,
        'health.complexity': 70,
        'health.cohesion': 60,
        'health.coupling': 22,
        'health.typing': 80,
        'health.maintainability': 75,
      },
    };
    const result = getHealthHint('health.overall', node);
    expect(result).not.toBeNull();
    expect(result.text).toContain('Overall: 35 / 100');
    expect(result.text).toContain('weakest: coupling (22)');
    expect(result.details).toHaveLength(5);
  });

  it('handles partial metrics gracefully', () => {
    const node = {
      metrics: {
        'health.complexity': 50,
        'ccn.avg': 12,
        // cognitive.avg missing
      },
    };
    const result = getHealthHint('health.complexity', node);
    expect(result).not.toBeNull();
    expect(result.details).toHaveLength(1);
    expect(result.details[0]).toContain('CCN avg = 12');
  });

  it('returns null when no input metrics available', () => {
    const node = {
      metrics: {
        'health.complexity': 50,
        // no ccn.avg or cognitive.avg
      },
    };
    const result = getHealthHint('health.complexity', node);
    expect(result).toBeNull();
  });
});

// ---------------------------------------------------------------------------
// initHints
// ---------------------------------------------------------------------------

describe('initHints', () => {
  it('populates METRIC_HINTS map', () => {
    expect(METRIC_HINTS.size).toBeGreaterThan(0);
    expect(METRIC_HINTS.has('ccn')).toBe(true);
  });

  it('populates HEALTH_DECOMPOSITION map', () => {
    expect(HEALTH_DECOMPOSITION.size).toBeGreaterThan(0);
    expect(HEALTH_DECOMPOSITION.has('health.complexity')).toBe(true);
  });

  it('creates format function from template', () => {
    const lcom = METRIC_HINTS.get('lcom');
    expect(lcom.format).toBeTypeOf('function');
    expect(lcom.format(1)).toBe('1 disconnected group');
    expect(lcom.format(3)).toBe('3 disconnected groups');
  });

  it('handles null input gracefully', () => {
    initHints(null);
    expect(METRIC_HINTS.size).toBe(0);
    expect(HEALTH_DECOMPOSITION.size).toBe(0);
    // Re-init for other tests
    initHints(hintsFixture);
  });
});

// ---------------------------------------------------------------------------
// Catalog completeness
// ---------------------------------------------------------------------------

describe('catalog completeness', () => {
  it('has hints for all expected complexity metrics', () => {
    expect(METRIC_HINTS.has('ccn')).toBe(true);
    expect(METRIC_HINTS.has('cognitive')).toBe(true);
    expect(METRIC_HINTS.has('npath')).toBe(true);
  });

  it('has hints for all expected cohesion metrics', () => {
    expect(METRIC_HINTS.has('lcom')).toBe(true);
    expect(METRIC_HINTS.has('tcc')).toBe(true);
    expect(METRIC_HINTS.has('lcc')).toBe(true);
    expect(METRIC_HINTS.has('wmc')).toBe(true);
  });

  it('has hints for all expected coupling metrics', () => {
    expect(METRIC_HINTS.has('cbo')).toBe(true);
    expect(METRIC_HINTS.has('instability')).toBe(true);
    expect(METRIC_HINTS.has('abstractness')).toBe(true);
    expect(METRIC_HINTS.has('distance')).toBe(true);
    expect(METRIC_HINTS.has('classRank')).toBe(true);
  });

  it('has decompositions for all health scores', () => {
    expect(HEALTH_DECOMPOSITION.has('health.complexity')).toBe(true);
    expect(HEALTH_DECOMPOSITION.has('health.cohesion')).toBe(true);
    expect(HEALTH_DECOMPOSITION.has('health.coupling')).toBe(true);
    expect(HEALTH_DECOMPOSITION.has('health.typing')).toBe(true);
    expect(HEALTH_DECOMPOSITION.has('health.maintainability')).toBe(true);
    expect(HEALTH_DECOMPOSITION.has('health.overall')).toBe(true);
  });

  it('every range array ends with an above:true entry', () => {
    for (const [key, def] of METRIC_HINTS) {
      const last = def.ranges[def.ranges.length - 1];
      expect(last.above, `${key} should end with above:true`).toBe(true);
    }
  });
});
