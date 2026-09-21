<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DistributedPackage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * `hook:install` judged against what a consumer receives, not against this
 * checkout.
 *
 * The two are different trees, and the difference is exactly where the defect
 * this control exists for lived: `/scripts/` is `export-ignore`d, the command
 * looked for `scripts/pre-commit-hook.sh` relative to the working directory
 * and to the package root, and in an installed package neither exists. It
 * exited 1 with `Hook script not found` for every consumer while the
 * functional tests were green — they fabricated that file in their own
 * fixture, so they measured a tree nobody installs.
 *
 * Every step below is the cheapest thing that keeps the answer true of the
 * package:
 *
 * - `git archive --worktree-attributes` is the oracle for "what ships",
 *   because an `export-ignore` row naming a directory marks the directory and
 *   not the files under it, so reading attributes per file reports every
 *   excluded file as shipped. It archives HEAD: a file nobody committed is a
 *   file no consumer receives. The cost of that is real and worth naming — a
 *   regression that exists only in the working tree passes here, and reddens
 *   on the first run after it is committed.
 * - `vendor/` is **copied**, never symlinked. A symlinked `vendor/` keeps the
 *   PSR-4 map pointing at this checkout's `src/`, and the run then loads the
 *   checkout's code and finds the checkout's `scripts/`. Measured on the tree
 *   that introduced this control: with a symlink the defective command exits
 *   0 here, and with a copy it exits 1. The `assertStringStartsWith` guard
 *   below is what makes that difference impossible to reintroduce silently.
 * - the hook is installed into a repository of its own, so the command's
 *   working directory is a consumer's project and not the package.
 *
 * Measured at roughly three seconds on the tree that added it, almost all of
 * it the `vendor/` copy. That is the price of judging the artifact instead of
 * the source; a cheaper control would be judging the source again. A real
 * `composer install --no-dev` inside the extracted package would be more
 * faithful still and is deliberately not done: it needs the network, and this
 * group's cost is the reason it exists rather than an accident of it.
 *
 * Copying `vendor/` has a consequence the dump hides, and it is refused rather
 * than accepted: {@see InstalledDependencyGraph} stops the run unless the graph
 * it would apply is the one HEAD describes, from `composer.json` through the
 * lock to `installed.json`. `dump-autoload --no-dev` takes the
 * production/development split from the copied `installed.json` and never from
 * the lock, which is what makes the disagreement invisible without the check.
 */
final class HookInstallWorksFromTheDistPackageTest extends TestCase
{
    #[Test]
    public function itInstallsAWorkingHookFromWhatTheDistPackageCarries(): void
    {
        // Before anything this run would otherwise have to clean up: a graph
        // that is not HEAD's makes this run a verdict about neither tree.
        InstalledDependencyGraph::assertMatchesHead(self::projectRoot());

        $scratch = self::scratchDirectory();
        $package = $scratch . '/package';
        $consumer = $scratch . '/consumer';

        try {
            self::extractDistPackage($package);

            self::assertDirectoryDoesNotExist(
                $package . '/scripts',
                'The dist package carries scripts/, so this control no longer distinguishes the package from the checkout.',
            );
            self::assertFileExists($package . '/bin/qmx', 'The dist package carries no binary, so there is nothing to run.');

            self::copyVendor($package);

            self::assertStringStartsWith(
                $package . '/',
                self::commandFileTheRunWouldLoad($package),
                'The run resolves the command out of this checkout rather than the extracted package, so a green result here would be about the wrong tree.',
            );

            self::initRepository($consumer);

            [$status, $output] = self::runHookInstall($package, $consumer);

            self::assertSame(0, $status, 'hook:install from the dist package failed:' . \PHP_EOL . $output);

            $hookPath = $consumer . '/.git/hooks/pre-commit';

            self::assertFileExists($hookPath);
            self::assertFalse(is_link($hookPath), 'The hook is a symlink, which cannot survive the package being moved or replaced.');
            self::assertTrue(is_executable($hookPath), 'The hook is not executable, so git will not run it.');

            $contents = (string) file_get_contents($hookPath);

            self::assertStringContainsString('Qualimetrix pre-commit hook', $contents);
            self::assertStringContainsString($package . '/bin/qmx', $contents, 'The hook does not name the binary that installed it.');

            self::assertHookRunsTheAnalysis($consumer);
        } finally {
            self::removeDirectory($scratch);
        }
    }

    /**
     * Runs the installed hook the way git would.
     *
     * Asserting the file's properties is not enough and the gap is not
     * theoretical: a distributed `bin/qmx` that loses its execute bit leaves
     * every one of those assertions true while the hook fails on every commit
     * with `Permission denied`. Only running it distinguishes the two.
     */
    private static function assertHookRunsTheAnalysis(string $consumer): void
    {
        file_put_contents(
            $consumer . '/Subject.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class Subject\n{\n    public function value(): int\n    {\n        return 1;\n    }\n}\n",
        );

        self::capture(['git', '-C', $consumer, 'add', 'Subject.php']);

        [$status, $output] = self::execute([$consumer . '/.git/hooks/pre-commit'], $consumer);

        self::assertNotSame(126, $status, 'git could not execute the hook:' . \PHP_EOL . $output);
        self::assertNotSame(127, $status, 'The hook could not find the binary it names:' . \PHP_EOL . $output);

        self::assertStringContainsString(
            'Running Qualimetrix on staged files',
            $output,
            'The hook did not reach its own analysis step.',
        );
        self::assertStringContainsString(
            'Analysis complete',
            $output,
            'The hook ran but the packaged binary produced no analysis:' . \PHP_EOL . $output,
        );
    }

    private static function extractDistPackage(string $into): void
    {
        $archive = \dirname($into) . '/package.tar';

        self::capture(['git', '-C', self::projectRoot(), 'archive', '--worktree-attributes', '--format=tar', '-o', $archive, 'HEAD']);

        self::assertTrue(mkdir($into, 0777, true));
        self::capture(['tar', '-xf', $archive, '-C', $into]);
    }

    /**
     * Third-party dependencies, plus an autoload map rebuilt to address the
     * extracted tree rather than this one.
     */
    private static function copyVendor(string $package): void
    {
        self::capture(['cp', '-R', self::projectRoot() . '/vendor', $package . '/vendor']);

        // Composer exports its own path when it runs a script, which is how
        // this control reaches it under `composer test`; a bare name is for
        // running phpunit directly.
        $composer = getenv('COMPOSER_BINARY');

        self::capture([
            \is_string($composer) && $composer !== '' ? $composer : 'composer',
            'dump-autoload', '--no-dev', '--no-scripts', '--no-interaction', '--quiet', '-d', $package,
        ]);
    }

    /**
     * The file a run out of the extracted package would load the command from.
     */
    private static function commandFileTheRunWouldLoad(string $package): string
    {
        return trim(self::capture([
            \PHP_BINARY,
            '-r',
            'require $argv[1] . "/vendor/autoload.php";'
            . ' echo (new ReflectionClass(\Qualimetrix\Infrastructure\Console\Command\HookInstallCommand::class))->getFileName();',
            $package,
        ]));
    }

    private static function initRepository(string $path): void
    {
        self::assertTrue(mkdir($path, 0777, true));
        self::capture(['git', 'init', '--quiet', $path]);

        // `init.templateDir` can leave a repository without one, and the
        // command refuses when the directory is missing — which would read
        // here as a defect in the command rather than in this fixture.
        if (!is_dir($path . '/.git/hooks')) {
            self::assertTrue(mkdir($path . '/.git/hooks', 0777, true));
        }
    }

    /**
     * @return array{int, string} exit status and the command's merged output
     */
    private static function runHookInstall(string $package, string $consumer): array
    {
        return self::execute([\PHP_BINARY, $package . '/bin/qmx', 'hook:install'], $consumer);
    }

    /**
     * Runs a command, or fails this test with what it said.
     *
     * @param list<string> $command
     */
    private static function capture(array $command): string
    {
        [$status, $output] = self::execute($command);

        self::assertSame(
            0,
            $status,
            implode(' ', $command) . ' failed, so nothing here was checked:' . \PHP_EOL . $output,
        );

        return $output;
    }

    /**
     * The repository's one drain-free-of-deadlock child runner, rather than a
     * private one: two pipes read in sequence is the defect its own governance
     * control exists to refuse. `GitScopeWorksFromTheDistPackageTest`, this
     * control's twin, reaches the same module the same way.
     *
     * That the module sits under `scripts/`, which `export-ignore` keeps out of
     * the package this control measures, is no obstacle. The package is this
     * control's *subject*, never its runtime: the file you are reading runs
     * from the checkout, and `governance/` is `export-ignore`d too, so a
     * control restricted to what the package carries could not exist at all.
     *
     * A failure is left to surface with the module's own wording, as the twin
     * leaves it. `run()` fails three distinguishable ways and publishes a
     * prefix per way precisely because the last two started the child and left
     * its work half-done; catching all three to relabel them "could not start"
     * would say the one thing the module's own docblock forbids saying.
     *
     * One difference from a bespoke runner is worth naming. The child gets a
     * stdin pipe closed at once rather than inheriting this process's stdin, so
     * a command that did read stdin would see EOF rather than block on a
     * terminal nobody is attending. That direction is the load-bearing half:
     * the enumeration behind "nothing started here reads stdin" was made once,
     * and nothing re-makes it.
     *
     * The streams are merged on return because every assertion below reads the
     * command's output as one transcript: a message printed to stderr is still
     * the command answering.
     *
     * @param list<string> $command
     *
     * @return array{int, string} exit status and the command's merged output
     */
    private static function execute(array $command, ?string $workingDirectory = null): array
    {
        $result = ChildProcess::run($command, $workingDirectory);

        return [$result['exitCode'], $result['stdout'] . $result['stderr']];
    }

    private static function scratchDirectory(): string
    {
        $path = sys_get_temp_dir() . '/qmx-dist-hook-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path, 0777, true));

        // Resolved, because the guard below compares this prefix against a
        // path PHP reports from inside the extracted tree, and on macOS the
        // temporary directory is reached through a symlink.
        $resolved = realpath($path);

        self::assertIsString($resolved);

        return $resolved;
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        // The extracted tree carries symlinks of its own under vendor/bin, and
        // descending into one would walk out of the scratch directory.
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;

            is_dir($child) && !is_link($child) ? self::removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
