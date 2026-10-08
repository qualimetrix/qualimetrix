import { parseAst } from 'rollup/parseAst';
import { walk } from 'estree-walker';
import { isKeyShaped } from './metric-key-catalog.mjs';

/**
 * Native AST census of literals and static property reads. Constant strings
 * and their concatenations are resolved; runtime selectors and data flow are
 * deliberately outside this census. Finding receivers are the viewer's `v`
 * and `finding`; removed finding spellings are caught on any receiver.
 */
export function payloadReads(source) {
  const ast = parseAst(source);
  const constants = new Map();
  for (const statement of ast.body) {
    const declaration = statement.type === 'ExportNamedDeclaration' ? statement.declaration : statement;
    if (declaration?.type !== 'VariableDeclaration' || declaration.kind !== 'const') continue;
    for (const binding of declaration.declarations) {
      if (binding.id.type === 'Identifier') constants.set(binding.id.name, binding.init);
    }
  }
  function constant(node, seen = new Set()) {
    if (node?.type === 'Literal' && typeof node.value === 'string') return node.value;
    if (node?.type === 'Identifier' && !seen.has(node.name)) {
      return constant(constants.get(node.name), new Set([...seen, node.name]));
    }
    if (node?.type === 'BinaryExpression' && node.operator === '+') {
      const left = constant(node.left, seen);
      const right = constant(node.right, seen);
      if (left !== undefined && right !== undefined) return left + right;
    }
    return undefined;
  }
  const property = node => node?.type === 'MemberExpression'
    ? node.computed ? constant(node.property) : node.property.name : undefined;
  const literals = [];
  const metrics = [];
  const findings = [];
  const dynamic = [];
  walk(ast, {
    enter(node, parent) {
      const line = () => source.slice(0, node.start).split('\n').length;
      if (node.type === 'Literal' && typeof node.value === 'string' && isKeyShaped(node.value)) {
        literals.push({ key: node.value, line: line() });
      }
      if (node.type !== 'MemberExpression' || parent?.type === 'CallExpression' && parent.callee === node) return;
      const receiver = node.object.type === 'ChainExpression' ? node.object.expression : node.object;
      const key = property(node);
      if (property(receiver) === 'metrics') {
        (key === undefined ? dynamic : metrics).push({ key, line: line() });
      }
      if (['ruleName', 'violationCode', 'symbolPath'].includes(key)
        || receiver.type === 'Identifier' && ['v', 'finding'].includes(receiver.name)) {
        if (key !== undefined) findings.push({ key, line: line() });
      }
    },
  });
  return { literals, metrics, findings, dynamic };
}
