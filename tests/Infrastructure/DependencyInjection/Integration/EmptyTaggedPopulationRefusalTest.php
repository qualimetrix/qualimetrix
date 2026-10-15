<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\DependencyInjection\Integration;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class EmptyTaggedPopulationRefusalTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function requiredTags(): iterable
    {
        foreach ([
            'qmx.rule',
            'qmx.configuration_validator',
            'qmx.collector',
            'qmx.derived_collector',
            'qmx.global_collector',
            'qmx.cohesion.lcom_configurable_collector',
            'qmx.analysis.run.file_set_inspection_participant',
            'qmx.formatter',
            'qmx.configuration_stage',
        ] as $tag) {
            yield $tag => [$tag];
        }
    }

    #[Test]
    #[DataProvider('requiredTags')]
    public function itRefusesAnEmptyRequiredPopulationBeforeConsumersRun(string $tag): void
    {
        $container = (new ContainerFactory())->configure();
        $container->addCompilerPass(new class ($tag) implements CompilerPassInterface {
            public function __construct(private readonly string $tag) {}

            public function process(ContainerBuilder $container): void
            {
                $population = $container->findTaggedServiceIds($this->tag);
                TestCase::assertNotEmpty($population, 'The fixture must remove a real tagged population.');
                foreach (array_keys($population) as $id) {
                    $container->getDefinition($id)->clearTag($this->tag);
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 80);

        self::expectException(LogicException::class);
        self::expectExceptionMessage(\sprintf('Tagged population "%s" has 0 services; at least 1 is required.', $tag));
        $container->compile();
    }

    #[Test]
    public function itBuildsTheUnchangedProductContainer(): void
    {
        self::assertTrue((new ContainerFactory())->create()->isCompiled());
    }
}
