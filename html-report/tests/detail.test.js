import { describe, it, expect } from 'vitest';
import { formatHealthCoverage, coverageRecordFor } from '../src/detail.js';

// The payload shape asserted here is HealthCoverageNarrator::record() in PHP,
// carried to the viewer as summary.healthCoverage keyed by the same health.*
// name as the score beside it. A score over a tenth of the classes and a score
// over all of them arrive at this surface looking identical; these cases pin
// what the reader is shown instead.

describe('formatHealthCoverage', () => {
  it('states the share and what it is a share of for a measured coverage', () => {
    const formatted = formatHealthCoverage({
      state: 'measured',
      measured: 166,
      eligible: 168,
      ratio: 166 / 168,
      unit: 'namespaces declaring a type',
      basis: 'coupling.distance-own.count',
      reason: null,
    });

    expect(formatted.short).toBe('99%');
    expect(formatted.full).toBe(
      'Computed over 166 of 168 namespaces declaring a type (99%), from coupling.distance-own.count',
    );
  });

  it('keeps an undefined coverage distinguishable from a coverage of nothing', () => {
    const undefinedCoverage = formatHealthCoverage({
      state: 'not-applicable',
      measured: null,
      eligible: null,
      ratio: null,
      unit: null,
      basis: null,
      reason: 'health.overall composes the other dimensions',
    });
    const nothingMeasured = formatHealthCoverage({
      state: 'measured',
      measured: 0,
      eligible: 2,
      ratio: 0,
      unit: 'classes',
      basis: 'cohesion.tcc.count',
      reason: null,
    });

    expect(undefinedCoverage.short).toBe('n/a');
    expect(undefinedCoverage.full).toContain('not applicable');
    expect(undefinedCoverage.full).toContain('composes the other dimensions');

    expect(nothingMeasured.short).toBe('0%');
    expect(nothingMeasured.full).toBe('Computed over 0 of 2 classes (0%), from cohesion.tcc.count');
  });

  it('renders no cell at all when the payload carries nothing for the dimension', () => {
    // An older report, or a node whose scores the payload states no coverage
    // for. An empty cell there would read as a measurement that came back
    // blank, which is a third claim neither state makes.
    expect(formatHealthCoverage(undefined)).toBeNull();
    expect(formatHealthCoverage(null)).toBeNull();
  });
});

describe('coverageRecordFor', () => {
  const summary = {
    healthCoverage: {
      'health.coupling': {
        state: 'measured',
        measured: 166,
        eligible: 168,
        ratio: 166 / 168,
        unit: 'namespaces declaring a type',
        basis: 'coupling.distance-own.count',
        reason: null,
      },
    },
  };

  it('gives the project node the record the payload states', () => {
    expect(coverageRecordFor({ type: 'project' }, summary, 'health.coupling')).toBe(
      summary.healthCoverage['health.coupling'],
    );
  });

  it('gives a namespace or class node nothing from the same payload', () => {
    // The object is on the report, not on the node, so a guard that only
    // looked it up by key would print the project's share of 168 namespaces
    // beside one namespace's own score.
    expect(coverageRecordFor({ type: 'namespace' }, summary, 'health.coupling')).toBeNull();
    expect(coverageRecordFor({ type: 'class' }, summary, 'health.coupling')).toBeNull();
  });

  it('gives nothing when the report carries no coverage at all', () => {
    expect(coverageRecordFor({ type: 'project' }, {}, 'health.coupling')).toBeNull();
    expect(coverageRecordFor({ type: 'project' }, summary, 'health.typing')).toBeNull();
  });
});
