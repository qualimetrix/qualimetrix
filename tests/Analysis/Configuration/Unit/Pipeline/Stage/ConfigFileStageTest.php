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
    #[Test]
    public function itAttributesTheExactNameCollisionToBothFiles(): void
    {
        touch($this->directory . '/qmx.yaml');
        touch($this->directory . '/qmx.yml');
        try {
            (new ConfigFileStage($this->loader))->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));
            self::fail('Both exact names must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame([$this->directory . '/qmx.yaml', $this->directory . '/qmx.yml'], array_map(static fn($origin): ?string => $origin->locator(), $refusal->sources()));
            self::assertSame([ConfigurationSource::ConfigFile, ConfigurationSource::ConfigFile], array_map(static fn($origin): ConfigurationSource => $origin->source(), $refusal->sources()));
            self::assertNull($refusal->position());
        }
    }

    #[Test]
    public function itDoesNotWarnAboutTheNearNameConsumedAsACustomPreset(): void
    {
        touch($this->directory . '/qmx-ci.yaml');
        $layer = (new ConfigFileStage($this->loader))->apply(new ConfigurationResolutionRequest(
            AbsolutePath::fromString($this->directory),
            presetNames: ['./qmx-ci.yaml'],
        ));
        self::assertTrue($layer === null || $layer->diagnostics === []);
    }

    #[Test]
    public function itStillWarnsForANearNameWhenThePresetIsElsewhere(): void
    {
        touch($this->directory . '/qmx-ci.yaml');
        $other = sys_get_temp_dir() . '/qmx-other-preset-' . bin2hex(random_bytes(6));
        mkdir($other);
        touch($other . '/qmx-ci.yaml');
        try {
            $layer = (new ConfigFileStage($this->loader))->apply(new ConfigurationResolutionRequest(
                AbsolutePath::fromString($this->directory),
                presetNames: [$other . '/qmx-ci.yaml'],
            ));
            self::assertNotNull($layer);
            self::assertCount(1, $layer->diagnostics);
            self::assertSame('qmx-ci.yaml', $layer->diagnostics[0]->sources[0]->origin->locator());
        } finally {
            unlink($other . '/qmx-ci.yaml');
            rmdir($other);
        }
    }

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
    public function itHandsTheAutoDetectedDocumentToItsDeclaredOwners(): void
    {
        touch($this->directory . '/qmx.yaml');
        $this->loader->expects(self::once())->method('read')
            ->with($this->directory . '/qmx.yaml', 'qmx.yaml')
            ->willReturn(new LoadedDocument(
                AuthoredNode::fromPlain(['cache' => ['enabled' => false], 'paths' => ['src']]),
            ));

        $layer = (new ConfigFileStage($this->loader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertNotNull($layer);
        self::assertSame('qmx.yaml', $layer->source);
        self::assertSame([], $layer->values);
        $document = \Qualimetrix\Analysis\Configuration\Document\DocumentComposer::compose(new \Qualimetrix\Analysis\Configuration\Document\DocumentSchema([...\Qualimetrix\Analysis\Configuration\ConfigurationRoot::cases(), ...\Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument::standaloneSections()]), $layer->authored);
        self::assertSame(['src'], $document->get('paths')?->plain());
        self::assertFalse($document->get('cache', 'enabled')?->plain());
        self::assertCount(1, $layer->authored);
        self::assertSame(ConfigurationSource::ConfigFile, $layer->authored[0]->origin->source());
        self::assertSame('qmx.yaml', $layer->authored[0]->origin->locator());
        self::assertSame(['cache' => ['enabled' => false], 'paths' => ['src']], $layer->authored[0]->root->plain());
    }

    /**
     * A refusal of the folded values waits for the engine, which judges the
     * written document first and answers in its own words.
     */
    #[Test]
    public function itHandsTheOriginalWrittenValueToTheEngineForJudgement(): void
    {
        touch($this->directory . '/qmx.yaml');
        $this->loader->expects(self::once())->method('read')->willReturn(new LoadedDocument(AuthoredNode::fromPlain(['rules' => 5])));
        $layer = (new ConfigFileStage($this->loader))->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));
        self::assertNotNull($layer);
        self::assertSame(['rules' => 5], $layer->authored[0]->root->plain());
        $schema = new \Qualimetrix\Analysis\Configuration\Document\DocumentSchema([...\Qualimetrix\Analysis\Configuration\ConfigurationRoot::cases(), ...\Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument::standaloneSections()]);
        try {
            \Qualimetrix\Analysis\Configuration\Document\DocumentComposer::compose($schema, $layer->authored);
            self::fail('The engine must refuse the original invalid rules value.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('"rules" in configuration file "qmx.yaml" must be a map, got int.', $refusal->summary());
            self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
            self::assertSame('qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame(['rules'], $refusal->position()?->segments);
            self::assertSame('rules', $refusal->position()->written);
        }
        $lawful = \Qualimetrix\Analysis\Configuration\Document\DocumentComposer::compose($schema, [new \Qualimetrix\Analysis\Configuration\Document\AuthoredLayer($layer->authored[0]->origin, AuthoredNode::fromPlain(['rules' => []]))]);
        self::assertNull($lawful->get('rules'));
    }

    #[Test]
    public function itUsesTheExplicitConfigPathInsteadOfAutoDetection(): void
    {
        touch($this->directory . '/qmx.yaml');
        touch($this->directory . '/custom.yaml');
        $this->loader->expects(self::once())->method('read')
            ->with($this->directory . '/custom.yaml', $this->directory . '/custom.yaml')
            ->willReturn(new LoadedDocument(AuthoredNode::fromPlain(['format' => 'json'])));

        $layer = (new ConfigFileStage($this->loader))->apply(
            new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory), $this->directory . '/custom.yaml'),
        );

        self::assertNotNull($layer);
        self::assertSame('custom.yaml', $layer->source);
        self::assertSame(['format' => 'json'], $layer->authored[0]->root->plain());
        self::assertSame([], $layer->values);
        self::assertSame($this->directory . '/custom.yaml', $layer->authored[0]->origin->locator());
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
        try {
            (new ConfigFileStage($this->loader))->apply(
                new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory . '/missing')),
            );
            self::fail('The missing directory must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString($this->directory . '/missing', $refusal->summary());
            self::assertNotSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
            self::assertNotSame('.', $refusal->sources()[0]->locator());
        }
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
        self::assertSame($name, $layer->diagnostics[0]->sources[0]->origin->locator());
    }

    #[Test]
    public function itKeepsNearConfigNamesSilentWhenAnExactNameExists(): void
    {
        touch($this->directory . '/qmx.yaml.bak');
        touch($this->directory . '/qmx.yaml');
        $this->loader->expects(self::once())->method('read')
            ->with($this->directory . '/qmx.yaml', 'qmx.yaml')
            ->willReturn(new LoadedDocument(AuthoredNode::fromPlain([])));

        $layer = (new ConfigFileStage($this->loader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));

        self::assertNotNull($layer);
        self::assertSame([], $layer->diagnostics);
    }
}
