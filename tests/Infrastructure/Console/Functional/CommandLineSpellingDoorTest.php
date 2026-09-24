<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRenameChannelsCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\Debug\LayerAssignmentCommand;
use Qualimetrix\Infrastructure\Console\Command\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\Command\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Qualimetrix\Infrastructure\Console\CommandLineSpelling;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Every door that reads a valued option or argument, fed by an embedder's
 * array input a value no command line can spell.
 *
 * Argv delivers strings only, so each of these doors was written for a
 * string, and a boolean reached it as a type error reported as the product's
 * own failure (exit 1), or was dropped and the run went on without the input
 * it was given (exit 2). Either way the caller learned nothing about their
 * input.
 */
#[CoversClass(CommandLineSpelling::class)]
final class CommandLineSpellingDoorTest extends TestCase
{
    private string $fixture = '';

    private string $workingDirectory = '';

    protected function setUp(): void
    {
        $workingDirectory = getcwd();
        self::assertNotFalse($workingDirectory);
        $this->workingDirectory = $workingDirectory;

        $this->fixture = sys_get_temp_dir() . '/qmx-spelling-door-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o755, true);
        file_put_contents($this->fixture . '/src/A.php', "<?php\nnamespace App;\nfinal class A {}\n");
        file_put_contents($this->fixture . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        chdir($this->fixture);
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        self::remove($this->fixture);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function provideDoors(): iterable
    {
        $check = ['command' => 'check', 'paths' => ['src'], '--no-cache' => true, '--workers' => '0'];

        foreach (['output', 'config', 'baseline', 'log-file', 'log-level', 'report', 'format', 'cache-dir', 'fail-on', 'memory-limit', 'profile-format', 'group-by', 'namespace', 'class', 'top'] as $option) {
            yield 'check --' . $option => [[...$check, '--' . $option => true], '--' . $option];
        }
        foreach (['suppress-path', 'suppress-namespace', 'rule-opt', 'preset', 'exclude', 'disable-rule', 'only-rule', 'exclude-health', 'format-opt'] as $option) {
            yield 'check --' . $option => [[...$check, '--' . $option => [true]], '--' . $option];
        }
        yield 'check --workers' => [['command' => 'check', 'paths' => ['src'], '--no-cache' => true, '--workers' => true], '--workers'];
        yield 'check paths' => [['command' => 'check', 'paths' => [true], '--no-cache' => true, '--workers' => '0'], 'paths'];

        $graph = ['command' => 'graph:export', 'paths' => ['src']];
        foreach (['format', 'direction', 'output'] as $option) {
            yield 'graph:export --' . $option => [[...$graph, '--' . $option => true], '--' . $option];
        }
        foreach (['namespace', 'exclude-namespace'] as $option) {
            yield 'graph:export --' . $option => [[...$graph, '--' . $option => [true]], '--' . $option];
        }
        yield 'graph:export paths' => [['command' => 'graph:export', 'paths' => [true]], 'paths'];

        yield 'rules --group' => [['command' => 'rules', '--group' => true], '--group'];
        yield 'directives --format' => [['command' => 'directives', 'paths' => ['src'], '--format' => true], '--format'];
        yield 'directives --sweep' => [['command' => 'directives', 'paths' => ['src'], '--sweep' => true], '--sweep'];
        yield 'debug:layer-assignment --format' => [['command' => 'debug:layer-assignment', 'fqn' => 'App\A', '--format' => true], '--format'];
        yield 'debug:layer-assignment fqn' => [['command' => 'debug:layer-assignment', 'fqn' => true], 'fqn'];

        yield 'baseline:generate baseline' => [['command' => 'baseline:generate', 'baseline' => true, 'paths' => ['src']], 'baseline'];
        yield 'baseline:generate --mode' => [['command' => 'baseline:generate', 'baseline' => 'b.json', 'paths' => ['src'], '--mode' => true], '--mode'];
        yield 'baseline:update baseline' => [['command' => 'baseline:update', 'baseline' => true, 'paths' => ['src']], 'baseline'];
        yield 'baseline:cleanup baseline' => [['command' => 'baseline:cleanup', 'baseline' => true, 'paths' => ['src']], 'baseline'];
        yield 'baseline:cleanup --remove' => [['command' => 'baseline:cleanup', 'baseline' => 'b.json', 'paths' => ['src'], '--remove' => [true]], '--remove'];
        yield 'baseline:explain subject' => [['command' => 'baseline:explain', 'subject' => true, 'paths' => ['src']], 'subject'];
        yield 'baseline:explain --channel' => [['command' => 'baseline:explain', 'subject' => 'App\A', 'paths' => ['src'], '--channel' => true], '--channel'];
        yield 'baseline:explain --baseline' => [['command' => 'baseline:explain', 'subject' => 'App\A', 'paths' => ['src'], '--baseline' => true], '--baseline'];
        yield 'baseline:rename-channels baseline' => [['command' => 'baseline:rename-channels', 'baseline' => true, 'map' => 'm.tsv'], 'baseline'];
        yield 'baseline:rename-channels map' => [['command' => 'baseline:rename-channels', 'baseline' => 'b.json', 'map' => true], 'map'];
        yield 'baseline:rename-channels --format' => [['command' => 'baseline:rename-channels', 'baseline' => 'b.json', 'map' => 'm.tsv', '--format' => true], '--format'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('provideDoors')]
    public function itRefusesAValueNoCommandLineCanSpell(array $input, string $door): void
    {
        $tester = $this->application();
        $exit = $tester->run($input, ['capture_stderr_separately' => true]);
        $said = $tester->getErrorOutput() . $tester->getDisplay();

        self::assertSame(3, $exit, $said);
        self::assertStringContainsString(\sprintf('Invalid %s value of type bool', $door), $said);
    }

    /**
     * `--report` is single-valued, and its reader took any non-string as "not
     * written": a list or a number ran the whole project unscoped, exit 0.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideReportValues(): iterable
    {
        yield 'a list' => [['git:HEAD'], 'Invalid --report value of type array'];
        yield 'a number, read as its digits' => [5, 'Invalid report scope: 5'];
    }

    #[Test]
    #[DataProvider('provideReportValues')]
    public function itRefusesAReportScopeNoCommandLineSpells(mixed $report, string $refusal): void
    {
        $tester = $this->application();
        $exit = $tester->run(
            ['command' => 'check', 'paths' => ['src'], '--no-cache' => true, '--workers' => '0', '--report' => $report],
            ['capture_stderr_separately' => true],
        );
        $said = $tester->getErrorOutput() . $tester->getDisplay();

        self::assertSame(3, $exit, $said);
        self::assertStringContainsString($refusal, $said);
    }

    /**
     * The legitimate neighbour: an integer is the number a command line would
     * have typed, and a value-optional flag written through an array input as
     * `true` is the flag alone.
     */
    #[Test]
    public function itReadsAnIntegerAsItsDigitsAndAFlagWrittenAsTrueAsTheFlag(): void
    {
        $tester = $this->application();
        $exit = $tester->run(
            ['command' => 'check', 'paths' => ['src'], '--no-cache' => true, '--workers' => 0, '--format' => 'json', '--top' => 5, '--profile' => true],
            ['capture_stderr_separately' => true],
        );

        self::assertNotContains($exit, [1, 3], $tester->getErrorOutput());
        /** @var array<string, mixed> $report */
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('error', $report);
        self::assertFileDoesNotExist($this->fixture . '/1', 'A value-optional flag written as true names no file.');
    }

    private function application(): ApplicationTester
    {
        $container = (new ContainerFactory())->create();
        /** @var ErrorStream $errorStream */
        $errorStream = $container->get(ErrorStream::class);
        /** @var RefusalPresenter $refusalPresenter */
        $refusalPresenter = $container->get(RefusalPresenter::class);
        $application = new Application($errorStream, $refusalPresenter);
        $application->setAutoExit(false);
        $application->setCommandLoader(new ContainerCommandLoader($container, [
            'check' => CheckCommand::class,
            'baseline:generate' => BaselineGenerateCommand::class,
            'baseline:update' => BaselineUpdateCommand::class,
            'baseline:cleanup' => BaselineCleanupCommand::class,
            'baseline:explain' => BaselineExplainCommand::class,
            'baseline:rename-channels' => BaselineRenameChannelsCommand::class,
            'debug:layer-assignment' => LayerAssignmentCommand::class,
            'directives' => DirectivesCommand::class,
            'graph:export' => GraphExportCommand::class,
            'rules' => RulesCommand::class,
        ]));

        return new ApplicationTester($application);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                self::remove($path . '/' . $entry);
            }
            rmdir($path);

            return;
        }

        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
