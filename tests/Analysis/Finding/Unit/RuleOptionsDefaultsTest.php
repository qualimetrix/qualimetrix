<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

final class RuleOptionsDefaultsTest extends TestCase
{
    #[Test]
    public function itBuildsEveryProducerWithItsOwnConstructorDefaultsAndHonoursExplicitDisablement(): void
    {
        $container = (new ContainerFactory())->create();
        $builder = $container->get(RuleOptionsBuild::class);
        self::assertInstanceOf(RuleOptionsBuild::class, $builder);
        $snapshot = $builder->build(FindingConfiguration::none());
        self::assertCount(54, $snapshot->all());
        $disabled = [];
        foreach ($snapshot->all() as $producer => $options) {
            $class = $options::class;
            self::assertEquals(new $class(), $options, $producer);
            $disabled[$producer] = ['enabled' => false];
        }
        $muted = $builder->build(FindingConfiguration::none()->withRuleOptions($disabled));
        foreach ($muted->all() as $producer => $options) {
            self::assertFalse($options->isEnabled(), $producer);
        }
    }
}
