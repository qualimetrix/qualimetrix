<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\ScopeWarningChecker;

#[CoversClass(ScopeWarningChecker::class)]
final class ScopeWarningCheckerTest extends TestCase
{
    private string $tempDir;
    private ScopeWarningChecker $checker;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_scope_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
        $this->checker = new ScopeWarningChecker();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function testNoComposerJsonReturnsNoWarnings(): void
    {
        // Missing composer.json is reported by CheckCommand, not ScopeWarningChecker
        $warnings = $this->checker->check($this->tempDir, ['src']);

        self::assertSame([], $warnings);
    }

    #[Test]
    public function testFullCoverageReturnsNoWarnings(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);

        $warnings = $this->checker->check($this->tempDir, ['src']);

        self::assertSame([], $warnings);
    }

    #[Test]
    public function testPartialCoverageReturnsWarning(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'Lib\\' => 'lib/',
                ],
            ],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);
        mkdir($this->tempDir . '/lib', 0o755, true);

        $warnings = $this->checker->check($this->tempDir, ['src']);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('lib', $warnings[0]);
    }

    #[Test]
    public function testAutoloadDevNotChecked(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\' => 'tests/',
                ],
            ],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);
        mkdir($this->tempDir . '/tests', 0o755, true);

        // Analyzing only src/ should NOT warn about missing tests/ (autoload-dev)
        $warnings = $this->checker->check($this->tempDir, ['src']);

        self::assertSame([], $warnings);
    }

    #[Test]
    public function testDotPathCoversEverything(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\' => 'tests/',
                ],
            ],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);
        mkdir($this->tempDir . '/tests', 0o755, true);

        $warnings = $this->checker->check($this->tempDir, ['.']);

        self::assertSame([], $warnings);
    }

    #[Test]
    public function testNonexistentAutoloadPathSkipped(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'Lib\\' => 'lib/', // does not exist on disk
                ],
            ],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);

        // Analyzing src covers src; lib doesn't exist so it's skipped — no warning
        $warnings = $this->checker->check($this->tempDir, ['src']);

        self::assertSame([], $warnings);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT),
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
