/**
 * Search functionality for finding namespaces/classes by name.
 */

/**
 * Creates a search handler for the tree data.
 *
 * @param {object} root - Root tree node
 * @returns {function} Search handler: (query: string) => SearchResult[]
 */
export function createSearchHandler(root) {
  // Build flat index of all nodes
  const index = buildIndex(root);

  return function search(query) {
    if (!query || query.trim().length < 2) {
      clearHighlights();
      return [];
    }

    const results = filterNodes(index, query.trim());
    highlightResults(results);
    return results;
  };
}

/**
 * Builds a flat index of all searchable nodes.
 *
 * @param {object} node - Tree node
 * @param {object[]} acc - Accumulator
 * @returns {object[]} Flat array of { name, path, type, node }
 */
export function buildIndex(node, acc = []) {
  if (node.type !== 'other') {
    acc.push({
      name: node.name,
      path: node.path,
      type: node.type,
      node: node,
    });
  }

  if (node.children) {
    for (const child of node.children) {
      buildIndex(child, acc);
    }
  }

  return acc;
}

/**
 * Filters nodes matching the search query.
 * Case-insensitive substring match on name and path.
 *
 * @param {object[]} index - Flat node index
 * @param {string} query - Search query
 * @returns {object[]} Matching nodes, sorted by relevance
 */
export function filterNodes(index, query) {
  const lower = query.toLowerCase();

  return index
    .filter(entry => {
      const nameMatch = entry.name.toLowerCase().includes(lower);
      const pathMatch = entry.path.toLowerCase().includes(lower);
      return nameMatch || pathMatch;
    })
    .sort((a, b) => {
      // Exact name match first
      const aExact = a.name.toLowerCase() === lower ? 0 : 1;
      const bExact = b.name.toLowerCase() === lower ? 0 : 1;
      if (aExact !== bExact) return aExact - bExact;

      // Name starts with query next
      const aStarts = a.name.toLowerCase().startsWith(lower) ? 0 : 1;
      const bStarts = b.name.toLowerCase().startsWith(lower) ? 0 : 1;
      if (aStarts !== bStarts) return aStarts - bStarts;

      // Shorter paths first
      return a.path.length - b.path.length;
    })
    .slice(0, 20);
}

function highlightResults(results) {
  clearHighlights();

  const container = document.getElementById('treemap');
  if (!container) return;

  const matchPaths = new Set(results.map(r => r.path));

  const nodes = container.querySelectorAll('.node[data-path]');
  for (const nodeEl of nodes) {
    const path = nodeEl.getAttribute('data-path');
    if (path && matchPaths.has(path)) {
      nodeEl.classList.add('search-highlight');
    }
  }
}

function clearHighlights() {
  const container = document.getElementById('treemap');
  if (!container) return;

  const highlighted = container.querySelectorAll('.search-highlight');
  for (const el of highlighted) {
    el.classList.remove('search-highlight');
  }
}
