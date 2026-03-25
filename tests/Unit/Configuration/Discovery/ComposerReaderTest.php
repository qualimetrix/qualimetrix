<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Discovery\ComposerReader;

#[CoversClass(ComposerReader::class)]
final class ComposerReaderTest extends TestCase
{
    private ComposerReader $reader;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->reader = new ComposerReader();
        $this->tempDir = sys_get_temp_dir() . '/composer_reader_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function extractsPathsFromPsr4Autoload(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'Tests\\' => 'tests/',
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->extractAutoloadPaths($this->tempDir . '/composer.json');

        self::assertSame(['src', 'tests'], $paths);
    }

    #[Test]
    public function returnsEmptyArrayWhenFileNotExists(): void
    {
        $paths = $this->reader->extractAutoloadPaths('/nonexistent/composer.json');

        self::assertSame([], $paths);
    }

    #[Test]
    public function returnsEmptyArrayWhenNoAutoload(): void
    {
        $this->writeComposerJson(['name' => 'test/package']);

        $paths = $this->reader->extractAutoloadPaths($this->tempDir . '/composer.json');

        self::assertSame([], $paths);
    }

    #[Test]
    public function handlesMultiPathArrayMapping(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => ['src/', 'lib/'],
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->extractAutoloadPaths($this->tempDir . '/composer.json');

        self::assertSame(['src', 'lib'], $paths);
    }

    #[Test]
    public function includesAutoloadDevPaths(): void
    {
        $composerJson = [
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\' => 'tests/',
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->extractAutoloadPaths($this->tempDir . '/composer.json');

        self::assertSame(['tests'], $paths);
    }

    #[Test]
    public function mergesAutoloadAndAutoloadDevPaths(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\' => 'tests/',
                    'Fixtures\\' => ['fixtures/', 'test-data/'],
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->extractAutoloadPaths($this->tempDir . '/composer.json');

        self::assertSame(['src', 'tests', 'fixtures', 'test-data'], $paths);
    }

    #[Test]
    public function deduplicatesAcrossAutoloadAndAutoloadDev(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\' => 'src/',
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->extractAutoloadPaths($this->tempDir . '/composer.json');

        self::assertSame(['src'], $paths);
    }

    #[Test]
    public function deduplicatesPaths(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'App\\Sub\\' => 'src/',
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->extractAutoloadPaths($this->tempDir . '/composer.json');

        self::assertSame(['src'], $paths);
    }

    #[Test]
    public function handlesRootPsr4Mapping(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => '',
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->extractAutoloadPaths($this->tempDir . '/composer.json');

        self::assertSame(['.'], $paths);
    }

    #[Test]
    public function handlesRootPsr4MappingInArray(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => ['', 'src/'],
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->extractAutoloadPaths($this->tempDir . '/composer.json');

        self::assertSame(['.', 'src'], $paths);
    }

    #[Test]
    public function normalizesTrailingSlashes(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src///',
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->extractAutoloadPaths($this->tempDir . '/composer.json');

        self::assertSame(['src'], $paths);
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
