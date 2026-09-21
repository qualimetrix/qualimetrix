<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DistributedPackage;

use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * The tree a consumer receives, on disk and runnable.
 *
 * One definition, for the same reason {@see InstalledDependencyGraph} is one:
 * the controls that judge a command against the distributed package all build
 * this fixture, and copies of it drift — with the half that drifted still
 * passing. That is not a prediction here, it is the observed state this class
 * was extracted from: the two copies of the vendor step were identical code,
 * and only one of them still carried the paragraph explaining why Composer's
 * own path is preferred to a bare name.
 *
 * What this class owns is the *mechanism*. What each control asserts about the
 * package it gets back, and the words it refuses in, stay with that control —
 * including the `assertStringStartsWith` guard each one points at its own class
 * ({@see HookInstallWorksFromTheDistPackageTest} at the hook command,
 * {@see GitScopeWorksFromTheDistPackageTest} at `GitClient`), which is what
 * keeps a run from silently measuring this checkout instead of the package.
 *
 * ## What ships, and why read that way
 *
 * Two separate claims, spelled out the way the group's other archive readers
 * spell them ({@see HtmlReportShipsOnlyWhatItReadsTest},
 * {@see PharCarriesWhatTheDistCarriesTest}):
 *
 * - `git archive` rather than `git check-attr`, because an `export-ignore` row
 *   naming a directory marks the directory and not the files under it, so
 *   reading attributes per file reports every excluded file as shipped.
 * - `--worktree-attributes`, because it judges the working copy's
 *   `.gitattributes`, so a row added and not yet committed is honoured rather
 *   than ignored.
 *
 * The two halves are read from different places, and that is deliberate rather
 * than a contradiction: the *attributes* are the working tree's, the *content*
 * is HEAD's. The second half has a cost worth naming — a regression that
 * exists only in the working tree passes here, and reddens on the first run
 * after it is committed.
 *
 * ## `vendor/` is copied, never symlinked
 *
 * Composer writes its map relative to the directory holding `vendor/`
 * (`$baseDir = dirname($vendorDir)`; the generated map carries no absolute
 * path), so the copy alone is already what makes the package resolve its own
 * `src/`. Measured: with nothing dumped at all, `GitClient` resolves inside the
 * extracted tree. A symlinked `vendor/` instead leaves the map pointing at this
 * checkout, and the run then loads the checkout's code and finds the
 * checkout's `scripts/` — which is the defect the hook control exists for.
 * Measured on the tree that introduced that control: the defective
 * `hook:install` exited 0 through a symlink and 1 through a copy.
 *
 * ## What `--no-dev` on the dump buys — and what it does not
 *
 * It does **not** buy the paragraph above. The copied map already addresses the
 * extracted tree; re-dumping only keeps it that way. What the flag buys is two
 * things, and no control rests on both:
 *
 * 1. The dev graph leaves the map, so a dev-only package whose files were
 *    copied in with `vendor/` becomes unreachable through it. Measured: before
 *    the dump, PHPUnit still resolves inside the package. Only the git-scope
 *    control rests on this, and it asserts it rather than trusting it.
 * 2. It is the only dump that succeeds here at all: `autoload-dev` names
 *    `tools/phpstan/tests/Fixtures/`, which is `export-ignore`d and so absent
 *    from the archive, and a dev dump exits 1 on it. Every caller rests on
 *    this one by needing the dump to return.
 *
 * A real `composer install --no-dev` inside the extracted package would be more
 * faithful still and is deliberately not done: it needs the network.
 *
 * ## What a caller owns
 *
 * The directory handed to {@see self::extract()}, its parent — where the
 * intermediate `package.tar` is written — and the removal of both, for which
 * this group has {@see ScratchTree}.
 */
final class ExtractedDistPackage
{
    /**
     * Extracts what HEAD ships into `$package`, which must not yet exist.
     *
     * Writes `package.tar` beside `$package`, in the caller's directory, and
     * leaves it there to be cleaned up with the rest of the scratch tree.
     *
     * @throws RuntimeException when the archive cannot be taken or unpacked
     */
    public static function extract(string $root, string $package): void
    {
        $archive = \dirname($package) . '/package.tar';

        self::run(['git', '-C', $root, 'archive', '--worktree-attributes', '--format=tar', '-o', $archive, 'HEAD']);

        if (!mkdir($package, 0777, true)) {
            throw new RuntimeException('Could not create ' . $package . ', so nothing here was checked.');
        }

        self::run(['tar', '-xf', $archive, '-C', $package]);
    }

    /**
     * Third-party code, plus an autoload map of the extracted tree's own.
     *
     * @throws RuntimeException when the copy or the dump fails
     */
    public static function makeRunnable(string $root, string $package): void
    {
        self::run(['cp', '-R', $root . '/vendor', $package . '/vendor']);

        // Composer exports its own path when it runs a script, which is how
        // these controls reach it under `composer test`; a bare name is for
        // running phpunit directly.
        $composer = getenv('COMPOSER_BINARY');

        self::run([
            \is_string($composer) && $composer !== '' ? $composer : 'composer',
            'dump-autoload', '--no-dev', '--no-scripts', '--no-interaction', '--quiet', '-d', $package,
        ]);
    }

    /**
     * Runs a command, or refuses.
     *
     * A refusal rather than a failure, for the reason
     * {@see InstalledDependencyGraph::assertMatchesHead()} states: the run has
     * not judged its subject and found it broken, it has found that it cannot
     * build the subject at all. PHPUnit reports a thrown exception as an error
     * and an assertion as a failure, and that is the distinction being spent.
     *
     * A child that never ran, or whose pipes could not be drained, is left to
     * `ChildProcess::run()` to report in its own words — for the reason
     * {@see HookInstallWorksFromTheDistPackageTest} already gives about its own
     * runner, which is not restated here.
     *
     * @param list<string> $command
     *
     * @throws RuntimeException when the command ran and failed, naming it and
     *                          what it said. `ChildProcess::run()` raises its
     *                          own, which name the child rather than the
     *                          command, for a child it could not start or drain
     */
    private static function run(array $command): void
    {
        $result = ChildProcess::run($command);

        if ($result['exitCode'] !== 0) {
            throw new RuntimeException(
                implode(' ', $command) . ' failed, so nothing here was checked:' . \PHP_EOL
                    . trim($result['stdout'] . $result['stderr']),
            );
        }
    }
}
