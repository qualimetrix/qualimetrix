/**
 * URL hash navigation for deep linking.
 *
 * Format: #ns:App/Payment (namespace), #cl:App/Payment/Processor (class)
 * Backslash \ → / in hash, standard encodeURIComponent for special chars.
 */

import { findNode } from './tree.js';

/**
 * Parses a URL hash into a navigation target.
 *
 * @param {string} hash - URL hash (e.g., '#ns:App/Payment')
 * @returns {{ type: 'namespace'|'class'|null, path: string }|null}
 */
export function parseHash(hash) {
  if (!hash || hash === '#') return null;

  const withoutHash = hash.startsWith('#') ? hash.substring(1) : hash;

  const colonIndex = withoutHash.indexOf(':');
  if (colonIndex === -1) return null;

  const prefix = withoutHash.substring(0, colonIndex);
  const encodedPath = withoutHash.substring(colonIndex + 1);

  if (prefix !== 'ns' && prefix !== 'cl') return null;

  const decodedPath = decodeURIComponent(encodedPath).replace(/\//g, '\\');

  return {
    type: prefix === 'ns' ? 'namespace' : 'class',
    path: decodedPath,
  };
}

/**
 * Generates a URL hash for a tree node.
 *
 * @param {object} node - Tree node
 * @returns {string} URL hash (e.g., '#ns:App/Payment')
 */
export function generateHash(node) {
  if (!node || !node.path || node.type === 'project' || node.type === 'other') {
    return '';
  }

  const prefix = node.type === 'class' ? 'cl' : 'ns';
  const encodedPath = encodeURIComponent(node.path.replace(/\\/g, '/'));

  return `#${prefix}:${encodedPath}`;
}

/**
 * Initializes hash navigation with hashchange listener.
 *
 * @param {object} rootNode - Root tree node
 * @param {function} navigateTo - Callback to navigate to a node
 */
export function initHashNavigation(rootNode, navigateTo) {
  function handleHash() {
    const parsed = parseHash(window.location.hash);
    if (!parsed) return;

    const node = findNode(rootNode, parsed.path);
    if (node) {
      navigateTo(node);
    }
  }

  window.addEventListener('hashchange', handleHash);

  // Handle initial hash on page load
  if (window.location.hash) {
    handleHash();
  }
}
