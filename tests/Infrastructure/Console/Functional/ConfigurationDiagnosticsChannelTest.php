<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRun;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\Formatter\Json\JsonFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A warning about accepted configuration reaches its author from every
 * command that reads the document, and a JSON report carries it with the
 * layers it is about.
 *
 * The configuration is lawful and draws one warning: a preset filters the
 * rules, the file writes `only_rules: []` and lifts that filter.
 */
#[CoversClass(ConfigurationInputAdapter::class)]
#[CoversClass(AnalysisPreflight::class)]
#[CoversClass(BaselineRun::class)]
#[CoversClass(CheckCommand::class)]
#[CoversClass(JsonFormatter::class)]
final class ConfigurationDiagnosticsChannelTest extends TestCase
{
    private const string WARNING = 'Warning: "only_rules" is written empty in configuration file';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-config-diagnostics-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/src', 0o755, true);
        file_put_contents($this->directory . '/src/Sample.php', "<?php\nnamespace App;\nfinal class Sample {}\n");
        file_put_contents($this->directory . '/focused.yaml', "only_rules: [complexity.ccn]\n");
        file_put_contents($this->directory . '/qmx.yaml', "only_rules: []\n");
    }

    protected function tearDown(): void
    {
        exec(\sprintf('rm -rf %s', escapeshellarg($this->directory)));
    }

    /**
     * `debug:layer-assignment` resolves through the same preflight as
     * `directives`, and takes no `--preset` to draw this warning with.
     *
     * @return iterable<string, array{class-string<Command>, array<string, mixed>}>
     */
    public static function provideDocumentReadingCommands(): iterable
    {
        yield 'check' => [CheckCommand::class, ['paths' => ['src']]];
        yield 'directives' => [DirectivesCommand::class, ['paths' => ['src']]];
        yield 'baseline:generate' => [BaselineGenerateCommand::class, ['baseline' => 'baseline.json', 'paths' => ['src']]];
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('provideDocumentReadingCommands')]
    public function itWritesTheWarningToStderrFromEveryCommandThatReadsTheDocument(string $commandClass, array $arguments): void
    {
        $tester = $this->execute($commandClass, $arguments);

        self::assertSame(1, substr_count($tester->getErrorOutput(), self::WARNING), $tester->getErrorOutput());
        self::assertStringNotContainsString(self::WARNING, $tester->getDisplay());
    }

    #[Test]
    public function itPublishesTheWarningWithBothLayersInTheJsonReport(): void
    {
        $tester = $this->execute(CheckCommand::class, ['paths' => ['src'], '--format' => 'json']);

        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertCount(1, $report['configurationDiagnostics']);
        $diagnostic = $report['configurationDiagnostics'][0];
        self::assertStringStartsWith('"only_rules" is written empty in configuration file', $diagnostic['message']);
        self::assertSame(
            [
                ['kind' => 'preset', 'name' => $this->directory . '/focused.yaml', 'imported_by' => null],
                ['kind' => 'file', 'name' => 'qmx.yaml', 'imported_by' => null],
            ],
            $diagnostic['source'],
        );
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, mixed> $arguments
     */
    private function execute(string $commandClass, array $arguments): CommandTester
    {
        $command = (new ContainerFactory())->create()->get($commandClass);
        self::assertInstanceOf(Command::class, $command);

        $previous = getcwd();
        chdir($this->directory);
        try {
            $tester = new CommandTester($command);
            $tester->execute(
                [...$arguments, '--config' => 'qmx.yaml', '--preset' => [$this->directory . '/focused.yaml']],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous === false ? '/' : $previous);
        }

        return $tester;
    }
}
