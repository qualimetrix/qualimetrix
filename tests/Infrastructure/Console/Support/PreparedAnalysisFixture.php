<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Support;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\PreparedAnalysisInput;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/** A real console invocation prepared on the same container used by the analysis. */
final class PreparedAnalysisFixture
{
    private bool $closed = false;

    private function __construct(
        private readonly ContainerBuilder $container,
        private readonly PreparedAnalysisInput $prepared,
        private readonly string $temporaryDirectory,
    ) {}

    /**
     * @param list<AbsolutePath> $paths
     * @param array<string, mixed> $authoredConfig
     */
    public static function start(AbsolutePath $workingDirectory, array $paths, array $authoredConfig): self
    {
        $previousDirectory = getcwd();
        if ($previousDirectory === false) {
            throw new RuntimeException('Cannot determine the current working directory.');
        }

        $temporaryDirectory = sys_get_temp_dir() . '/qmx-preflight-' . bin2hex(random_bytes(8));
        if (!mkdir($temporaryDirectory)) {
            throw new RuntimeException('Cannot create a temporary configuration directory.');
        }

        try {
            $configurationFile = $temporaryDirectory . '/qmx.yaml';
            if (file_put_contents($configurationFile, Yaml::dump($authoredConfig, 10, 2)) === false) {
                throw new RuntimeException('Cannot write temporary authored configuration.');
            }

            if (!chdir($workingDirectory->value())) {
                throw new RuntimeException('Cannot enter the analysis working directory.');
            }

            $container = (new ContainerFactory())->configure();
            $container->getDefinition(AnalysisPreflight::class)->setPublic(true);
            $container->compile();

            $command = $container->get(CheckCommand::class);
            if (!$command instanceof CheckCommand) {
                throw new RuntimeException('The check command is unavailable.');
            }

            $preflight = $container->get(AnalysisPreflight::class);
            if (!$preflight instanceof AnalysisPreflight) {
                throw new RuntimeException('The analysis preflight is unavailable.');
            }

            $input = new ArrayInput([
                'paths' => array_map(static fn(AbsolutePath $path): string => $path->value(), $paths),
                '--config' => $configurationFile,
                '--no-cache' => true,
                '--workers' => '0',
            ], $command->getDefinition());

            return new self($container, $preflight->resolve($input, new BufferedOutput()), $temporaryDirectory);
        } catch (Throwable $failure) {
            self::removeDirectory($temporaryDirectory);
            throw $failure;
        } finally {
            chdir($previousDirectory);
        }
    }

    public function container(): ContainerBuilder
    {
        return $this->container;
    }

    public function prepared(): PreparedAnalysisInput
    {
        return $this->prepared;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        self::removeDirectory($this->temporaryDirectory);
        $this->closed = true;
    }

    public function __destruct()
    {
        $this->close();
    }

    private static function removeDirectory(string $directory): void
    {
        if (is_file($directory . '/qmx.yaml')) {
            unlink($directory . '/qmx.yaml');
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
