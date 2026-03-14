/**
 * Subtree metrics — computes hierarchical roll-up from flat namespace data.
 *
 * Health scores are LOC-weighted averages of children's subtree scores.
 */

import { getLoc } from './tree.js';

const HEALTH_METRICS = [
  'health.complexity', 'health.cohesion', 'health.coupling',
  'health.typing', 'health.maintainability', 'health.overall',
];

/**
 * Recursively computes subtree metrics (bottom-up).
 *
 * For leaf nodes (classes): _subtree = own metrics.
 * For namespace nodes: LOC-weighted average of children's subtree scores,
 * summed LOC and violation counts.
 *
 * @param {object} node - Tree node
 */
export function computeSubtreeMetrics(node) {
  if (!node.children || node.children.length === 0) {
    // Leaf node — subtree is own data
    node._subtree = {
      metrics: { ...node.metrics },
      loc: getLoc(node),
      violationCount: node.violationCountTotal || 0,
    };
    return;
  }

  // Recurse into children first
  for (const child of node.children) {
    computeSubtreeMetrics(child);
  }

  // Sum LOC and violations across subtree
  let totalLoc = 0;
  let totalViolations = 0;
  for (const child of node.children) {
    totalLoc += child._subtree.loc;
    totalViolations += child._subtree.violationCount;
  }

  // LOC-weighted average for each health metric
  const metrics = {};
  for (const metric of HEALTH_METRICS) {
    let weightedSum = 0;
    let weightTotal = 0;

    for (const child of node.children) {
      const value = child._subtree.metrics[metric];
      const loc = child._subtree.loc;
      if (value != null && loc > 0) {
        weightedSum += value * loc;
        weightTotal += loc;
      }
    }

    if (weightTotal > 0) {
      metrics[metric] = weightedSum / weightTotal;
    }
  }

  node._subtree = {
    metrics,
    loc: totalLoc,
    violationCount: totalViolations,
  };
}

/**
 * Returns top N direct child namespaces sorted by subtree health ASC (worst first).
 *
 * @param {object} node - Parent tree node
 * @param {number} n - Maximum number of results
 * @param {string} metric - Health metric key to sort by
 * @returns {object[]} Worst child namespaces
 */
export function getWorstSubNamespaces(node, n = 5, metric = 'health.overall') {
  if (!node.children) return [];

  return node.children
    .filter(c => c.type === 'namespace' && c._subtree?.metrics[metric] != null)
    .sort((a, b) => (a._subtree.metrics[metric] ?? 100) - (b._subtree.metrics[metric] ?? 100))
    .slice(0, n);
}
