<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\MetricVocabulary;
use QmxFindingGate\Process;
use QmxFindingGate\ProcessHandle;
use QmxFindingGate\ReferenceTree;
use QmxFindingGate\RenameMaps;

/**
 * A reference checkout is given back to the repository it was taken from on
 * every exit path: a creation that fails halfway, a registration git left
 * locked, a signal, a PHP fatal, and a kill while `git worktree add` runs.
 *
 * Every repository here is a throwaway one, never the developer's: a case that
 * fails halfway would otherwise leave behind exactly the registration these
 * cases exist to refuse.
 */
#[Group('finding-gate-e2e')]
final class ReferenceTreeReleaseTest extends TestCase
{
    private ?string $repository = null;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function tearDown(): void
    {
        if ($this->repository === null) {
            return;
        }
        foreach (self::registeredWorktrees($this->repository) as $path) {
            Process::run(['git', '-C', $this->repository, 'worktree', 'remove', '--force', '--force', $path], $this->repository);
        }
        Fs::removeRecursively($this->repository);
    }

    /**
     * Without the message the case passes when `create()` fails at the
     * vocabulary check instead, which always cleaned up.
     */
    #[Test]
    public function itGivesTheCheckoutBackWhenCreationFailsAfterTakingIt(): void
    {
        $repository = $this->throwawayRepository(withVendor: false);

        try {
            ReferenceTree::create($repository, 'HEAD', RenameMaps::fromPairs([], MetricVocabulary::ofTree($repository)));
            self::fail('A reference tree with nothing to install was created.');
        } catch (GateError $error) {
            self::assertStringContainsString('has no vendor/', $error->getMessage());
        }

        self::assertSame([], self::registeredWorktrees($repository));
    }

    /**
     * `prune` skips a locked entry and a single `--force` refuses it, so the
     * release takes the second `--force`.
     */
    #[Test]
    public function itReleasesARegistrationGitLeftLockedWhileInitializing(): void
    {
        $repository = $this->throwawayRepository(withVendor: true);
        $tree = ReferenceTree::create($repository, 'HEAD', RenameMaps::fromPairs([], MetricVocabulary::ofTree($repository)));

        // A live checkout must be visible to the path comparison, or every
        // "nothing is registered" below would hold while seeing nothing.
        self::assertSame([$tree->root], self::registeredWorktrees($repository));

        $administrations = glob($repository . '/.git/worktrees/*');
        self::assertNotEmpty($administrations, 'git keeps its worktree administration somewhere this case does not lock');
        foreach ($administrations as $administration) {
            Fs::write($administration . '/locked', "initializing\n");
        }

        $tree->remove();

        self::assertSame([], self::registeredWorktrees($repository));
        self::assertDirectoryDoesNotExist($tree->root);
    }

    /** @return iterable<string, array{int|null, int|null}> */
    public static function provideInterruptions(): iterable
    {
        yield 'SIGINT' => [\SIGINT, 130];
        yield 'SIGTERM' => [\SIGTERM, 143];
        yield 'a PHP fatal' => [null, null];
    }

    #[Test]
    #[DataProvider('provideInterruptions')]
    public function itReleasesEverythingARunTookWhenItIsInterrupted(?int $signal, ?int $expectedExit): void
    {
        $repository = $this->throwawayRepository(withVendor: true);
        $child = self::startHoldingChild($repository, fatal: $signal === null, diagnostic: $diagnostic);
        self::assertNotNull($child, 'the run never took a reference tree: ' . $diagnostic);

        if ($signal !== null) {
            posix_kill($child['pid'], $signal);
        }
        $exit = self::waitForExit($child);

        foreach ($child['paths'] as $path) {
            self::assertDirectoryDoesNotExist($path);
        }
        self::assertSame([], self::registeredWorktrees($repository));
        if ($expectedExit !== null) {
            self::assertSame($expectedExit, $exit, 'the run exits 128 + the signal');
        }
    }

    /**
     * The release is held before the checkout is asked for, because a
     * half-finished add is registered all the same. `git` is shadowed by a
     * wrapper that adds the worktree and then sleeps, so the signal lands with
     * the registration written and the command still running.
     */
    #[Test]
    public function itDeregistersACheckoutWhenTheRunIsKilledDuringWorktreeAdd(): void
    {
        $repository = $this->throwawayRepository(withVendor: true);
        $shadow = Fs::temporaryDirectory('slow-git-');
        Fs::write(
            $shadow . '/git',
            "#!/bin/sh\ncase \" $* \" in\n  *\" worktree add \"*) /usr/bin/git \"$@\" && sleep 30 ;;\n"
            . "  *) exec /usr/bin/git \"$@\" ;;\nesac\n",
        );
        chmod($shadow . '/git', 0o755);

        try {
            $child = self::startHoldingChild($repository, fatal: false, pathPrefix: $shadow, announceBeforeCheckout: true, diagnostic: $diagnostic);
            self::assertNotNull($child, 'the run never reached git worktree add: ' . $diagnostic);

            $deadline = hrtime(true) / 1_000_000_000 + 30;
            while (self::registeredWorktrees($repository) === [] && hrtime(true) / 1_000_000_000 < $deadline) {
                usleep(50_000);
            }
            self::assertNotSame([], self::registeredWorktrees($repository), 'the run was signalled before its checkout was registered');

            posix_kill($child['pid'], \SIGTERM);
            self::waitForExit($child);

            self::assertSame([], self::registeredWorktrees($repository));
        } finally {
            Fs::removeRecursively($shadow);
        }
    }

    /**
     * Starts a run that takes a reference tree and then blocks inside a poll,
     * where an interrupt is acted on, or fatals, where only the shutdown
     * backstop is. Started with a bare `proc_open`, so the signal reaches the
     * run itself and its exit code is read from it; stderr goes to a file, so a
     * chatty child cannot block the announcement read.
     *
     * @param-out string $diagnostic
     *
     * @return array{pid: int, handle: resource, stdout: resource, paths: list<string>}|null
     */
    private static function startHoldingChild(
        string $repository,
        bool $fatal,
        ?string $pathPrefix = null,
        bool $announceBeforeCheckout = false,
        ?string &$diagnostic = null,
    ): ?array {
        $diagnostic = '';
        $source = <<<'PHP'
            <?php
            namespace QmxFindingGate;
            require $argv[1] . '/classes.php';
            try {
                $run = Fs::temporaryDirectory('finding-gate-run-');
                if ($argv[4] === 'early') {
                    echo $run, "\n", $run, "\n", "ready\n";
                }
                $tree = ReferenceTree::create($argv[2], 'HEAD', RenameMaps::fromPairs([], MetricVocabulary::ofTree($argv[2])));
                if ($argv[4] !== 'early') {
                    echo $run, "\n", \dirname($tree->root), "\n", "ready\n";
                }
                if ($argv[3] === 'fatal') {
                    throw new \Error('a deliberate fatal, after the run took everything it takes');
                }
                Process::run(['/bin/sleep', '30'], $argv[2]);
            } catch (GateError $error) {
                fwrite(STDERR, $error->getMessage() . "\n");
                exit(Interruption::exitCode() ?? 3);
            }
            PHP;

        $directory = Fs::temporaryDirectory('interrupted-run-');
        $file = $directory . '/run.php';
        $stderrFile = $directory . '/stderr.log';
        Fs::write($file, $source);
        $environment = $pathPrefix === null ? null : [
            'PATH' => $pathPrefix . ':' . (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            ...ProcessHandle::inheritedGitConfiguration(),
        ];

        $handle = proc_open(
            [\PHP_BINARY, $file, \dirname(__DIR__), $repository, $fatal ? 'fatal' : 'block', $announceBeforeCheckout ? 'early' : 'late'],
            [1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']],
            $pipes,
            $repository,
            $environment,
        );
        if (!\is_resource($handle)) {
            $diagnostic = self::stderrOf($stderrFile);

            return null;
        }

        stream_set_blocking($pipes[1], false);
        $paths = [];
        $deadline = hrtime(true) / 1_000_000_000 + 30;
        while (\count($paths) < 2 && hrtime(true) / 1_000_000_000 < $deadline) {
            $line = fgets($pipes[1]);
            if ($line === false) {
                usleep(20_000);

                continue;
            }
            if (trim($line) !== 'ready' && trim($line) !== '') {
                $paths[] = trim($line);
            }
        }

        $pid = proc_get_status($handle)['pid'];
        $diagnostic = self::stderrOf($stderrFile);
        if (\count($paths) !== 2) {
            // A child that never announced is a child nobody is holding.
            @posix_kill($pid, \SIGKILL);
            fclose($pipes[1]);
            proc_close($handle);

            return null;
        }

        return ['pid' => $pid, 'handle' => $handle, 'stdout' => $pipes[1], 'paths' => $paths];
    }

    private static function stderrOf(string $path): string
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;

        return $contents === false ? '' : trim($contents);
    }

    /** @param array{pid: int, handle: resource, stdout: resource, paths: list<string>} $child */
    private static function waitForExit(array $child): int
    {
        $deadline = hrtime(true) / 1_000_000_000 + 30;
        while (hrtime(true) / 1_000_000_000 < $deadline) {
            $status = proc_get_status($child['handle']);
            if ($status['running'] === false) {
                fclose($child['stdout']);
                proc_close($child['handle']);

                return $status['exitcode'];
            }
            usleep(20_000);
        }
        posix_kill($child['pid'], \SIGKILL);
        fclose($child['stdout']);
        proc_close($child['handle']);

        return -1;
    }

    /**
     * A committed repository carrying the two files {@see MetricVocabulary}
     * reads, or `create()` stops at the vocabulary check before any of this.
     */
    private function throwawayRepository(bool $withVendor): string
    {
        $root = Fs::temporaryDirectory('reference-release-repository-');
        $this->repository = $root;
        $contract = $root . '/src/Analysis/Evidence/Measurement/Contract';
        Fs::write($contract . '/AggregationStrategy.php', "<?php\n\nenum AggregationStrategy: string\n{\n    case Sum = 'sum';\n}\n");
        Fs::write($contract . '/MetricName.php', "<?php\n\nfinal class MetricName\n{\n    public const string CCN = 'ccn';\n}\n");
        if ($withVendor) {
            Fs::write($root . '/vendor/autoload.php', "<?php\n");
        }
        foreach ([
            ['git', 'init', '--quiet'],
            ['git', 'add', '--all'],
            ['git', '-c', 'user.email=gate-test@qmx', '-c', 'user.name=gate-test', 'commit', '--quiet', '--message', 'fixture'],
        ] as $command) {
            $result = Process::run($command, $root);
            self::assertSame(0, $result['exit'], $result['stderr']);
        }

        return $root;
    }

    /**
     * Every worktree the repository registered beyond itself, asked of git: a
     * directory that is gone while `git worktree list` still names it is the
     * defect, not the absence of one.
     *
     * @return list<string>
     */
    private static function registeredWorktrees(string $repository): array
    {
        $listed = Process::run(['git', '-C', $repository, 'worktree', 'list', '--porcelain'], $repository);
        $paths = [];
        foreach (explode("\n", $listed['stdout']) as $line) {
            if (str_starts_with($line, 'worktree ') && substr($line, 9) !== $repository) {
                $paths[] = substr($line, 9);
            }
        }

        return $paths;
    }
}
