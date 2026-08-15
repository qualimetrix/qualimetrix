<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline\Stage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Discovery\ComposerAutoloadPathReaderInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(ComposerDiscoveryStage::class)]
final class ComposerDiscoveryStageTest extends TestCase
{
    private ComposerAutoloadPathReaderInterface&MockObject $reader;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(ComposerAutoloadPathReaderInterface::class);
    }

    #[Test]
    public function itHasComposerSourceIdentity(): void
    {
        $this->reader->expects(self::never())->method('extractAutoloadPaths');
        $stage = new ComposerDiscoveryStage($this->reader);
        self::assertSame(10, $stage->priority());
        self::assertSame('composer', $stage->name());
    }

    #[Test]
    public function itReturnsNullWhenComposerHasNoAutoloadPaths(): void
    {
        $this->reader->expects(self::once())->method('extractAutoloadPaths')
            ->with('/project/composer.json')->willReturn([]);

        self::assertNull((new ComposerDiscoveryStage($this->reader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'))));
    }

    #[Test]
    public function itPublishesDiscoveredPathsFromTheInvocationDirectory(): void
    {
        $this->reader->expects(self::once())->method('extractAutoloadPaths')
            ->with('/project/composer.json')->willReturn(['src', 'tests']);

        $layer = (new ComposerDiscoveryStage($this->reader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));

        self::assertNotNull($layer);
        self::assertSame('composer.json', $layer->source);
        self::assertSame(['paths' => ['src', 'tests']], $layer->values);
    }
}
