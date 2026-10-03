<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RunTargets::class)]
final class RunTargetCollisionTest extends TestCase
{
    private string $directory;
    private string $originalCwd;

    protected function setUp(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        $this->originalCwd = $cwd;
        $this->directory = sys_get_temp_dir() . '/qmx-collision-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        file_put_contents($this->directory . '/Source.php', '<?php namespace Demo; final class Source {}');
        chdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        foreach (array_diff((array) scandir($this->directory), ['.', '..']) as $entry) {
            unlink($this->directory . '/' . $entry);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function itRefusesAReportAndProfileNamingOneExistingInodeBeforeEitherWrites(): void
    {
        $report = $this->directory . '/report.json';
        file_put_contents($report, 'KEEP');
        link($report, $this->directory . '/profile.json');

        $tester = $this->check(['--output' => $report, '--profile' => $this->directory . '/profile.json']);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertStringContainsString('same output target', $tester->getDisplay());
        self::assertSame('KEEP', file_get_contents($report));
        self::assertSame('KEEP', file_get_contents($this->directory . '/profile.json'));
    }

    #[Test]
    public function itRefusesAnImplicitReportAndProfileNamingStandardOutput(): void
    {
        $tester = $this->check(['--profile' => 'php://stdout']);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertStringContainsString('standard output', $tester->getDisplay());
        self::assertSame(['Source.php'], array_values(array_diff((array) scandir($this->directory), ['.', '..'])));
    }

    #[Test]
    public function itRefusesAReportAndLogNamingOneAbsentPathWithoutCreatingIt(): void
    {
        $path = $this->directory . '/both.json';
        $tester = $this->check(['--output' => $path, '--log-file' => $path]);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertStringContainsString('same output target', $tester->getDisplay());
        self::assertFileDoesNotExist($path);
    }

    #[Test]
    public function itAllowsTheSameNullDeviceForTwoExplicitDocuments(): void
    {
        $tester = $this->check(['--output' => '/dev/null', '--profile' => '/dev/null']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame('char', filetype('/dev/null'));
    }

    /** @param array<string, mixed> $options */
    private function check(array $options): CommandTester
    {
        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $tester = new CommandTester($command);
        $tester->execute([
            'paths' => [$this->directory . '/Source.php'],
            '--format' => 'json',
            '--workers' => '0',
            '--no-cache' => true,
            ...$options,
        ], ['capture_stderr_separately' => true]);

        return $tester;
    }
}
