<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

#[\PHPUnit\Framework\Attributes\CoversClass(\Qualimetrix\Infrastructure\Console\AnalysisPreflight::class)]
final class RuleOptionsPreflightWiringTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function unassignedWithoutLayers(): iterable
    {
        yield 'error missing' => ["rules:\n  architecture.unassigned-class:\n    mode: error\n", []];
        yield 'warn missing' => ["rules:\n  architecture.unassigned-class:\n    mode: warn\n", []];
        yield 'error empty' => ["architecture:\n  layers: []\nrules:\n  architecture.unassigned-class:\n    mode: error\n", []];
        yield 'warn empty' => ["architecture:\n  layers: []\nrules:\n  architecture.unassigned-class:\n    mode: warn\n", []];
        yield 'foreign only does not disable' => ["rules:\n  architecture.unassigned-class:\n    mode: error\n", ['--only-rule' => ['complexity.ccn']]];
        yield 'alias mode' => ["{}\n", ['--unassigned-class-mode' => 'warn']];
        yield 'rule option mode' => ["{}\n", ['--rule-opt' => ['architecture.unassigned-class:mode=error']]];
    }

    /** @param array<string, mixed> $options */
    #[Test]
    #[DataProvider('unassignedWithoutLayers')]
    public function itRequiresLayersForAnEnabledUnassignedModeBeforeDiscovery(string $yaml, array $options): void
    {
        $directory = sys_get_temp_dir() . '/qmx-unassigned-preflight-' . bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory . '/qmx.yaml', $yaml);
        $before = getcwd();
        self::assertNotFalse($before);
        try {
            chdir($directory);
            $discovery = self::createMock(ProjectFilesInterface::class);
            $discovery->expects(self::never())->method('discover');
            $container = (new ContainerFactory())->configure();
            $container->removeAlias(ProjectFilesInterface::class);
            $container->register(ProjectFilesInterface::class)->setSynthetic(true)->setPublic(true);
            $container->compile();
            $container->set(ProjectFilesInterface::class, $discovery);
            $command = $container->get(CheckCommand::class);
            self::assertInstanceOf(CheckCommand::class, $command);
            $tester = new CommandTester($command);
            self::assertSame(3, $tester->execute([
                'paths' => [$directory], '--config' => $directory . '/qmx.yaml',
                '--no-cache' => true, '--workers' => 0, '--no-progress' => true, ...$options,
            ], ['capture_stderr_separately' => true]), $tester->getDisplay() . $tester->getErrorOutput());
            self::assertStringContainsString('architecture.layers', $tester->getErrorOutput());
            self::assertStringContainsString('architecture.unassigned-class', $tester->getErrorOutput());
            if (isset($options['--unassigned-class-mode'])) {
                self::assertStringContainsString('--unassigned-class-mode', $tester->getErrorOutput());
            }
            if (isset($options['--rule-opt'])) {
                self::assertStringContainsString('--rule-opt', $tester->getErrorOutput());
            }
        } finally {
            chdir($before);
            unlink($directory . '/qmx.yaml');
            rmdir($directory);
        }
    }

    #[Test]
    public function itNamesThePresetThatEnabledTheUnassignedModeBeforeDiscovery(): void
    {
        $directory = sys_get_temp_dir() . '/qmx-unassigned-preset-' . bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory . '/qmx.yaml', "{}\n");
        file_put_contents($directory . '/team.yaml', "rules:\n  architecture.unassigned-class:\n    mode: error\n");
        $before = getcwd();
        self::assertNotFalse($before);
        try {
            chdir($directory);
            $discovery = self::createMock(ProjectFilesInterface::class);
            $discovery->expects(self::never())->method('discover');
            $container = (new ContainerFactory())->configure();
            $container->removeAlias(ProjectFilesInterface::class);
            $container->register(ProjectFilesInterface::class)->setSynthetic(true)->setPublic(true);
            $container->compile();
            $container->set(ProjectFilesInterface::class, $discovery);
            $command = $container->get(CheckCommand::class);
            self::assertInstanceOf(CheckCommand::class, $command);
            $tester = new CommandTester($command);
            self::assertSame(3, $tester->execute([
                'paths' => [$directory], '--config' => 'qmx.yaml', '--preset' => ['./team.yaml'],
                '--no-cache' => true, '--workers' => 0, '--no-progress' => true,
            ], ['capture_stderr_separately' => true]));
            self::assertStringContainsString('preset', $tester->getErrorOutput());
            self::assertStringContainsString('team.yaml', $tester->getErrorOutput());
            self::assertStringContainsString('architecture.layers', $tester->getErrorOutput());
        } finally {
            chdir($before);
            unlink($directory . '/qmx.yaml');
            unlink($directory . '/team.yaml');
            rmdir($directory);
        }
    }

    /** @return iterable<string, array{list<string>, string, string}> */
    public static function duplicateCliWrites(): iterable
    {
        yield 'same alias and value' => [['--cyclomatic-warning=10', '--cyclomatic-warning=10'], '--cyclomatic-warning', '--cyclomatic-warning'];
        yield 'same alias different value' => [['--cyclomatic-warning=10', '--cyclomatic-warning=20'], '--cyclomatic-warning', '--cyclomatic-warning'];
        yield 'alias and rule option' => [['--cyclomatic-warning=10', '--rule-opt=complexity.ccn:callable.warning=20'], '--cyclomatic-warning', '--rule-opt'];
        yield 'two rule options' => [['--rule-opt=complexity.ccn:callable.warning=10', '--rule-opt=complexity.ccn:callable.warning=20'], '--rule-opt', '--rule-opt'];
    }

    /** @param list<string> $options */
    #[Test]
    #[DataProvider('duplicateCliWrites')]
    public function itRefusesEveryDuplicateCliWriteBeforeAnalysis(array $options, string $first, string $second): void
    {
        $root = \dirname(__DIR__, 4);
        require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';
        $result = \Qualimetrix\Subprocess\ChildProcess::run([
            \PHP_BINARY, '-d', 'xdebug.mode=off', $root . '/bin/qmx', 'check', __DIR__,
            '--no-cache', '--workers=1', ...$options,
        ], workingDirectory: $root);
        $stdout = $result['stdout'];
        $stderr = $result['stderr'];

        self::assertSame(3, $result['exitCode'], (string) $stdout . (string) $stderr);
        self::assertStringContainsString($first, (string) $stderr);
        self::assertStringContainsString($second, (string) $stderr);
        self::assertStringContainsString('overlapping rule option paths', (string) $stderr);
    }

    #[Test]
    public function itRefusesAnInvalidDisabledProducerBeforeProjectFilesDiscovery(): void
    {
        $discovery = self::createMock(ProjectFilesInterface::class);
        $discovery->expects(self::never())->method('discover');
        $container = (new ContainerFactory())->configure();
        $container->removeAlias(ProjectFilesInterface::class);
        $container->register(ProjectFilesInterface::class)->setSynthetic(true)->setPublic(true);
        $container->getDefinition(AnalysisPreflight::class)->setPublic(true);
        $container->compile();
        $container->set(ProjectFilesInterface::class, $discovery);
        $command = $container->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $tester = new CommandTester($command);
        self::assertSame(3, $tester->execute([
            'paths' => [__DIR__], '--no-cache' => true, '--workers' => '1',
            '--rule-opt' => ['code-smell.goto:enabled=false', 'code-smell.goto:misspelled=1'],
        ], ['capture_stderr_separately' => true]));
        self::assertStringContainsString(
            'Configuration error: Option "misspelled" is not an option of rule "code-smell.goto".'
            . ' Options here: enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths.',
            $tester->getErrorOutput(),
        );
    }

    #[Test]
    public function itReportsTheDisabledProducerRefusalAsExitThreeThroughTheRealCli(): void
    {
        $root = \dirname(__DIR__, 4);
        require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';
        $result = \Qualimetrix\Subprocess\ChildProcess::run([
            \PHP_BINARY, '-d', 'xdebug.mode=off', $root . '/bin/qmx', 'check', __DIR__,
            '--no-cache', '--workers=1', '--rule-opt=code-smell.goto:enabled=false',
            '--rule-opt=code-smell.goto:misspelled=1',
        ], workingDirectory: $root);
        $stdout = $result['stdout'];
        $stderr = $result['stderr'];
        self::assertSame(3, $result['exitCode'], (string) $stdout . (string) $stderr);
        self::assertStringContainsString('Option "misspelled" is not an option of rule "code-smell.goto".', (string) $stderr);
    }
}
