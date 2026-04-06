<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Preset;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Preset\PresetResolver;

#[CoversClass(PresetResolver::class)]
final class PresetResolverTest extends TestCase
{
    private PresetResolver $resolver;
    private string $tempDir;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->resolver = new PresetResolver();
        $this->tempDir = sys_get_temp_dir() . '/qmx-preset-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function resolvesBuiltInStrictPreset(): void
    {
        $path = $this->resolver->resolve('strict', '/tmp');

        self::assertStringEndsWith('/strict.yaml', $path);
        self::assertFileExists($path);
    }

    #[Test]
    public function resolvesBuiltInLegacyPreset(): void
    {
        $path = $this->resolver->resolve('legacy', '/tmp');

        self::assertStringEndsWith('/legacy.yaml', $path);
    }

    #[Test]
    public function resolvesBuiltInCiPreset(): void
    {
        $path = $this->resolver->resolve('ci', '/tmp');

        self::assertStringEndsWith('/ci.yaml', $path);
    }

    #[Test]
    public function throwsOnUnknownBuiltInName(): void
    {
        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessageMatches('/Unknown preset.*foo/');
        self::expectExceptionMessageMatches('/Available presets/');

        $this->resolver->resolve('foo', '/tmp');
    }

    #[Test]
    public function detectsFilePathBySlash(): void
    {
        $file = $this->createTempFile('custom.yaml');

        $path = $this->resolver->resolve('./custom.yaml', $this->tempDir);

        self::assertSame($this->tempDir . '/./custom.yaml', $path);
    }

    #[Test]
    public function detectsFilePathByYamlExtension(): void
    {
        $file = $this->createTempFile('custom.yaml');

        $path = $this->resolver->resolve('custom.yaml', $this->tempDir);

        self::assertSame($this->tempDir . '/custom.yaml', $path);
    }

    #[Test]
    public function detectsFilePathByYmlExtension(): void
    {
        $file = $this->createTempFile('custom.yml');

        $path = $this->resolver->resolve('custom.yml', $this->tempDir);

        self::assertSame($this->tempDir . '/custom.yml', $path);
    }

    #[Test]
    public function resolvesAbsoluteFilePath(): void
    {
        $file = $this->createTempFile('absolute.yaml');

        $path = $this->resolver->resolve($file, '/tmp');

        self::assertSame($file, $path);
    }

    #[Test]
    public function throwsOnNonExistentFilePath(): void
    {
        self::expectException(ConfigLoadException::class);

        $this->resolver->resolve('./missing.yaml', '/tmp');
    }

    #[Test]
    public function isBuiltInReturnsTrueForKnownNames(): void
    {
        self::assertTrue($this->resolver->isBuiltIn('ci'));
        self::assertTrue($this->resolver->isBuiltIn('legacy'));
        self::assertTrue($this->resolver->isBuiltIn('strict'));
    }

    #[Test]
    public function isBuiltInReturnsFalseForUnknownName(): void
    {
        self::assertFalse($this->resolver->isBuiltIn('foo'));
        self::assertFalse($this->resolver->isBuiltIn('./path.yaml'));
    }

    #[Test]
    public function getAvailableNamesReturnsAlphabeticList(): void
    {
        self::assertSame(['ci', 'legacy', 'strict'], PresetResolver::getAvailableNames());
    }

    private function createTempFile(string $name): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, '# test preset');
        $this->tempFiles[] = $path;

        return $path;
    }
}
