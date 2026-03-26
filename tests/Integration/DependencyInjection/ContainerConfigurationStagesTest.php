<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * Verifies that all configuration stages are properly registered
 * in the real DI container via autoconfiguration and compiler passes.
 */
#[CoversClass(ConfigurationPipeline::class)]
final class ContainerConfigurationStagesTest extends TestCase
{
    private ConfigurationPipeline $pipeline;

    protected function setUp(): void
    {
        $container = (new ContainerFactory())->create();

        $pipeline = $container->get(ConfigurationPipeline::class);
        self::assertInstanceOf(ConfigurationPipeline::class, $pipeline);

        $this->pipeline = $pipeline;
    }

    #[Test]
    public function presetStageIsRegisteredInPipeline(): void
    {
        $stages = $this->pipeline->stages();

        $presetStage = null;
        foreach ($stages as $stage) {
            if ($stage->name() === 'preset') {
                $presetStage = $stage;
                break;
            }
        }

        self::assertNotNull($presetStage, 'PresetStage must be registered in the container');
        self::assertSame(15, $presetStage->priority());
        self::assertSame('preset', $presetStage->name());
    }

    #[Test]
    public function allStagesAreRegisteredWithCorrectPriorities(): void
    {
        $stages = $this->pipeline->stages();

        $priorities = array_map(
            static fn($stage): int => $stage->priority(),
            $stages,
        );

        $names = array_map(
            static fn($stage): string => $stage->name(),
            $stages,
        );

        self::assertSame([0, 10, 15, 20, 30], $priorities);
        self::assertSame(['defaults', 'composer', 'preset', 'config_file', 'cli'], $names);
    }
}
