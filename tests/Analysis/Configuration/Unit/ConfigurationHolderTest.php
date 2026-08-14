<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Configuration\Runtime\TransitionalRuntimeConfigurationHolder;
use Qualimetrix\Core\Path\AbsolutePath;
use RuntimeException;

#[CoversClass(TransitionalRuntimeConfigurationHolder::class)]
final class ConfigurationHolderTest extends TestCase
{
    #[Test]
    public function itInitiallyHasNoConfiguration(): void
    {
        $provider = new TransitionalRuntimeConfigurationHolder();

        self::assertFalse($provider->hasConfiguration());
    }

    #[Test]
    public function itThrowsWhenGettingConfigurationNotSet(): void
    {
        $provider = new TransitionalRuntimeConfigurationHolder();

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Configuration not set');

        $provider->getConfiguration();
    }

    #[Test]
    public function itSetsAndGetsConfiguration(): void
    {
        $provider = new TransitionalRuntimeConfigurationHolder();
        $config = new TransitionalRuntimeConfiguration(
            cacheDir: AbsolutePath::fromString('/custom/cache'),
        );

        $provider->setConfiguration($config);

        self::assertTrue($provider->hasConfiguration());
        self::assertSame($config, $provider->getConfiguration());
    }

    #[Test]
    public function itHasRuleOptionsInitiallyEmpty(): void
    {
        $provider = new TransitionalRuntimeConfigurationHolder();

        self::assertSame([], $provider->getRuleOptions());
    }

    #[Test]
    public function itSetsAndGetsRuleOptions(): void
    {
        $provider = new TransitionalRuntimeConfigurationHolder();
        $options = [
            'cyclomatic-complexity' => ['threshold' => 15],
            'namespace-size' => ['max_classes' => 20],
        ];

        $provider->setRuleOptions($options);

        self::assertSame($options, $provider->getRuleOptions());
    }

    #[Test]
    public function itAllowsConfigurationToBeReplaced(): void
    {
        $provider = new TransitionalRuntimeConfigurationHolder();
        $config1 = new TransitionalRuntimeConfiguration(cacheEnabled: true);
        $config2 = new TransitionalRuntimeConfiguration(cacheEnabled: false);

        $provider->setConfiguration($config1);
        $provider->setConfiguration($config2);

        self::assertSame($config2, $provider->getConfiguration());
    }

    #[Test]
    public function itAllowsRuleOptionsToBeReplaced(): void
    {
        $provider = new TransitionalRuntimeConfigurationHolder();

        $provider->setRuleOptions(['rule1' => ['a' => 1]]);
        $provider->setRuleOptions(['rule2' => ['b' => 2]]);

        self::assertSame(['rule2' => ['b' => 2]], $provider->getRuleOptions());
    }
}
