#!/usr/bin/env python3
"""Compare a built archive with its own committed tree through the finding gate."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--reference', default='HEAD', help='Committed tree to build and compare (default: HEAD)')
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    evidence = root / 'build/evidence/gate-phar'
    evidence.mkdir(parents=True, exist_ok=True)
    environment = dict(os.environ, LC_ALL='C', TZ='UTC')
    index = []

    def run(command, cwd, *, check=True):
        result = subprocess.run(command, cwd=cwd, env=environment, capture_output=True, text=True)
        item = {'argv': command, 'cwd': str(cwd), 'rc': result.returncode}
        index.append(item)
        (evidence / f'{len(index):02}-stdout.log').write_text(result.stdout)
        (evidence / f'{len(index):02}-stderr.log').write_text(result.stderr)
        (evidence / 'commands.json').write_text(json.dumps(index, indent=2) + '\n')
        if check and result.returncode != 0:
            raise RuntimeError(f'Command exited {result.returncode}: {command!r}\n{result.stderr}')
        return result

    commit = run(['git', 'rev-parse', '--verify', args.reference + '^{commit}'], root).stdout.strip()
    # The archive and its tree must describe a commit, never a mixture of a commit and local edits.
    run(['git', 'diff', '--exit-code', commit, '--', 'src', 'composer.lock', 'bin/qmx', 'box.json', 'scripts/build-phar.sh', 'scripts/finding-gate', 'finding-gate'], root)
    with tempfile.TemporaryDirectory(prefix='finding-gate-phar-') as scratch:
        shim = Path(scratch) / 'tree'
        try:
            run(['git', 'worktree', 'add', '--detach', str(shim), commit], root)
            run(['composer', 'install', '--no-dev', '--no-progress', '--prefer-dist', '--optimize-autoloader'], shim)
            if (shim / 'vendor').is_symlink() or not (shim / 'vendor/autoload.php').is_file():
                raise RuntimeError('The archive comparison requires real installed vendor in its own tree.')
            if (shim / 'composer.lock').read_bytes() != (root / 'composer.lock').read_bytes():
                raise RuntimeError('The build changed the dependency lock.')
            # A verified tool cache may be shared; the dependencies and product sources may not.
            environment['QMX_BOX_CACHE'] = str(root / 'build/tools')
            run(['composer', 'phar'], shim)
            archive = shim / 'build/qmx.phar'
            if not archive.is_file() or archive.stat().st_size == 0:
                raise RuntimeError('The build produced no archive.')
            run(['php', str(archive), '--version'], shim)
            # Box has already archived the original entry point. Only the candidate launcher now changes.
            (shim / 'bin/qmx').write_text("#!/usr/bin/env php\n<?php\nrequire dirname(__DIR__) . '/build/qmx.phar';\n")
            result = run(['php', str(root / 'scripts/finding-gate.php'), '--candidate=' + str(shim), '--reference=' + commit, '--report=' + str(evidence / 'report.json')], root, check=False)
            print(result.stdout, end='')
            print(result.stderr, end='', file=__import__('sys').stderr)
            return result.returncode
        finally:
            run(['git', 'worktree', 'remove', '--force', '--force', str(shim)], root, check=False)
            listed = run(['git', 'worktree', 'list', '--porcelain'], root)
            if 'worktree ' + str(shim) in listed.stdout.splitlines():
                raise RuntimeError('The archive comparison left its own worktree registered.')


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (RuntimeError, OSError) as error:
        print('finding-gate-phar: ' + str(error), file=__import__('sys').stderr)
        raise SystemExit(3)
