<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\CoverageMode;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\LayerPolicy;
use Qualimetrix\Architecture\Domain\Layer\LayerRegistry;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Tests\Architecture\Support\AllowListBuilder;

#[CoversClass(ArchitectureConfiguration::class)]
final class ArchitectureConfigurationTest extends TestCase
{
    #[Test]
    public function gettersReturnConstructorArguments(): void
    {
        $registry = new LayerRegistry([new LayerDefinition('core', new MembershipSpec(['App\\Core']))]);
        $policy = AllowListBuilder::policyFromExactMap(['core' => []]);
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
            new LayerRegistry([new LayerDefinition('core', new MembershipSpec(['App\\Core']))]),
            new LayerPolicy([]),
            CoverageMode::Ignore,
        );

        self::assertFalse($config->isEmpty());
    }
}
