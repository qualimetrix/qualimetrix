<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationHolder;
use RuntimeException;

#[CoversClass(ConfigurationHolder::class)]
final class ConfigurationHolderTest extends TestCase
{
    #[Test]
    public function itInitiallyHasNoConfiguration(): void
    {
        $provider = new ConfigurationHolder();

        self::assertFalse($provider->hasConfiguration());
    }

    #[Test]
    public function itThrowsWhenGettingConfigurationNotSet(): void
    {
        $provider = new ConfigurationHolder();

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Configuration not set');

        $provider->getConfiguration();
    }

    #[Test]
    public function itSetsAndGetsConfiguration(): void
    {
        $provider = new ConfigurationHolder();
        $config = new AnalysisConfiguration(
            cacheDir: '/custom/cache',
            format: 'json',
        );

        $provider->setConfiguration($config);

        self::assertTrue($provider->hasConfiguration());
        self::assertSame($config, $provider->getConfiguration());
    }

    #[Test]
    public function itHasRuleOptionsInitiallyEmpty(): void
    {
        $provider = new ConfigurationHolder();

        self::assertSame([], $provider->getRuleOptions());
    }

    #[Test]
    public function itSetsAndGetsRuleOptions(): void
    {
        $provider = new ConfigurationHolder();
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
        $provider = new ConfigurationHolder();
        $config1 = new AnalysisConfiguration(format: 'text');
        $config2 = new AnalysisConfiguration(format: 'json');

        $provider->setConfiguration($config1);
        $provider->setConfiguration($config2);

        self::assertSame($config2, $provider->getConfiguration());
    }

    #[Test]
    public function itAllowsRuleOptionsToBeReplaced(): void
    {
        $provider = new ConfigurationHolder();

        $provider->setRuleOptions(['rule1' => ['a' => 1]]);
        $provider->setRuleOptions(['rule2' => ['b' => 2]]);

        self::assertSame(['rule2' => ['b' => 2]], $provider->getRuleOptions());
    }
}
