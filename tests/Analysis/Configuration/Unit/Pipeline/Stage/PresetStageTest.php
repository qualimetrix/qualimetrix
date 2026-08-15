<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline\Stage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Loader\ConfigLoaderInterface;
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
        $this->loader->expects(self::never())->method('load');
        $stage = $this->stage();
        self::assertSame(15, $stage->priority());
        self::assertSame('preset', $stage->name());
    }

    #[Test]
    public function itReturnsNullWithoutPresetNames(): void
    {
        $this->loader->expects(self::never())->method('load');
        self::assertNull($this->stage()->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'))));
    }

    #[Test]
    public function itSplitsAndDeduplicatesPresetNamesWhileRetainingOrderedDocuments(): void
    {
        $this->loader->expects(self::exactly(2))->method('load')->willReturnOnConsecutiveCalls(
            ['format' => 'text', 'rules' => ['size.loc' => ['warning' => 1000]]],
            ['failOn' => 'error', 'rules' => ['size.loc' => ['error' => 2000]]],
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
    }

    #[Test]
    public function itFiltersEmptyPresetSegments(): void
    {
        $this->loader->expects(self::once())->method('load')->willReturn(['format' => 'json']);

        $layer = $this->stage()->apply(
            new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), null, [', strict, ']),
        );

        self::assertNotNull($layer);
        self::assertSame('preset:strict', $layer->source);
    }

    private function stage(): PresetStage
    {
        return new PresetStage($this->loader, new PresetResolver());
    }
}
