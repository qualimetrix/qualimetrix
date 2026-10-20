/** Published namespace metrics prepared for the detail view. */

/** Returns direct child namespaces ordered by their published score. */
export function getWorstSubNamespaces(node, n = 5, metric = 'health.overall') {
  return (node.children || [])
    .filter(child => child.type === 'namespace' && child.metrics?.[metric] != null)
    .sort((a, b) => a.metrics[metric] - b.metrics[metric])
    .slice(0, n);
}
