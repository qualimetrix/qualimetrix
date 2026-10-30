<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Infrastructure\Console\Baseline\Generation\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Baseline\Measurement\BaselineRun;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\Console\GraphExport\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A command that writes a file refuses, before it writes, a target that is a
 * file its configuration was read from, whichever way that file was reached.
 */
#[CoversClass(ConfigurationDocument::class)]
#[CoversClass(RunTargets::class)]
#[CoversClass(BaselineRun::class)]
final class ConfigurationInputCollisionTest extends TestCase
{
    private const string ROOT = "imports: [base.yaml]\npaths: [Source.php]\n";
    private const string BASE = "memory_limit: 1G\n";
    private const string PRESET = "memory_limit: 1G\n";

    private string $directory;
    private string $originalCwd;

    protected function setUp(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        $this->originalCwd = $cwd;
        $this->directory = sys_get_temp_dir() . '/qmx-config-collision-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        file_put_contents($this->directory . '/Source.php', '<?php namespace Demo; final class Source {}');
        file_put_contents($this->directory . '/qmx.yaml', self::ROOT);
        file_put_contents($this->directory . '/base.yaml', self::BASE);
        file_put_contents($this->directory . '/team.yaml', self::PRESET);
        chdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        self::remove($this->directory);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::remove($path . '/' . $entry);
        }
        rmdir($path);
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('writesOverAConfigurationFile')]
    public function itRefusesAWriteOverAFileTheConfigurationWasReadFrom(string $commandClass, array $input, string $named): void
    {
        $tester = $this->execute($commandClass, $input);

        // A JSON envelope escapes the quotes the origin is named with.
        $answer = str_replace('\\"', '"', $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame(3, $tester->getStatusCode(), $answer);
        self::assertStringContainsString($commandClass === BaselineGenerateCommand::class ? 'is ' . $named . '; a baseline cannot replace' : $named . ' name the same input target', $answer);
        self::assertSame(self::ROOT, file_get_contents($this->directory . '/qmx.yaml'));
        self::assertSame(self::BASE, file_get_contents($this->directory . '/base.yaml'));
        self::assertSame(self::PRESET, file_get_contents($this->directory . '/team.yaml'));
    }

    /** @return iterable<string, array{class-string<Command>, array<string, mixed>, string}> */
    public static function writesOverAConfigurationFile(): iterable
    {
        $kinds = [
            'discovered' => ['qmx.yaml', [], 'configuration file "qmx.yaml"'],
            'explicit' => ['team.yaml', ['--config' => 'team.yaml'], 'configuration file "team.yaml"'],
            'imported' => ['base.yaml', [], 'configuration file "base.yaml" (imported by configuration file "qmx.yaml")'],
            'preset' => ['team.yaml', ['--preset' => ['./team.yaml']], 'preset "./team.yaml"'],
            'preset also imported' => ['base.yaml', ['--preset' => ['./base.yaml']], 'preset "./base.yaml"'],
        ];
        $check = ['--format' => 'json', '--workers' => '0', '--no-cache' => true];
        foreach ($kinds as $kind => [$file, $preset, $named]) {
            foreach (['--output', '--profile', '--log-file'] as $option) {
                yield "check {$option} over the {$kind} file" => [CheckCommand::class, [...$check, ...$preset, $option => $file], $named];
            }
            yield "graph:export --output over the {$kind} file" => [GraphExportCommand::class, [...$preset, '--output' => $file], $named];
            yield "baseline:generate over the {$kind} file" => [BaselineGenerateCommand::class, ['--workers' => '0', ...$preset, 'baseline' => $file, '--force' => true], $named];
        }
    }

    #[Test]
    #[DataProvider('nestedAndSelectedConfigurationFiles')]
    public function itRefusesAWriteOverANestedOrSelectedConfigurationFile(string $file, string $named): void
    {
        mkdir($this->directory . '/cfg');
        file_put_contents($this->directory . '/qmx.yaml', "imports: [base.yaml, {subtree: cfg}]\n");
        file_put_contents($this->directory . '/base.yaml', "imports: [nested.yaml]\n");
        file_put_contents($this->directory . '/nested.yaml', self::BASE);
        file_put_contents($this->directory . '/cfg/selected.yaml', self::BASE);

        $tester = $this->execute(CheckCommand::class, ['--format' => 'json', '--workers' => '0', '--no-cache' => true, '--output' => $file]);

        $answer = str_replace('\\"', '"', $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame(3, $tester->getStatusCode(), $answer);
        self::assertStringContainsString($named . ' name the same input target', $answer);
        self::assertSame(self::BASE, file_get_contents($this->directory . '/' . $file));
    }

    /** @return iterable<string, array{string, string}> */
    public static function nestedAndSelectedConfigurationFiles(): iterable
    {
        yield 'nested' => ['nested.yaml', 'configuration file "nested.yaml" (imported by configuration file "base.yaml" (imported by configuration file "qmx.yaml"))'];
        yield 'selected' => ['cfg/selected.yaml', 'configuration file "cfg/selected.yaml" (imported by configuration file "qmx.yaml")'];
    }

    #[Test]
    #[DataProvider('otherNamesOfTheDiscoveredConfiguration')]
    public function itRefusesAnotherNameOfTheDiscoveredConfiguration(string $kind): void
    {
        $kind === 'symlink' ? symlink('qmx.yaml', $this->directory . '/other.yaml') : link($this->directory . '/qmx.yaml', $this->directory . '/other.yaml');

        $tester = $this->execute(CheckCommand::class, ['--format' => 'json', '--workers' => '0', '--no-cache' => true, '--output' => 'other.yaml']);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame(self::ROOT, file_get_contents($this->directory . '/qmx.yaml'));
    }

    /** @return iterable<string, array{string}> */
    public static function otherNamesOfTheDiscoveredConfiguration(): iterable
    {
        yield 'symlink' => ['symlink'];
        yield 'hard link' => ['hard link'];
    }

    #[Test]
    public function itWritesAReportBesideTheConfigurationFiles(): void
    {
        $tester = $this->execute(CheckCommand::class, ['--format' => 'json', '--workers' => '0', '--no-cache' => true, '--output' => 'report.json']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertStringContainsString('"summary"', (string) file_get_contents($this->directory . '/report.json'));
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, mixed> $input
     */
    private function execute(string $commandClass, array $input): CommandTester
    {
        $command = (new ContainerFactory())->create()->get($commandClass);
        self::assertInstanceOf($commandClass, $command);
        $tester = new CommandTester($command);
        $tester->execute(['paths' => ['Source.php'], ...$input], ['capture_stderr_separately' => true]);

        return $tester;
    }
}
