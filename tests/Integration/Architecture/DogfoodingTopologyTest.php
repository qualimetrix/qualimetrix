<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Architecture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition;
use Qualimetrix\Configuration\Discovery\ComposerReader;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Configuration\Pipeline\Stage\PresetStage;
use Qualimetrix\Configuration\Preset\PresetResolver;
use Qualimetrix\Core\Dependency\DependencyType;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Regression guard for the project's own {@code qmx.yaml} architecture
 * topology. The repository's dogfooding config replaces the former
 * {@code deptrac.yaml}; this test pins the shape so a future edit that
 * collapses Analysis/Infrastructure sub-layers back into a flat
 * {@code analysis} / {@code infrastructure} layer (or removes the
 * {@code relations:} filter on {@code infra-di → metrics-*}) fails
 * here instead of silently weakening enforcement.
 *
 * The test does NOT re-test {@see ArchitectureConfigurationFactory} or
 * {@see \Qualimetrix\Architecture\Domain\Layer\LayerPolicy} mechanics — only
 * the contract this project commits to in its own dogfooding config.
 */
#[CoversClass(ArchitectureConfigurationFactory::class)]
#[CoversClass(ConfigurationPipeline::class)]
final class DogfoodingTopologyTest extends TestCase
{
    /**
     * Every layer name we commit to in {@code qmx.yaml}. Adding a new
     * Analysis/Infrastructure sub-namespace? Add it here and in the YAML.
     *
     * @return list<string>
     */
    private static function expectedLayerNames(): array
    {
        return [
            'core',
            'configuration',
            'architecture',
            'metrics-{Category}',
            'rules',
            'reporting',
            'baseline',
            'analysis-exception',
            'analysis-discovery',
            'analysis-namespace',
            'analysis-repository',
            'analysis-duplication',
            'analysis-aggregator',
            'analysis-ruleexecution',
            'analysis-collection',
            'analysis-lifecycle',
            'analysis-pipeline',
            'infra-serializer',
            'infra-logging',
            'infra-profiler',
            'infra-rule',
            'infra-git',
            'infra-cache',
            'infra-ast',
            'infra-parallel',
            'infra-console',
            'infra-di',
        ];
    }

    #[Test]
    public function dogfoodingConfigDeclaresAllSubLayers(): void
    {
        $arch = $this->loadProjectArchitecture();

        $declared = array_map(self::entryName(...), $arch->entries());
        foreach (self::expectedLayerNames() as $expected) {
            self::assertContains(
                $expected,
                $declared,
                "qmx.yaml is missing the '{$expected}' layer — sub-layer split removed?",
            );
        }
    }

    #[Test]
    public function dogfoodingConfigDoesNotReintroduceFlatParentLayers(): void
    {
        $arch = $this->loadProjectArchitecture();

        $declared = array_map(self::entryName(...), $arch->entries());

        // A flat 'analysis' / 'infrastructure' / 'metrics' layer would silently
        // mask every cross-sublayer edge that the current split catches.
        self::assertNotContains('analysis', $declared);
        self::assertNotContains('infrastructure', $declared);
        self::assertNotContains('metrics', $declared);
    }

    #[Test]
    public function analysisDiscoveryMustNotReachAnalysisPipeline(): void
    {
        $policy = $this->loadProjectArchitecture()->policy();

        self::assertFalse(
            $policy->isAllowed('analysis-discovery', 'analysis-pipeline'),
            'qmx.yaml must keep analysis-discovery isolated from analysis-pipeline — '
            . 'the sub-layer split is the whole reason deptrac was retired.',
        );
        self::assertTrue(
            $policy->isAllowed('analysis-pipeline', 'analysis-discovery'),
            'Pipeline orchestrates Discovery (the documented direction), so the '
            . 'reverse edge must remain allowed — guards against an accidental '
            . 'symmetric allow-list collapse.',
        );
    }

    #[Test]
    public function infraDiMayReferenceMetricCollectorsButMustNotExtendThem(): void
    {
        $policy = $this->loadProjectArchitecture()->policy();

        self::assertTrue(
            $policy->isAllowed('infra-di', 'metrics-Complexity', DependencyType::TypeHint),
            'DI configurator wires collectors via type references — type_reference '
            . 'must remain in the relations: filter for infra-di → metrics-*.',
        );
        self::assertFalse(
            $policy->isAllowed('infra-di', 'metrics-Complexity', DependencyType::Extends),
            'DI configurator must NEVER extend a collector — inheritance must stay '
            . 'out of the relations: filter for infra-di → metrics-*.',
        );
    }

    private static function entryName(LayerDefinition|TemplateLayerDefinition $entry): string
    {
        return $entry instanceof TemplateLayerDefinition ? $entry->nameTemplate : $entry->name;
    }

    private function loadProjectArchitecture(): ArchitectureConfiguration
    {
        $repoRoot = realpath(__DIR__ . '/../../..');
        self::assertIsString($repoRoot, 'Could not resolve repository root.');
        self::assertFileExists($repoRoot . '/qmx.yaml', 'Project qmx.yaml is missing.');

        $loader = new YamlConfigLoader();
        $resolver = new PresetResolver();
        $composerReader = new ComposerReader();

        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage(new DefaultsStage());
        $pipeline->addStage(new ComposerDiscoveryStage($composerReader));
        $pipeline->addStage(new PresetStage($loader, $resolver));
        $pipeline->addStage(new ConfigFileStage($loader));
        $pipeline->addStage(new CliStage());

        $definition = new InputDefinition([
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
        $input = new ArrayInput([], $definition);

        return $pipeline->resolve(new ConfigurationContext($input, $repoRoot))->architecture;
    }
}
