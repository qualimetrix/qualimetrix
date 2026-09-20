<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DistributedPackage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * `--report=git:...` judged against what a consumer receives.
 *
 * The defect this control exists for: `GitClient` imports Symfony Process,
 * which was declared in neither `require` nor `require-dev` and arrived
 * transitively through php-cs-fixer. Every git scope therefore died with
 * `Internal error: Class "Symfony\Component\Process\Process" not found` on the
 * phar and on any `--no-dev` install, while the test suite — which runs with
 * the dev graph, where php-cs-fixer supplies the package — stayed green. No CI
 * job executed a git scope, so nothing else could have caught it either.
 *
 * Two measured facts decide the shape of this control, and both contradict the
 * obvious way to write it:
 *
 * - **The fatal is valid JSON.** It prints `{"error": "...", "exit_code": 1}`,
 *   so asserting that stdout parses would pass on the defect.
 * - **The exit code does not separate the cases.** The fatal exits 1; a healthy
 *   run over this fixture exits 2, because a consumer repository has no
 *   `composer.json` and the missing namespace map raises warnings.
 *
 * What separates them is the report's shape, so that is what is asserted:
 * no `error` key, and `coverage.analyzed` equal to the one staged file. The
 * second assertion is the one carrying the subject — it says the git scope
 * resolved the staged path and analysis reached it, rather than merely saying
 * the process did not crash.
 *
 * `--no-dev` on the autoload dump is required, not a preference: `autoload-dev`
 * names `tools/phpstan/tests/Fixtures/`, which is `export-ignore`d and so
 * absent from the archive, and a dev dump inside the extracted package fails
 * outright.
 *
 * Like its sibling {@see HookInstallWorksFromTheDistPackageTest}, this archives
 * HEAD and copies `vendor/` rather than symlinking it.
 *
 * Copying `vendor/` is what makes the dependency graph a second tree, and this
 * control does not guess which one it is looking at: {@see InstalledDependencyGraph}
 * refuses the run outright when the installed graph is not the one HEAD's lock
 * describes. The residue that leaves is versions, which are not compared.
 */
final class GitScopeWorksFromTheDistPackageTest extends TestCase
{
    #[Test]
    public function itAnalysesTheStagedFileFromWhatTheDistPackageCarries(): void
    {
        // First, and before anything this run would otherwise have to clean
        // up: a run whose dependency graph is not HEAD's cannot answer the
        // question this control asks, in either direction.
        InstalledDependencyGraph::assertMatchesHead(self::projectRoot());

        $scratch = self::scratchDirectory();
        $package = $scratch . '/package';
        $consumer = $scratch . '/consumer';

        try {
            self::extractDistPackage($package);
            self::copyVendor($package);

            // Without this the control could go green for the wrong reason:
            // if the dump ever stopped excluding the dev graph, the fixture
            // would quietly become a dev tree and prove nothing about what a
            // consumer receives. PHPUnit is the cheapest witness for that --
            // it is dev-only and it is certainly installed here.
            self::assertFalse(
                self::packageResolves($package, 'PHPUnit\\Framework\\TestCase'),
                'The extracted package still resolves a dev-only class, so this fixture is not production-shaped.',
            );

            self::assertStringStartsWith(
                $package . '/',
                self::gitClientFileTheRunWouldLoad($package),
                'The run resolves GitClient out of this checkout rather than the extracted package, so a green result here would be about the wrong tree.',
            );

            self::initRepositoryWithStagedFile($consumer);

            [$status, $stdout, $stderr] = self::runGitScope($package, $consumer);

            $report = json_decode($stdout, true);

            self::assertIsArray(
                $report,
                'The git scope produced no JSON report at all:' . \PHP_EOL . $stdout . $stderr,
            );

            // Named explicitly so a recurrence reports the dependency defect
            // rather than an unexplained shape mismatch.
            self::assertArrayNotHasKey(
                'error',
                $report,
                'The git scope failed from the dist package: ' . ($report['error'] ?? '') . \PHP_EOL
                    . 'Exit status ' . $status . '.',
            );

            self::assertSame(
                1,
                $report['coverage']['analyzed'] ?? null,
                'The git scope did not analyse the one staged file, so the scope resolved nothing:' . \PHP_EOL . $stdout,
            );
        } finally {
            self::removeDirectory($scratch);
        }
    }

    /**
     * Whether a class is reachable through the extracted package's autoloader.
     */
    private static function packageResolves(string $package, string $class): bool
    {
        return trim(self::capture([
            \PHP_BINARY,
            '-r',
            'require $argv[1] . "/vendor/autoload.php"; echo class_exists($argv[2]) ? "yes" : "no";',
            $package,
            $class,
        ])) === 'yes';
    }

    /**
     * The file a run out of the extracted package would load `GitClient` from.
     */
    private static function gitClientFileTheRunWouldLoad(string $package): string
    {
        return trim(self::capture([
            \PHP_BINARY,
            '-r',
            'require $argv[1] . "/vendor/autoload.php";'
            . ' echo (new ReflectionClass(\Qualimetrix\Infrastructure\Git\GitClient::class))->getFileName();',
            $package,
        ]));
    }

    private static function extractDistPackage(string $into): void
    {
        $archive = \dirname($into) . '/package.tar';

        self::capture(['git', '-C', self::projectRoot(), 'archive', '--worktree-attributes', '--format=tar', '-o', $archive, 'HEAD']);

        self::assertTrue(mkdir($into, 0777, true));
        self::capture(['tar', '-xf', $archive, '-C', $into]);
    }

    /**
     * Third-party dependencies, plus an autoload map rebuilt without the dev
     * graph — which is what makes a dev-only package unreachable here even
     * though its files were copied in.
     */
    private static function copyVendor(string $package): void
    {
        self::capture(['cp', '-R', self::projectRoot() . '/vendor', $package . '/vendor']);

        $composer = getenv('COMPOSER_BINARY');

        self::capture([
            \is_string($composer) && $composer !== '' ? $composer : 'composer',
            'dump-autoload', '--no-dev', '--no-scripts', '--no-interaction', '--quiet', '-d', $package,
        ]);
    }

    /**
     * A consumer repository holding one staged, never-committed file.
     *
     * Staged rather than committed on purpose: `git:staged` then has exactly
     * one answer this test can predict, and the fixture needs no commit and no
     * configured identity to produce it.
     */
    private static function initRepositoryWithStagedFile(string $path): void
    {
        self::assertTrue(mkdir($path, 0777, true));
        self::capture(['git', 'init', '--quiet', $path]);

        file_put_contents(
            $path . '/Subject.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class Subject\n{\n    public function value(): int\n    {\n        return 1;\n    }\n}\n",
        );

        self::capture(['git', '-C', $path, 'add', 'Subject.php']);
    }

    /**
     * @return array{int, string, string} exit status, stdout and stderr
     */
    private static function runGitScope(string $package, string $consumer): array
    {
        return self::execute(
            [\PHP_BINARY, $package . '/bin/qmx', 'check', '.', '--report=git:staged', '--format=json', '--workers=0'],
            $consumer,
        );
    }

    /**
     * Runs a command, or fails this test with what it said.
     *
     * @param list<string> $command
     */
    private static function capture(array $command, ?string $workingDirectory = null): string
    {
        [$status, $stdout, $stderr] = self::execute($command, $workingDirectory);

        self::assertSame(
            0,
            $status,
            implode(' ', $command) . ' failed, so nothing here was checked:' . \PHP_EOL . $stdout . $stderr,
        );

        return $stdout;
    }

    /**
     * The repository's one drain-free-of-deadlock child runner, rather than a
     * private one: two pipes read in sequence is the defect its own governance
     * control exists to refuse.
     *
     * @param list<string> $command
     *
     * @return array{int, string, string} exit status, stdout and stderr
     */
    private static function execute(array $command, ?string $workingDirectory = null): array
    {
        $result = ChildProcess::run($command, $workingDirectory);

        return [$result['exitCode'], $result['stdout'], $result['stderr']];
    }

    private static function scratchDirectory(): string
    {
        $path = sys_get_temp_dir() . '/qmx-dist-git-scope-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($path, 0777, true));

        // Resolved, because the guard above compares this prefix against a
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
