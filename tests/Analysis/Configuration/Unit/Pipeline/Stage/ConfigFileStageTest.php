<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline\Stage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Analysis\Configuration\Loader\LoadedDocument;
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
        $entries = scandir($this->directory);
        foreach ($entries === false ? [] : $entries as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($this->directory . '/' . $file);
            }
        }
        @rmdir($this->directory);
    }

    #[Test]
    public function itHasConfigFileSourceIdentity(): void
    {
        $this->loader->expects(self::never())->method('read');
        $stage = new ConfigFileStage($this->loader);
        self::assertSame(20, $stage->priority());
        self::assertSame('config_file', $stage->name());
    }

    #[Test]
    public function itReturnsNullWhenNoConfigFileExists(): void
    {
        $this->loader->expects(self::never())->method('read');
        self::assertNull((new ConfigFileStage($this->loader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory))));
    }

    #[Test]
    public function itLoadsAndNormalizesTheAutoDetectedYamlDocument(): void
    {
        touch($this->directory . '/qmx.yaml');
        $this->loader->expects(self::once())->method('read')
            ->with($this->directory . '/qmx.yaml')
            ->willReturn(new LoadedDocument(
                AuthoredNode::fromPlain(['cache' => ['enabled' => false], 'paths' => ['src']]),
                ['cache' => ['enabled' => false], 'paths' => ['src']],
            ));

        $layer = (new ConfigFileStage($this->loader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertNotNull($layer);
        self::assertSame('qmx.yaml', $layer->source);
        self::assertSame(['paths' => ['src'], 'cache.enabled' => false], $layer->values);
        self::assertCount(1, $layer->authored);
        self::assertSame(ConfigurationSource::ConfigFile, $layer->authored[0]->origin->source());
        self::assertSame($this->directory . '/qmx.yaml', $layer->authored[0]->origin->locator());
        self::assertSame(['cache' => ['enabled' => false], 'paths' => ['src']], $layer->authored[0]->root->plain());
    }

    /**
     * A refusal of the folded values waits for the engine, which judges the
     * written document first and answers in its own words.
     */
    #[Test]
    public function itHandsTheFoldedValuesRefusalOnInsteadOfThrowingIt(): void
    {
        touch($this->directory . '/qmx.yaml');
        $refusal = ConfigurationRefusal::aboutConfigFileDocument($this->directory . '/qmx.yaml', 'folded values refused');
        $this->loader->expects(self::once())->method('read')->willReturn(new LoadedDocument(AuthoredNode::fromPlain(['rules' => 5]), [], $refusal));

        $layer = (new ConfigFileStage($this->loader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertNotNull($layer);
        self::assertSame([$refusal], $layer->deferredRefusals);
    }

    #[Test]
    public function itUsesTheExplicitConfigPathInsteadOfAutoDetection(): void
    {
        touch($this->directory . '/qmx.yaml');
        touch($this->directory . '/custom.yaml');
        $this->loader->expects(self::once())->method('read')
            ->with($this->directory . '/custom.yaml')
            ->willReturn(new LoadedDocument(AuthoredNode::fromPlain(['format' => 'json']), ['format' => 'json']));

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
        $this->loader->expects(self::never())->method('read');
        $this->expectException(ConfigurationRefusal::class);
        (new ConfigFileStage($this->loader))->apply(
            new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory), $this->directory . '/missing.yaml'),
        );
    }

    #[Test]
    public function itRefusesAnUnlistableAutoDiscoveryDirectory(): void
    {
        $this->loader->expects(self::never())->method('read');
        $this->expectException(ConfigurationRefusal::class);

        (new ConfigFileStage($this->loader))->apply(
            new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory . '/missing')),
        );
    }

    #[Test]
    public function itRejectsBothExactAutoDetectedConfigNames(): void
    {
        touch($this->directory . '/qmx.yaml');
        touch($this->directory . '/qmx.yml');
        $this->loader->expects(self::never())->method('read');

        $this->expectException(ConfigurationRefusal::class);
        (new ConfigFileStage($this->loader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));
    }

    /** @return iterable<string, array{string}> */
    public static function provideNearConfigNames(): iterable
    {
        yield 'case variant' => ['QMX.YAML'];
        yield 'case variant with lowercase extension' => ['QMX.yaml'];
        yield 'dot prefix' => ['.qmx.yaml'];
        yield 'middle suffix' => ['qmx.config.yaml'];
        yield 'suffix variant' => ['qmx.yaml.bak'];
        yield 'prefix variant' => ['backup-qmx.yml'];
        yield 'extensionless base' => ['qmx'];
    }

    #[Test]
    #[DataProvider('provideNearConfigNames')]
    public function itReportsANearConfigNameWithoutLoadingIt(string $name): void
    {
        touch($this->directory . '/' . $name);
        $this->loader->expects(self::never())->method('read');

        $layer = (new ConfigFileStage($this->loader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertNotNull($layer);
        self::assertSame([], $layer->values);
        self::assertCount(1, $layer->diagnostics);
        self::assertStringContainsString($name, $layer->diagnostics[0]->message);
        self::assertSame($this->directory . '/' . $name, $layer->diagnostics[0]->sources[0]->origin->locator());
    }

    #[Test]
    public function itKeepsNearConfigNamesSilentWhenAnExactNameExists(): void
    {
        touch($this->directory . '/qmx.yaml.bak');
        touch($this->directory . '/qmx.yaml');
        $this->loader->expects(self::once())->method('read')
            ->with($this->directory . '/qmx.yaml')
            ->willReturn(new LoadedDocument(AuthoredNode::fromPlain([]), []));

        $layer = (new ConfigFileStage($this->loader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertNotNull($layer);
        self::assertSame([], $layer->diagnostics);
    }
}
