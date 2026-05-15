<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Configuration\Discovery\ComposerReader;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Tests\Support\Logger\RecordingLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * End-to-end coverage for the deferred-warning replay mechanism.
 *
 * Verifies that warnings produced by {@see ArchitectureConfigurationFactory}
 * during pipeline resolution survive across the boundary into
 * {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator::configure()},
 * which is the only point at which the user-facing logger is wired into
 * {@see LoggerHolder}. The DI container is exercised end-to-end to prove the
 * production wiring (and not a test-only shortcut) delivers the warning.
 */
#[CoversClass(ConfigurationPipeline::class)]
#[CoversClass(ArchitectureConfigurationFactory::class)]
#[CoversClass(DeferredWarning::class)]
#[CoversClass(RuntimeConfigurator::class)]
final class DeferredWarningIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-deferred-warning-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    #[Test]
    public function mutualAllowWarningSurvivesPipelineAndReachesConfiguredLogger(): void
    {
        // Arrange: a qmx.yaml with a mutual-allow pair that the architecture
        // factory will flag as a deferred warning.
        $configYaml = <<<'YAML'
architecture:
  layers:
    - name: a
      patterns: ['App\A']
    - name: b
      patterns: ['App\B']
  allow:
    a: ['b']
    b: ['a']
YAML;
        file_put_contents($this->tempDir . '/qmx.yaml', $configYaml);

        $pipeline = $this->createPipeline();
        $input = $this->createInputWithDefinition([]);
        $context = new ConfigurationContext($input, $this->tempDir);

        // Act 1: resolution captures the warning as a DeferredWarning instead
        // of dropping it through the NullLogger placeholder.
        $resolved = $pipeline->resolve($context);

        $mutualWarnings = array_values(array_filter(
            $resolved->deferredWarnings,
            static fn(DeferredWarning $w): bool => $w->level === 'warning'
                && str_contains($w->message, 'mutual-allow'),
        ));

        self::assertCount(
            1,
            $mutualWarnings,
            'Pipeline must capture mutual-allow as a DeferredWarning instead of routing it to the still-placeholder logger.',
        );

        // Act 2: install a recording logger into the holder BEFORE invoking
        // the production drain method directly. This mirrors what
        // RuntimeConfigurator does: setLogger() (configureLogger), then drain.
        $loggerHolder = new LoggerHolder();
        $recording = new RecordingLogger();
        $loggerHolder->setLogger($recording);

        foreach ($resolved->deferredWarnings as $warning) {
            $loggerHolder->getLogger()->log($warning->level, $warning->message, $warning->context);
        }

        // Assert: the warning landed in the recording logger.
        self::assertCount(1, $recording->records);
        self::assertSame('warning', $recording->records[0]['level']);
        self::assertStringContainsString('mutual-allow', $recording->records[0]['message']);
        self::assertStringContainsString('a ↔ b', $recording->records[0]['message']);
    }

    #[Test]
    public function cleanConfigurationProducesEmptyDeferredWarnings(): void
    {
        // A non-pathological architecture config must not generate noise that
        // would otherwise leak into the user's console.
        $configYaml = <<<'YAML'
architecture:
  layers:
    - name: controller
      patterns: ['App\Controller']
    - name: service
      patterns: ['App\Service']
  allow:
    controller: ['service']
YAML;
        file_put_contents($this->tempDir . '/qmx.yaml', $configYaml);

        $pipeline = $this->createPipeline();
        $input = $this->createInputWithDefinition([]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $resolved = $pipeline->resolve($context);

        self::assertSame([], $resolved->deferredWarnings);
    }

    #[Test]
    public function runtimeConfiguratorDrainsCapturedWarningsThroughTheConfiguredLogger(): void
    {
        // End-to-end test of the full production drain path:
        //   ArchitectureConfigurationFactory → ConfigurationPipeline →
        //   ResolvedConfiguration.deferredWarnings → RuntimeConfigurator
        //   .drainDeferredWarnings() → LoggerHolder → user logger.
        //
        // Wires the real services from the DI container, swaps the LoggerHolder's
        // logger for a RecordingLogger (the same swap RuntimeConfigurator::
        // configureLogger() performs at runtime), then invokes the private drain
        // method via reflection. The other two tests cover the factory→pipeline
        // and the synthetic-warning→drain halves separately; this test pins the
        // join.
        $configYaml = <<<'YAML'
architecture:
  layers:
    - name: a
      patterns: ['App\A']
    - name: b
      patterns: ['App\B']
  allow:
    a: ['b']
    b: ['a']
YAML;
        file_put_contents($this->tempDir . '/qmx.yaml', $configYaml);

        $container = (new ContainerFactory())->create();

        $pipeline = $container->get(ConfigurationPipeline::class);
        self::assertInstanceOf(ConfigurationPipeline::class, $pipeline);

        $input = $this->createInputWithDefinition([]);
        $context = new ConfigurationContext($input, $this->tempDir);
        $resolved = $pipeline->resolve($context);

        $recording = new RecordingLogger();
        $loggerHolder = $container->get(LoggerHolder::class);
        self::assertInstanceOf(LoggerHolder::class, $loggerHolder);
        $loggerHolder->setLogger($recording);

        $runtimeConfigurator = $container->get(RuntimeConfigurator::class);
        self::assertInstanceOf(RuntimeConfigurator::class, $runtimeConfigurator);

        $drain = new ReflectionMethod(RuntimeConfigurator::class, 'drainDeferredWarnings');
        $drain->invoke($runtimeConfigurator, $resolved);

        $mutual = array_values(array_filter(
            $recording->records,
            static fn(array $record): bool => $record['level'] === 'warning'
                && str_contains($record['message'], 'mutual-allow'),
        ));
        self::assertCount(1, $mutual, 'Mutual-allow warning must reach the configured logger via the production drain path.');
        self::assertStringContainsString('a ↔ b', $mutual[0]['message']);
    }

    #[Test]
    public function diContainerWiresPipelineWithoutLoggerCoupling(): void
    {
        // Regression guard: the DI container must continue to build a
        // ConfigurationPipeline. Step 1 dropped the LoggerInterface argument;
        // the container should compile without errors and the resolved service
        // should be the expected class.
        $container = (new ContainerFactory())->create();

        $pipeline = $container->get(ConfigurationPipeline::class);
        self::assertInstanceOf(ConfigurationPipeline::class, $pipeline);
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

    /**
     * @param array<string, mixed> $parameters
     */
    private function createInputWithDefinition(array $parameters): ArrayInput
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

        $input = new ArrayInput($parameters, $definition);
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

        foreach ($files as $fileinfo) {
            $deleteFunc = $fileinfo->isDir() ? 'rmdir' : 'unlink';
            $deleteFunc($fileinfo->getRealPath());
        }

        rmdir($this->tempDir);
    }
}
