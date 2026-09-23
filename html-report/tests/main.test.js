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
// live is not the cheap option here: init() also builds the D3 treemap,
// wires hash navigation, search and resize listeners, and reads a dozen other
// element ids, so a fake DOM covering it would be a large surface built only
// for this one assertion, not the small one renderFooter() itself needed. The
// cheapest thing that can still fail when a future edit drops or rewires the
// call site stays a structural check over init()'s own AST: a
// `renderFooter(DATA.project)` call expression (or the equivalent
// `const { project } = DATA; renderFooter(project)` form) somewhere in
// init()'s body. `rollup` and `estree-walker` are already devDependencies
// (rollup builds the bundle; estree-walker is one of rollup's own
// dependencies), so this adds no new package.
//
// What this cannot see: the walk does not check reachability, so a call
// sitting in a nested function that is never invoked, or behind a condition
// that is always false, still counts as "found" — the same blind spot any
// non-execution structural check has for dead code. It also does not resolve
// destructuring through more than one assignment, or through renaming
// (`const { project: p } = DATA`). It closes the gap that matters for an
// ordinary refactor of the call site, not the gap that matters for dead code.

describe('init() calls renderFooter(DATA.project)', () => {
  it('carries a renderFooter(DATA.project) call in its own body', () => {
    const mainJsPath = fileURLToPath(new URL('../src/main.js', import.meta.url));
    const source = readFileSync(mainJsPath, 'utf8');
    const ast = parseAst(source);

    const initFunction = ast.body
      .map((node) => (node.type === 'ExportNamedDeclaration' ? node.declaration : node))
      .find((node) => node?.type === 'FunctionDeclaration' && node.id?.name === 'init');

    expect(initFunction, 'src/main.js must export a top-level function init()').toBeDefined();

    // Local names bound to DATA.project via `const { project } = DATA`
    // (shorthand only — a rename to `{ project: p }` is not tracked).
    const projectAliases = new Set();

    const isDataProjectMember = (node) => (
      node?.type === 'MemberExpression'
      && node.computed === false
      && node.object.type === 'Identifier'
      && node.object.name === 'DATA'
      && node.property.type === 'Identifier'
      && node.property.name === 'project'
    );

    walk(initFunction.body, {
      enter(node) {
        if (
          node.type === 'VariableDeclarator'
          && node.id.type === 'ObjectPattern'
          && node.init?.type === 'Identifier'
          && node.init.name === 'DATA'
        ) {
          for (const property of node.id.properties) {
            if (
              property.type === 'Property'
              && property.key.type === 'Identifier'
              && property.key.name === 'project'
              && property.value.type === 'Identifier'
            ) {
              projectAliases.add(property.value.name);
            }
          }
        }
      },
    });

    let found = false;

    walk(initFunction.body, {
      enter(node) {
        if (
          node.type === 'CallExpression'
          && node.callee.type === 'Identifier'
          && node.callee.name === 'renderFooter'
          && node.arguments.length === 1
          && (
            isDataProjectMember(node.arguments[0])
            || (node.arguments[0].type === 'Identifier' && projectAliases.has(node.arguments[0].name))
          )
        ) {
          found = true;
        }
      },
    });

    expect(
      found,
      'init() must call renderFooter(DATA.project), directly or via a `const { project } = DATA` alias',
    ).toBe(true);
  });
});
