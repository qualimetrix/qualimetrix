<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Configuration\Pipeline\Stage;

use AiMessDetector\Configuration\Discovery\ComposerReader;
use AiMessDetector\Configuration\Pipeline\ConfigurationContext;
use AiMessDetector\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

#[CoversClass(ComposerDiscoveryStage::class)]
final class ComposerDiscoveryStageTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/composer_discovery_stage_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function hasPriorityTen(): void
    {
        $stage = new ComposerDiscoveryStage(new ComposerReader());

        self::assertSame(10, $stage->priority());
    }

    #[Test]
    public function hasNameComposer(): void
    {
        $stage = new ComposerDiscoveryStage(new ComposerReader());

        self::assertSame('composer', $stage->name());
    }

    #[Test]
    public function returnsNullWhenNoPathsFound(): void
    {
        $stage = new ComposerDiscoveryStage(new ComposerReader());
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir);

        $layer = $stage->apply($context);

        self::assertNull($layer);
    }

    #[Test]
    public function returnsLayerWithDiscoveredPaths(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'Tests\\' => 'lib/',
                ],
            ],
        ]);

        $stage = new ComposerDiscoveryStage(new ComposerReader());
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir);

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame('composer.json', $layer->source);
        self::assertSame(['src', 'lib'], $layer->values['paths']);
    }

    #[Test]
    public function passesCorrectPathToReader(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ]);

        $stage = new ComposerDiscoveryStage(new ComposerReader());
        $context = new ConfigurationContext(new ArrayInput([]), $this->tempDir);

        $layer = $stage->apply($context);

        self::assertNotNull($layer);
        self::assertSame(['src'], $layer->values['paths']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode($data, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
