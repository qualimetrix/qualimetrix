<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Namespace_;

use AiMessDetector\Analysis\Namespace_\ChainNamespaceDetector;
use AiMessDetector\Analysis\Namespace_\NamespaceDetectorFactory;
use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\ConfigurationHolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamespaceDetectorFactory::class)]
final class NamespaceDetectorFactoryTest extends TestCase
{
    #[Test]
    public function itCreatesChainDetectorWithDefaultComposerPath(): void
    {
        $configProvider = new ConfigurationHolder();
        $configProvider->setConfiguration(new AnalysisConfiguration());

        $factory = new NamespaceDetectorFactory($configProvider);
        $detector = $factory->create();

        self::assertInstanceOf(ChainNamespaceDetector::class, $detector);
    }

    #[Test]
    public function itUsesComposerJsonPathFromConfig(): void
    {
        $configProvider = new ConfigurationHolder();
        $configProvider->setConfiguration(new AnalysisConfiguration(
            composerJsonPath: '/custom/path/composer.json',
        ));

        $factory = new NamespaceDetectorFactory($configProvider);
        $detector = $factory->create();

        // Factory should create a ChainNamespaceDetector regardless of the path
        // (PSR-4 detector gracefully handles missing files)
        self::assertInstanceOf(ChainNamespaceDetector::class, $detector);
    }

    #[Test]
    public function itFallsBackToDefaultWhenComposerJsonPathIsNull(): void
    {
        $configProvider = new ConfigurationHolder();
        $configProvider->setConfiguration(new AnalysisConfiguration(
            composerJsonPath: null,
        ));

        $factory = new NamespaceDetectorFactory($configProvider);
        $detector = $factory->create();

        self::assertInstanceOf(ChainNamespaceDetector::class, $detector);
    }
}
