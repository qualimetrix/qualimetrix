<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline\Stage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(ConfigFileStage::class)]
final class ConfigFileStageTest extends TestCase
{
    private string $directory;
    private ConfigLoaderInterface&MockObject $loader;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-config-stage-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->loader = $this->createMock(ConfigLoaderInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (['qmx.yaml', 'qmx.yml', 'custom.yaml'] as $file) {
            @unlink($this->directory . '/' . $file);
        }
        @rmdir($this->directory);
    }

    #[Test]
    public function itHasConfigFileSourceIdentity(): void
    {
        $this->loader->expects(self::never())->method('load');
        $stage = new ConfigFileStage($this->loader);
        self::assertSame(20, $stage->priority());
        self::assertSame('config_file', $stage->name());
    }

    #[Test]
    public function itReturnsNullWhenNoConfigFileExists(): void
    {
        $this->loader->expects(self::never())->method('load');
        self::assertNull((new ConfigFileStage($this->loader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory))));
    }

    #[Test]
    public function itLoadsAndNormalizesTheAutoDetectedYamlDocument(): void
    {
        touch($this->directory . '/qmx.yaml');
        $this->loader->expects(self::once())->method('load')
            ->with($this->directory . '/qmx.yaml')
            ->willReturn(['cache' => ['enabled' => false], 'paths' => ['src']]);

        $layer = (new ConfigFileStage($this->loader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertNotNull($layer);
        self::assertSame('qmx.yaml', $layer->source);
        self::assertSame(['paths' => ['src'], 'cache.enabled' => false], $layer->values);
    }

    #[Test]
    public function itUsesTheExplicitConfigPathInsteadOfAutoDetection(): void
    {
        touch($this->directory . '/qmx.yaml');
        touch($this->directory . '/custom.yaml');
        $this->loader->expects(self::once())->method('load')
            ->with($this->directory . '/custom.yaml')->willReturn(['format' => 'json']);

        $layer = (new ConfigFileStage($this->loader))->apply(
            new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory), $this->directory . '/custom.yaml'),
        );

        self::assertNotNull($layer);
        self::assertSame('custom.yaml', $layer->source);
        self::assertSame(['format' => 'json'], $layer->values);
    }

    #[Test]
    public function itRejectsAMissingExplicitConfigPath(): void
    {
        $this->loader->expects(self::never())->method('load');
        $this->expectException(ConfigLoadException::class);
        (new ConfigFileStage($this->loader))->apply(
            new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory), $this->directory . '/missing.yaml'),
        );
    }
}
