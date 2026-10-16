import { readFileSync } from 'node:fs';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { parseHTML } from 'linkedom';
import { collectNamespacesWithMetrics, renderMartinDiagram, cleanupTooltip } from '../src/martin-diagram.js';
import { getLoc, findNode } from '../src/tree.js';
import { getWorstSubNamespaces } from '../src/subtree.js';
import { createColorScale, getHealthColor } from '../src/color.js';
import { init } from '../src/main.js';
import { renderDetail } from '../src/detail.js';

const payload = JSON.parse(readFileSync(new URL('./fixtures/payload.json', import.meta.url), 'utf8'));
const fresh = () => structuredClone(payload);
const classNode = (tree, logicalPath) => {
  const node = findNode(tree, logicalPath);
  if (node?.type === 'class') return node;
  const stack = [tree];
  while (stack.length) {
    const current = stack.pop();
    if (current.type === 'class' && current.path === logicalPath) return current;
    stack.push(...(current.children || []));
  }
  return null;
};
let previous;
beforeEach(() => {
  previous = { document: globalThis.document, window: globalThis.window, getComputedStyle: globalThis.getComputedStyle };
  const { document, window } = parseHTML('<html><body><div id="health-bars"></div><div id="node-summary"></div><div id="worst-sub-namespaces"></div><div id="worst-offenders"></div><div id="metrics-table"></div><div id="violations-table"></div><div id="diagram"></div></body></html>');
  globalThis.document = document;
  globalThis.window = window;
  globalThis.getComputedStyle = () => ({ getPropertyValue: () => '#ffffff' });
  window.innerWidth = 1000;
  window.innerHeight = 800;
  Object.defineProperties(document.getElementById('diagram'), { clientWidth: { value: 800 }, clientHeight: { value: 600 } });
});
afterEach(() => {
  cleanupTooltip();
  Object.assign(globalThis, previous);
});

describe('native HTML payload consumer', () => {
  it('plots the coupling metrics published by the real CLI', () => {
    const data = fresh();
    const app = findNode(data.tree, 'App');
    const points = collectNamespacesWithMetrics(app);
    expect(points).toHaveLength(2);
    const cx = points.find(point => point.node.path === 'App\\Cx');
    expect(cx.instability).toBe(cx.node.metrics['coupling.instability']);
    expect(cx.abstractness).toBe(cx.node.metrics['coupling.abstractness']);
    expect(cx.distance).toBe(cx.node.metrics['coupling.distance']);
    renderMartinDiagram(app, document.getElementById('diagram'), {});
    expect(document.querySelectorAll('circle.md-dot').length).toBe(2);
  });

  it('uses the class-like own LOC as the area weight', () => {
    const data = fresh();
    for (const name of ['App\\Cx\\Greeter', 'App\\Cx\\Greeting', 'App\\Cx\\Status', 'App\\Cx\\Bytes%FF']) {
      const node = classNode(data.tree, name);
      expect(node.metrics).not.toHaveProperty('size.loc.sum');
      expect(getLoc(node)).toBe(node.metrics['size.class-loc']);
      expect(getLoc(node)).toBeGreaterThan(0);
    }
    expect(getLoc(findNode(data.tree, '(no namespace)'))).toBeGreaterThan(0);
  });

  it('keeps the published namespace bag instead of recomputing health from classes', () => {
    const data = fresh();
    const cx = findNode(data.tree, 'App\\Cx');
    const published = structuredClone(cx.metrics);
    const worst = getWorstSubNamespaces(findNode(data.tree, 'App'));
    expect(worst).toContain(cx);
    expect(cx.metrics).toEqual(published);
  });

  it('never invents health.overall from a different metric', () => {
    expect(getHealthColor({ metrics: { 'maintainability.mi.avg': 75 } }, 'health.overall', createColorScale())).toBe('#888888');
  });

  it('renders and sorts actual current records with separate message, advice and accepted status', () => {
    const data = fresh();
    const bytes = classNode(data.tree, 'App\\Cx\\Bytes%FF');
    expect(bytes.violations.filter(record => record.severity === 'error')).toHaveLength(2);
    expect(() => renderDetail(bytes, data.summary, 'health.overall')).not.toThrow();
    const cells = [...document.querySelectorAll('#violations-table tbody tr')].map(row => [...row.querySelectorAll('td')].map(cell => cell.textContent));
    const record = bytes.violations.find(record => record.code === 'architecture.layer-violation');
    expect(cells.find(row => row[0] === record.code)).toEqual([record.code, record.file, record.severity, record.message, record.recommendation, '', String(record.line)]);
    const cx = findNode(data.tree, 'App\\Cx');
    const breach = cx.violations.find(record => record.acceptedLevel);
    renderDetail(cx, data.summary, 'health.overall');
    const row = [...document.querySelectorAll('#violations-table tbody tr td')].map(cell => cell.textContent);
    expect(row).toContain(breach.message);
    expect(row).toContain(breach.recommendation);
    expect(row).toContain(`accepted at ${breach.acceptedLevel.describe}, now ${breach.metricValue}`);
  });

  it('keeps a real namespace diagnostic and advice in independent DOM cells', () => {
    const data = fresh();
    const cx = findNode(data.tree, 'App\\Cx');
    const record = cx.violations[0];
    renderDetail(cx, data.summary, 'health.overall');
    const cells = [...document.querySelectorAll('#violations-table tbody tr td')].map(cell => cell.textContent);
    expect(cells).toContain(record.message);
    expect(cells).toContain(record.recommendation);
    expect(cells).toContain(`accepted at ${record.acceptedLevel.describe}, now ${record.metricValue}`);
  });

  it('shows own class LOC and labels namespace scores as published values', () => {
    const data = fresh();
    const greeter = classNode(data.tree, 'App\\Cx\\Greeter');
    renderDetail(greeter, data.summary, 'health.overall');
    expect(document.getElementById('node-summary').textContent).toContain(`Lines of Code${getLoc(greeter) || greeter.metrics['size.class-loc']}`);
    renderDetail(findNode(data.tree, 'App'), data.summary, 'health.overall');
    expect(document.getElementById('worst-sub-namespaces').textContent).toContain('Published namespace score');
    expect(document.getElementById('metrics-table').textContent).not.toContain('This namespace only — excludes sub-namespaces');
  });

  it('shows current finding codes in the main treemap tooltip', () => {
    const data = fresh();
    data.tree = findNode(data.tree, 'App\\Cx');
    const template = readFileSync(new URL('../report.html', import.meta.url), 'utf8');
    const { document, window } = parseHTML(template.replace('__DATA__', JSON.stringify(data)));
    globalThis.document = document;
    globalThis.window = window;
    window.location = new URL('https://example.test/report.html');
    const container = document.getElementById('treemap');
    Object.defineProperties(container, { clientWidth: { value: 1000 }, clientHeight: { value: 700 } });
    init();
    const bytes = document.querySelector('[data-path="App\\\\Cx\\\\Bytes%FF"]');
    expect(bytes).not.toBeNull();
    bytes.dispatchEvent(new window.Event('mouseenter'));
    const tooltip = document.querySelector('.treemap-tooltip:not(.detail-tooltip)');
    expect(tooltip.textContent).toContain('complexity.ccn');
    expect(tooltip.textContent).toContain('architecture.layer-violation');
    expect(tooltip.textContent).not.toContain('undefined');
  });

  it('shows the published fractional namespace score without a second health calculation', () => {
    const node = { type: 'project', metrics: {}, children: [
      { name: 'Cx', path: 'App\\Cx', type: 'namespace', metrics: { 'health.overall': 42.07, 'size.loc.sum': 100 }, violationCountTotal: 0, children: [
        { name: 'Child', type: 'class', metrics: { 'health.overall': 99, 'size.class-loc': 100 } },
      ] },
    ] };
    renderDetail(node, {}, 'health.overall');
    const cells = [...document.querySelectorAll('#worst-sub-namespaces tbody tr td')].map(cell => cell.textContent);
    expect(cells).toEqual(['Cx', '42.07', '100', '0']);
  });

  it('retains the real global, byte marker and dependency identity in its generated input', () => {
    const data = fresh();
    expect(findNode(data.tree, '(no namespace)').metrics['coupling.instability']).toBe(0);
    expect(data.invalidUtf8Replaced).toBeGreaterThan(0);
    const bytes = classNode(data.tree, 'App\\Cx\\Bytes%FF');
    const edge = bytes.violations.find(record => record.edge);
    expect(edge.edge.target).toBe('class:App\\Domain\\Port');
    expect(edge.occurrence).toMatch(/^[a-f0-9]+$/);
  });
});
