<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Architecture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Architecture\ArchitectureConfiguration;
use Qualimetrix\Core\Architecture\ArchitectureConfigurationHolder;
use Qualimetrix\Core\Architecture\CoverageMode;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\LayerPolicy;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;
use Qualimetrix\Core\Architecture\Layer\MembershipSpec;

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
            new LayerPolicy(['core' => []]),
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
            new LayerPolicy(['core' => []]),
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
