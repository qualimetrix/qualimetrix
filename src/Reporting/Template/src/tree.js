/**
 * Tree data utilities for D3 treemap.
 */

/** Minimum area in px² for a node to be individually visible */
export const VISIBILITY_THRESHOLD = 400;

/**
 * Prepares tree data for D3 hierarchy.
 * Adds computed properties needed for rendering.
 *
 * @param {object} rawTree - Raw tree from JSON data
 * @returns {object} Tree with computed properties
 */
export function buildTreeData(rawTree) {
  return rawTree;
}

/**
 * Finds a node by its path in the tree.
 *
 * @param {object} root - Root tree node
 * @param {string} path - Node path (e.g., "App\\Payment")
 * @returns {object|null} Found node or null
 */
export function findNode(root, path) {
  if (root.path === path) return root;
  if (!root.children) return null;

  for (const child of root.children) {
    const found = findNode(child, path);
    if (found) return found;
  }
  return null;
}

/**
 * Collects all leaf nodes (classes) from a subtree.
 *
 * @param {object} node - Tree node
 * @returns {object[]} Array of leaf nodes
 */
export function collectLeaves(node) {
  if (!node.children || node.children.length === 0) {
    return [node];
  }
  return node.children.flatMap(collectLeaves);
}

/**
 * Gets the N worst classes by health score (ascending).
 *
 * @param {object} node - Tree node to search within
 * @param {number} n - Number of worst classes to return
 * @param {string} metric - Metric key to sort by
 * @returns {object[]} Worst classes sorted by metric ASC
 */
export function getWorstOffenders(node, n = 10, metric = 'health.overall') {
  const leaves = collectLeaves(node).filter(l => l.type === 'class');

  return leaves
    .filter(l => l.metrics && l.metrics[metric] != null)
    .sort((a, b) => (a.metrics[metric] ?? 100) - (b.metrics[metric] ?? 100))
    .slice(0, n);
}

/**
 * Aggregates small nodes into an "Other" group for visibility.
 * Nodes with area below the threshold are merged.
 *
 * @param {object[]} children - Child nodes
 * @param {number} totalArea - Total available area in px²
 * @param {number} totalValue - Total LOC of all children
 * @returns {object[]} Children with small nodes aggregated into "Other"
 */
export function aggregateSmallNodes(children, totalArea, totalValue) {
  if (!children || children.length === 0 || totalArea <= 0 || totalValue <= 0) {
    return children || [];
  }

  const visible = [];
  const small = [];

  for (const child of children) {
    const childValue = getLoc(child);
    const childArea = (childValue / totalValue) * totalArea;

    if (childArea >= VISIBILITY_THRESHOLD) {
      visible.push(child);
    } else {
      small.push(child);
    }
  }

  if (small.length === 0) return children;
  if (small.length === 1) return children; // Don't aggregate a single node

  const otherLoc = small.reduce((sum, n) => sum + getLoc(n), 0);
  const otherViolations = small.reduce((sum, n) => sum + (n.violationCountTotal || 0), 0);

  const otherNode = {
    name: `Other (${small.length} items)`,
    path: '',
    type: 'other',
    metrics: { 'loc.sum': otherLoc },
    violations: [],
    violationCountTotal: otherViolations,
    debtMinutes: 0,
    children: small,
    _isOther: true,
  };

  visible.push(otherNode);
  return visible;
}

/**
 * Gets LOC value from a node's metrics.
 *
 * @param {object} node - Tree node
 * @returns {number} LOC value (minimum 1 to avoid zero-weight in treemap)
 */
export function getLoc(node) {
  const loc = node.metrics?.['loc.sum'];
  return (loc != null && loc > 0) ? loc : 0;
}
