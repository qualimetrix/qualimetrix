<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Configuration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
use Qualimetrix\Core\Violation\Severity;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Integration test exercising all 5 configuration stages together.
 *
 * Verifies merge semantics: Defaults -> Composer -> Preset -> ConfigFile -> CLI.
 */
#[CoversClass(ConfigurationPipeline::class)]
final class FullPipelineIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_full_pipeline_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function configFileOverridesPreset(): void
    {
        $this->writeYaml('qmx.yaml', [
            'rules' => [
                'complexity.cyclomatic' => [
                    'method' => [
                        'warning' => 12,
                    ],
                ],
            ],
        ]);

        $resolved = $this->resolveFullPipeline(['--preset' => ['strict']]);

        // Config file (priority 20) overrides preset (priority 15)
        self::assertSame(12, $resolved->ruleOptions['complexity.cyclomatic']['method']['warning']);
        // Preset values not overridden by config file are preserved (strict.yaml: method.error = 15)
        self::assertSame(15, $resolved->ruleOptions['complexity.cyclomatic']['method']['error']);
    }

    #[Test]
    public function cliOverridesConfigFile(): void
    {
        $this->writeYaml('qmx.yaml', [
            'failOn' => 'warning',
        ]);

        $resolved = $this->resolveFullPipeline(['--fail-on' => 'error']);

        // CLI (priority 30) overrides config file (priority 20)
        self::assertSame(Severity::Error, $resolved->analysis->failOn);
    }

    #[Test]
    public function conflictingPresetsLastWinsDisabledRulesAccumulate(): void
    {
        $resolved = $this->resolveFullPipeline(['--preset' => ['legacy,strict']]);

        // Strict is applied last, so its CCN thresholds win
        self::assertSame(7, $resolved->ruleOptions['complexity.cyclomatic']['method']['warning']);

        // Disabled rules from legacy are preserved (union semantics)
        self::assertContains('code-smell.boolean-argument', $resolved->analysis->disabledRules);
        self::assertContains('code-smell.data-class', $resolved->analysis->disabledRules);
        self::assertContains('code-smell.god-class', $resolved->analysis->disabledRules);
    }

    #[Test]
    public function presetDisabledRulesMergeWithConfigFileDisabledRules(): void
    {
        $this->writeYaml('qmx.yaml', [
            'disabledRules' => ['complexity.npath'],
        ]);

        $resolved = $this->resolveFullPipeline(['--preset' => ['legacy']]);

        // Legacy preset disables these rules
        self::assertContains('code-smell.boolean-argument', $resolved->analysis->disabledRules);
        self::assertContains('code-smell.data-class', $resolved->analysis->disabledRules);

        // Config file adds its own disabled rule (union semantics)
        self::assertContains('complexity.npath', $resolved->analysis->disabledRules);
    }

    #[Test]
    public function appliedSourcesTrackAllStages(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ]);

        $this->writeYaml('qmx.yaml', [
            'format' => 'json',
        ]);

        $resolved = $this->resolveFullPipeline([
            '--preset' => ['strict'],
            '--disable-rule' => ['size.loc'],
        ]);

        self::assertContains('defaults', $resolved->appliedSources);
        self::assertContains('composer.json', $resolved->appliedSources);
        self::assertContains('preset:strict', $resolved->appliedSources);
        self::assertContains('qmx.yaml', $resolved->appliedSources);
        self::assertContains('cli', $resolved->appliedSources);
    }

    /**
     * @param array<string, mixed> $inputParams CLI parameters (e.g., ['--preset' => ['strict']])
     */
    private function resolveFullPipeline(array $inputParams = []): ResolvedConfiguration
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
        $input = new ArrayInput($inputParams, $definition);
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

    /**
     * @param array<string, mixed> $data
     */
    private function writeYaml(string $filename, array $data): void
    {
        $yaml = \Symfony\Component\Yaml\Yaml::dump($data, 4);
        file_put_contents($this->tempDir . '/' . $filename, $yaml);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
