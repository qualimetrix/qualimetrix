import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { parseAst } from 'rollup/parseAst';
import { walk } from 'estree-walker';

import { renderFooter } from '../src/main.js';

// ---------------------------------------------------------------------------
// Minimal DOM stand-in
// ---------------------------------------------------------------------------
//
// The vitest config here runs under environment: 'node' (no jsdom
// dependency is installed), so `renderFooter` is exercised against a
// hand-rolled `document`/element pair rather than a real DOM. It implements
// exactly the surface `renderFooter` touches — `getElementById`,
// `createElement`, `Node.append`, and `textContent` — so the assertions
// below read the footer element's actual mounted content (what `append`
// built), not a static grep of source or the built bundle.

class FakeElement {
  constructor(tagName) {
    this.tagName = tagName;
    this._children = [];
  }

  set textContent(value) {
    this._children = value === '' ? [] : [value];
  }

  get textContent() {
    return this._children
      .map((child) => (typeof child === 'string' ? child : child.textContent))
      .join('');
  }

  append(...nodes) {
    this._children.push(...nodes);
  }

  get links() {
    return this._children.filter((child) => child instanceof FakeElement && child.tagName === 'a');
  }
}

let originalDocument;
let footer;

beforeEach(() => {
  originalDocument = globalThis.document;
  footer = new FakeElement('footer');
  globalThis.document = {
    getElementById: (id) => (id === 'report-footer' ? footer : null),
    createElement: (tag) => new FakeElement(tag),
  };
});

afterEach(() => {
  globalThis.document = originalDocument;
});

const PROJECT = {
  generatedAt: '2026-09-23T10:00:00Z',
  qmxVersion: '1.2.3',
  docs: 'https://qualimetrix.dev',
  llmsTxt: 'https://qualimetrix.dev/llms.txt',
};

describe('renderFooter', () => {
  it('renders the generated date and version', () => {
    renderFooter(PROJECT);

    expect(footer.textContent).toContain('Qualimetrix 1.2.3');
  });

  it('renders the docs address as a clickable link under the "Docs:" label', () => {
    renderFooter(PROJECT);

    const docsLink = footer.links.find((link) => link.href === PROJECT.docs);
    expect(docsLink).toBeDefined();
    expect(docsLink.textContent).toBe(PROJECT.docs);
    expect(footer.textContent).toContain(`Docs: ${PROJECT.docs}`);
  });

  it('renders the llms.txt address as a clickable link under the load-bearing "AI agents:" label', () => {
    renderFooter(PROJECT);

    const llmsLink = footer.links.find((link) => link.href === PROJECT.llmsTxt);
    expect(llmsLink).toBeDefined();
    expect(llmsLink.textContent).toBe(PROJECT.llmsTxt);
    expect(footer.textContent).toContain(`AI agents: ${PROJECT.llmsTxt}`);
  });

  it('does not throw when the documentation addresses are absent from report-data', () => {
    const { docs, llmsTxt, ...projectWithoutAddresses } = PROJECT;

    expect(() => renderFooter(projectWithoutAddresses)).not.toThrow();
    expect(footer.textContent).toContain('Qualimetrix 1.2.3');
    expect(footer.links).toHaveLength(0);
  });

  it('does nothing when the footer element is missing from the page', () => {
    globalThis.document.getElementById = () => null;

    expect(() => renderFooter(PROJECT)).not.toThrow();
  });
});

// ---------------------------------------------------------------------------
// init() -> renderFooter(DATA.project) wiring
// ---------------------------------------------------------------------------
//
// renderFooter() itself is exercised above against a fake DOM; that proves the
// function renders correctly, not that init() still calls it. Driving init()
// live would need a real report-data payload, D3 and a DOM environment this
// suite does not have (see the file header above) — the cheapest thing that
// can still fail when a future edit drops or rewires the call site is a
// structural check over init()'s own AST: a `renderFooter(DATA.project)` call
// expression, directly in init()'s body. `rollup` and `estree-walker` are
// already devDependencies (rollup builds the bundle; estree-walker is one of
// rollup's own dependencies), so this adds no new package.

describe('init() calls renderFooter(DATA.project)', () => {
  it('carries a renderFooter(DATA.project) call in its own body', () => {
    const mainJsPath = fileURLToPath(new URL('../src/main.js', import.meta.url));
    const source = readFileSync(mainJsPath, 'utf8');
    const ast = parseAst(source);

    const initFunction = ast.body
      .map((node) => (node.type === 'ExportNamedDeclaration' ? node.declaration : node))
      .find((node) => node?.type === 'FunctionDeclaration' && node.id?.name === 'init');

    expect(initFunction, 'src/main.js must export a top-level function init()').toBeDefined();

    let found = false;

    walk(initFunction.body, {
      enter(node) {
        if (
          node.type === 'CallExpression'
          && node.callee.type === 'Identifier'
          && node.callee.name === 'renderFooter'
          && node.arguments.length === 1
          && node.arguments[0].type === 'MemberExpression'
          && node.arguments[0].object.type === 'Identifier'
          && node.arguments[0].object.name === 'DATA'
          && node.arguments[0].property.type === 'Identifier'
          && node.arguments[0].property.name === 'project'
        ) {
          found = true;
        }
      },
    });

    expect(found, 'init() must call renderFooter(DATA.project)').toBe(true);
  });
});
