/**
 * AIMD HTML Report — Main Entry Point
 *
 * Reads embedded JSON data and renders the interactive treemap report.
 */

import { hierarchy, treemap, treemapSquarify } from 'd3-hierarchy';

import { buildTreeData, findNode, getLoc, aggregateSmallNodes } from './tree.js';
import { createColorScale, getHealthColor } from './color.js';
import { parseHash, generateHash, initHashNavigation } from './hash.js';
import { createSearchHandler } from './search.js';
import { renderDetail, setNavigateTo } from './detail.js';
import { computeSubtreeMetrics } from './subtree.js';

/** @type {import('./types').ReportData} */
let DATA;

/** @type {string} */
let currentMetric = 'health.overall';

/** @type {import('./types').TreeNode|null} */
let currentNode = null;

/** @type {function} Color scale function */
let colorScale;

/** @type {HTMLElement|null} Tooltip element */
let tooltip = null;

/** Debounce timer for resize */
let resizeTimer = null;

/**
 * Initialize the report application.
 */
export function init() {
  const el = document.getElementById('report-data');
  if (!el || !el.textContent) {
    console.error('No report data found');
    return;
  }

  DATA = JSON.parse(el.textContent);

  // Detect neutral color from CSS variable (adapts to dark/light mode)
  const neutralColor = getComputedStyle(document.documentElement)
    .getPropertyValue('--color-neutral').trim() || '#ffffff';
  colorScale = createColorScale(neutralColor);

  // Show partial analysis warning if needed
  if (DATA.project.partialAnalysis) {
    const warning = document.getElementById('partial-warning');
    if (warning) {
      const fileCount = DATA.summary.totalFiles;
      warning.textContent = `Partial analysis: only ${fileCount} files analyzed. Health scores and aggregated metrics may be incomplete.`;
      warning.style.display = 'block';
    }
  }

  // Build tree data for D3
  const treeData = buildTreeData(DATA.tree);

  // Compute subtree metrics for hierarchical roll-up (worst sub-namespaces)
  computeSubtreeMetrics(treeData);

  // Auto-drill into single-child namespaces to skip unhelpful single-rectangle views
  // e.g., <project> → AiMessDetector (single root ns) → show AiMessDetector's children directly
  let initialNode = treeData;
  while (initialNode.children && initialNode.children.length === 1
    && initialNode.children[0].children && initialNode.children[0].children.length > 0
    && initialNode.children[0].type === 'namespace') {
    initialNode = initialNode.children[0];
  }

  currentNode = initialNode;

  // Create tooltip element
  createTooltip();

  // Initialize components
  initMetricSelector(DATA.computedMetricDefinitions);
  initBreadcrumb();
  initSearch();
  initHashNavigation(treeData, navigateTo);
  setNavigateTo(navigateTo);
  renderTreemap(currentNode);
  updateBreadcrumb(currentNode);
  renderDetail(currentNode, DATA.summary, currentMetric);
  renderFooter(DATA.project);

  // Resizable split panel
  initResizeHandle();

  // Resize handler with debounce
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (currentNode) {
        renderTreemap(currentNode);
      }
    }, 150);
  });
}

function initMetricSelector(definitions) {
  const sel = document.getElementById('metric-selector');
  if (!sel) return;

  // Always include 'health.overall' first
  const metrics = Object.keys(definitions);
  if (!metrics.includes('health.overall')) {
    metrics.unshift('health.overall');
  }

  sel.innerHTML = '';
  for (const metric of metrics) {
    const option = document.createElement('option');
    option.value = metric;
    option.textContent = definitions[metric]?.description || metric;
    sel.appendChild(option);
  }

  sel.addEventListener('change', (e) => {
    const previousMetric = currentMetric;
    currentMetric = e.target.value;

    if (previousMetric !== currentMetric) {
      animateColorTransition();
    }

    renderDetail(currentNode, DATA.summary, currentMetric);
  });
}

function initBreadcrumb() {
  const nav = document.getElementById('breadcrumb');
  if (!nav) return;

  // Click handler delegated to nav for breadcrumb links
  nav.addEventListener('click', (e) => {
    const link = e.target.closest('a[data-path]');
    if (!link) return;

    e.preventDefault();
    const path = link.getAttribute('data-path');
    const treeData = buildTreeData(DATA.tree);
    const target = path === '' ? treeData : findNode(treeData, path);
    if (target) {
      navigateTo(target);
    }
  });
}

function initSearch() {
  const input = document.getElementById('search');
  if (!input) return;

  const handler = createSearchHandler(DATA.tree);
  input.addEventListener('input', (e) => {
    handler(e.target.value);
  });
}

// ---------------------------------------------------------------------------
// Treemap rendering
// ---------------------------------------------------------------------------

/**
 * Renders the D3 treemap for the given node.
 *
 * Shows only direct children of the current node (single-level).
 * Namespaces appear as rectangles with their aggregated LOC.
 * Click a namespace to drill down. Click a class to select it.
 *
 * @param {object} node - Tree node to render
 */
function renderTreemap(node) {
  const container = document.getElementById('treemap');
  if (!container) return;

  const width = container.clientWidth;
  const height = container.clientHeight;

  if (width <= 0 || height <= 0) return;

  // Clear existing content
  container.innerHTML = '';

  const children = node.children || [];

  // If this is a leaf node (class), show a single colored rectangle
  if (!children.length) {
    renderLeafNode(container, node, width, height);
    return;
  }

  // Prepare children with small-node aggregation
  const totalArea = width * height;
  const totalLoc = children.reduce((sum, c) => sum + getLoc(c), 0);
  const visibleChildren = aggregateSmallNodes(children, totalArea, totalLoc);

  // Build single-level hierarchy: root + direct children only (no recursion)
  const treeRoot = {
    name: node.name,
    children: visibleChildren.map(child => ({
      name: child.name,
      _loc: Math.max(getLoc(child), 1), // D3 needs > 0
      _sourceNode: child,
    })),
  };

  // Create D3 hierarchy
  const root = hierarchy(treeRoot)
    .sum(d => d._loc || 0)
    .sort((a, b) => b.value - a.value);

  // Apply treemap layout
  treemap()
    .size([width, height])
    .paddingInner(2)
    .paddingOuter(1)
    .tile(treemapSquarify)
    (root);

  // Render each direct child
  for (const child of root.children || []) {
    const d = child.data;
    const sourceNode = d._sourceNode;
    if (!sourceNode) continue;

    const nodeWidth = child.x1 - child.x0;
    const nodeHeight = child.y1 - child.y0;

    if (nodeWidth < 1 || nodeHeight < 1) continue;

    const div = document.createElement('div');
    div.className = 'node';
    div.setAttribute('data-path', sourceNode.path || '');

    // Position
    div.style.left = child.x0 + 'px';
    div.style.top = child.y0 + 'px';
    div.style.width = nodeWidth + 'px';
    div.style.height = nodeHeight + 'px';

    // Color from health score
    const bgColor = getHealthColor(sourceNode, currentMetric, colorScale);
    div.style.backgroundColor = bgColor;

    // Red border only for nodes with their own error-severity violations
    if (hasOwnErrorViolations(sourceNode)) {
      div.classList.add('has-violations');
    }

    // Label and sub-info
    if (nodeWidth > 40 && nodeHeight > 18) {
      const label = document.createElement('div');
      label.className = 'node-label';
      label.textContent = sourceNode.name;
      label.style.maxWidth = (nodeWidth - 8) + 'px';
      div.appendChild(label);

      // Show health score + LOC on larger rectangles
      const healthScore = sourceNode.metrics?.[currentMetric];
      const nodeLoc = getLoc(sourceNode);
      if (nodeWidth > 80 && nodeHeight > 36 && (healthScore != null || nodeLoc > 0)) {
        const sub = document.createElement('div');
        sub.className = 'node-sub';
        const parts = [];
        if (healthScore != null) parts.push(Math.round(healthScore));
        if (nodeLoc > 0) parts.push(nodeLoc.toLocaleString() + ' LOC');
        sub.textContent = parts.join(' · ');
        sub.style.maxWidth = (nodeWidth - 8) + 'px';
        div.appendChild(sub);
      }

      // Show violation count badge on nodes with violations
      if (sourceNode.violationCountTotal > 0 && nodeWidth > 50 && nodeHeight > 28) {
        const badge = document.createElement('span');
        badge.className = 'node-badge';
        badge.textContent = sourceNode.violationCountTotal;
        div.appendChild(badge);
      }
    }

    // Click handler
    div.addEventListener('click', () => {
      if (sourceNode._isOther) return;

      if (sourceNode.children && sourceNode.children.length > 0 && sourceNode.type !== 'class') {
        // Drill down into namespace
        navigateTo(sourceNode);
        window.location.hash = generateHash(sourceNode);
      } else {
        // Select class — show detail
        selectNode(sourceNode);
        window.location.hash = generateHash(sourceNode);
      }
    });

    // Tooltip handlers
    div.addEventListener('mouseenter', (e) => showTooltip(e, sourceNode));
    div.addEventListener('mousemove', (e) => moveTooltip(e));
    div.addEventListener('mouseleave', () => hideTooltip());

    container.appendChild(div);
  }
}

/**
 * Renders a single leaf node filling the entire container.
 */
function renderLeafNode(container, node, width, height) {
  const div = document.createElement('div');
  div.className = 'node';
  div.setAttribute('data-path', node.path || '');
  div.style.left = '0px';
  div.style.top = '0px';
  div.style.width = width + 'px';
  div.style.height = height + 'px';

  const bgColor = getHealthColor(node, currentMetric, colorScale);
  div.style.backgroundColor = bgColor;

  if (hasErrorViolations(node)) {
    div.classList.add('has-violations');
  }

  const label = document.createElement('div');
  label.className = 'node-label';
  label.textContent = node.name;
  label.style.fontSize = '16px';
  div.appendChild(label);

  div.addEventListener('mouseenter', (e) => showTooltip(e, node));
  div.addEventListener('mousemove', (e) => moveTooltip(e));
  div.addEventListener('mouseleave', () => hideTooltip());

  container.appendChild(div);
}

/**
 * Checks if a node has its own error-severity violations (not descendants).
 */
function hasOwnErrorViolations(node) {
  return node.violations && node.violations.some(v => v.severity === 'error');
}

/**
 * Selects a node (shows detail) without drilling down.
 */
function selectNode(node) {
  currentNode = node;
  renderDetail(node, DATA.summary, currentMetric);
  updateBreadcrumb(node);

  // Highlight the selected node
  const container = document.getElementById('treemap');
  if (container) {
    container.querySelectorAll('.node.selected').forEach(el => el.classList.remove('selected'));
    const el = container.querySelector(`[data-path="${CSS.escape(node.path)}"]`);
    if (el) el.classList.add('selected');
  }
}

// ---------------------------------------------------------------------------
// Breadcrumb
// ---------------------------------------------------------------------------

function navigateTo(node) {
  currentNode = node;
  renderTreemap(node);
  renderDetail(node, DATA.summary, currentMetric);
  updateBreadcrumb(node);
}

function updateBreadcrumb(node) {
  const nav = document.getElementById('breadcrumb');
  if (!nav) return;

  // Build the path from root to current node
  const segments = buildBreadcrumbPath(node);
  nav.innerHTML = '';

  for (let i = 0; i < segments.length; i++) {
    const seg = segments[i];
    const isLast = i === segments.length - 1;

    if (i > 0) {
      const sep = document.createElement('span');
      sep.className = 'separator';
      sep.textContent = ' > ';
      nav.appendChild(sep);
    }

    if (isLast) {
      const span = document.createElement('span');
      span.className = 'current';
      span.textContent = seg.name;
      nav.appendChild(span);
    } else {
      const link = document.createElement('a');
      link.href = '#';
      link.setAttribute('data-path', seg.path);
      link.textContent = seg.name;
      nav.appendChild(link);
    }
  }
}

/**
 * Builds an array of { name, path } segments from root to node.
 */
function buildBreadcrumbPath(node) {
  const treeData = buildTreeData(DATA.tree);
  const segments = [];

  // Always start with root
  segments.push({ name: treeData.name || 'Project', path: '' });

  if (!node.path) return segments;

  // Split the path and find each intermediate node
  const parts = node.path.split('\\');
  let currentPath = '';

  for (let i = 0; i < parts.length; i++) {
    currentPath = i === 0 ? parts[0] : currentPath + '\\' + parts[i];
    const found = findNode(treeData, currentPath);
    if (found) {
      segments.push({ name: found.name, path: found.path });
    } else {
      segments.push({ name: parts[i], path: currentPath });
    }
  }

  return segments;
}

// ---------------------------------------------------------------------------
// Tooltip
// ---------------------------------------------------------------------------

function createTooltip() {
  tooltip = document.createElement('div');
  tooltip.className = 'treemap-tooltip';
  tooltip.style.display = 'none';
  document.body.appendChild(tooltip);
}

function showTooltip(event, node) {
  if (!tooltip) return;

  const loc = getLoc(node);

  let html = `<strong>${escapeHtml(node.name)}</strong><br>`;
  html += `LOC: ${loc.toLocaleString()}`;

  if (node.violationCountTotal > 0) {
    html += ` | Violations: ${node.violationCountTotal}`;
  }

  // Show all health scores as a compact list
  const healthKeys = ['complexity', 'cohesion', 'coupling', 'typing', 'maintainability', 'overall'];
  const healthEntries = healthKeys
    .map(k => ({ label: k, value: node.metrics?.['health.' + k] }))
    .filter(e => e.value != null);

  if (healthEntries.length > 0) {
    html += '<br><span style="color:#aaa">Health:</span>';
    for (const e of healthEntries) {
      const v = Math.round(e.value);
      const color = v >= 70 ? '#28a745' : v >= 40 ? '#ffc107' : '#ff6b6b';
      html += `<br><span style="color:${color}">●</span> ${e.label}: ${v}`;
    }
  }

  // Show up to 3 top issues with useful descriptions
  const violations = node.violations || [];
  const shown = violations.slice(0, 3);
  if (shown.length > 0) {
    html += '<br><span style="color:#aaa">Issues:</span>';
    for (const v of shown) {
      const label = v.violationCode || v.ruleName;
      const sevColor = v.severity === 'error' ? '#ff6b6b' : '#ffc107';
      html += `<br><span style="color:${sevColor}">●</span> ${escapeHtml(label)}`;
    }
    if (violations.length > 3) {
      html += `<br><span style="color:#aaa">+${violations.length - 3} more</span>`;
    }
  }

  if (node._isOther && node.children) {
    html += `<br><span style="color:#aaa">${node.children.length} small items aggregated</span>`;
  }

  tooltip.innerHTML = html;
  tooltip.style.display = 'block';
  moveTooltip(event);
}

function moveTooltip(event) {
  if (!tooltip) return;

  const offsetX = 12;
  const offsetY = 12;

  let x = event.clientX + offsetX;
  let y = event.clientY + offsetY;

  // Keep tooltip within viewport
  const rect = tooltip.getBoundingClientRect();
  const vw = window.innerWidth;
  const vh = window.innerHeight;

  if (x + rect.width > vw) {
    x = event.clientX - rect.width - offsetX;
  }
  if (y + rect.height > vh) {
    y = event.clientY - rect.height - offsetY;
  }

  tooltip.style.left = x + 'px';
  tooltip.style.top = y + 'px';
}

function hideTooltip() {
  if (tooltip) {
    tooltip.style.display = 'none';
  }
}

function escapeHtml(str) {
  if (!str) return '';
  return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// ---------------------------------------------------------------------------
// Metric selector animation
// ---------------------------------------------------------------------------

/**
 * Animates the color transition when the metric selector changes.
 * Transitions each treemap node's background-color over 300ms.
 */
function animateColorTransition() {
  const container = document.getElementById('treemap');
  if (!container) return;

  const nodes = container.querySelectorAll('.node');

  for (const nodeEl of nodes) {
    const path = nodeEl.getAttribute('data-path');
    if (path == null) continue;

    // Find the source node by path
    const treeData = buildTreeData(DATA.tree);
    const sourceNode = path === '' ? treeData : findNode(treeData, path);
    if (!sourceNode) continue;

    const newColor = getHealthColor(sourceNode, currentMetric, colorScale);

    // Use CSS transition for smooth animation
    nodeEl.style.transition = 'background-color 300ms ease';
    nodeEl.style.backgroundColor = newColor;

    // Remove transition property after animation completes
    setTimeout(() => {
      nodeEl.style.transition = 'opacity 0.2s';
    }, 310);
  }
}

// ---------------------------------------------------------------------------
// Resizable split panel
// ---------------------------------------------------------------------------

function initResizeHandle() {
  const handle = document.getElementById('resize-handle');
  const treemapEl = document.getElementById('treemap');
  const detailEl = document.getElementById('detail-panel');
  const layout = document.getElementById('split-layout');

  if (!handle || !treemapEl || !detailEl || !layout) return;

  let isDragging = false;
  let startY = 0;
  let startTreemapHeight = 0;

  handle.addEventListener('mousedown', (e) => {
    isDragging = true;
    startY = e.clientY;
    startTreemapHeight = treemapEl.getBoundingClientRect().height;
    handle.classList.add('dragging');
    document.body.style.cursor = 'row-resize';
    document.body.style.userSelect = 'none';
    e.preventDefault();
  });

  document.addEventListener('mousemove', (e) => {
    if (!isDragging) return;

    const delta = e.clientY - startY;
    const layoutHeight = layout.getBoundingClientRect().height;
    const handleHeight = handle.getBoundingClientRect().height;
    const newTreemapHeight = Math.max(100, Math.min(
      layoutHeight - handleHeight - 100,
      startTreemapHeight + delta,
    ));

    treemapEl.style.flex = 'none';
    treemapEl.style.height = newTreemapHeight + 'px';
    detailEl.style.flex = '1';
  });

  document.addEventListener('mouseup', () => {
    if (!isDragging) return;
    isDragging = false;
    handle.classList.remove('dragging');
    document.body.style.cursor = '';
    document.body.style.userSelect = '';

    // Re-render treemap with new dimensions
    if (currentNode) {
      renderTreemap(currentNode);
    }
  });
}

// ---------------------------------------------------------------------------
// Footer
// ---------------------------------------------------------------------------

function renderFooter(project) {
  const footer = document.getElementById('report-footer');
  if (!footer) return;

  const date = new Date(project.generatedAt);
  const formatted = date.toLocaleDateString(undefined, {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });

  footer.textContent = `Generated ${formatted} | AIMD ${project.aimdVersion}`;
}

// Auto-init when DOM is ready
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}
