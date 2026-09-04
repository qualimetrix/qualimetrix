<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The promise as a user can observe it: a configuration error ends the run
 * with a non-zero code **and** is printed, whatever the run was told to
 * suppress.
 *
 * The unit sweep in
 * {@see \Qualimetrix\Tests\Reporting\FindingProjection\Unit\ConfigurationErrorProjectionTest}
 * pins the same guarantee per pipeline stage; this class exists because the
 * defect it guards against was only visible end to end. `check` gates on the
 * list the projection returns, so every stage that could drop the finding
 * dropped the exit code with it — `--fail-on=none` plus a file-wide
 * `@qmx-ignore-file` turned a broken configuration into a green build with an
 * empty finding list.
 */
final class CheckCommandConfigurationErrorGateTest extends TestCase
{
    private const int EXIT_ERROR = 2;

    private const string UNRESOLVED_CHANNEL = 'annotation.unresolved-directive';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-config-error-gate-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/src', 0777, true);
        // A configuration of its own, so the repository's `qmx.yaml` — with
        // its layers and its own diagnostics — never reaches this run.
        file_put_contents($this->tempDir . '/qmx.yaml', "rules: {}\n");
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
    }

    /**
     * `@qmx-ignore-file` naming the diagnostic's own channel, and
     * `--fail-on=none` on top of it: the loudest possible request to be
     * quiet.
     */
    #[Test]
    public function itFailsAndReportsThroughAnInlineSuppressionOfTheDiagnostic(): void
    {
        $this->writeSource(<<<'PHP_SOURCE'
            /**
             * @qmx-ignore coupling.instabilty -- a typo, which is the configuration error
             * @qmx-ignore-file annotation.unresolved-directive -- the attempt to silence it
             */
            PHP_SOURCE);

        $tester = $this->runCheck(['--fail-on' => 'none']);

        self::assertSame(self::EXIT_ERROR, $tester->getStatusCode());
        self::assertStringContainsString(self::UNRESOLVED_CHANNEL, $tester->getDisplay());
    }

    /**
     * The same finding, taken out by an exclusion rather than an annotation:
     * `exclude_paths` covering the only analysed directory.
     */
    #[Test]
    public function itFailsAndReportsThroughAPathExclusionCoveringTheFile(): void
    {
        $this->writeSource(<<<'PHP_SOURCE'
            /**
             * @qmx-ignore coupling.instabilty -- a typo, which is the configuration error
             */
            PHP_SOURCE);

        $tester = $this->runCheck([
            '--fail-on' => 'none',
            '--exclude-path' => ['**/Subject.php'],
        ]);

        self::assertSame(self::EXIT_ERROR, $tester->getStatusCode());
        self::assertStringContainsString(self::UNRESOLVED_CHANNEL, $tester->getDisplay());
    }

    private function writeSource(string $docblock): void
    {
        file_put_contents($this->tempDir . '/src/Subject.php', <<<PHP_SOURCE
            <?php

            declare(strict_types=1);

            namespace Demo;

            {$docblock}
            final class Subject
            {
                public function value(): int
                {
                    return 1;
                }
            }

            PHP_SOURCE);
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
            '--format' => 'json',
            '--workers' => '0',
            ...$options,
        ], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
