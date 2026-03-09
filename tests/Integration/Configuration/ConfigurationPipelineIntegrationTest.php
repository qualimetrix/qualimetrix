<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Integration\Configuration;

use AiMessDetector\Configuration\Discovery\ComposerReader;
use AiMessDetector\Configuration\Loader\YamlConfigLoader;
use AiMessDetector\Configuration\Pipeline\ConfigurationContext;
use AiMessDetector\Configuration\Pipeline\ConfigurationPipeline;
use AiMessDetector\Configuration\Pipeline\Stage\CliStage;
use AiMessDetector\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use AiMessDetector\Configuration\Pipeline\Stage\ConfigFileStage;
use AiMessDetector\Configuration\Pipeline\Stage\DefaultsStage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Integration tests for Configuration Pipeline.
 *
 * Verifies:
 * - Zero-config experience (works without arguments, finds paths from composer.json)
 * - Priority ordering (stages applied in correct order: 0, 10, 20, 30)
 * - Layer merging (CLI overrides config file, config file overrides composer.json)
 * - Excludes accumulation (excludes from all layers are accumulated)
 */
#[CoversClass(ConfigurationPipeline::class)]
#[CoversClass(ConfigurationContext::class)]
#[CoversClass(DefaultsStage::class)]
#[CoversClass(ComposerDiscoveryStage::class)]
#[CoversClass(ConfigFileStage::class)]
#[CoversClass(CliStage::class)]
final class ConfigurationPipelineIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/aimd-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    #[Test]
    public function zeroConfigExperience_noArgumentsNoConfigFile_usesDefaultPaths(): void
    {
        // Arrange: Empty directory without composer.json and config files
        $input = $this->createInputWithDefinition([]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: Defaults should be used
        self::assertSame(['.'], $resolved->paths->paths);
        self::assertSame(['vendor', 'node_modules', '.git'], $resolved->paths->excludes);
    }

    #[Test]
    public function zeroConfigExperience_composerJsonExists_autoDiscoversPaths(): void
    {
        // Arrange: Create composer.json with PSR-4
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'Tests\\' => 'tests/',
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composerJson));

        $input = $this->createInputWithDefinition([]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: Paths should be discovered from composer.json
        self::assertSame(['src', 'tests'], $resolved->paths->paths);
        self::assertSame(['vendor', 'node_modules', '.git'], $resolved->paths->excludes);
    }

    #[Test]
    public function priorityOrdering_stagesAppliedInCorrectOrder(): void
    {
        // Arrange: Create all configuration sources
        // 1. composer.json (priority: 10)
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composerJson));

        // 2. aimd.yaml (priority: 20)
        $configYaml = <<<YAML
paths:
  - lib/
  - app/
exclude:
  - cache/
YAML;
        file_put_contents($this->tempDir . '/aimd.yaml', $configYaml);

        // 3. CLI arguments (priority: 30) — using InputDefinition
        $input = $this->createInputWithDefinition([
            'paths' => ['custom/'],
            '--exclude' => ['temp/'],
        ]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: CLI should override everything for non-mergeable keys
        self::assertSame(['custom/'], $resolved->paths->paths, 'CLI paths should override config file and composer.json');
        // Excludes merge across all stages: defaults + config file + CLI
        $excludes = $resolved->paths->excludes;
        self::assertContains('vendor', $excludes, 'excludes from defaults');
        self::assertContains('cache/', $excludes, 'excludes from config file');
        self::assertContains('temp/', $excludes, 'excludes from CLI');
    }

    #[Test]
    public function layerMerging_configFileOverridesComposer(): void
    {
        // Arrange: composer.json + config file (without CLI)
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composerJson));

        $configYaml = <<<YAML
paths:
  - lib/
  - app/
YAML;
        file_put_contents($this->tempDir . '/aimd.yaml', $configYaml);

        $input = $this->createInputWithDefinition([]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: Config file should override composer.json
        self::assertSame(['lib/', 'app/'], $resolved->paths->paths, 'Config file should override composer.json paths');
    }

    #[Test]
    public function layerMerging_cliOverridesConfigFile(): void
    {
        // Arrange: config file + CLI
        $configYaml = <<<YAML
paths:
  - lib/
format: json
YAML;
        file_put_contents($this->tempDir . '/aimd.yaml', $configYaml);

        $input = $this->createInputWithDefinition([
            'paths' => ['override/'],
            '--format' => 'checkstyle',
        ]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: CLI should override config file
        self::assertSame(['override/'], $resolved->paths->paths);
        self::assertSame('checkstyle', $resolved->analysis->format);
    }

    #[Test]
    public function excludesAccumulation_cliExcludesMergeWithDefaults(): void
    {
        // Arrange: Only CLI excludes (without config file)
        $input = $this->createInputWithDefinition([
            '--exclude' => ['custom-exclude/', 'another-exclude/'],
        ]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: CLI excludes should be merged with default excludes
        $excludes = $resolved->paths->excludes;
        self::assertContains('vendor', $excludes);
        self::assertContains('node_modules', $excludes);
        self::assertContains('.git', $excludes);
        self::assertContains('custom-exclude/', $excludes);
        self::assertContains('another-exclude/', $excludes);
    }

    #[Test]
    public function excludesAccumulation_configFileExcludesMergeWithDefaults(): void
    {
        // Arrange: Config file with excludes
        $configYaml = <<<YAML
exclude:
  - build/
  - dist/
YAML;
        file_put_contents($this->tempDir . '/aimd.yaml', $configYaml);

        $input = $this->createInputWithDefinition([]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: Config file excludes merge with defaults
        $excludes = $resolved->paths->excludes;
        self::assertContains('vendor', $excludes);
        self::assertContains('node_modules', $excludes);
        self::assertContains('.git', $excludes);
        self::assertContains('build/', $excludes);
        self::assertContains('dist/', $excludes);
    }

    #[Test]
    public function configFile_aimdYamlSupported(): void
    {
        // Arrange: Create aimd.yaml config file
        $configYaml = <<<YAML
paths:
  - src-custom/
YAML;
        file_put_contents($this->tempDir . '/aimd.yaml', $configYaml);

        $input = $this->createInputWithDefinition([]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert
        self::assertSame(['src-custom/'], $resolved->paths->paths);
    }

    #[Test]
    public function complexScenario_allLayersCombined(): void
    {
        // Arrange: All layers are active simultaneously
        // 1. Defaults (priority: 0) - format: text
        // 2. Composer (priority: 10) - paths: ['src']
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composerJson));

        // 3. Config file (priority: 20) — paths: ['lib'], excludes: ['cache'], format: json
        $configYaml = <<<YAML
paths:
  - lib/
exclude:
  - cache/
format: json
YAML;
        file_put_contents($this->tempDir . '/aimd.yaml', $configYaml);

        // 4. CLI (priority: 30) — excludes: ['temp']
        $input = $this->createInputWithDefinition([
            '--exclude' => ['temp/'],
        ]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: Each option is taken from the highest priority source where it is defined
        self::assertSame(['lib/'], $resolved->paths->paths, 'paths from config file (overrides composer)');
        // Excludes merge across all stages: defaults + config file + CLI
        $excludes = $resolved->paths->excludes;
        self::assertContains('vendor', $excludes, 'excludes from defaults');
        self::assertContains('cache/', $excludes, 'excludes from config file');
        self::assertContains('temp/', $excludes, 'excludes from CLI');
        self::assertSame('json', $resolved->analysis->format, 'format from config file (overrides defaults)');
        self::assertSame('.aimd-cache', $resolved->analysis->cacheDir, 'cache.dir defaults to .aimd-cache');
        self::assertTrue($resolved->analysis->cacheEnabled, 'cache.enabled defaults to true');
    }

    #[Test]
    public function onlyRulesFromCli_replacesConfigFileOnlyRules(): void
    {
        // Arrange: config file sets only_rules, CLI overrides with different set
        $configYaml = <<<YAML
onlyRules:
  - complexity.cyclomatic
  - size.loc
YAML;
        file_put_contents($this->tempDir . '/aimd.yaml', $configYaml);

        $input = $this->createInputWithDefinition([
            '--only-rule' => ['coupling.cbo'],
        ]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: CLI only_rules should REPLACE config file only_rules (not merge)
        self::assertSame(
            ['coupling.cbo'],
            $resolved->analysis->onlyRules,
            'CLI only_rules should replace config file only_rules, not merge with them',
        );
    }

    #[Test]
    public function stagesReturnsOrderedList(): void
    {
        // Arrange
        $pipeline = $this->createPipeline();

        // Act
        $stages = $pipeline->stages();

        // Assert: Stages should be sorted by priority
        self::assertCount(4, $stages);
        self::assertSame(0, $stages[0]->priority(), 'First stage should be DefaultsStage (priority: 0)');
        self::assertSame(10, $stages[1]->priority(), 'Second stage should be ComposerDiscoveryStage (priority: 10)');
        self::assertSame(20, $stages[2]->priority(), 'Third stage should be ConfigFileStage (priority: 20)');
        self::assertSame(30, $stages[3]->priority(), 'Fourth stage should be CliStage (priority: 30)');
    }

    #[Test]
    public function ruleOptions_mergedFromConfigFile(): void
    {
        // Arrange: Config file with rule options
        $configYaml = <<<YAML
rules:
  complexity:
    method:
      warning: 15
      error: 25
  cognitive:
    method:
      warning: 20
YAML;
        file_put_contents($this->tempDir . '/aimd.yaml', $configYaml);

        $input = $this->createInputWithDefinition([]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: Rule options should be available
        self::assertArrayHasKey('complexity', $resolved->ruleOptions);
        self::assertArrayHasKey('cognitive', $resolved->ruleOptions);
        self::assertSame(15, $resolved->ruleOptions['complexity']['method']['warning']);
        self::assertSame(25, $resolved->ruleOptions['complexity']['method']['error']);
        self::assertSame(20, $resolved->ruleOptions['cognitive']['method']['warning']);
    }

    #[Test]
    public function configFileFormat_notOverriddenByCliDefault(): void
    {
        // Arrange: Config file sets format to json, CLI does not pass --format
        $configYaml = <<<YAML
format: json
YAML;
        file_put_contents($this->tempDir . '/aimd.yaml', $configYaml);

        $input = $this->createInputWithDefinition([]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: Config file format should be used (not overridden by CLI default)
        self::assertSame('json', $resolved->analysis->format);
    }

    #[Test]
    public function configFileFormat_overriddenByExplicitCliOption(): void
    {
        // Arrange: Config file sets format to json, CLI explicitly passes --format=text
        $configYaml = <<<YAML
format: json
YAML;
        file_put_contents($this->tempDir . '/aimd.yaml', $configYaml);

        $input = $this->createInputWithDefinition(['--format' => 'text']);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: CLI explicit format should override config file
        self::assertSame('text', $resolved->analysis->format);
    }

    #[Test]
    public function configFileCacheDir_notOverriddenByCliDefault(): void
    {
        // Arrange: Config file sets cache dir, CLI does not pass --cache-dir
        $configYaml = <<<YAML
cache:
  dir: custom-cache
YAML;
        file_put_contents($this->tempDir . '/aimd.yaml', $configYaml);

        $input = $this->createInputWithDefinition([]);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: Config file cache dir should be used (not overridden by CLI default)
        self::assertSame('custom-cache', $resolved->analysis->cacheDir);
    }

    #[Test]
    public function configFileCacheDir_overriddenByExplicitCliOption(): void
    {
        // Arrange: Config file sets cache dir, CLI explicitly passes --cache-dir
        $configYaml = <<<YAML
cache:
  dir: custom-cache
YAML;
        file_put_contents($this->tempDir . '/aimd.yaml', $configYaml);

        $input = $this->createInputWithDefinition(['--cache-dir' => '/explicit/cache']);
        $context = new ConfigurationContext($input, $this->tempDir);

        $pipeline = $this->createPipeline();

        // Act
        $resolved = $pipeline->resolve($context);

        // Assert: CLI explicit cache-dir should override config file
        self::assertSame('/explicit/cache', $resolved->analysis->cacheDir);
    }

    private function createPipeline(): ConfigurationPipeline
    {
        $pipeline = new ConfigurationPipeline();

        // Register all stages in correct priority order
        $pipeline->addStage(new DefaultsStage());
        $pipeline->addStage(new ComposerDiscoveryStage(new ComposerReader()));
        $pipeline->addStage(new ConfigFileStage(new YamlConfigLoader()));
        $pipeline->addStage(new CliStage());

        return $pipeline;
    }

    /**
     * Creates ArrayInput with proper InputDefinition (matching CheckCommand).
     *
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
