<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Configuration\Pipeline\Stage\PresetStage;
use Qualimetrix\Configuration\Preset\PresetResolver;
use Qualimetrix\Core\Violation\Severity;
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
        self::assertSame(7, $resolved->ruleOptions['complexity.cyclomatic']['method']['warning']);
    }

    #[Test]
    public function legacyPresetRelaxesThresholds(): void
    {
        $resolved = $this->resolveWithPresets(['legacy']);

        self::assertArrayHasKey('complexity.cyclomatic', $resolved->ruleOptions);
        self::assertSame(20, $resolved->ruleOptions['complexity.cyclomatic']['method']['warning']);
    }

    #[Test]
    public function ciPresetSetsFailOnError(): void
    {
        $resolved = $this->resolveWithPresets(['ci']);

        self::assertSame(Severity::Error, $resolved->analysis->failOn);
    }

    #[Test]
    public function multiplePresetsAreMerged(): void
    {
        $resolved = $this->resolveWithPresets(['strict', 'ci']);

        // Strict thresholds are applied
        self::assertArrayHasKey('complexity.cyclomatic', $resolved->ruleOptions);
        self::assertSame(7, $resolved->ruleOptions['complexity.cyclomatic']['method']['warning']);

        // CI failOn is applied
        self::assertSame(Severity::Error, $resolved->analysis->failOn);
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

        self::assertContains('code-smell.boolean-argument', $resolved->analysis->disabledRules);
    }

    /**
     * @param list<string> $presetNames
     */
    private function resolveWithPresets(array $presetNames): \Qualimetrix\Configuration\Pipeline\ResolvedConfiguration
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
