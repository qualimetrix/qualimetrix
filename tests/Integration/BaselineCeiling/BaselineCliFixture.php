<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\BaselineCeiling;

use FilesystemIterator;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Support\Console\TempDirectory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A real CLI project copied from a tracked fixture.
 *
 * Each command receives a new compiled container. This preserves the process
 * boundary the executable has and keeps static configuration state from
 * turning an ordering error into a passing test.
 */
final class BaselineCliFixture
{
    public readonly string $root;
    public readonly string $baselinePath;

    private function __construct(string $root)
    {
        $this->root = $root;
        $this->baselinePath = $root . '/baseline.json';
    }

    public static function from(string $name): self
    {
        $root = TempDirectory::create('qmx-baseline-ceiling-');
        $fixture = \dirname(__DIR__, 2) . '/Fixtures/BaselineV10/' . $name;
        self::copyDirectory($fixture, $root);

        return new self($root);
    }

    public function remove(): void
    {
        TempDirectory::remove($this->root);
    }

    /**
     * @param list<string> $paths
     */
    public function generate(array $paths): CommandTester
    {
        return $this->generateAt($this->baselinePath, $paths);
    }

    /**
     * @param list<string> $paths
     */
    public function generateAt(string $baselinePath, array $paths): CommandTester
    {
        return $this->execute(BaselineGenerateCommand::class, [
            'baseline' => $baselinePath,
            'paths' => $paths,
            '--config' => $this->root . '/qmx.yaml',
        ]);
    }

    /**
     * @param list<string> $paths
     * @param array<string, mixed> $options
     */
    public function check(array $paths, array $options = []): CommandTester
    {
        return $this->execute(CheckCommand::class, [
            'paths' => $paths,
            '--config' => $this->root . '/qmx.yaml',
            '--no-progress' => true,
            ...$options,
        ]);
    }

    /**
     * @param list<string> $paths
     * @param array<string, mixed> $options
     */
    public function cleanup(array $paths, array $options = []): CommandTester
    {
        return $this->execute(BaselineCleanupCommand::class, [
            'baseline' => $this->baselinePath,
            'paths' => $paths,
            '--config' => $this->root . '/qmx.yaml',
            ...$options,
        ]);
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, mixed> $input
     */
    private function execute(string $commandClass, array $input): CommandTester
    {
        /** @var Command $command */
        $command = (new ContainerFactory())->create()->get($commandClass);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private static function copyDirectory(string $from, string $to): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $target = $to . '/' . $iterator->getSubPathname();

            if ($item->isDir()) {
                mkdir($target, 0777, true);

                continue;
            }

            copy($item->getPathname(), $target);
        }
    }
}
