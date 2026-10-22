<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\PrivateLeakScan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

final class PrivateLeakScanVerdictTest extends TestCase
{
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
        self::assertSame(0, self::execute([
            'git', 'commit', '-q', '--allow-empty', '--no-verify', '-m', 'chore: second',
        ], $this->scratch)[0]);

        [$exitCode, $output] = $this->scan(['--commits', 'HEAD~1..HEAD']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Commit messages clean', $output);
        self::assertStringNotContainsString('unavailable', $output);
    }

    #[Test]
    public function itKeepsGitRangeShorthandAvailable(): void
    {
        $this->initializeRepository();
        self::assertSame(0, self::execute([
            'git', 'commit', '-q', '--allow-empty', '--no-verify', '-m', 'chore: second',
        ], $this->scratch)[0]);

        foreach (['..HEAD', 'HEAD..', 'HEAD~1...HEAD', 'HEAD'] as $range) {
            [$exitCode, $output] = $this->scan(['--commits', $range]);

            self::assertSame(0, $exitCode, "$range: $output");
            self::assertStringContainsString('Commit messages clean', $output, $range);
        }
    }

    #[Test]
    public function itRefusesToJudgeFilesWhenTrackedFilesCannotBeListed(): void
    {
        $result = ChildProcess::run([
            'env', 'GIT_CEILING_DIRECTORIES=' . $this->scratch,
            'bash', $this->scratch . '/scripts/check-private-leaks.sh',
        ], $this->scratch);

        self::assertSame(2, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('Cannot list tracked files', $result['stderr']);
        self::assertStringNotContainsString('No private paths, terms or inventory shapes', $result['stdout']);
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

    /** @param list<string> $arguments
     * @return array{int, string}
     */
    private function scan(array $arguments): array
    {
        return self::execute([
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
