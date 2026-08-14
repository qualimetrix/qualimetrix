<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationContext;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\PresetStage;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(PresetStage::class)]
final class PresetIntegrationTest extends TestCase
{
    #[Test]
    public function strictPresetTightensThresholds(): void
    {
        $resolved = $this->resolveWithPresets(['strict']);

        self::assertArrayHasKey('complexity.cyclomatic', $resolved->ruleOptions);
        self::assertSame(7, $resolved->ruleOptions['complexity.cyclomatic']['callable']['warning']);
    }

    #[Test]
    public function legacyPresetRelaxesThresholds(): void
    {
        $resolved = $this->resolveWithPresets(['legacy']);

        self::assertArrayHasKey('complexity.cyclomatic', $resolved->ruleOptions);
        self::assertSame(20, $resolved->ruleOptions['complexity.cyclomatic']['callable']['warning']);
    }

    #[Test]
    public function ciPresetSetsFailOnError(): void
    {
        $resolved = $this->resolveWithPresets(['ci']);

        self::assertSame(Severity::Error, $resolved->runtime->failOn);
    }

    #[Test]
    public function multiplePresetsAreMerged(): void
    {
        $resolved = $this->resolveWithPresets(['strict', 'ci']);

        // Strict thresholds are applied
        self::assertArrayHasKey('complexity.cyclomatic', $resolved->ruleOptions);
        self::assertSame(7, $resolved->ruleOptions['complexity.cyclomatic']['callable']['warning']);

        // CI failOn is applied
        self::assertSame(Severity::Error, $resolved->runtime->failOn);
    }

    #[Test]
    public function presetSourceIsTracked(): void
    {
        $resolved = $this->resolveWithPresets(['strict']);

        self::assertContains('preset:strict', $resolved->appliedSources);
    }

    #[Test]
    public function legacyPresetDisablesRules(): void
    {
        $resolved = $this->resolveWithPresets(['legacy']);

        self::assertContains('code-smell.boolean-argument', $resolved->ruleSelection->disabled);
    }

    /**
     * @param list<string> $presetNames
     */
    private function resolveWithPresets(array $presetNames): \Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration
    {
        $loader = new YamlConfigLoader();
        $resolver = new PresetResolver();
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage(new DefaultsStage());
        $pipeline->addStage(new PresetStage($loader, $resolver));

        $definition = new InputDefinition([
            new InputOption('preset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
        ]);
        $input = new ArrayInput(['--preset' => $presetNames], $definition);
        $context = new ConfigurationContext($input, sys_get_temp_dir());

        return $pipeline->resolve($context);
    }
}
