<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DistributedPackage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
 * the source; a cheaper control would be judging the source again.
 */
final class HookInstallWorksFromTheDistPackageTest extends TestCase
{
    #[Test]
    public function itInstallsAWorkingHookFromWhatTheDistPackageCarries(): void
    {
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
        } finally {
            self::removeDirectory($scratch);
        }
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
    private static function capture(array $command, ?string $workingDirectory = null): string
    {
        [$status, $output] = self::execute($command, $workingDirectory);

        self::assertSame(
            0,
            $status,
            implode(' ', $command) . ' failed, so nothing here was checked:' . \PHP_EOL . $output,
        );

        return $output;
    }

    /**
     * Both streams go to files rather than pipes.
     *
     * A parent that reads one pipe to EOF before touching the other deadlocks
     * as soon as the child fills the OS pipe buffer on the stream read second:
     * the child blocks mid-write, so it never exits and the first stream never
     * reaches EOF. `git archive` over this repository is exactly the size where
     * that starts to matter. Files have no such buffer.
     *
     * @param list<string> $command
     *
     * @return array{int, string} exit status and the command's merged output
     */
    private static function execute(array $command, ?string $workingDirectory = null): array
    {
        $outPath = tempnam(sys_get_temp_dir(), 'qmx-dist-out-');
        $errPath = tempnam(sys_get_temp_dir(), 'qmx-dist-err-');

        self::assertIsString($outPath);
        self::assertIsString($errPath);

        $process = proc_open(
            $command,
            [1 => ['file', $outPath, 'w'], 2 => ['file', $errPath, 'w']],
            $pipes,
            $workingDirectory,
        );

        self::assertIsResource($process, 'Could not start ' . $command[0] . ', so nothing here was checked.');

        $status = proc_close($process);
        $output = (string) file_get_contents($outPath) . (string) file_get_contents($errPath);

        unlink($outPath);
        unlink($errPath);

        return [$status, $output];
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
