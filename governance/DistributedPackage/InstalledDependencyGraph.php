<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DistributedPackage;

use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * Whether the dependency graph a dist-package run would apply is HEAD's.
 *
 * Every control in this group judges an archive of HEAD, but runs it against a
 * `vendor/` copied from this checkout. The two are different trees, and where
 * they disagree the run produces a verdict about neither.
 *
 * The disagreement is not visible in the obvious place. `dump-autoload
 * --no-dev` does not recompute the production/development split from the lock:
 * it reads `dev-package-names` out of `vendor/composer/installed.json`, which
 * the copy brings along. So a package's side of the split is decided by the
 * last real `composer install`, while `composer.json` and `src/` are decided
 * by HEAD. Both directions were measured on the tree that introduced the git
 * scope control:
 *
 * - a correct fix stays red until someone runs an install, because the
 *   installed split still carries the defect;
 * - a broken `composer.json` at HEAD goes green, because the installed split
 *   still carries the cure. This is not hypothetical — it is how the first
 *   attempt to prove that control red produced a false green.
 *
 * In CI the two always agree: the test job installs from the lock at the commit
 * it checked out. This is a local-developer trap, and it is refused here rather
 * than documented, because a documented defect is still a defect.
 *
 * One definition, for the same reason {@see \Qualimetrix\Governance\DeclaredDependencies\ShippedTree}
 * is one: both controls that copy `vendor/` and dump without the dev graph ask
 * this question, and two copies of it would drift — with the half that drifted
 * still passing.
 *
 * ## Two links, because a consumer's graph is two derivations away
 *
 * What a consumer resolves comes from `composer.json`. What this run applies
 * comes from `installed.json`. The lock is the step between them, and checking
 * only the step nearest the run leaves the other one unguarded:
 *
 * 1. `composer.json` at HEAD against `composer.lock` at HEAD, by asking
 *    Composer itself. Without this link the guard is green on a HEAD that
 *    moved a package out of `require` without a `composer update`, which is
 *    the very defect class the git scope control exists for — measured, by
 *    moving `symfony/process` to `require-dev` in `composer.json` alone and
 *    watching the control stay green through 17 assertions.
 * 2. `composer.lock` at HEAD against this checkout's `installed.json`, by
 *    package name per side of the split.
 *
 * The first link is delegated rather than reimplemented. Composer's own
 * `content-hash` covers a canonicalised subset of `composer.json` that only
 * Composer defines; a hand-rolled comparison of `require` against the lock's
 * package sets expresses the simple case and accepts the rest, because a
 * package named in `require-dev` legitimately appears among the lock's
 * production packages when something in `require` pulls it in.
 *
 * ## What link 2 compares
 *
 * Name, side and version. Version is included because it is free and because
 * excluding it leaves a real hole: a `vendor/` holding an older revision of a
 * production package resolves a different set of classes than HEAD's lock
 * pins, and the callers ask exactly whether a class is reachable. It costs no
 * false refusals — on a fresh install the two files agree on every one of the
 * ninety-two version strings, measured, because `installed.json` is written
 * from the lock.
 *
 * Both sides of the split are compared, though only the production side
 * decides the callers' verdicts. The development side is what the git scope
 * control's own witness rests on: it proves the fixture is production-shaped
 * by showing PHPUnit unreachable, which means something only while PHPUnit is
 * installed *and* marked development. A split that disagreed there would
 * redden that witness with the wrong diagnosis.
 *
 * A commit landing between this check and the caller's `git archive HEAD`
 * would move HEAD underneath the run. That window is not closed, because
 * closing it means resolving HEAD to a revision here and threading it through
 * every caller's archive — a contract change for a race that needs a commit
 * during a three-second test.
 */
final class InstalledDependencyGraph
{
    /**
     * Refuses when the graph this run would apply is not HEAD's.
     *
     * A refusal rather than a failure on purpose: the run has not judged the
     * subject and found it broken, it has found that it cannot judge the
     * subject at all. PHPUnit reports a thrown exception as an error and an
     * assertion as a failure, and that is the distinction being spent.
     *
     * @throws RuntimeException naming the cure for the disagreement it found
     */
    public static function assertMatchesHead(string $root): void
    {
        self::assertHeadsLockAnswersItsOwnComposerJson($root);

        $atHead = self::lockAtHead($root);
        $inVendor = self::installed($root);

        $differences = [];

        foreach (array_unique([...array_keys($atHead), ...array_keys($inVendor)]) as $name) {
            $head = $atHead[$name] ?? 'absent';
            $vendor = $inVendor[$name] ?? 'absent';

            if ($head !== $vendor) {
                $differences[] = '  ' . $name . ': ' . $head . ' at HEAD, ' . $vendor . ' in vendor/';
            }
        }

        if ($differences === []) {
            return;
        }

        sort($differences);

        throw new RuntimeException(
            'vendor/ disagrees with HEAD, so this control cannot judge either tree.' . \PHP_EOL
            . self::cureFor($root) . \PHP_EOL
            . 'The autoload dump takes the production/development split from vendor/composer/installed.json,' . \PHP_EOL
            . 'not from the lock, so the split below is what a run here would actually apply:' . \PHP_EOL
            . implode(\PHP_EOL, $differences),
        );
    }

    /**
     * Which of the two disagreements this is, because they have opposite cures.
     *
     * `composer install` installs from the *working tree's* lock. When that
     * lock is the one `vendor/` was installed from and it is simply not
     * committed yet, the install is a no-op and the developer gets the same
     * refusal again. Naming the cure is what this class promises, so it has to
     * distinguish the case rather than always say the commoner one.
     */
    private static function cureFor(string $root): string
    {
        $lockIsCommitted = ChildProcess::run(
            ['git', '-C', $root, 'diff', '--quiet', 'HEAD', '--', 'composer.lock'],
        )['exitCode'] === 0;

        return $lockIsCommitted
            ? 'vendor/ was installed from a different lock than HEAD carries — run composer install.'
            : 'composer.lock is edited but not committed, and these controls judge HEAD —' . \PHP_EOL
                . 'commit the lock, or set the edit aside until they are run again.';
    }

    /**
     * Link 1: that HEAD's lock is the lock HEAD's `composer.json` resolves to.
     *
     * Asked of Composer, in a directory holding nothing but those two files,
     * so the answer is about HEAD and not about the working tree. Composer
     * separates a stale lock from an invalid manifest by exit code and says
     * which in its own words, so its output is quoted rather than translated.
     */
    private static function assertHeadsLockAnswersItsOwnComposerJson(string $root): void
    {
        $directory = ScratchTree::create('qmx-head-manifest-');

        try {
            foreach (['composer.json', 'composer.lock'] as $name) {
                file_put_contents($directory . '/' . $name, self::showAtHead($root, $name));
            }

            $composer = getenv('COMPOSER_BINARY');

            $result = ChildProcess::run(
                [
                    \is_string($composer) && $composer !== '' ? $composer : 'composer',
                    'validate', '--no-check-all', '--no-check-publish', '--no-interaction',
                ],
                $directory,
            );

            if ($result['exitCode'] !== 0) {
                throw new RuntimeException(
                    'composer.lock at HEAD does not answer composer.json at HEAD, so the graph this run would'
                    . \PHP_EOL . 'apply is one no consumer resolves. Composer says:' . \PHP_EOL
                    . trim($result['stdout'] . $result['stderr']),
                );
            }
        } finally {
            ScratchTree::remove($directory);
        }
    }

    /**
     * What HEAD's lock says about each package it names.
     *
     * Read from HEAD rather than from the working tree's `composer.lock`,
     * because HEAD is what the archive these controls judge is taken from. An
     * uncommitted lock edit is a disagreement, not an exemption.
     *
     * @return array<string, string>
     */
    private static function lockAtHead(string $root): array
    {
        $source = 'composer.lock at HEAD';
        $lock = self::decode(self::showAtHead($root, 'composer.lock'), $source);

        $described = [];

        foreach (['packages' => 'production', 'packages-dev' => 'development'] as $key => $side) {
            foreach (self::versions($lock, $key, $source) as $name => $version) {
                $described[$name] = $side . ' ' . $version;
            }
        }

        return $described;
    }

    private static function showAtHead(string $root, string $path): string
    {
        $result = ChildProcess::run(['git', '-C', $root, 'show', 'HEAD:' . $path]);

        if ($result['exitCode'] !== 0) {
            throw new RuntimeException(
                'Could not read ' . $path . ' at HEAD, so nothing here was checked:' . \PHP_EOL . $result['stderr'],
            );
        }

        return $result['stdout'];
    }

    /**
     * What the copied `vendor/` would impose for each package it holds.
     *
     * One flat list plus a roster of development names, rather than two lists:
     * that is the shape `installed.json` has, and it is the shape the autoload
     * dump reads the split from.
     *
     * @return array<string, string>
     */
    private static function installed(string $root): array
    {
        $path = $root . '/vendor/composer/installed.json';
        $contents = @file_get_contents($path);

        if (!\is_string($contents)) {
            throw new RuntimeException(
                'No ' . $path . ', so there is no installed graph to compare — run composer install.',
            );
        }

        $installed = self::decode($contents, $path);

        if (!isset($installed['dev-package-names']) || !\is_array($installed['dev-package-names'])) {
            throw new RuntimeException(
                $path . ' carries no dev-package-names, which is where the autoload dump reads the split from.',
            );
        }

        $development = array_filter($installed['dev-package-names'], '\is_string');
        $described = [];

        foreach (self::versions($installed, 'packages', $path) as $name => $version) {
            $side = \in_array($name, $development, true) ? 'development' : 'production';

            $described[$name] = $side . ' ' . $version;
        }

        return $described;
    }

    /**
     * The version each package in one section of a document reports.
     *
     * A package with no readable version reports `?` rather than being
     * skipped: a document that cannot say which revision it holds disagrees
     * with one that can, and dropping it would let the two agree by omission.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, string>
     */
    private static function versions(array $document, string $key, string $source): array
    {
        if (!isset($document[$key]) || !\is_array($document[$key])) {
            throw new RuntimeException($source . ' carries no ' . $key . ' array, so the split cannot be read from it.');
        }

        $versions = [];

        foreach ($document[$key] as $package) {
            if (\is_array($package) && isset($package['name']) && \is_string($package['name'])) {
                $versions[$package['name']] = isset($package['version']) && \is_string($package['version'])
                    ? $package['version']
                    : '?';
            }
        }

        return $versions;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json, string $source): array
    {
        $document = json_decode($json, true);

        if (!\is_array($document)) {
            throw new RuntimeException($source . ' is not a JSON object, so the split cannot be read from it.');
        }

        /** @var array<string, mixed> $document */
        return $document;
    }
}
