<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\PrivateLeakScan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

final class PrivateLeakScanVerdictTest extends TestCase
{
    private const string MARKER = 'qmx-private-leak-test-marker';

    private string $scratch = '';

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';
    }

    protected function setUp(): void
    {
        if (self::execute(['git', '--version'], sys_get_temp_dir())[0] !== 0) {
            self::markTestSkipped('git is not available');
        }

        $this->scratch = sys_get_temp_dir() . '/qmx-private-leak-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch . '/scripts', 0o777, true));
        self::assertTrue(copy(
            \dirname(__DIR__, 2) . '/scripts/check-private-leaks.sh',
            $this->scratch . '/scripts/check-private-leaks.sh',
        ));
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== '' && is_dir($this->scratch)) {
            self::execute(['rm', '-rf', $this->scratch], sys_get_temp_dir());
        }
    }

    #[Test]
    public function itSkipsAnUnavailableCommitRangeWithoutCallingItClean(): void
    {
        $this->initializeRepository();
        $range = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa..bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        [$exitCode, $output] = $this->scan(['--commits', $range]);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString("Commit range '$range' unavailable", $output);
        self::assertStringContainsString('skipped', $output);
        self::assertStringNotContainsString('clean', $output);
    }

    #[Test]
    public function itJudgesAnAvailableCommitRange(): void
    {
        $this->initializeRepository();
        $this->commit('chore: base ' . self::MARKER);
        $this->commit('chore: clean');

        [$exitCode, $output] = $this->scan(['--commits', 'HEAD~1..HEAD']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Commit messages clean', $output);
        self::assertStringNotContainsString('unavailable', $output);
    }

    #[Test]
    public function itFindsAPrivateTermInsideTheCommitRange(): void
    {
        $this->initializeRepository();
        $this->commit('chore: private ' . self::MARKER);

        [$exitCode, $output] = $this->scan(['--commits', 'HEAD~1..HEAD']);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('contains a private term', $output);
        self::assertStringNotContainsString(self::MARKER, $output);
    }

    #[Test]
    public function itKeepsGitRangeShorthandAvailable(): void
    {
        $this->initializeRepository();
        $this->commit('chore: clean');

        foreach (['..HEAD', 'HEAD..', 'HEAD~1...HEAD', 'HEAD'] as $range) {
            [$exitCode, $output] = $this->scan(['--commits', $range]);

            self::assertSame(0, $exitCode, "$range: $output");
            self::assertStringContainsString('Commit messages clean', $output, $range);
        }

        $this->commit('chore: private ' . self::MARKER);
        [$exitCode, $output] = $this->scan(['--commits', 'HEAD^!']);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('contains a private term', $output);
        self::assertStringNotContainsString('unavailable', $output);
    }

    #[Test]
    public function itRefusesToJudgeFilesWhenTrackedFilesCannotBeListed(): void
    {
        $this->initializeRepository();
        $nested = $this->scratch . '/nested';
        self::assertTrue(mkdir($nested . '/scripts', 0o777, true));
        self::assertTrue(copy(
            $this->scratch . '/scripts/check-private-leaks.sh',
            $nested . '/scripts/check-private-leaks.sh',
        ));
        [$gitExit, $gitOutput] = self::execute(['git', 'rev-parse', '--show-toplevel'], $nested);
        self::assertSame(0, $gitExit, $gitOutput);
        self::assertSame(realpath($this->scratch), trim($gitOutput));

        $result = ChildProcess::run([
            'env', 'QMX_PRIVATE_TERMS=' . self::MARKER,
            'GIT_CEILING_DIRECTORIES=' . realpath($this->scratch),
            'bash', $nested . '/scripts/check-private-leaks.sh',
        ], $nested);

        self::assertSame(2, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('Cannot list tracked files', $result['stderr']);
        self::assertStringNotContainsString('No private paths, terms or inventory shapes', $result['stdout']);
    }

    #[Test]
    public function itNamesStagedFilesWhenTheirListingFails(): void
    {
        $result = ChildProcess::run([
            'env', 'QMX_PRIVATE_TERMS=' . self::MARKER,
            'GIT_CEILING_DIRECTORIES=' . \dirname($this->scratch),
            'bash', $this->scratch . '/scripts/check-private-leaks.sh', '--staged',
        ], $this->scratch);

        self::assertSame(2, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('Cannot list staged files', $result['stderr']);
        self::assertStringNotContainsString('Cannot list tracked files', $result['stderr']);
    }

    #[Test]
    public function itReportsAnUnreadableStageFromThePreCommitHook(): void
    {
        $this->copyPreCommitHook();

        [$exitCode, $output] = $this->runPreCommit(\dirname($this->scratch));

        self::assertSame(2, $exitCode, $output);
        self::assertStringContainsString('could not inspect staged files', $output);
        self::assertStringNotContainsString('private path or term detected', $output);
    }

    #[Test]
    public function itReportsADetectedLeakFromThePreCommitHook(): void
    {
        $this->initializeRepository();
        $this->copyPreCommitHook();
        self::assertSame(\strlen(self::MARKER), file_put_contents($this->scratch . '/leak.txt', self::MARKER));
        self::assertSame(0, self::execute([
            'git', 'add', 'leak.txt',
        ], $this->scratch)[0]);

        [$exitCode, $output] = $this->runPreCommit();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('private path or term detected', $output);
        self::assertStringNotContainsString('could not inspect staged files', $output);
    }

    private function initializeRepository(): void
    {
        foreach ([
            ['git', 'init', '-q'],
            ['git', 'config', 'user.email', 'test@example.com'],
            ['git', 'config', 'user.name', 'Test'],
            ['git', 'commit', '-q', '--allow-empty', '--no-verify', '-m', 'chore: base'],
        ] as $command) {
            self::assertSame(0, self::execute($command, $this->scratch)[0], implode(' ', $command));
        }
    }

    private function commit(string $message): void
    {
        self::assertSame(0, self::execute([
            'git', 'commit', '-q', '--allow-empty', '--no-verify', '-m', $message,
        ], $this->scratch)[0]);
    }

    private function copyPreCommitHook(): void
    {
        self::assertTrue(mkdir($this->scratch . '/.githooks'));
        self::assertTrue(copy(
            \dirname(__DIR__, 2) . '/.githooks/pre-commit',
            $this->scratch . '/.githooks/pre-commit',
        ));
    }

    /** @return array{int, string} */
    private function runPreCommit(?string $ceiling = null): array
    {
        $command = ['env', 'QMX_PRIVATE_TERMS=' . self::MARKER];
        if ($ceiling !== null) {
            $command[] = 'GIT_CEILING_DIRECTORIES=' . $ceiling;
        }
        $command[] = 'bash';
        $command[] = $this->scratch . '/.githooks/pre-commit';

        return self::execute($command, $this->scratch);
    }

    /** @param list<string> $arguments
     * @return array{int, string}
     */
    private function scan(array $arguments): array
    {
        return self::execute([
            'env', 'QMX_PRIVATE_TERMS=' . self::MARKER,
            'bash', $this->scratch . '/scripts/check-private-leaks.sh', ...$arguments,
        ], $this->scratch);
    }

    /** @param list<string> $command
     * @return array{int, string}
     */
    private static function execute(array $command, string $cwd): array
    {
        $result = ChildProcess::run($command, $cwd);

        return [$result['exitCode'], $result['stdout'] . $result['stderr']];
    }
}
