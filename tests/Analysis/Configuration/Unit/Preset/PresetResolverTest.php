<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Preset;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;

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
        $this->tempDir = sys_get_temp_dir() . '/qmx-preset-test-' . bin2hex(random_bytes(6));
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
    public function itResolvesTheBuiltInStrictPresetToItsYamlFile(): void
    {
        $path = $this->resolver->resolve('strict', '/tmp');

        self::assertStringEndsWith('/strict.yaml', $path);
        self::assertFileExists($path);
    }

    #[Test]
    public function itResolvesTheBuiltInLegacyPresetToItsYamlFile(): void
    {
        $path = $this->resolver->resolve('legacy', '/tmp');

        self::assertStringEndsWith('/legacy.yaml', $path);
    }

    #[Test]
    public function itResolvesTheBuiltInCiPresetToItsYamlFile(): void
    {
        $path = $this->resolver->resolve('ci', '/tmp');

        self::assertStringEndsWith('/ci.yaml', $path);
    }

    #[Test]
    public function itRefusesAnUnknownPresetNameListingAvailablePresets(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessageMatches('/Unknown preset.*foo/');
        self::expectExceptionMessageMatches('/Available presets/');

        $this->resolver->resolve('foo', '/tmp');
    }

    #[Test]
    public function itTreatsAPathContainingASlashAsAFilePath(): void
    {
        $file = $this->createTempFile('custom.yaml');

        $path = $this->resolver->resolve('./custom.yaml', $this->tempDir);

        self::assertSame($this->tempDir . '/./custom.yaml', $path);
    }

    #[Test]
    public function itTreatsAYamlExtensionAsAFilePath(): void
    {
        $file = $this->createTempFile('custom.yaml');

        $path = $this->resolver->resolve('custom.yaml', $this->tempDir);

        self::assertSame($this->tempDir . '/custom.yaml', $path);
    }

    #[Test]
    public function itTreatsAYmlExtensionAsAFilePath(): void
    {
        $file = $this->createTempFile('custom.yml');

        $path = $this->resolver->resolve('custom.yml', $this->tempDir);

        self::assertSame($this->tempDir . '/custom.yml', $path);
    }

    #[Test]
    public function itResolvesAnAbsoluteFilePathUnchanged(): void
    {
        $file = $this->createTempFile('absolute.yaml');

        $path = $this->resolver->resolve($file, '/tmp');

        self::assertSame($file, $path);
    }

    #[Test]
    public function itRefusesAFilePathThatDoesNotExist(): void
    {
        self::expectException(ConfigurationRefusal::class);

        $this->resolver->resolve('./missing.yaml', '/tmp');
    }

    #[Test]
    public function itRecognizesKnownPresetNamesAsBuiltIn(): void
    {
        self::assertTrue($this->resolver->isBuiltIn('ci'));
        self::assertTrue($this->resolver->isBuiltIn('legacy'));
        self::assertTrue($this->resolver->isBuiltIn('strict'));
    }

    #[Test]
    public function itDoesNotRecognizeUnknownNamesAsBuiltIn(): void
    {
        self::assertFalse($this->resolver->isBuiltIn('foo'));
        self::assertFalse($this->resolver->isBuiltIn('./path.yaml'));
    }

    #[Test]
    public function itListsAvailablePresetNamesAlphabetically(): void
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
