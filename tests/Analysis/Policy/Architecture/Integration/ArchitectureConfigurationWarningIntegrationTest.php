<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationContext;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/** Exercises Architecture-owned warning semantics over normalized YAML contributions. */
#[CoversClass(ArchitecturePolicy::class)]
final class ArchitectureConfigurationWarningIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-architecture-warning-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    #[Test]
    public function wildcardSelfAllowWarningIsOwnedByArchitectureConfiguration(): void
    {
        file_put_contents($this->tempDir . '/qmx.yaml', <<<'YAML'
architecture:
  layers:
    - name: domain-orders
      patterns: ['App\Domain\Orders\**']
  allow:
    domain-*: ['domain-*']
YAML);

        $resolved = $this->createPipeline()->resolve(
            new ConfigurationContext($this->createInput(), $this->tempDir),
        );
        self::assertCount(1, $resolved->document->contributions('architecture'));

        $warnings = (new ArchitecturePolicy())->configure($resolved->document);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('wildcard-self-allow', $warnings[0]->message);
        self::assertStringContainsString("'domain-*'", $warnings[0]->message);
    }

    #[Test]
    public function cleanConfigurationProducesNoArchitectureWarnings(): void
    {
        file_put_contents($this->tempDir . '/qmx.yaml', <<<'YAML'
architecture:
  layers:
    - name: controller
      patterns: ['App\Controller']
    - name: service
      patterns: ['App\Service']
  allow:
    controller: ['service']
YAML);

        $resolved = $this->createPipeline()->resolve(
            new ConfigurationContext($this->createInput(), $this->tempDir),
        );
        self::assertCount(1, $resolved->document->contributions('architecture'));

        self::assertSame([], (new ArchitecturePolicy())->configure($resolved->document));
    }

    private function createPipeline(): ConfigurationPipeline
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage(new DefaultsStage());
        $pipeline->addStage(new ComposerDiscoveryStage(new ComposerReader()));
        $pipeline->addStage(new ConfigFileStage(new YamlConfigLoader()));
        $pipeline->addStage(new CliStage());

        return $pipeline;
    }

    private function createInput(): ArrayInput
    {
        $definition = new InputDefinition([
            new InputArgument('paths', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Paths to analyze', []),
            new InputOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude directories'),
            new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format'),
            new InputOption('no-cache', null, InputOption::VALUE_NONE, 'Disable caching'),
            new InputOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'Cache directory'),
            new InputOption('disable-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Disable rules'),
            new InputOption('only-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only rules'),
        ]);
        $input = new ArrayInput([], $definition);
        $input->setInteractive(false);

        return $input;
    }

    private function removeTempDir(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $fileInfo) {
            $delete = $fileInfo->isDir() ? 'rmdir' : 'unlink';
            $delete($fileInfo->getRealPath());
        }
        rmdir($this->tempDir);
    }
}
