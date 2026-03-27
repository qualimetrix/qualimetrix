/**
 * URL hash navigation for deep linking.
 *
 * Formats:
 *   #ns:App/Payment       — treemap view, navigate to namespace
 *   #cl:App/Payment/Proc  — treemap view, navigate to class
 *   #md:App/Payment       — Martin diagram view, navigate to namespace
 *
 * Backslash \ -> / in hash, standard encodeURIComponent for special chars.
 */

import { findNode } from './tree.js';

/**
 * Parses a URL hash into a navigation target.
 *
 * @param {string} hash - URL hash (e.g., '#ns:App/Payment' or '#md:App/Payment')
 * @returns {{ type: 'namespace'|'class'|null, path: string, view: 'treemap'|'martin' }|null}
 */
export function parseHash(hash) {
  if (!hash || hash === '#') return null;

  const withoutHash = hash.startsWith('#') ? hash.substring(1) : hash;

  const colonIndex = withoutHash.indexOf(':');
  if (colonIndex === -1) return null;

  const prefix = withoutHash.substring(0, colonIndex);
  const rest = withoutHash.substring(colonIndex + 1);

  let decodedPath;
  try {
    decodedPath = decodeURIComponent(rest).replace(/\//g, '\\');
  } catch {
    return null; // Malformed percent-encoding
  }

  // Martin diagram: #md:App/Payment or #md: (root)
  if (prefix === 'md') {
    return {
      type: decodedPath ? 'namespace' : null,
      path: decodedPath,
      view: 'martin',
    };
  }

  // Treemap: #ns:App/Payment or #cl:App/Payment/Proc
  if (prefix !== 'ns' && prefix !== 'cl') return null;

  return {
    type: prefix === 'ns' ? 'namespace' : 'class',
    path: decodedPath,
    view: 'treemap',
  };
}

/**
 * Generates a URL hash for a tree node.
 *
 * @param {object} node - Tree node
 * @param {string} [view='treemap'] - Current view ('treemap' or 'martin')
 * @returns {string} URL hash
 */
export function generateHash(node, view = 'treemap') {
  if (!node || node.type === 'other') {
    return '';
  }

  // For Martin view, always generate a hash (even for root/project nodes)
  if (view === 'martin') {
    const encodedPath = node.path
      ? encodeURIComponent(node.path.replace(/\\/g, '/'))
      : '';
    return `#md:${encodedPath}`;
  }

  // Treemap: no hash for root/project
  if (!node.path || node.type === 'project') {
    return '';
  }

  const encodedPath = encodeURIComponent(node.path.replace(/\\/g, '/'));
  const prefix = node.type === 'class' ? 'cl' : 'ns';
  return `#${prefix}:${encodedPath}`;
}

/**
 * Initializes hash navigation with hashchange listener.
 *
 * @param {object} rootNode - Root tree node
 * @param {function} navigateTo - Callback to navigate to a node
 * @param {object} [defaultNode] - Node to navigate to when hash is empty
 * @param {function} [switchView] - Callback to switch view ('treemap' or 'martin')
 */
export function initHashNavigation(rootNode, navigateTo, defaultNode, switchView) {
  function handleHash() {
    const parsed = parseHash(window.location.hash);
    if (!parsed) {
      if (switchView) switchView('treemap');
      if (defaultNode) navigateTo(defaultNode);
      return;
    }

    if (switchView && parsed.view) {
      switchView(parsed.view);
    }

    if (!parsed.path) {
      // Root view (e.g., #md: with empty path)
      if (defaultNode) navigateTo(defaultNode);
    } else {
      const node = findNode(rootNode, parsed.path);
      if (node) {
        navigateTo(node);
      }
    }
  }

  window.addEventListener('hashchange', handleHash);

  // Handle initial hash on page load
  if (window.location.hash) {
    handleHash();
  }
}
