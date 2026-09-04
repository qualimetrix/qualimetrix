<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * A finding about the project survives a report narrowed to a git range —
 * observed where a user observes it, as the exit code of `check`.
 *
 * The unit sweep in
 * {@see \Qualimetrix\Tests\Reporting\FindingProjection\Unit\ProjectScopedChannelProjectionTest}
 * pins the same promise per stage. This class exists because the defect was
 * only visible end to end: `architecture.unassigned-class` was exempt from
 * `exclude_paths` and `exclude_namespaces` and silently dropped by the git
 * scope, so `--report=git:staged` turned the gate a user had switched on into
 * a green build with nothing printed to say what had happened.
 *
 * The run is deliberately made from a temporary repository with nothing
 * staged: an empty scope is the sharpest form of the narrowing, and it does
 * not depend on what the developer running the suite happens to have staged.
 */
final class CheckCommandProjectScopedGateTest extends TestCase
{
    private const int EXIT_ERROR = 2;

    private const string PROJECT_SCOPED_CHANNEL = 'architecture.unassigned-class';

    private const string FILE_SCOPED_CHANNEL = 'complexity.cyclomatic';

    private string $tempDir;

    private string $originalCwd;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();

        $tempDir = realpath(sys_get_temp_dir()) . '/qmx-project-scoped-gate-' . uniqid();
        mkdir($tempDir . '/src', 0777, true);
        $this->tempDir = $tempDir;

        // One layer covering `Demo\A` only: `Demo\B` is then unassigned while
        // every declared layer is reached, so the only finding about the
        // project is the one under test — no `architecture.unreachable-layer`,
        // which is a configuration error and would gate on its own.
        file_put_contents($tempDir . '/qmx.yaml', <<<'YAML'
            architecture:
              coverage: ignore
              layers:
                - name: covered
                  patterns: ['Demo\A']
              allow: {}
            rules: {}

            YAML);

        $this->writeClass('A', '        return 1;');
        $this->writeClass('B', <<<'PHP_BODY'
                    if ($value > 1) {
                        return 1;
                    }

                    if ($value > 2) {
                        return 2;
                    }

                    return 0;
            PHP_BODY);

        $this->initRepository();
        chdir($tempDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        self::removeDirectory($this->tempDir);
    }

    /**
     * The gate the user asked for fires, and the finding is printed rather
     * than only counted — a red build with no stated cause is the other half
     * of the same defect.
     */
    #[Test]
    public function itFailsAndReportsThroughAReportNarrowedToAnEmptyGitScope(): void
    {
        $tester = $this->runCheck(['--report' => 'git:staged']);

        self::assertSame(self::EXIT_ERROR, $tester->getStatusCode());
        self::assertStringContainsString(self::PROJECT_SCOPED_CHANNEL, $tester->getDisplay());
    }

    /**
     * The counterweight: the same run must still narrow what narrowing is
     * for. Without it the test above would pass just as well against a git
     * scope that filtered nothing at all.
     */
    #[Test]
    public function itStillDropsAFileScopedFindingFromTheSameRun(): void
    {
        $unscoped = $this->runCheck([]);
        self::assertStringContainsString(self::FILE_SCOPED_CHANNEL, $unscoped->getDisplay());

        $scoped = $this->runCheck(['--report' => 'git:staged']);
        self::assertStringNotContainsString(self::FILE_SCOPED_CHANNEL, $scoped->getDisplay());
    }

    private function writeClass(string $name, string $body): void
    {
        file_put_contents($this->tempDir . '/src/' . $name . '.php', <<<PHP_SOURCE
            <?php

            declare(strict_types=1);

            namespace Demo;

            final class {$name}
            {
                public function value(int \$value): int
                {
            {$body}
                }
            }

            PHP_SOURCE);
    }

    private function initRepository(): void
    {
        $commands = ['git init', 'git config user.email "test@example.com"', 'git config user.name "Test User"'];
        foreach ($commands as $command) {
            $process = Process::fromShellCommandline($command, $this->tempDir);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new RuntimeException(\sprintf('Command failed: %s', $process->getErrorOutput()));
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCheck(array $options): CommandTester
    {
        $container = (new ContainerFactory())->create();
        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);
        $application = new Application(new ErrorStream());
        $application->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $this->tempDir . '/qmx.yaml',
            '--workers' => '0',
            '--no-cache' => true,
            '--detail' => true,
            '--rule-opt' => [
                'architecture.unassigned-class:mode=error',
                'complexity.cyclomatic:threshold=1',
            ],
            ...$options,
        ], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
