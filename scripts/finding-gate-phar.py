#!/usr/bin/env python3
"""Compare the existing build/qmx.phar artifact with its committed tree through the finding gate."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import shutil
import tempfile


def prepare_identity_comparison(tree: Path) -> None:
    """An archive must match one commit, without permissions for a transition between commits."""
    gate = tree / 'finding-gate'
    for path in [*gate.glob('declared-*.tsv'), *(gate / 'maps').glob('*.tsv')]:
        header = path.read_text().splitlines()[0] + '\n'
        path.unlink()
        path.write_text(header)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--reference', default='HEAD', help='Committed tree to compare with the existing archive (default: HEAD)')
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    evidence = root / 'build/evidence/gate-phar'
    evidence.mkdir(parents=True, exist_ok=True)
    environment = dict(os.environ, LC_ALL='C', TZ='UTC')
    index = []
    archive_source = root / 'build/qmx.phar'
    artifact = {'source': str(archive_source), 'consumedWithoutRebuild': True}

    def archive_digest(path):
        if path.is_symlink() or not path.is_file() or path.stat().st_size == 0:
            raise RuntimeError('The comparison requires an existing, nonempty, real build/qmx.phar artifact.')
        return hashlib.sha256(path.read_bytes()).hexdigest()

    def verify_archive_copy(archive):
        source_digest = archive_digest(archive_source)
        copied_digest = archive_digest(archive)
        if source_digest != artifact['sha256'] or copied_digest != artifact['sha256']:
            raise RuntimeError('The original archive or its consumed copy changed during the comparison.')
        artifact.update(copy=str(archive), copySha256=copied_digest, sourceSha256After=source_digest)
        (evidence / 'archive.json').write_text(json.dumps(artifact, indent=2) + '\n')

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
    artifact.update(commit=commit, sha256=archive_digest(archive_source), size=archive_source.stat().st_size)
    (evidence / 'archive.json').write_text(json.dumps(artifact, indent=2) + '\n')
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
                raise RuntimeError('Dependency installation changed the committed lock.')
            archive = shim / 'build/qmx.phar'
            archive.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(archive_source, archive)
            verify_archive_copy(archive)
            run(['php', str(archive), '--version'], shim)
            # The supplied archive owns the entry point. Only the candidate launcher now changes.
            (shim / 'bin/qmx').write_text("#!/usr/bin/env php\n<?php\nrequire dirname(__DIR__) . '/build/qmx.phar';\n")
            prepare_identity_comparison(shim)
            result = run(['php', str(root / 'scripts/finding-gate.php'), '--candidate=' + str(shim), '--reference=' + commit, '--report=' + str(evidence / 'report.json')], root, check=False)
            verify_archive_copy(archive)
            artifact.update(gateExit=result.returncode, report=str(evidence / 'report.json'))
            (evidence / 'archive.json').write_text(json.dumps(artifact, indent=2) + '\n')
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
