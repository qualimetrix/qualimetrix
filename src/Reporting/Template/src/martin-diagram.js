/**
 * Martin Diagram — Instability/Abstractness scatter plot.
 *
 * Renders an SVG scatter plot following Robert C. Martin's package metrics:
 * X = Instability (0..1), Y = Abstractness (0..1).
 * Diagonal = Main Sequence (A + I = 1).
 * Zones of Pain (stable + concrete) and Uselessness (unstable + abstract) are shaded.
 */

import { scaleLinear, scaleSqrt } from 'd3-scale';
import { axisBottom, axisLeft } from 'd3-axis';
import { line } from 'd3-shape';
import { select } from 'd3-selection';
import { format } from 'd3-format';
import { escapeHtml } from './detail.js';

const MARGIN = { top: 24, right: 24, bottom: 48, left: 52 };
const MIN_RADIUS = 4;
const MAX_RADIUS = 30;

/** Minimum inner dimensions (px) to render zone labels */
const LABEL_MIN_SIZE = 200;

/** Singleton tooltip element, reused across re-renders */
let tooltipEl = null;

/**
 * Collects direct namespace children that have both instability and abstractness metrics.
 *
 * @param {object} node - Parent tree node
 * @returns {Array<{node: object, instability: number, abstractness: number, distance: number, loc: number}>}
 */
export function collectNamespacesWithMetrics(node) {
  if (!node.children) return [];

  const result = [];

  for (const child of node.children) {
    if (child.type !== 'namespace') continue;

    const instability = child.metrics?.instability;
    const abstractness = child.metrics?.abstractness;

    if (instability == null || abstractness == null) continue;
    if (!Number.isFinite(instability) || !Number.isFinite(abstractness)) continue;

    result.push({
      node: child,
      instability,
      abstractness,
      distance: child.metrics?.distance ?? Math.abs(abstractness + instability - 1),
      loc: child.metrics?.['loc.sum'] ?? 0,
    });
  }

  return result;
}

/**
 * Checks whether a namespace has child namespaces with I/A metrics (can drill down).
 *
 * @param {object} node - Namespace tree node
 * @returns {boolean}
 */
export function canDrillDown(node) {
  return collectNamespacesWithMetrics(node).length > 0;
}

/**
 * Creates a radius scale for dot sizes based on LOC values.
 *
 * @param {number[]} locValues - Array of LOC values
 * @returns {function} Scale function: loc -> radius in px
 */
export function createRadiusScale(locValues) {
  if (locValues.length === 0) {
    return () => MIN_RADIUS;
  }

  let min = Infinity;
  let max = -Infinity;
  for (const v of locValues) {
    if (v < min) min = v;
    if (v > max) max = v;
  }

  if (min === max) {
    return () => (MIN_RADIUS + MAX_RADIUS) / 2;
  }

  return scaleSqrt()
    .domain([min, max])
    .range([MIN_RADIUS, MAX_RADIUS])
    .clamp(true);
}

/**
 * Creates a color scale for distance from main sequence.
 * Max theoretical distance is ~0.707 (corner to diagonal), so 0.5 maps to full red.
 *
 * @returns {function} Scale function: distance (0..1) -> CSS color string
 */
export function createDistanceColorScale() {
  return scaleLinear()
    .domain([0, 0.25, 0.5])
    .range(['#28a745', '#ffc107', '#dc3545'])
    .clamp(true);
}

/**
 * Removes the Martin Diagram tooltip from the DOM.
 * Called on view switch to prevent stale tooltips.
 */
export function cleanupTooltip() {
  if (tooltipEl) {
    tooltipEl.remove();
    tooltipEl = null;
  }
}

/**
 * Renders the Martin Diagram for a given node.
 *
 * @param {object} node - Tree node whose namespace children become dots
 * @param {HTMLElement} container - DOM container to render into
 * @param {object} callbacks
 * @param {function} callbacks.onDrillDown - Called with namespace node on drill-down click
 * @param {function} callbacks.onSwitchToTreemap - Called with leaf namespace node
 * @param {function} callbacks.onSelect - Called with namespace node on click (for detail panel)
 */
export function renderMartinDiagram(node, container, { onDrillDown, onSwitchToTreemap, onSelect }) {
  // Hide tooltip before destroying SVG (prevents ghost tooltip on drill-down)
  if (tooltipEl) tooltipEl.style.display = 'none';

  // Read dimensions BEFORE clearing content (same pattern as treemap).
  // This preserves drag-resize inline styles; switchView() handles resets.
  const width = container.clientWidth;
  const height = container.clientHeight;

  container.innerHTML = '';

  const data = collectNamespacesWithMetrics(node);
  const totalNamespaces = node.children
    ? node.children.filter(c => c.type === 'namespace').length
    : 0;

  if (data.length === 0) {
    renderEmptyState(container);
    return;
  }

  if (width <= 0 || height <= 0) return;

  const innerWidth = width - MARGIN.left - MARGIN.right;
  const innerHeight = height - MARGIN.top - MARGIN.bottom;

  if (innerWidth < 60 || innerHeight < 60) return;

  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('width', width);
  svg.setAttribute('height', height);
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', `Martin Diagram: ${data.length} namespaces`);
  svg.classList.add('martin-diagram-svg');
  container.appendChild(svg);

  const d3svg = select(svg);

  const g = d3svg.append('g')
    .attr('transform', `translate(${MARGIN.left},${MARGIN.top})`);

  // Scales
  const xScale = scaleLinear().domain([0, 1]).range([0, innerWidth]);
  const yScale = scaleLinear().domain([0, 1]).range([innerHeight, 0]);

  // Background zones
  renderZones(g, xScale, yScale, innerWidth, innerHeight);

  // Main sequence line
  renderMainSequence(g, xScale, yScale, innerWidth, innerHeight);

  // Grid lines
  renderGrid(g, xScale, yScale, innerWidth, innerHeight);

  // Axes
  renderAxes(g, xScale, yScale, innerWidth, innerHeight);

  // Precompute drill-down eligibility to avoid per-dot re-traversal
  const drillMap = new Map();
  for (const d of data) {
    drillMap.set(d.node, canDrillDown(d.node));
  }

  // Dots
  const radiusScale = createRadiusScale(data.map(d => d.loc));
  const colorScale = createDistanceColorScale();

  renderDots(g, data, xScale, yScale, radiusScale, colorScale, drillMap, {
    onDrillDown,
    onSwitchToTreemap,
    onSelect,
  });

  // Show hint when some namespaces are missing metrics
  const skipped = totalNamespaces - data.length;
  if (skipped > 0) {
    const hint = document.createElement('div');
    hint.className = 'md-hint';
    hint.textContent = `${skipped} namespace${skipped > 1 ? 's' : ''} not shown (no instability/abstractness data)`;
    container.appendChild(hint);
  }
}

function renderEmptyState(container) {
  const msg = document.createElement('div');
  msg.className = 'md-empty-state';
  msg.innerHTML = '<p>No instability/abstractness data available at this level.</p>' +
    '<p>Try navigating to a parent namespace, or switch to Treemap view.</p>';
  container.appendChild(msg);
}

function renderZones(g, xScale, yScale, innerWidth, innerHeight) {
  // Zone of Pain: bottom-left corner (low I = stable, low A = concrete)
  g.append('polygon')
    .attr('points', [
      [xScale(0), yScale(0)],
      [xScale(0.5), yScale(0)],
      [xScale(0), yScale(0.5)],
    ].map(p => p.join(',')).join(' '))
    .attr('class', 'zone-pain');

  // Zone of Uselessness: top-right corner (high I = unstable, high A = abstract)
  g.append('polygon')
    .attr('points', [
      [xScale(1), yScale(1)],
      [xScale(0.5), yScale(1)],
      [xScale(1), yScale(0.5)],
    ].map(p => p.join(',')).join(' '))
    .attr('class', 'zone-uselessness');

  // Zone labels (only when plot is large enough to avoid overlap)
  if (innerWidth >= LABEL_MIN_SIZE && innerHeight >= LABEL_MIN_SIZE) {
    const pain = g.append('g').attr('class', 'zone-label-group');
    pain.append('text')
      .attr('x', xScale(0.08))
      .attr('y', yScale(0.12))
      .attr('class', 'zone-label')
      .text('Zone of Pain');
    pain.append('text')
      .attr('x', xScale(0.08))
      .attr('y', yScale(0.12) + 14)
      .attr('class', 'zone-label-hint')
      .text('Stable & concrete — hard to change');

    const useless = g.append('g').attr('class', 'zone-label-group');
    useless.append('text')
      .attr('x', xScale(0.92))
      .attr('y', yScale(0.92))
      .attr('class', 'zone-label zone-label-right')
      .text('Zone of Uselessness');
    useless.append('text')
      .attr('x', xScale(0.92))
      .attr('y', yScale(0.92) + 14)
      .attr('class', 'zone-label-hint zone-label-right')
      .text('Unstable & abstract — dead code');
  }
}

function renderMainSequence(g, xScale, yScale, innerWidth, innerHeight) {
  const lineGen = line()
    .x(d => xScale(d[0]))
    .y(d => yScale(d[1]));

  g.append('path')
    .attr('d', lineGen([[0, 1], [1, 0]]))
    .attr('class', 'main-sequence-line');

  // Label (only on larger plots)
  if (innerWidth >= LABEL_MIN_SIZE && innerHeight >= LABEL_MIN_SIZE) {
    g.append('text')
      .attr('x', xScale(0.55))
      .attr('y', yScale(0.55) - 6)
      .attr('class', 'main-sequence-label')
      .attr('transform', `rotate(-45, ${xScale(0.55)}, ${yScale(0.55) - 6})`)
      .text('Main Sequence \u2014 ideal balance');
  }
}

function renderGrid(g, xScale, yScale, innerWidth, innerHeight) {
  const ticks = [0.2, 0.4, 0.6, 0.8];

  for (const t of ticks) {
    g.append('line')
      .attr('x1', xScale(t)).attr('y1', 0)
      .attr('x2', xScale(t)).attr('y2', innerHeight)
      .attr('class', 'md-grid-line');

    g.append('line')
      .attr('x1', 0).attr('y1', yScale(t))
      .attr('x2', innerWidth).attr('y2', yScale(t))
      .attr('class', 'md-grid-line');
  }
}

function renderAxes(g, xScale, yScale, innerWidth, innerHeight) {
  const fmt = format('.1f');

  const xAxis = axisBottom(xScale)
    .tickValues([0, 0.2, 0.4, 0.6, 0.8, 1.0])
    .tickFormat(fmt);

  const yAxis = axisLeft(yScale)
    .tickValues([0, 0.2, 0.4, 0.6, 0.8, 1.0])
    .tickFormat(fmt);

  g.append('g')
    .attr('class', 'md-axis md-axis-x')
    .attr('transform', `translate(0,${innerHeight})`)
    .call(xAxis);

  g.append('g')
    .attr('class', 'md-axis md-axis-y')
    .call(yAxis);

  // Axis labels with explanations
  const xLabelY = innerHeight + 36;
  g.append('text')
    .attr('class', 'md-axis-label')
    .attr('x', innerWidth / 2)
    .attr('y', xLabelY)
    .text('Instability \u2014 likelihood of being affected by changes (0 = stable, 1 = unstable)');

  g.append('text')
    .attr('class', 'md-axis-label')
    .attr('transform', 'rotate(-90)')
    .attr('x', -innerHeight / 2)
    .attr('y', -40)
    .text('Abstractness \u2014 ratio of interfaces to total types (0 = concrete, 1 = abstract)');
}

function ensureTooltip() {
  if (!tooltipEl) {
    tooltipEl = document.createElement('div');
    tooltipEl.className = 'md-tooltip';
    tooltipEl.style.display = 'none';
    document.body.appendChild(tooltipEl);
  }
  return tooltipEl;
}

function renderDots(g, data, xScale, yScale, radiusScale, colorScale, drillMap, callbacks) {
  const dots = g.append('g').attr('class', 'md-dots');

  for (const d of data) {
    const cx = xScale(d.instability);
    const cy = yScale(d.abstractness);
    const r = radiusScale(d.loc);
    const fill = colorScale(d.distance);
    const drillable = drillMap.get(d.node);

    const fmt2 = format('.2f');
    const circle = dots.append('circle')
      .attr('cx', cx)
      .attr('cy', cy)
      .attr('r', r)
      .attr('fill', fill)
      .attr('class', 'md-dot' + (drillable ? ' md-dot-drillable' : ''))
      .attr('data-testid', `md-dot-${d.node.name}`)
      .attr('data-name', d.node.name)
      .attr('data-path', d.node.path || '')
      .attr('data-instability', fmt2(d.instability))
      .attr('data-abstractness', fmt2(d.abstractness))
      .attr('data-distance', fmt2(d.distance));

    // Text label next to dot
    dots.append('text')
      .attr('x', cx + r + 4)
      .attr('y', cy + 4)
      .attr('class', 'md-dot-label')
      .text(d.node.name);

    circle.on('mouseenter', (event) => {
      const tooltip = ensureTooltip();
      const verdict = getVerdict(d.instability, d.abstractness, d.distance);
      tooltip.innerHTML = `<strong>${escapeHtml(d.node.name)}</strong><br>` +
        `Instability: ${fmt2(d.instability)} <span style="color:#aaa">(how easy to affect)</span><br>` +
        `Abstractness: ${fmt2(d.abstractness)} <span style="color:#aaa">(interfaces ratio)</span><br>` +
        `Distance: ${fmt2(d.distance)} <span style="color:#aaa">(from ideal)</span><br>` +
        `LOC: ${d.loc.toLocaleString()}<br>` +
        `<span style="margin-top:4px;display:inline-block;color:${verdict.color}">${verdict.text}</span>`;
      tooltip.style.display = 'block';
      moveTooltip(event, tooltip);
    });

    circle.on('mousemove', (event) => {
      const tooltip = ensureTooltip();
      moveTooltip(event, tooltip);
    });

    circle.on('mouseleave', () => {
      if (tooltipEl) tooltipEl.style.display = 'none';
    });

    circle.on('click', () => {
      if (drillable && callbacks.onDrillDown) {
        callbacks.onDrillDown(d.node);
      } else if (!drillable) {
        // Select leaf namespace (show in detail panel) and switch to treemap
        if (callbacks.onSelect) callbacks.onSelect(d.node);
        if (callbacks.onSwitchToTreemap) callbacks.onSwitchToTreemap(d.node);
      }
    });
  }
}

/**
 * Returns a human-readable verdict for a namespace's position on the diagram.
 */
function getVerdict(instability, abstractness, distance) {
  // Zone of Pain: low I, low A (stable & concrete)
  if (instability < 0.3 && abstractness < 0.3) {
    return {
      text: 'Zone of Pain — rigid, consider adding interfaces',
      color: '#dc3545',
    };
  }

  // Zone of Uselessness: high I, high A (unstable & abstract)
  if (instability > 0.7 && abstractness > 0.7) {
    return {
      text: 'Zone of Uselessness — unused abstractions, simplify',
      color: '#fd7e14',
    };
  }

  if (distance <= 0.15) {
    return { text: 'Well balanced — close to ideal', color: '#28a745' };
  }

  if (distance <= 0.3) {
    return { text: 'Acceptable — minor imbalance', color: '#ffc107' };
  }

  return { text: 'Far from ideal — review abstraction/stability balance', color: '#dc3545' };
}

function moveTooltip(event, tooltip) {
  const offsetX = 14;
  const offsetY = 14;
  let x = event.clientX + offsetX;
  let y = event.clientY + offsetY;

  const rect = tooltip.getBoundingClientRect();
  if (x + rect.width > window.innerWidth) {
    x = event.clientX - rect.width - offsetX;
  }
  if (y + rect.height > window.innerHeight) {
    y = event.clientY - rect.height - offsetY;
  }

  tooltip.style.left = x + 'px';
  tooltip.style.top = y + 'px';
}
