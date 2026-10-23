import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseAst } from 'rollup/parseAst';

const SCRIPT_DIRECTORY = dirname(fileURLToPath(import.meta.url));
const VIEWER_SOURCE_DIRECTORY = resolve(SCRIPT_DIRECTORY, '..', 'src');

function javascriptFiles(directory) {
  const files = [];
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...javascriptFiles(path));
    } else if (entry.isFile() && entry.name.endsWith('.js')) {
      files.push(path);
    }
  }
  return files.sort();
}

export function d3Imports(sourceDirectory = VIEWER_SOURCE_DIRECTORY) {
  const imports = new Map();

  for (const file of javascriptFiles(sourceDirectory)) {
    const program = parseAst(readFileSync(file, 'utf8'));
    for (const statement of program.body) {
      if (statement.type !== 'ImportDeclaration' || !statement.source.value.startsWith('d3-')) {
        continue;
      }

      const names = imports.get(statement.source.value) ?? new Set();
      for (const specifier of statement.specifiers) {
        if (specifier.type === 'ImportDefaultSpecifier') {
          throw new Error(`D3 default imports are not supported: ${file}`);
        }
        if (specifier.type === 'ImportNamespaceSpecifier') {
          throw new Error(`D3 namespace imports are not supported: ${file}`);
        }
        names.add(specifier.imported.name);
      }
      imports.set(statement.source.value, names);
    }
  }

  return Object.fromEntries(
    [...imports.entries()]
      .sort(([left], [right]) => left.localeCompare(right))
      .map(([module, names]) => [module, [...names].sort()]),
  );
}
