<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationHolder;
use RuntimeException;

#[CoversClass(ConfigurationHolder::class)]
final class ConfigurationHolderTest extends TestCase
{
    public function testInitiallyHasNoConfiguration(): void
    {
        $provider = new ConfigurationHolder();

        self::assertFalse($provider->hasConfiguration());
    }

    public function testGetConfigurationThrowsWhenNotSet(): void
    {
        $provider = new ConfigurationHolder();

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Configuration not set');

        $provider->getConfiguration();
    }

    public function testSetAndGetConfiguration(): void
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

    public function testRuleOptionsInitiallyEmpty(): void
    {
        $provider = new ConfigurationHolder();

        self::assertSame([], $provider->getRuleOptions());
    }

    public function testSetAndGetRuleOptions(): void
    {
        $provider = new ConfigurationHolder();
        $options = [
            'cyclomatic-complexity' => ['threshold' => 15],
            'namespace-size' => ['max_classes' => 20],
        ];

        $provider->setRuleOptions($options);

        self::assertSame($options, $provider->getRuleOptions());
    }

    public function testConfigurationCanBeReplaced(): void
    {
        $provider = new ConfigurationHolder();
        $config1 = new AnalysisConfiguration(format: 'text');
        $config2 = new AnalysisConfiguration(format: 'json');

        $provider->setConfiguration($config1);
        $provider->setConfiguration($config2);

        self::assertSame($config2, $provider->getConfiguration());
    }

    public function testRuleOptionsCanBeReplaced(): void
    {
        $provider = new ConfigurationHolder();

        $provider->setRuleOptions(['rule1' => ['a' => 1]]);
        $provider->setRuleOptions(['rule2' => ['b' => 2]]);

        self::assertSame(['rule2' => ['b' => 2]], $provider->getRuleOptions());
    }
}
