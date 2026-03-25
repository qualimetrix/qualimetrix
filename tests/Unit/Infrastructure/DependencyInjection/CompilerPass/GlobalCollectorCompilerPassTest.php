<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\DependencyInjection\CompilerPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\GlobalCollectorRunner;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\GlobalCollectorCompilerPass;
use Qualimetrix\Metrics\Coupling\CouplingCollector;
use Qualimetrix\Metrics\Structure\NocCollector;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(GlobalCollectorCompilerPass::class)]
final class GlobalCollectorCompilerPassTest extends TestCase
{
    #[Test]
    public function collectsTaggedServicesIntoGlobalCollectorRunner(): void
    {
        $container = new ContainerBuilder();
        $container->register(GlobalCollectorRunner::class);
        $container->register(CouplingCollector::class)
            ->addTag(GlobalCollectorCompilerPass::TAG);
        $container->register(NocCollector::class)
            ->addTag(GlobalCollectorCompilerPass::TAG);

        $pass = new GlobalCollectorCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(GlobalCollectorRunner::class);
        $collectors = $definition->getArgument('$collectors');

        self::assertCount(2, $collectors);
        self::assertInstanceOf(Reference::class, $collectors[0]);
        self::assertInstanceOf(Reference::class, $collectors[1]);
    }

    #[Test]
    public function doesNothingWhenRunnerNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(CouplingCollector::class)
            ->addTag(GlobalCollectorCompilerPass::TAG);

        $pass = new GlobalCollectorCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition(GlobalCollectorRunner::class));
    }

    #[Test]
    public function setsEmptyArrayWhenNoTaggedServices(): void
    {
        $container = new ContainerBuilder();
        $container->register(GlobalCollectorRunner::class);

        $pass = new GlobalCollectorCompilerPass();
        $pass->process($container);

        $definition = $container->getDefinition(GlobalCollectorRunner::class);

        self::assertSame([], $definition->getArgument('$collectors'));
    }
}
