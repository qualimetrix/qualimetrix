<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfiguredValueWriters;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerPolicy;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\AllowListBuilder;

#[CoversClass(ArchitectureConfiguration::class)]
final class ArchitectureConfigurationTest extends TestCase
{
    #[Test]
    public function itExposesTheConstructorArgumentsThroughItsGetters(): void
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
    public function itIsEmptyWhenNoLayerIsDeclared(): void
    {
        $config = new ArchitectureConfiguration(
            new LayerRegistry([]),
            new LayerPolicy([]),
            CoverageMode::Ignore,
        );

        self::assertTrue($config->isEmpty());
    }

    #[Test]
    public function itIsNotEmptyWhenAtLeastOneLayerIsDeclared(): void
    {
        $config = new ArchitectureConfiguration(
            new LayerRegistry([new LayerDefinition('core', new MembershipSpec(['App\\Core']))]),
            new LayerPolicy([]),
            CoverageMode::Ignore,
        );

        self::assertFalse($config->isEmpty());
    }

    #[Test]
    public function itNamesNoWriterByDefault(): void
    {
        $config = new ArchitectureConfiguration(new LayerRegistry([]), new LayerPolicy([]), CoverageMode::Ignore);

        self::assertNull($config->layerWriters);
        self::assertSame('', $config->layerWritersSentence());
    }

    #[Test]
    public function itKeepsTheLayerWritersThroughExpansion(): void
    {
        $writers = new ConfiguredValueWriters([ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/project/qmx.yaml')]);
        $config = new ArchitectureConfiguration(
            new LayerRegistry([]),
            new LayerPolicy([]),
            CoverageMode::Ignore,
            layerWriters: $writers,
        );

        $expanded = $config->withExpansion([], ['module-{module}']);

        self::assertSame($writers, $expanded->layerWriters);
        self::assertSame(' Written by configuration file "/project/qmx.yaml".', $expanded->layerWritersSentence());
        self::assertSame(['module-{module}'], $expanded->emptyTemplateNames());
    }
}
