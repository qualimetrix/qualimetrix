<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[\PHPUnit\Framework\Attributes\CoversClass(\Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild::class)]
final class RuleOptionsDefaultsTest extends TestCase
{
    #[Test]
    public function itBuildsEveryProducerWithItsOwnConstructorDefaultsAndHonoursExplicitDisablement(): void
    {
        $container = (new ContainerFactory())->create();
        $builder = $container->get(RuleOptionsBuild::class);
        self::assertInstanceOf(RuleOptionsBuild::class, $builder);
        $execution = $container->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $execution);
        $snapshot = ResolvedOptionsFixture::ready(FindingConfiguration::none(), $execution->allRules())->resolvedOptions;
        self::assertNotNull($snapshot);
        self::assertCount(56, $snapshot->all());
        $disabled = [];
        foreach ($snapshot->all() as $producer => $options) {
            $class = $options::class;
            self::assertEquals(new $class(), $options, $producer);
            $disabled[$producer] = ['enabled' => false];
        }
        $muted = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument(\Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::document([['source' => 'config', 'values' => ['rules' => $disabled]]], \Qualimetrix\Core\Path\AbsolutePath::fromString('/project'))), $execution->allRules())->resolvedOptions;
        self::assertNotNull($muted);
        foreach ($muted->all() as $producer => $options) {
            self::assertFalse($options->isEnabled(), $producer);
        }
    }
}
