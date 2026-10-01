<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRun;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\Debug\LayerAssignmentCommand;
use Qualimetrix\Infrastructure\Console\Command\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\Command\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
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
     * @return iterable<string, array{class-string<Command>, array<string, mixed>}>
     */
    public static function provideDocumentReadingCommands(): iterable
    {
        yield 'check' => [CheckCommand::class, ['paths' => ['src']]];
        yield 'directives' => [DirectivesCommand::class, ['paths' => ['src']]];
        yield 'baseline:generate' => [BaselineGenerateCommand::class, ['baseline' => 'baseline.json', 'paths' => ['src']]];
        yield 'rules' => [RulesCommand::class, []];
        yield 'graph' => [GraphExportCommand::class, ['paths' => ['src']]];
        yield 'debug' => [LayerAssignmentCommand::class, ['fqn' => 'App\\Sample']];
        yield 'baseline:update' => [BaselineUpdateCommand::class, ['baseline' => 'baseline.json', 'paths' => ['src']]];
        yield 'baseline:cleanup' => [BaselineCleanupCommand::class, ['baseline' => 'baseline.json', 'paths' => ['src']]];
        yield 'baseline:explain' => [BaselineExplainCommand::class, ['subject' => 'declaration:class:App\\Sample@src/Sample.php', 'paths' => ['src']]];
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

    #[Test]
    public function itRefusesAnUnknownSelectorBeforeDiscoveryAndThroughTheRealCli(): void
    {
        file_put_contents($this->directory . '/qmx.yaml', "paths: [src]\nonly_rules: [nosuch.channel]\ncache: {enabled: false}\nparallel: {workers: 0}\n");
        $discovery = self::createMock(FileDiscoveryFactoryInterface::class);
        $discovery->expects(self::never())->method('create');
        $container = (new ContainerFactory())->configure();
        $container->removeAlias(FileDiscoveryFactoryInterface::class);
        $container->register(FileDiscoveryFactoryInterface::class)->setSynthetic(true)->setPublic(true);
        $container->compile();
        $container->set(FileDiscoveryFactoryInterface::class, $discovery);
        $command = $container->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $tester = new CommandTester($command);
        $previous = getcwd();
        chdir($this->directory);
        try {
            self::assertSame(3, $tester->execute(['--config' => 'qmx.yaml'], ['capture_stderr_separately' => true]));
        } finally {
            chdir($previous === false ? '/' : $previous);
        }
        self::assertStringContainsString('Rule selector "nosuch.channel" does not match any registered producer or channel.', $tester->getErrorOutput());

        require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';
        $result = \Qualimetrix\Subprocess\ChildProcess::run([
            \PHP_BINARY, '-d', 'xdebug.mode=off', \dirname(__DIR__, 4) . '/bin/qmx', 'check',
            '--config=' . $this->directory . '/qmx.yaml', '--working-dir=' . $this->directory,
        ], workingDirectory: $this->directory);
        $stdout = $result['stdout'];
        $stderr = $result['stderr'];
        self::assertSame(3, $result['exitCode'], (string) $stdout . (string) $stderr);
        self::assertStringContainsString('Rule selector "nosuch.channel" does not match any registered producer or channel.', (string) $stderr);
    }

    #[Test]
    public function itPublishesTheNarrowedGroupDiagnosticFromTheFinalFindingCarrier(): void
    {
        $message = 'The group "code-smell.*" no longer includes "design.god-class" or "design.data-class". Name those producers explicitly if they should remain selected.';
        foreach ([
            [CheckCommand::class, ['paths' => ['src'], '--format' => 'json']],
            [DirectivesCommand::class, ['paths' => ['src']]],
            [BaselineGenerateCommand::class, ['baseline' => 'baseline.json', 'paths' => ['src']]],
            [RulesCommand::class, []],
        ] as [$class, $arguments]) {
            $tester = $this->execute($class, $arguments, ['code-smell.*']);
            self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
            self::assertSame(1, substr_count($tester->getErrorOutput(), 'Warning: ' . $message), $tester->getErrorOutput());
            if ($class === CheckCommand::class) {
                $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame($message, $report['configurationDiagnostics'][0]['message']);
                self::assertSame([['kind' => 'file', 'name' => 'qmx.yaml', 'imported_by' => null]], $report['configurationDiagnostics'][0]['source']);
            } else {
                self::assertStringNotContainsString($message, $tester->getDisplay());
            }
        }
    }

    #[Test]
    public function itWritesAnIgnoredNearConfigFilenameWarningToTheErrorStream(): void
    {
        unlink($this->directory . '/qmx.yaml');
        file_put_contents($this->directory . '/qmx.yaml.bak', "paths: [src]\n");
        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(Command::class, $command);

        $previous = getcwd();
        chdir($this->directory);
        try {
            $tester = new CommandTester($command);
            $tester->execute(['paths' => ['src']], ['capture_stderr_separately' => true]);
        } finally {
            chdir($previous === false ? '/' : $previous);
        }

        self::assertStringContainsString('Ignored configuration-like filename "qmx.yaml.bak"', $tester->getErrorOutput());
        self::assertStringNotContainsString('Ignored configuration-like filename', $tester->getDisplay());
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, mixed> $arguments
     * @param list<string> $only
     */
    private function execute(string $commandClass, array $arguments, array $only = []): CommandTester
    {
        $command = (new ContainerFactory())->create()->get($commandClass);
        self::assertInstanceOf(Command::class, $command);

        $previous = getcwd();
        chdir($this->directory);
        try {
            file_put_contents($this->directory . '/qmx.yaml', "paths: [src]\nonly_rules: [" . implode(", ", $only) . "]\ncache: {enabled: false}\nparallel: {workers: 0}\n");
            if ($commandClass === BaselineUpdateCommand::class || $commandClass === BaselineCleanupCommand::class) {
                $generate = (new ContainerFactory())->create()->get(BaselineGenerateCommand::class);
                self::assertInstanceOf(BaselineGenerateCommand::class, $generate);
                self::assertSame(0, (new CommandTester($generate))->execute(['baseline' => 'baseline.json', 'paths' => ['src']]));
            }
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
