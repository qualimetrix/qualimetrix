<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

final class RuleOptionsPreflightWiringTest extends TestCase
{
    #[Test]
    public function itRefusesAnInvalidDisabledProducerBeforeTheDiscoveryFactoryIsCalled(): void
    {
        $discovery = self::createMock(FileDiscoveryFactoryInterface::class);
        $discovery->expects(self::never())->method('create');
        $container = (new ContainerFactory())->configure();
        $container->removeAlias(FileDiscoveryFactoryInterface::class);
        $container->register(FileDiscoveryFactoryInterface::class)->setSynthetic(true)->setPublic(true);
        $container->getDefinition(AnalysisPreflight::class)->setPublic(true);
        $container->compile();
        $container->set(FileDiscoveryFactoryInterface::class, $discovery);
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
        $process = proc_open([
            \PHP_BINARY, '-d', 'xdebug.mode=off', $root . '/bin/qmx', 'check', __DIR__,
            '--no-cache', '--workers=1', '--rule-opt=code-smell.goto:enabled=false',
            '--rule-opt=code-smell.goto:misspelled=1',
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(3, proc_close($process), (string) $stdout . (string) $stderr);
        self::assertStringContainsString('Option "misspelled" is not an option of rule "code-smell.goto".', (string) $stderr);
    }
}
