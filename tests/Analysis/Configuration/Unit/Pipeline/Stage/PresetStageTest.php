<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline\Stage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Analysis\Configuration\Loader\LoadedDocument;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\PresetStage;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(PresetStage::class)]
final class PresetStageTest extends TestCase
{
    private ConfigLoaderInterface&MockObject $loader;

    protected function setUp(): void
    {
        $this->loader = $this->createMock(ConfigLoaderInterface::class);
    }

    #[Test]
    public function itHasPresetSourceIdentity(): void
    {
        $this->loader->expects(self::never())->method('read');
        $stage = $this->stage();
        self::assertSame(15, $stage->priority());
        self::assertSame('preset', $stage->name());
    }

    #[Test]
    public function itReturnsNullWithoutPresetNames(): void
    {
        $this->loader->expects(self::never())->method('read');
        self::assertNull($this->stage()->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'))));
    }

    #[Test]
    public function itSplitsAndDeduplicatesPresetNamesWhileRetainingOrderedDocuments(): void
    {
        $loaded = [
            new LoadedDocument(
                AuthoredNode::fromPlain(['format' => 'text', 'rules' => ['size.loc' => ['warning' => 1000]]]),
            ),
            new LoadedDocument(
                AuthoredNode::fromPlain(['fail-on' => 'error', 'rules' => ['size.loc' => ['error' => 2000]]]),
            ),
        ];
        $paths = [
            (new PresetResolver())->resolve('strict', '/project'),
            (new PresetResolver())->resolve('ci', '/project'),
        ];
        $index = 0;
        $this->loader->expects(self::exactly(2))->method('read')->willReturnCallback(
            static function (string $physicalPath, string $sourceName) use ($loaded, $paths, &$index): LoadedDocument {
                self::assertSame($paths[$index], $physicalPath);
                self::assertSame($physicalPath, $sourceName);

                return $loaded[$index++];
            },
        );

        $layer = $this->stage()->apply(
            new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), null, ['strict, ci', 'strict']),
        );

        self::assertNotNull($layer);
        self::assertSame('preset:strict,ci', $layer->source);
        self::assertSame([], $layer->values);
        self::assertSame([
            ['format' => 'text', 'rules' => ['size.loc' => ['warning' => 1000]]],
            ['fail-on' => 'error', 'rules' => ['size.loc' => ['error' => 2000]]],
        ], array_map(static fn(AuthoredLayer $preset): mixed => $preset->root->plain(), $layer->authored));
        self::assertSame(['strict', 'ci'], array_map(
            static fn(AuthoredLayer $preset): ?string => $preset->origin->locator(),
            $layer->authored,
        ), 'Each preset is a layer of its own, named.');
        self::assertSame(ConfigurationSource::Preset, $layer->authored[1]->origin->source());
        self::assertSame(['fail-on' => 'error', 'rules' => ['size.loc' => ['error' => 2000]]], $layer->authored[1]->root->plain(), 'A preset reaches the engine as written.');
        self::assertCount(2, $layer->authored);
        $schema = new \Qualimetrix\Analysis\Configuration\Document\DocumentSchema([...\Qualimetrix\Analysis\Configuration\ConfigurationRoot::cases(), ...\Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument::standaloneSections()]);
        try {
            \Qualimetrix\Analysis\Configuration\Document\DocumentComposer::compose($schema, $layer->authored);
            self::fail('The actual rules owner must refuse the original unknown producer.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('Rule option owner "size.loc" does not match any registered producer rule.', $refusal->summary());
            self::assertSame(ConfigurationSource::Preset, $refusal->sources()[0]->source());
            self::assertSame('strict', $refusal->sources()[0]->locator());
            self::assertSame(['rules', 'size.loc'], $refusal->position()?->segments);
            self::assertSame('size.loc', $refusal->position()->written);
        }
        $document = \Qualimetrix\Analysis\Configuration\Document\DocumentComposer::compose($schema, [
            new AuthoredLayer($layer->authored[0]->origin, AuthoredNode::fromPlain(['format' => 'text', 'rules' => ['size.method-count' => ['warning' => 1000]]])),
            new AuthoredLayer($layer->authored[1]->origin, AuthoredNode::fromPlain(['fail-on' => 'error', 'rules' => ['size.method-count' => ['error' => 2000]]])),
        ]);
        $warning = $document->get('rules', 'size.method-count', 'warning');
        $error = $document->get('rules', 'size.method-count', 'error');
        self::assertNotNull($warning);
        self::assertNotNull($error);
        self::assertSame(1000, $warning->plain());
        self::assertSame(2000, $error->plain());
        self::assertSame(['strict', 'ci'], [$warning->contributors()[0]->origin->locator(), $error->contributors()[0]->origin->locator()]);
        self::assertSame([0, 1], [$warning->contributors()[0]->layerIndex, $error->contributors()[0]->layerIndex]);
        self::assertSame('text', $document->get('format')?->plain());
        self::assertSame('error', $document->get('fail_on')?->plain());

    }

    /**
     * An empty name between commas is what `--preset=$A,$B` looks like with
     * one variable unset; skipping it would run fewer presets than written.
     */
    #[Test]
    public function itRefusesAnEmptyPresetSegment(): void
    {
        $this->loader->expects(self::never())->method('read');

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('empty preset name');

        $this->stage()->apply(
            new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), null, [', strict, ']),
        );
    }

    #[Test]
    public function itTrimsWhitespaceAroundAPresetName(): void
    {
        $path = (new PresetResolver())->resolve('strict', '/project');
        $this->loader->expects(self::once())->method('read')->with($path, $path)->willReturn(
            new LoadedDocument(AuthoredNode::fromPlain(['format' => 'json'])),
        );

        $layer = $this->stage()->apply(
            new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), null, [' strict ']),
        );

        self::assertNotNull($layer);
        self::assertSame('preset:strict', $layer->source);
    }

    private function stage(): PresetStage
    {
        return new PresetStage($this->loader, new PresetResolver());
    }
}
