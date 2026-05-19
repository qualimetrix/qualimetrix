<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BaselineWriter;

/**
 * ADR 0015 Phase 4 regression pin: BaselineWriter now relies on
 * PathFactory::tryProjectRelative() for canonical key relativization. This
 * test asserts the writer→loader contract still round-trips identically:
 * the same canonical keys are restored, and out-of-tree absolute file: keys
 * are preserved verbatim instead of being silently dropped.
 */
#[CoversClass(BaselineWriter::class)]
#[CoversClass(BaselineLoader::class)]
final class BaselineRoundTripVOTest extends TestCase
{
    private BaselineWriter $writer;
    private BaselineLoader $loader;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->writer = new BaselineWriter();
        $this->loader = new BaselineLoader();
        $this->tempDir = sys_get_temp_dir() . '/qmx_baseline_vo_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach ((array) glob($this->tempDir . '/*') as $file) {
                if (\is_string($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempDir);
        }
    }

    #[Test]
    public function itRoundTripsRelativeFileKeys(): void
    {
        $original = new Baseline(
            version: 5,
            generated: new DateTimeImmutable('2026-05-19T12:00:00+00:00'),
            entries: [
                'file:src/Service/UserService.php' => [
                    new BaselineEntry('complexity.cyclomatic', 'h1'),
                    new BaselineEntry('complexity.cognitive', 'h2'),
                ],
                'class:App\\Service\\UserService' => [
                    new BaselineEntry('coupling.cbo', 'h3'),
                ],
            ],
        );

        $path = $this->tempDir . '/baseline.json';
        $this->writer->write($original, $path, '/home/user/project');

        $reloaded = $this->loader->load($path);

        self::assertSame(array_keys($original->entries), array_keys($reloaded->entries));
        self::assertCount(2, $reloaded->entries['file:src/Service/UserService.php']);
        self::assertCount(1, $reloaded->entries['class:App\\Service\\UserService']);
    }

    #[Test]
    public function itRoundTripsAbsoluteFileKeysAfterRelativization(): void
    {
        $original = new Baseline(
            version: 5,
            generated: new DateTimeImmutable(),
            entries: [
                'file:/home/user/project/src/Foo.php' => [
                    new BaselineEntry('size.loc', 'abc'),
                ],
            ],
        );

        $path = $this->tempDir . '/baseline.json';
        $this->writer->write($original, $path, '/home/user/project');

        $reloaded = $this->loader->load($path);

        self::assertSame(['file:src/Foo.php'], array_keys($reloaded->entries));
    }

    #[Test]
    public function itPreservesOutOfTreeAbsoluteFileKeys(): void
    {
        $original = new Baseline(
            version: 5,
            generated: new DateTimeImmutable(),
            entries: [
                'file:/external/vendor/src/Bar.php' => [
                    new BaselineEntry('size.loc', 'xyz'),
                ],
                'file:/home/user/project/src/InTree.php' => [
                    new BaselineEntry('complexity.cyclomatic', 'def'),
                ],
            ],
        );

        $path = $this->tempDir . '/baseline.json';
        $this->writer->write($original, $path, '/home/user/project');

        $reloaded = $this->loader->load($path);

        self::assertArrayHasKey(
            'file:/external/vendor/src/Bar.php',
            $reloaded->entries,
            'Out-of-tree absolute paths must be preserved verbatim',
        );
        self::assertArrayHasKey('file:src/InTree.php', $reloaded->entries);
    }
}
