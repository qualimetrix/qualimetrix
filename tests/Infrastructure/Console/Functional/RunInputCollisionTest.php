<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Infrastructure\Console\Baseline\Generation\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\Console\GraphExport\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\RunSetup\Execution\RunConfigurationPreparation;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Subprocess\ChildProcess;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A command that writes a file refuses, before it writes, a target that is the
 * Composer manifest or a source the run analyses, and a report on standard
 * output that the shell opened on any file the run reads.
 */
#[CoversClass(ComposerDiscoveryStage::class)]
#[CoversClass(RunConfigurationPreparation::class)]
#[CoversClass(RunTargets::class)]
final class RunInputCollisionTest extends TestCase
{
    private const string MANIFEST = '{"name": "demo/app", "autoload": {"psr-4": {"Demo\\\\": "src/"}}}';
    private const string SOURCE = "<?php\n\nnamespace Demo;\n\nfinal class Source {}\n";
    private const string GENERATED = "<?php\n\n// @generated\n\nnamespace Demo;\n\nfinal class Generated {}\n";

    private string $directory;
    private string $originalCwd;

    protected function setUp(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        $this->originalCwd = $cwd;
        $this->directory = sys_get_temp_dir() . '/qmx-run-input-collision-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/src', 0o777, true);
        file_put_contents($this->directory . '/composer.json', self::MANIFEST);
        file_put_contents($this->directory . '/src/Source.php', self::SOURCE);
        file_put_contents($this->directory . '/src/Generated.php', self::GENERATED);
        link($this->directory . '/src/Source.php', $this->directory . '/Alias.php');
        symlink('src/Source.php', $this->directory . '/Link.php');
        file_put_contents($this->directory . '/qmx.yaml', "paths: [src]\n");
        chdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        self::remove($this->directory);
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('writesOverARunInput')]
    public function itRefusesAWriteOverAFileTheRunReads(string $commandClass, array $input, string $named): void
    {
        $tester = $this->execute($commandClass, $input);

        // A JSON envelope escapes the quotes the input is named with.
        $answer = str_replace('\\"', '"', $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame(3, $tester->getStatusCode(), $answer);
        self::assertStringContainsString($commandClass === BaselineGenerateCommand::class ? 'is ' . $named . '; a baseline cannot replace' : $named . ' name the same input target', $answer);
        $this->assertInputsIntact();
    }

    /** @return iterable<string, array{class-string<Command>, array<string, mixed>, string}> */
    public static function writesOverARunInput(): iterable
    {
        $kinds = [
            'Composer manifest' => ['composer.json', 'composer.json'],
            'analysed source' => ['src/Source.php', 'source file "src/Source.php"'],
            'analysed source under another spelling' => ['./src/../src/Source.php', 'source file "src/Source.php"'],
            'symlink to an analysed source' => ['Link.php', 'source file "src/Source.php"'],
            'hard link of an analysed source' => ['Alias.php', 'source file "src/Source.php"'],
            'manifest under another spelling' => ['src/../composer.json', 'composer.json'],
            'source excluded as generated' => ['src/Generated.php', 'source file "src/Generated.php"'],
        ];
        $check = ['--format' => 'json', '--workers' => '0', '--no-cache' => true];
        foreach ($kinds as $kind => [$file, $named]) {
            foreach (['--output', '--profile', '--log-file'] as $option) {
                yield "check {$option} over the {$kind}" => [CheckCommand::class, [...$check, $option => $file], $named];
            }
            yield "graph:export --output over the {$kind}" => [GraphExportCommand::class, ['--output' => $file], $named];
            yield "baseline:generate over the {$kind}" => [BaselineGenerateCommand::class, ['--workers' => '0', 'baseline' => $file, '--force' => true], $named];
        }
    }

    #[Test]
    public function itRefusesAWriteOverAManifestThatDeclaresNoCode(): void
    {
        file_put_contents($this->directory . '/composer.json', '{"name": "demo/app"}');

        $tester = $this->execute(CheckCommand::class, ['--workers' => '0', '--no-cache' => true, '--output' => 'composer.json']);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertStringContainsString('--output and composer.json name the same input target', $tester->getErrorOutput());
        self::assertSame('{"name": "demo/app"}', file_get_contents($this->directory . '/composer.json'));
    }

    #[Test]
    public function itWritesANewFileAmongTheAnalysedSources(): void
    {
        $tester = $this->execute(CheckCommand::class, ['--format' => 'json', '--workers' => '0', '--no-cache' => true, '--output' => 'src/report.json']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertStringContainsString('"summary"', (string) file_get_contents($this->directory . '/src/report.json'));
        $this->assertInputsIntact();
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('standardOutputOpenedOnARunInput')]
    public function itRefusesAReportOnStandardOutputOpenedOnAFileTheRunReads(array $arguments, string $file, string $named): void
    {
        $before = (string) file_get_contents($this->directory . '/' . $file);
        $run = ChildProcess::run(
            ['/bin/sh', '-c', 'file=$1; shift; exec "$@" >> "$file"', 'sh', $file, \PHP_BINARY, \dirname(__DIR__, 4) . '/bin/qmx', ...$arguments, '--workers=0', '--no-cache'],
            $this->directory,
        );

        self::assertSame(3, $run['exitCode'], $run['stderr']);
        self::assertStringContainsString('standard output and ' . $named . ' name the same input target', $run['stderr']);
        self::assertSame($before, file_get_contents($this->directory . '/' . $file), 'Nothing, not even the refusal, reaches standard output');
    }

    /** @return iterable<string, array{list<string>, string, string}> */
    public static function standardOutputOpenedOnARunInput(): iterable
    {
        yield 'check report appended to the configuration' => [['check', '--format=json'], 'qmx.yaml', 'configuration file "qmx.yaml"'];
        yield 'check report appended to the manifest' => [['check'], 'composer.json', 'composer.json'];
        yield 'check report appended to an analysed source' => [['check', '--format=json'], 'src/Source.php', 'source file "src/Source.php"'];
        yield 'graph appended to an analysed source' => [['graph:export'], 'src/Source.php', 'source file "src/Source.php"'];
        yield 'check report appended to a source, with a colliding profile' => [['check', '--format=json', '--profile=composer.json'], 'src/Source.php', 'source file "src/Source.php"'];
    }

    private function assertInputsIntact(): void
    {
        self::assertSame(self::MANIFEST, file_get_contents($this->directory . '/composer.json'));
        self::assertSame(self::SOURCE, file_get_contents($this->directory . '/src/Source.php'));
        self::assertSame(self::GENERATED, file_get_contents($this->directory . '/src/Generated.php'));
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
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
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
}
