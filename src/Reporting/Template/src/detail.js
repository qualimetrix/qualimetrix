/**
 * Detail panel — renders health bars, worst offenders, metrics, violations.
 */

import { getWorstOffenders } from './tree.js';
import { getWorstSubNamespaces } from './subtree.js';

/** @type {function|null} Navigation callback for sub-namespace clicks */
let _navigateTo = null;

/**
 * Sets the navigation callback used by sub-namespace click handlers.
 *
 * @param {function} fn - navigateTo(node) callback
 */
export function setNavigateTo(fn) {
  _navigateTo = fn;
}

/**
 * Renders the detail panel for a selected node.
 *
 * @param {object} node - Selected tree node
 * @param {object} summary - Report summary data
 * @param {string} currentMetric - Currently selected metric
 */
export function renderDetail(node, summary, currentMetric) {
  if (!node) return;

  renderHealthBars(node, summary);
  renderNodeSummary(node);
  renderWorstSubNamespaces(node, currentMetric);
  renderWorstClasses(node, currentMetric);
  renderMetricsTable(node);
  renderViolationsTable(node);
}

function renderHealthBars(node, summary) {
  const container = document.getElementById('health-bars');
  if (!container) return;

  const healthMetrics = ['health.complexity', 'health.cohesion', 'health.coupling',
    'health.typing', 'health.maintainability', 'health.overall'];

  // Use node metrics if available, otherwise summary
  const source = node.type === 'project' ? summary.healthScores : node.metrics;

  container.innerHTML = '';

  for (const metric of healthMetrics) {
    const value = source?.[metric];
    if (value == null) continue;

    const label = metric.replace('health.', '');
    const row = document.createElement('div');
    row.className = 'health-bar-row';

    const nameEl = document.createElement('span');
    nameEl.className = 'health-bar-label';
    nameEl.textContent = label;

    const barOuter = document.createElement('div');
    barOuter.className = 'health-bar-outer';

    const barInner = document.createElement('div');
    barInner.className = 'health-bar-inner';
    barInner.style.width = `${Math.max(0, Math.min(100, value))}%`;
    barInner.setAttribute('data-score', Math.round(value));

    const valueEl = document.createElement('span');
    valueEl.className = 'health-bar-value';
    valueEl.textContent = Math.round(value);

    barOuter.appendChild(barInner);
    row.appendChild(nameEl);
    row.appendChild(barOuter);
    row.appendChild(valueEl);
    container.appendChild(row);
  }
}

function renderNodeSummary(node) {
  const container = document.getElementById('node-summary');
  if (!container) return;

  container.innerHTML = '';

  const items = [];

  const loc = node.metrics?.['loc.sum'];
  if (loc != null) items.push(['Lines of Code', loc.toLocaleString()]);

  const violations = node.violationCountTotal;
  if (violations != null) items.push(['Violations', violations.toLocaleString()]);

  const debt = node.debtMinutes;
  if (debt != null && debt > 0) {
    const hours = Math.floor(debt / 60);
    const mins = debt % 60;
    const formatted = hours > 0 ? `${hours}h ${mins}m` : `${mins}m`;
    items.push(['Tech Debt', formatted]);
  }

  // Child counts for non-class nodes
  if (node.children && node.children.length > 0) {
    const namespaces = node.children.filter(c => c.type === 'namespace').length;
    const classes = node.children.filter(c => c.type === 'class').length;
    if (namespaces > 0) items.push(['Namespaces', namespaces.toLocaleString()]);
    if (classes > 0) items.push(['Classes', classes.toLocaleString()]);
  }

  for (const [label, value] of items) {
    const row = document.createElement('div');
    row.className = 'summary-row';

    const labelEl = document.createElement('span');
    labelEl.className = 'summary-label';
    labelEl.textContent = label;

    const valueEl = document.createElement('span');
    valueEl.className = 'summary-value';
    valueEl.textContent = value;

    row.appendChild(labelEl);
    row.appendChild(valueEl);
    container.appendChild(row);
  }
}

function renderWorstSubNamespaces(node, metric) {
  const container = document.getElementById('worst-sub-namespaces');
  if (!container) return;

  container.innerHTML = '';

  if (node.type === 'class') {
    container.style.display = 'none';
    return;
  }

  const worst = getWorstSubNamespaces(node, 10, metric);
  if (worst.length === 0) {
    container.style.display = 'none';
    return;
  }

  container.style.display = '';

  const title = document.createElement('h3');
  title.textContent = 'Worst Sub-Namespaces';
  container.appendChild(title);

  const table = document.createElement('table');
  table.className = 'worst-offenders-table';

  const thead = document.createElement('thead');
  thead.innerHTML = '<tr><th>Namespace</th><th>Subtree Score</th><th>LOC</th><th>Violations</th></tr>';
  table.appendChild(thead);

  const tbody = document.createElement('tbody');
  for (const ns of worst) {
    const tr = document.createElement('tr');
    tr.className = 'clickable-row';
    tr.innerHTML = `<td>${escapeHtml(ns.name)}</td>` +
      `<td>${Math.round(ns._subtree.metrics[metric] ?? 0)}</td>` +
      `<td>${ns._subtree.loc}</td>` +
      `<td>${ns._subtree.violationCount}</td>`;
    tr.addEventListener('click', () => {
      if (_navigateTo) _navigateTo(ns);
    });
    tbody.appendChild(tr);
  }
  table.appendChild(tbody);
  container.appendChild(table);
}

function renderWorstClasses(node, metric) {
  const container = document.getElementById('worst-offenders');
  if (!container) return;

  container.innerHTML = '';

  if (node.type === 'class') {
    container.style.display = 'none';
    return;
  }

  container.style.display = '';

  const worst = getWorstOffenders(node, 10, metric);
  if (worst.length === 0) {
    container.innerHTML = '<p class="empty-state">No classes with health scores</p>';
    return;
  }

  const details = document.createElement('details');
  details.className = 'worst-classes-toggle';

  const summary = document.createElement('summary');
  summary.textContent = `Worst Classes (${worst.length})`;
  details.appendChild(summary);

  const table = document.createElement('table');
  table.className = 'worst-offenders-table';

  const thead = document.createElement('thead');
  thead.innerHTML = '<tr><th>Class</th><th>Score</th><th>LOC</th><th>Violations</th></tr>';
  table.appendChild(thead);

  const tbody = document.createElement('tbody');
  for (const cls of worst) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${escapeHtml(cls.name)}</td>` +
      `<td>${Math.round(cls.metrics[metric] ?? 0)}</td>` +
      `<td>${cls.metrics['loc.sum'] ?? 0}</td>` +
      `<td>${cls.violationCountTotal}</td>`;
    tbody.appendChild(tr);
  }
  table.appendChild(tbody);
  details.appendChild(table);
  container.appendChild(details);
}

function renderMetricsTable(node) {
  const container = document.getElementById('metrics-table');
  if (!container) return;

  container.innerHTML = '';

  if (!node.metrics || Object.keys(node.metrics).length === 0) {
    return;
  }

  const title = document.createElement('h3');
  title.textContent = 'Metrics';
  container.appendChild(title);

  const table = document.createElement('table');
  table.className = 'metrics-table';

  const tbody = document.createElement('tbody');

  // Group metrics: health first, then others
  const entries = Object.entries(node.metrics)
    .filter(([, v]) => v != null)
    .sort(([a], [b]) => {
      const aHealth = a.startsWith('health.') ? 0 : 1;
      const bHealth = b.startsWith('health.') ? 0 : 1;
      if (aHealth !== bHealth) return aHealth - bHealth;
      return a.localeCompare(b);
    });

  for (const [key, value] of entries) {
    const tr = document.createElement('tr');
    const keyTd = document.createElement('td');
    keyTd.textContent = key;
    const valTd = document.createElement('td');
    valTd.textContent = typeof value === 'number' ? formatMetricValue(value) : String(value);
    tr.appendChild(keyTd);
    tr.appendChild(valTd);
    tbody.appendChild(tr);
  }

  table.appendChild(tbody);
  container.appendChild(table);
}

function renderViolationsTable(node) {
  const container = document.getElementById('violations-table');
  if (!container) return;

  container.innerHTML = '';

  if (!node.violations || node.violations.length === 0) {
    return;
  }

  const title = document.createElement('h3');
  title.textContent = `Violations (${node.violations.length})`;
  container.appendChild(title);

  const table = document.createElement('table');
  table.className = 'violations-table';

  const thead = document.createElement('thead');
  thead.innerHTML = '<tr><th>Rule</th><th>Severity</th><th>Message</th><th>Line</th></tr>';
  table.appendChild(thead);

  const tbody = document.createElement('tbody');

  const sorted = [...node.violations].sort((a, b) => {
    // Errors first
    if (a.severity !== b.severity) return a.severity === 'error' ? -1 : 1;
    return a.ruleName.localeCompare(b.ruleName);
  });

  for (const v of sorted) {
    const tr = document.createElement('tr');
    tr.className = `violation-${v.severity}`;
    tr.innerHTML = `<td>${escapeHtml(v.ruleName)}</td>` +
      `<td>${escapeHtml(v.severity)}</td>` +
      `<td>${escapeHtml(v.message)}</td>` +
      `<td>${v.line ?? ''}</td>`;
    tbody.appendChild(tr);
  }

  table.appendChild(tbody);
  container.appendChild(table);
}

function formatMetricValue(value) {
  if (Number.isInteger(value)) return String(value);
  return value.toFixed(2);
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

// Export escapeHtml for reuse
export { escapeHtml, formatMetricValue };
