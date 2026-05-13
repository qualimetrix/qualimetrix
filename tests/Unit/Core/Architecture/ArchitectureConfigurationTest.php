<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Architecture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Architecture\ArchitectureConfiguration;
use Qualimetrix\Core\Architecture\CoverageMode;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\LayerPolicy;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;

#[CoversClass(ArchitectureConfiguration::class)]
final class ArchitectureConfigurationTest extends TestCase
{
    #[Test]
    public function gettersReturnConstructorArguments(): void
    {
        $registry = new LayerRegistry([new LayerDefinition('core', ['App\\Core'])]);
        $policy = new LayerPolicy(['core' => []]);
        $coverage = CoverageMode::Warn;

        $config = new ArchitectureConfiguration($registry, $policy, $coverage);

        self::assertSame($registry, $config->registry());
        self::assertSame($policy, $config->policy());
        self::assertSame($coverage, $config->coverage());
    }

    #[Test]
    public function isEmptyReturnsTrueForEmptyRegistry(): void
    {
        $config = new ArchitectureConfiguration(
            new LayerRegistry([]),
            new LayerPolicy([]),
            CoverageMode::Ignore,
        );

        self::assertTrue($config->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseWhenAtLeastOneLayerIsDeclared(): void
    {
        $config = new ArchitectureConfiguration(
            new LayerRegistry([new LayerDefinition('core', ['App\\Core'])]),
            new LayerPolicy([]),
            CoverageMode::Ignore,
        );

        self::assertFalse($config->isEmpty());
    }
}
