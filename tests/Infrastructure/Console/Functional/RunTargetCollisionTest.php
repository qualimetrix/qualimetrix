<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Subprocess\ChildProcess;
use Symfony\Component\Console\Tester\CommandTester;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

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
    public function itRefusesAReportThatNamesItsExplicitConfigurationInput(): void
    {
        $config = $this->directory . '/qmx.yaml';
        $original = "rules: {}\n";
        file_put_contents($config, $original);

        $tester = $this->check(['--config' => $config, '--output' => $config]);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertStringContainsString('same input target', $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame($original, file_get_contents($config));
    }

    #[Test]
    public function itRefusesAReportThatNamesItsBaselineInput(): void
    {
        $baseline = $this->directory . '/baseline.json';
        $original = '{"version":14,"generated":"2026-08-05T12:00:00+03:00","scope":[],"exclusions":{"patterns":[],"generated":"excluded"},"entries":{}}';
        file_put_contents($baseline, $original);

        $tester = $this->check(['--baseline' => $baseline, '--output' => $baseline]);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertStringContainsString('same input target', $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame($original, file_get_contents($baseline));
    }

    #[Test]
    public function itAllowsTheSameNullDeviceForTwoExplicitDocuments(): void
    {
        $tester = $this->check(['--output' => '/dev/null', '--profile' => '/dev/null']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame('char', filetype('/dev/null'));
    }

    #[Test]
    #[DataProvider('stderrOutputs')]
    public function itAllowsAnExplicitStderrReportWhenShellRedirectsStderrToStdout(string $option): void
    {
        $command = escapeshellarg(\PHP_BINARY)
            . ' ' . escapeshellarg(\dirname(__DIR__, 4) . '/bin/qmx')
            . ' check Source.php --format=json --workers=0 --no-cache ' . $option . '=/dev/stderr 2>&1';
        $run = ChildProcess::run(['sh', '-c', $command], $this->directory);

        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertStringContainsString('"summary"', $run['stdout']);
        self::assertStringNotContainsString('same output target', $run['stdout']);
    }

    /** @return iterable<string, array{string}> */
    public static function stderrOutputs(): iterable
    {
        foreach (['--output', '--log-file', '--profile'] as $option) {
            yield $option => [$option];
        }
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
