/**
 * Diagnostic hints for metric values.
 *
 * Provides human-readable interpretations of raw metric values
 * and health score decompositions for the HTML report.
 *
 * Hint data is embedded in the report JSON by PHP (MetricHintProvider)
 * and loaded at startup via initHints().
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
 * @property {function} [format] - Optional custom format function
 */

/** @type {Map<string, HintDef>} */
let METRIC_HINTS = new Map();

/**
 * Health score decomposition definitions.
 * Maps health metric keys to their contributing input metrics.
 *
 * @type {Map<string, {inputs: Array<{key: string, altKey: string|null, label: string, ideal: string, direction: string}>}>}
 */
let HEALTH_DECOMPOSITION = new Map();

/**
 * Initializes hint data from the embedded JSON produced by MetricHintProvider::exportForHtml().
 *
 * Must be called before any hint lookups. Populates METRIC_HINTS and HEALTH_DECOMPOSITION.
 *
 * @param {object} hintsData - The hints object from DATA.hints
 * @param {object} hintsData.metricHints - Metric hint definitions
 * @param {object} hintsData.healthDecomposition - Health decomposition definitions
 */
export function initHints(hintsData) {
  METRIC_HINTS = new Map();
  HEALTH_DECOMPOSITION = new Map();

  if (!hintsData) return;

  // Populate METRIC_HINTS
  if (hintsData.metricHints) {
    for (const [key, def] of Object.entries(hintsData.metricHints)) {
      const entry = {
        label: def.label,
        ranges: def.ranges,
      };

      // Build format function from template if present
      if (def.formatTemplate) {
        const template = def.formatTemplate;
        entry.format = (v) => {
          return template
            .replace('{value}', String(v))
            .replace('{plural}', v === 1 ? '' : 's');
        };
      }

      METRIC_HINTS.set(key, entry);
    }
  }

  // Populate HEALTH_DECOMPOSITION
  if (hintsData.healthDecomposition) {
    for (const [key, def] of Object.entries(hintsData.healthDecomposition)) {
      HEALTH_DECOMPOSITION.set(key, def);
    }
  }
}

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
  // Derive sub-health dimensions from HEALTH_DECOMPOSITION (PHP is the source of truth)
  const subKeys = [...HEALTH_DECOMPOSITION.keys()].filter(k => k !== 'health.overall');

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
