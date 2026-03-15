/**
 * Diagnostic hints for metric values.
 *
 * Provides human-readable interpretations of raw metric values
 * and health score decompositions for the HTML report.
 */

/**
 * @typedef {object} HintRange
 * @property {number} [max] - Upper bound (inclusive) for this range
 * @property {boolean} [above] - If true, matches values above previous range
 * @property {string} text - Human-readable interpretation
 */

/**
 * @typedef {object} HintDef
 * @property {string} label - Human-readable metric name
 * @property {HintRange[]} ranges - Ordered ranges (lowest first)
 * @property {string} [unit] - Optional unit suffix (e.g., 'lines')
 */

/** @type {Map<string, HintDef>} */
const METRIC_HINTS = new Map([
  // --- Complexity ---
  ['ccn', {
    label: 'Cyclomatic Complexity',
    ranges: [
      { max: 4, text: 'Simple, easy to test' },
      { max: 10, text: 'Moderate complexity' },
      { max: 20, text: 'Complex, consider refactoring' },
      { max: 50, text: 'Very complex, hard to maintain' },
      { above: true, text: 'Extremely complex' },
    ],
  }],
  ['cognitive', {
    label: 'Cognitive Complexity',
    ranges: [
      { max: 5, text: 'Simple, easy to understand' },
      { max: 15, text: 'Moderate complexity' },
      { max: 30, text: 'Complex, hard to follow' },
      { above: true, text: 'Very hard to follow' },
    ],
  }],
  ['npath', {
    label: 'NPath Complexity',
    ranges: [
      { max: 20, text: 'Simple, few execution paths' },
      { max: 200, text: 'Moderate path count' },
      { max: 1000, text: 'Many execution paths' },
      { above: true, text: 'Explosive path count' },
    ],
  }],

  // --- Cohesion ---
  ['lcom', {
    label: 'LCOM4',
    ranges: [
      { max: 1, text: 'Cohesive — single responsibility' },
      { max: 3, text: 'Moderate cohesion' },
      { max: 5, text: 'Low cohesion, consider splitting' },
      { above: true, text: 'Very low cohesion' },
    ],
    format: (v) => `${v} disconnected group${v === 1 ? '' : 's'}`,
  }],
  ['tcc', {
    label: 'Tight Class Cohesion',
    ranges: [
      { max: 0.29, text: 'Low method interconnection' },
      { max: 0.49, text: 'Moderate cohesion' },
      { above: true, text: 'Good cohesion' },
    ],
  }],
  ['lcc', {
    label: 'Loose Class Cohesion',
    ranges: [
      { max: 0.29, text: 'Low cohesion (incl. transitive)' },
      { max: 0.49, text: 'Moderate cohesion' },
      { above: true, text: 'Good cohesion' },
    ],
  }],
  ['wmc', {
    label: 'Weighted Methods per Class',
    ranges: [
      { max: 20, text: 'Manageable class' },
      { max: 50, text: 'Large class' },
      { max: 80, text: 'Very large class' },
      { above: true, text: 'Excessive — consider splitting' },
    ],
  }],

  // --- Coupling ---
  ['cbo', {
    label: 'Coupling Between Objects',
    ranges: [
      { max: 7, text: 'Normal coupling' },
      { max: 14, text: 'Moderate coupling' },
      { max: 20, text: 'High coupling' },
      { above: true, text: 'Very high coupling' },
    ],
  }],
  ['instability', {
    label: 'Instability',
    ranges: [
      { max: 0.09, text: 'Maximally stable' },
      { max: 0.29, text: 'Stable' },
      { max: 0.7, text: 'Balanced' },
      { max: 0.9, text: 'Unstable' },
      { above: true, text: 'Maximally unstable' },
    ],
  }],
  ['abstractness', {
    label: 'Abstractness',
    ranges: [
      { max: 0.09, text: 'All concrete' },
      { max: 0.5, text: 'Mostly concrete' },
      { max: 0.9, text: 'Mostly abstract' },
      { above: true, text: 'All abstract' },
    ],
  }],
  ['distance', {
    label: 'Distance from Main Sequence',
    ranges: [
      { max: 0.1, text: 'On main sequence' },
      { max: 0.3, text: 'Acceptable balance' },
      { above: true, text: 'Off balance' },
    ],
  }],
  ['classRank', {
    label: 'ClassRank',
    ranges: [
      { max: 0.009, text: 'Peripheral class' },
      { max: 0.02, text: 'Moderate importance' },
      { max: 0.05, text: 'Important hub' },
      { above: true, text: 'Critical coupling point' },
    ],
  }],

  // --- Design ---
  ['dit', {
    label: 'Depth of Inheritance Tree',
    ranges: [
      { max: 0, text: 'Root class' },
      { max: 3, text: 'Normal depth' },
      { max: 6, text: 'Deep hierarchy' },
      { above: true, text: 'Fragile hierarchy' },
    ],
  }],
  ['noc', {
    label: 'Number of Children',
    ranges: [
      { max: 0, text: 'Leaf class' },
      { max: 5, text: 'Normal inheritance' },
      { max: 10, text: 'Many subclasses' },
      { above: true, text: 'Heavy base class' },
    ],
  }],
  ['rfc', {
    label: 'Response for a Class',
    ranges: [
      { max: 20, text: 'Simple interface' },
      { max: 50, text: 'Moderate interface' },
      { max: 100, text: 'Complex interface' },
      { above: true, text: 'Very complex interface' },
    ],
  }],

  // --- Size ---
  ['methodCount', {
    label: 'Method Count',
    ranges: [
      { max: 10, text: 'Focused class' },
      { max: 20, text: 'Large class' },
      { max: 30, text: 'Very large class' },
      { above: true, text: 'God Class territory' },
    ],
  }],
  ['propertyCount', {
    label: 'Property Count',
    ranges: [
      { max: 10, text: 'Normal' },
      { max: 15, text: 'Large' },
      { max: 20, text: 'Heavy' },
      { above: true, text: 'Excessive' },
    ],
  }],
  ['classCount.sum', {
    label: 'Class Count',
    ranges: [
      { max: 10, text: 'Focused namespace' },
      { max: 15, text: 'Moderate namespace' },
      { max: 25, text: 'Large namespace' },
      { above: true, text: 'Bloated namespace' },
    ],
  }],

  // --- Maintainability ---
  ['mi', {
    label: 'Maintainability Index',
    ranges: [
      { max: 19, text: 'Critical — very hard to maintain' },
      { max: 39, text: 'Poor — refactoring recommended' },
      { max: 64, text: 'Moderate — could benefit from simplification' },
      { max: 84, text: 'Good maintainability' },
      { above: true, text: 'Excellent maintainability' },
    ],
  }],

  // --- Type Coverage ---
  ['typeCoverage.pct', {
    label: 'Type Coverage',
    ranges: [
      { max: 49, text: 'Low type coverage' },
      { max: 79, text: 'Moderate type coverage' },
      { above: true, text: 'Good type coverage' },
    ],
  }],
  ['typeCoverage.param', {
    label: 'Parameter Type Coverage',
    ranges: [
      { max: 49, text: 'Low coverage' },
      { max: 79, text: 'Moderate coverage' },
      { above: true, text: 'Good coverage' },
    ],
  }],
  ['typeCoverage.return', {
    label: 'Return Type Coverage',
    ranges: [
      { max: 49, text: 'Low coverage' },
      { max: 79, text: 'Moderate coverage' },
      { above: true, text: 'Good coverage' },
    ],
  }],
  ['typeCoverage.property', {
    label: 'Property Type Coverage',
    ranges: [
      { max: 49, text: 'Low coverage' },
      { max: 79, text: 'Moderate coverage' },
      { above: true, text: 'Good coverage' },
    ],
  }],
]);

/**
 * Health score decomposition definitions.
 * Maps health metric keys to their contributing input metrics.
 *
 * @type {Map<string, {inputs: Array<{key: string, label: string, ideal: number|string, direction: string}>}>}
 */
const HEALTH_DECOMPOSITION = new Map([
  ['health.complexity', {
    inputs: [
      { key: 'ccn.avg', altKey: 'ccn', label: 'CCN', ideal: '1-4', direction: 'lower' },
      { key: 'cognitive.avg', altKey: 'cognitive', label: 'Cognitive', ideal: '0-5', direction: 'lower' },
    ],
  }],
  ['health.cohesion', {
    inputs: [
      { key: 'tcc', altKey: 'tcc.avg', label: 'TCC', ideal: '1.0', direction: 'higher' },
      { key: 'lcom', altKey: 'lcom.avg', label: 'LCOM', ideal: '1', direction: 'lower' },
    ],
  }],
  ['health.coupling', {
    inputs: [
      { key: 'cbo', altKey: 'cbo.avg', label: 'CBO', ideal: '0-7', direction: 'lower' },
      { key: 'distance', altKey: 'distance.avg', label: 'Distance', ideal: '0.0', direction: 'lower' },
    ],
  }],
  ['health.typing', {
    inputs: [
      { key: 'typeCoverage.pct', label: 'Coverage', ideal: '100%', direction: 'higher' },
    ],
  }],
  ['health.maintainability', {
    inputs: [
      { key: 'mi.avg', altKey: 'mi', label: 'MI', ideal: '85+', direction: 'higher' },
    ],
  }],
  ['health.overall', {
    inputs: [], // Special: decomposes into sub-health scores
  }],
]);

/**
 * Returns a human-readable hint for a metric value.
 *
 * @param {string} metricKey - The metric key (e.g. 'ccn', 'ccn.avg', 'lcom.max')
 * @param {*} value - The metric value
 * @returns {string|null} Hint text, or null if no hint available
 */
export function getMetricHint(metricKey, value) {
  if (value == null || typeof value !== 'number' || !isFinite(value)) return null;

  // Strip aggregation suffixes to find base hint definition
  const baseKey = resolveBaseKey(metricKey);
  const def = METRIC_HINTS.get(baseKey);
  if (!def) return null;

  // Find matching range
  const text = matchRange(def.ranges, value);
  if (!text) return null;

  // For LCOM, show group count only for the exact key (not aggregated .avg/.max)
  if (def.format && metricKey === baseKey) {
    return def.format(value);
  }

  return text;
}

/**
 * Returns health score decomposition for tooltip display.
 *
 * @param {string} metricKey - Health metric key (e.g. 'health.cohesion')
 * @param {object} node - Tree node with metrics
 * @returns {{text: string, details: string[]}|null} Decomposition, or null
 */
export function getHealthHint(metricKey, node) {
  if (!node?.metrics) return null;

  const value = node.metrics[metricKey];
  if (value == null) return null;

  const decomp = HEALTH_DECOMPOSITION.get(metricKey);
  if (!decomp) return null;

  const score = Math.round(value);

  // Special case: health.overall decomposes into sub-health scores
  if (metricKey === 'health.overall') {
    return getOverallDecomposition(node, score);
  }

  const details = [];
  for (const input of decomp.inputs) {
    const inputValue = node.metrics[input.key] ?? node.metrics[input.altKey];
    if (inputValue == null) continue;

    const formatted = typeof inputValue === 'number' ? formatInputValue(inputValue) : String(inputValue);
    const hint = getMetricHint(input.key, inputValue);
    const hintSuffix = hint ? ` — ${hint.toLowerCase()}` : '';
    details.push(`${input.label} = ${formatted}${hintSuffix}`);
  }

  if (details.length === 0) return null;

  const label = metricKey.replace('health.', '');
  return {
    text: `${capitalize(label)}: ${score} / 100`,
    details,
  };
}

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

/**
 * Resolves a metric key with aggregation suffix to its base hint key.
 * E.g. 'ccn.avg' → 'ccn', 'lcom.max' → 'lcom', 'ccn' → 'ccn'
 */
function resolveBaseKey(key) {
  // First check for exact match
  if (METRIC_HINTS.has(key)) return key;

  // Strip known aggregation suffixes
  const suffixes = ['.avg', '.max', '.min', '.sum'];
  for (const suffix of suffixes) {
    if (key.endsWith(suffix)) {
      const base = key.slice(0, -suffix.length);
      if (METRIC_HINTS.has(base)) return base;
    }
  }

  return key;
}

/**
 * Finds the matching range text for a value.
 */
function matchRange(ranges, value) {
  for (const range of ranges) {
    if (range.above) return range.text;
    if (value <= range.max) return range.text;
  }
  return null;
}

/**
 * Formats a numeric input value for display.
 */
function formatInputValue(value) {
  if (Number.isInteger(value)) return String(value);
  return value.toFixed(2);
}

/**
 * Capitalizes the first letter.
 */
function capitalize(str) {
  return str.charAt(0).toUpperCase() + str.slice(1);
}

/**
 * Decomposes health.overall into its weakest sub-health score.
 */
function getOverallDecomposition(node, score) {
  const subKeys = [
    'health.complexity', 'health.cohesion', 'health.coupling',
    'health.typing', 'health.maintainability',
  ];

  let weakest = null;
  let weakestValue = Infinity;
  const details = [];

  for (const key of subKeys) {
    const v = node.metrics[key];
    if (v == null) continue;

    const label = key.replace('health.', '');
    const rounded = Math.round(v);
    details.push(`${capitalize(label)}: ${rounded}`);

    if (v < weakestValue) {
      weakestValue = v;
      weakest = label;
    }
  }

  if (details.length === 0) return null;

  const text = weakest
    ? `Overall: ${score} / 100 — weakest: ${weakest} (${Math.round(weakestValue)})`
    : `Overall: ${score} / 100`;

  return { text, details };
}

// Export for testing
export { METRIC_HINTS, HEALTH_DECOMPOSITION, resolveBaseKey, matchRange };
