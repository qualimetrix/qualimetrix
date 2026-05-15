<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\ArchitectureConfigurationHolder;
use Qualimetrix\Architecture\Domain\CoverageMode;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\LayerRegistry;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Tests\Architecture\Support\AllowListBuilder;

#[CoversClass(ArchitectureConfigurationHolder::class)]
final class ArchitectureConfigurationHolderTest extends TestCase
{
    #[Test]
    public function defaultConstructedHolderReturnsEmptyConfiguration(): void
    {
        $holder = new ArchitectureConfigurationHolder();

        $config = $holder->get();
        self::assertTrue($config->isEmpty());
        self::assertSame(CoverageMode::Ignore, $config->coverage());
    }

    #[Test]
    public function setReplacesConfigurationAndGetReturnsIt(): void
    {
        $holder = new ArchitectureConfigurationHolder();
        $custom = new ArchitectureConfiguration(
            new LayerRegistry([new LayerDefinition('core', new MembershipSpec(['App\\Core']))]),
            AllowListBuilder::policyFromExactMap(['core' => []]),
            CoverageMode::Warn,
        );

        $holder->set($custom);

        self::assertSame($custom, $holder->get());
    }

    #[Test]
    public function resetRestoresDefaultEmptyConfiguration(): void
    {
        $holder = new ArchitectureConfigurationHolder();
        $custom = new ArchitectureConfiguration(
            new LayerRegistry([new LayerDefinition('core', new MembershipSpec(['App\\Core']))]),
            AllowListBuilder::policyFromExactMap(['core' => []]),
            CoverageMode::Error,
        );
        $holder->set($custom);

        $holder->reset();

        $afterReset = $holder->get();
        self::assertTrue($afterReset->isEmpty());
        self::assertSame(CoverageMode::Ignore, $afterReset->coverage());
        self::assertNotSame($custom, $afterReset);
    }
}
