<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline\Stage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Composer\ComposerManifestReader;

#[CoversClass(ComposerDiscoveryStage::class)]
final class ComposerDiscoveryStageTest extends TestCase
{
    private ComposerManifestReaderInterface&MockObject $reader;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(ComposerManifestReaderInterface::class);
    }

    #[Test]
    public function itHasComposerSourceIdentity(): void
    {
        $this->reader->expects(self::never())->method('read');
        $stage = new ComposerDiscoveryStage($this->reader);
        self::assertSame(10, $stage->priority());
        self::assertSame('composer', $stage->name());
    }

    #[Test]
    public function itReturnsNullWhenComposerDeclaresNoAutoloadTargets(): void
    {
        $root = AbsolutePath::fromString('/project');
        $this->reader->expects(self::once())->method('read')->with($root)
            ->willReturn((new \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestDecoder())->decode($root, '{}'));

        self::assertNull((new ComposerDiscoveryStage($this->reader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'))));
    }

    #[Test]
    public function itPublishesDiscoveredPathsFromTheInvocationDirectory(): void
    {
        $root = AbsolutePath::fromString('/project');
        $this->reader->expects(self::once())->method('read')->with($root)
            ->willReturn((new \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestDecoder())->decode($root, '{"autoload":{"classmap":["src","lib"]},"autoload-dev":{"classmap":["tests"]}}'));

        $layer = (new ComposerDiscoveryStage($this->reader))
            ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));

        self::assertNotNull($layer);
        self::assertSame('composer.json', $layer->source);
        self::assertSame(
            [ConfigSchema::DISCOVERED_AUTOLOAD_PATHS => ['src', 'lib'], ConfigSchema::DISCOVERED_AUTOLOAD_DEV_PATHS => ['tests']],
            $layer->values,
        );
    }

    /**
     * The two lists reach the run configuration apart and neither becomes
     * `paths` here: which of them a run analyses is `include_autoload_dev`,
     * and a source after this one may write it. Each is the whole section,
     * not its PSR-4 roots alone — the scope denominator reads the same.
     */
    #[Test]
    public function itKeepsTheProductionAndDevelopmentTargetsApart(): void
    {
        $this->reader->expects(self::never())->method('read');
        $directory = sys_get_temp_dir() . '/qmx-composer-discovery-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'src/'], 'files' => ['helpers.php']],
            'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'tests/'], 'classmap' => ['fixtures/']],
        ], \JSON_THROW_ON_ERROR));

        try {
            $layer = (new ComposerDiscoveryStage(new ComposerManifestReader()))
                ->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString($directory)));

            self::assertNotNull($layer);
            self::assertSame(
                [
                    ConfigSchema::DISCOVERED_AUTOLOAD_PATHS => ['src', 'helpers.php'],
                    ConfigSchema::DISCOVERED_AUTOLOAD_DEV_PATHS => ['tests', 'fixtures'],
                ],
                $layer->values,
            );
        } finally {
            unlink($directory . '/composer.json');
            rmdir($directory);
        }
    }
}
