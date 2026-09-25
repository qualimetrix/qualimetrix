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
        $this->loader->expects(self::exactly(2))->method('read')->willReturnOnConsecutiveCalls(
            new LoadedDocument(
                AuthoredNode::fromPlain(['format' => 'text']),
                ['format' => 'text', 'rules' => ['size.loc' => ['warning' => 1000]]],
            ),
            new LoadedDocument(
                AuthoredNode::fromPlain(['fail-on' => 'error']),
                ['failOn' => 'error', 'rules' => ['size.loc' => ['error' => 2000]]],
            ),
        );

        $layer = $this->stage()->apply(
            new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), null, ['strict, ci', 'strict']),
        );

        self::assertNotNull($layer);
        self::assertSame('preset:strict,ci', $layer->source);
        self::assertSame([], $layer->values);
        self::assertSame([
            ['format' => 'text', 'rules' => ['size.loc' => ['warning' => 1000]]],
            ['rules' => ['size.loc' => ['error' => 2000]], 'fail_on' => 'error'],
        ], $layer->documents);
        self::assertSame(['strict', 'ci'], array_map(
            static fn(AuthoredLayer $preset): ?string => $preset->origin->locator(),
            $layer->authored,
        ), 'Each preset is a layer of its own, named.');
        self::assertSame(ConfigurationSource::Preset, $layer->authored[1]->origin->source());
        self::assertSame(['fail-on' => 'error'], $layer->authored[1]->root->plain(), 'A preset reaches the engine as written.');
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
        $this->loader->expects(self::once())->method('read')->willReturn(
            new LoadedDocument(AuthoredNode::fromPlain(['format' => 'json']), ['format' => 'json']),
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
