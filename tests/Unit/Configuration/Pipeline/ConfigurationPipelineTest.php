<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Configuration\Pipeline\Stage\ConfigurationStageInterface;
use Symfony\Component\Console\Input\ArrayInput;

#[CoversClass(ConfigurationPipeline::class)]
final class ConfigurationPipelineTest extends TestCase
{
    #[Test]
    public function resolveWithNoStagesReturnsDefaults(): void
    {
        $pipeline = new ConfigurationPipeline();
        $context = new ConfigurationContext(new ArrayInput([]), '/tmp');

        $resolved = $pipeline->resolve($context);

        self::assertSame(['.'], $resolved->paths->paths);
        self::assertSame(['vendor', 'node_modules', '.git'], $resolved->paths->excludes);
    }

    #[Test]
    public function stagesAreSortedByPriority(): void
    {
        $pipeline = new ConfigurationPipeline();

        $high = $this->createStage(30, 'cli', null);
        $low = $this->createStage(0, 'defaults', null);
        $mid = $this->createStage(10, 'composer', null);

        $pipeline->addStage($high);
        $pipeline->addStage($low);
        $pipeline->addStage($mid);

        $stages = $pipeline->stages();

        self::assertSame('defaults', $stages[0]->name());
        self::assertSame('composer', $stages[1]->name());
        self::assertSame('cli', $stages[2]->name());
    }

    #[Test]
    public function laterStageOverridesEarlier(): void
    {
        $pipeline = new ConfigurationPipeline();

        $defaults = $this->createStage(0, 'defaults', new ConfigurationLayer('defaults', [
            'paths' => ['.'],
            'format' => 'text',
        ]));

        $cli = $this->createStage(30, 'cli', new ConfigurationLayer('cli', [
            'paths' => ['src'],
        ]));

        $pipeline->addStage($defaults);
        $pipeline->addStage($cli);

        $context = new ConfigurationContext(new ArrayInput([]), '/tmp');
        $resolved = $pipeline->resolve($context);

        // CLI paths override defaults
        self::assertSame(['src'], $resolved->paths->paths);
        // Format from defaults is preserved
        self::assertSame('text', $resolved->analysis->format);
    }

    #[Test]
    public function nullLayerIsSkipped(): void
    {
        $pipeline = new ConfigurationPipeline();

        $defaults = $this->createStage(0, 'defaults', new ConfigurationLayer('defaults', [
            'paths' => ['default'],
        ]));

        $composer = $this->createStage(10, 'composer', null);

        $pipeline->addStage($defaults);
        $pipeline->addStage($composer);

        $context = new ConfigurationContext(new ArrayInput([]), '/tmp');
        $resolved = $pipeline->resolve($context);

        // Defaults preserved because composer returned null
        self::assertSame(['default'], $resolved->paths->paths);
    }

    #[Test]
    public function ruleOptionsAreMerged(): void
    {
        $pipeline = new ConfigurationPipeline();

        $configFile = $this->createStage(20, 'config', new ConfigurationLayer('qmx.yaml', [
            'rules' => [
                'complexity' => ['warning' => 10],
            ],
        ]));

        $pipeline->addStage($configFile);

        $context = new ConfigurationContext(new ArrayInput([]), '/tmp');
        $resolved = $pipeline->resolve($context);

        self::assertSame(['complexity' => ['warning' => 10]], $resolved->ruleOptions);
    }

    #[Test]
    public function disabledRulesAreMergedNotOverwritten(): void
    {
        $pipeline = new ConfigurationPipeline();

        $yamlStage = $this->createStage(20, 'config', new ConfigurationLayer('qmx.yaml', [
            'disabled_rules' => ['complexity.cyclomatic', 'size.loc'],
        ]));

        $cliStage = $this->createStage(30, 'cli', new ConfigurationLayer('cli', [
            'disabled_rules' => ['coupling.cbo'],
        ]));

        $pipeline->addStage($yamlStage);
        $pipeline->addStage($cliStage);

        $context = new ConfigurationContext(new ArrayInput([]), '/tmp');
        $resolved = $pipeline->resolve($context);

        // Should contain union of both stages, not just CLI values
        $disabledRules = $resolved->analysis->disabledRules;
        self::assertContains('complexity.cyclomatic', $disabledRules);
        self::assertContains('size.loc', $disabledRules);
        self::assertContains('coupling.cbo', $disabledRules);
        self::assertCount(3, $disabledRules);
    }

    #[Test]
    public function excludePathsAreMergedNotOverwritten(): void
    {
        $pipeline = new ConfigurationPipeline();

        $yamlStage = $this->createStage(20, 'config', new ConfigurationLayer('qmx.yaml', [
            'exclude_paths' => ['tests/', 'vendor/'],
        ]));

        $cliStage = $this->createStage(30, 'cli', new ConfigurationLayer('cli', [
            'exclude_paths' => ['generated/'],
        ]));

        $pipeline->addStage($yamlStage);
        $pipeline->addStage($cliStage);

        $context = new ConfigurationContext(new ArrayInput([]), '/tmp');
        $resolved = $pipeline->resolve($context);

        $excludePaths = $resolved->analysis->excludePaths;
        self::assertContains('tests/', $excludePaths);
        self::assertContains('vendor/', $excludePaths);
        self::assertContains('generated/', $excludePaths);
        self::assertCount(3, $excludePaths);
    }

    #[Test]
    public function mergedArraysDeduplicateValues(): void
    {
        $pipeline = new ConfigurationPipeline();

        $yamlStage = $this->createStage(20, 'config', new ConfigurationLayer('qmx.yaml', [
            'disabled_rules' => ['complexity.cyclomatic'],
        ]));

        $cliStage = $this->createStage(30, 'cli', new ConfigurationLayer('cli', [
            'disabled_rules' => ['complexity.cyclomatic', 'size.loc'],
        ]));

        $pipeline->addStage($yamlStage);
        $pipeline->addStage($cliStage);

        $context = new ConfigurationContext(new ArrayInput([]), '/tmp');
        $resolved = $pipeline->resolve($context);

        $disabledRules = $resolved->analysis->disabledRules;
        // Should have no duplicates
        self::assertCount(2, $disabledRules);
        self::assertContains('complexity.cyclomatic', $disabledRules);
        self::assertContains('size.loc', $disabledRules);
    }

    #[Test]
    public function excludesAreMergedNotOverwritten(): void
    {
        $pipeline = new ConfigurationPipeline();

        $yamlStage = $this->createStage(20, 'config', new ConfigurationLayer('qmx.yaml', [
            'excludes' => ['vendor', 'tests'],
        ]));

        $cliStage = $this->createStage(30, 'cli', new ConfigurationLayer('cli', [
            'excludes' => ['generated', 'cache'],
        ]));

        $pipeline->addStage($yamlStage);
        $pipeline->addStage($cliStage);

        $context = new ConfigurationContext(new ArrayInput([]), '/tmp');
        $resolved = $pipeline->resolve($context);

        // CLI excludes should be merged with config file excludes, not replace them
        $excludes = $resolved->paths->excludes;
        self::assertContains('vendor', $excludes);
        self::assertContains('tests', $excludes);
        self::assertContains('generated', $excludes);
        self::assertContains('cache', $excludes);
        self::assertCount(4, $excludes);
    }

    private function createStage(int $priority, string $name, ?ConfigurationLayer $layer): ConfigurationStageInterface
    {
        return new class ($priority, $name, $layer) implements ConfigurationStageInterface {
            public function __construct(
                private readonly int $priority,
                private readonly string $name,
                private readonly ?ConfigurationLayer $layer,
            ) {}

            public function priority(): int
            {
                return $this->priority;
            }

            public function name(): string
            {
                return $this->name;
            }

            public function apply(ConfigurationContext $context): ?ConfigurationLayer
            {
                return $this->layer;
            }
        };
    }
}
