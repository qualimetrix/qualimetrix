#!/usr/bin/env python3
"""Propose exact symbol/path rows from declared correspondence, refusing guesses."""
import argparse
import csv
import hashlib
import json
import subprocess
import sys
from pathlib import Path

TOOL_ROOT = Path(__file__).resolve().parent.parent
PARSER = r'''
require $argv[1] . '/vendor/autoload.php';
$parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
$finder = new PhpParser\NodeFinder();
$result = [];
foreach (json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) as $path => $text) {
    $nodes = $parser->parse($text);
    $traverser = new PhpParser\NodeTraverser(new PhpParser\NodeVisitor\NameResolver());
    $nodes = $traverser->traverse($nodes);
    $named = [];
    foreach ($finder->findInstanceOf($nodes, PhpParser\Node\Stmt\ClassLike::class) as $class) {
        if ($class->name === null) continue;
        $name = $class->namespacedName->toString();
        $named[] = $name;
        foreach ($class->getMethods() as $method) $named[] = $name . '::' . $method->name->toString();
    }
    foreach ($finder->findInstanceOf($nodes, PhpParser\Node\Stmt\Function_::class) as $function) {
        $named[] = $function->namespacedName->toString();
    }
    foreach ($named as $name) {
        if (isset($result[$name])) throw new RuntimeException('A declaration occurs twice: ' . $name);
        $result[$name] = $path;
    }
    if ($named !== []) $result[$path] = $path;
}
echo json_encode($result, JSON_THROW_ON_ERROR);
'''


def git(root, *arguments):
    return subprocess.check_output(['git', '-C', str(root), *arguments])


def snapshot(root, reference=None):
    if reference is None:
        files = git(root, 'ls-files', '--cached', '--others', '--exclude-standard', '--', 'src').decode().splitlines()
        return {name: (root / name).read_text() for name in sorted(set(files))
                if name.endswith('.php') and (root / name).is_file()}
    git(root, 'rev-parse', '--verify', reference + '^{commit}')
    files = git(root, 'ls-tree', '-r', '--name-only', reference, '--', 'src').decode().splitlines()
    return {name: git(root, 'show', reference + ':' + name).decode() for name in files if name.endswith('.php')}


def declarations(files):
    return json.loads(subprocess.check_output(['php', '-r', PARSER, str(TOOL_ROOT)], input=json.dumps(files).encode()))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--candidate', type=Path, required=True)
    parser.add_argument('--reference', required=True)
    parser.add_argument('--correspondences', type=Path, required=True, help='Explicit old/new TSV, no inferred ordinal matching')
    args = parser.parse_args()
    root = args.candidate.resolve()
    before = snapshot(root)
    old, new = declarations(snapshot(root, args.reference)), declarations(before)
    gone, introduced = set(old) - set(new), set(new) - set(old)
    if not gone and not introduced:
        raise ValueError('No changed declaration/path population; no proposal can be justified.')
    with args.correspondences.open(newline='') as stream:
        reader = csv.DictReader(stream, delimiter='\t')
        if reader.fieldnames != ['old', 'new']:
            raise ValueError('Correspondences must have the exact old/new header.')
        rows = list(reader)
    used_old, used_new = set(), set()
    for row in rows:
        source, target = row['old'], row['new']
        if source not in gone or target not in introduced or source in used_old or target in used_new:
            raise ValueError('A correspondence is absent, stale or ambiguous: ' + source + ' -> ' + target)
        used_old.add(source)
        used_new.add(target)
    if gone != used_old or introduced != used_new:
        raise ValueError('Unmatched declaration/path changes require explicit correspondence: '
                         + json.dumps({'old': sorted(gone - used_old), 'new': sorted(introduced - used_new)}))
    if snapshot(root) != before:
        raise ValueError('Candidate bytes changed during enumeration; the proposal has no stable source.')
    writer = csv.writer(sys.stdout, delimiter='\t', lineterminator='\n')
    writer.writerow(['old', 'new', 'reason'])
    for row in sorted(rows, key=lambda item: (item['old'], item['new'])):
        writer.writerow([row['old'], row['new'], '?'])
    digest = hashlib.sha256(json.dumps(before, sort_keys=True).encode()).hexdigest()
    print('Enumerated ' + str(len(old)) + '/' + str(len(new)) + ' atoms; candidate sha256=' + digest + '; unchanged', file=sys.stderr)
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        print('symbol-map: ' + str(error), file=sys.stderr)
        raise SystemExit(1)
