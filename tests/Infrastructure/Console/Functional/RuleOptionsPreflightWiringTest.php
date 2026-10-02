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
