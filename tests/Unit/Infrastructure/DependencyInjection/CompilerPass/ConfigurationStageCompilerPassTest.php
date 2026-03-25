<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\DependencyInjection\CompilerPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ConfigurationStageCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(ConfigurationStageCompilerPass::class)]
final class ConfigurationStageCompilerPassTest extends TestCase
{
    #[Test]
    public function addsTaggedStagesToPipeline(): void
    {
        $container = new ContainerBuilder();

        $container->register(ConfigurationPipeline::class);
        $container->register(DefaultsStage::class)
            ->addTag(ConfigurationStageCompilerPass::TAG);

        $pass = new ConfigurationStageCompilerPass();
        $pass->process($container);

        $pipeline = $container->getDefinition(ConfigurationPipeline::class);
        $methodCalls = $pipeline->getMethodCalls();

        self::assertCount(1, $methodCalls);
        self::assertSame('addStage', $methodCalls[0][0]);
        self::assertInstanceOf(Reference::class, $methodCalls[0][1][0]);
    }

    #[Test]
    public function doesNothingWhenPipelineNotRegistered(): void
    {
        $container = new ContainerBuilder();

        $container->register(DefaultsStage::class)
            ->addTag(ConfigurationStageCompilerPass::TAG);

        $pass = new ConfigurationStageCompilerPass();
        $pass->process($container);

        // No exception thrown, passes silently
        self::assertFalse($container->hasDefinition(ConfigurationPipeline::class));
    }

    #[Test]
    public function addsMultipleStages(): void
    {
        $container = new ContainerBuilder();

        $container->register(ConfigurationPipeline::class);
        $container->register('stage.defaults')
            ->addTag(ConfigurationStageCompilerPass::TAG);
        $container->register('stage.composer')
            ->addTag(ConfigurationStageCompilerPass::TAG);
        $container->register('stage.cli')
            ->addTag(ConfigurationStageCompilerPass::TAG);

        $pass = new ConfigurationStageCompilerPass();
        $pass->process($container);

        $pipeline = $container->getDefinition(ConfigurationPipeline::class);
        $methodCalls = $pipeline->getMethodCalls();

        self::assertCount(3, $methodCalls);
    }
}
