<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Architecture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Configuration\Discovery\ComposerReader;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Configuration\Pipeline\ResolvedConfiguration;
use Qualimetrix\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Configuration\Pipeline\Stage\PresetStage;
use Qualimetrix\Configuration\Preset\PresetResolver;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Consumer-expectation test for the ADR 0009 §5 two-layer test discipline.
 *
 * The characterization test
 * ({@see \Qualimetrix\Tests\Integration\Configuration\Loader\YamlNormalizationCharacterizationTest})
 * proves that the loader emits {@code architecture.max_expanded_layers}
 * verbatim. This test proves the **independent** assertion that the value
 * reaches {@see ArchitectureConfiguration::$maxExpandedLayers} via the
 * complete {@see ConfigurationPipeline} →
 * {@see ArchitectureConfigurationFactory} wiring. A regression at any layer
 * (loader, pipeline merge, factory) trips a row here even if the
 * characterization snapshot is silently updated.
 *
 * Pinned bug class: C1 (ADR 0009) — pre-Phase 3.5 the scalar leaf under the
 * MIXED {@code architecture} root was silently camelCased to
 * {@code maxExpandedLayers}, the factory's snake_case lookup fell back to
 * {@see ArchitectureConfiguration::DEFAULT_MAX_EXPANDED_LAYERS}, and the
 * user's value was lost without warning.
 */
#[CoversClass(ArchitectureConfigurationFactory::class)]
#[CoversClass(ConfigurationPipeline::class)]
final class MaxExpandedLayersFromYamlTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_max_expanded_layers_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }
        $files = glob($this->tempDir . '/*');
        if ($files === false) {
            $files = [];
        }
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    #[Test]
    public function userProvidedMaxExpandedLayersReachesConfiguration(): void
    {
        $this->writeYaml(<<<'YAML'
            architecture:
              layers:
                - name: app
                  patterns:
                    - 'App\\App'
              max_expanded_layers: 17
            YAML);

        $architecture = $this->resolveArchitecture();

        self::assertSame(
            17,
            $architecture->maxExpandedLayers,
            'architecture.max_expanded_layers must round-trip through YAML → loader → pipeline → factory '
            . 'and reach ArchitectureConfiguration::$maxExpandedLayers verbatim (ADR 0009 C1 closure).',
        );
    }

    #[Test]
    public function omittedMaxExpandedLayersFallsBackToDefault(): void
    {
        $this->writeYaml(<<<'YAML'
            architecture:
              layers:
                - name: app
                  patterns:
                    - 'App\\App'
            YAML);

        $architecture = $this->resolveArchitecture();

        self::assertSame(
            ArchitectureConfiguration::DEFAULT_MAX_EXPANDED_LAYERS,
            $architecture->maxExpandedLayers,
            'When max_expanded_layers is absent from YAML, the factory must use '
            . 'ArchitectureConfiguration::DEFAULT_MAX_EXPANDED_LAYERS (negative-control case).',
        );
    }

    private function resolveArchitecture(): ArchitectureConfiguration
    {
        return $this->resolveFullPipeline()->architecture;
    }

    private function writeYaml(string $contents): void
    {
        file_put_contents($this->tempDir . '/qmx.yaml', $contents);
    }

    private function resolveFullPipeline(): ResolvedConfiguration
    {
        $loader = new YamlConfigLoader();
        $resolver = new PresetResolver();
        $composerReader = new ComposerReader();

        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage(new DefaultsStage());
        $pipeline->addStage(new ComposerDiscoveryStage($composerReader));
        $pipeline->addStage(new PresetStage($loader, $resolver));
        $pipeline->addStage(new ConfigFileStage($loader));
        $pipeline->addStage(new CliStage());

        $definition = $this->buildInputDefinition();
        $input = new ArrayInput([], $definition);
        $context = new ConfigurationContext($input, $this->tempDir);

        return $pipeline->resolve($context);
    }

    private function buildInputDefinition(): InputDefinition
    {
        return new InputDefinition([
            new InputArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, '', []),
            new InputOption('preset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('format', null, InputOption::VALUE_REQUIRED),
            new InputOption('cache-dir', null, InputOption::VALUE_REQUIRED),
            new InputOption('no-cache', null, InputOption::VALUE_NONE),
            new InputOption('disable-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('only-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('fail-on', null, InputOption::VALUE_REQUIRED),
            new InputOption('exclude-health', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('include-generated', null, InputOption::VALUE_NONE),
            new InputOption('workers', null, InputOption::VALUE_REQUIRED),
        ]);
    }
}
