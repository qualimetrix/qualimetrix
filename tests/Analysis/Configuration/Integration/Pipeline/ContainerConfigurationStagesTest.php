<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document\ProbeSection;

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

        $pipeline = $container->get(ConfigurationPipelineInterface::class);
        self::assertInstanceOf(ConfigurationPipeline::class, $pipeline);
        $this->pipeline = $pipeline;
    }

    #[Test]
    public function itRegistersAllStagesWithTheCorrectPriorities(): void
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

    /** An owner declares its root by registering its section autoconfigured; the pipeline reads the document by it. */
    #[Test]
    public function itComposesTheDocumentAgainstEveryAutoconfiguredSection(): void
    {
        $container = (new ContainerFactory())->configure();
        $container->register('test.probe_section', ProbeSection::class)->setAutoconfigured(true);
        $container->compile();

        $directory = sys_get_temp_dir() . '/qmx-probe-' . bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory . '/qmx.yaml', "probe:\n  depth: 3\n");

        try {
            $pipeline = $container->get(ConfigurationPipelineInterface::class);
            self::assertInstanceOf(ConfigurationPipelineInterface::class, $pipeline);
            $document = $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString($directory)));
        } finally {
            unlink($directory . '/qmx.yaml');
            rmdir($directory);
        }

        self::assertSame(3, $document->resolved()->get('probe', 'depth')?->plain());
    }
}
