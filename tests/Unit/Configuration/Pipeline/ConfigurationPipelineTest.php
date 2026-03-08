<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Configuration\Pipeline;

use AiMessDetector\Configuration\Pipeline\ConfigurationContext;
use AiMessDetector\Configuration\Pipeline\ConfigurationLayer;
use AiMessDetector\Configuration\Pipeline\ConfigurationPipeline;
use AiMessDetector\Configuration\Pipeline\Stage\ConfigurationStageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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

        $configFile = $this->createStage(20, 'config', new ConfigurationLayer('aimd.yaml', [
            'rules' => [
                'complexity' => ['warning' => 10],
            ],
        ]));

        $pipeline->addStage($configFile);

        $context = new ConfigurationContext(new ArrayInput([]), '/tmp');
        $resolved = $pipeline->resolve($context);

        self::assertSame(['complexity' => ['warning' => 10]], $resolved->ruleOptions);
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
