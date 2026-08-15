<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Console\ScopeWarningChecker;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass(ScopeWarningChecker::class)]
final class ScopeWarningCheckerTest extends TestCase
{
    private string $tempDir;
    private AbsolutePath $projectRoot;
    private ScopeWarningChecker $checker;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_scope_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
        $this->projectRoot = AbsolutePath::fromString($this->tempDir);
        $this->checker = new ScopeWarningChecker(new ComposerReader());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function itReturnsNoWarningsWhenComposerJsonIsMissing(): void
    {
        // Missing composer.json is reported by CheckCommand, not ScopeWarningChecker
        $warnings = $this->checker->check($this->projectRoot, [$this->subPath('src')]);

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itReturnsNoWarningsForFullCoverage(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ]);
        mkdir($this->tempDir . '/src', 0o755, true);

        $warnings = $this->checker->check($this->projectRoot, [$this->subPath('src')]);

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itReturnsWarningForPartialCoverage(): void
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

        $warnings = $this->checker->check($this->projectRoot, [$this->subPath('src')]);

        self::assertCount(1, $warnings);
        self::assertSame(
            'Analyzed paths do not cover all autoload entries (missing: lib). Coupling and instability metrics may be incomplete.',
            $warnings[0],
        );
    }

    #[Test]
    public function itDoesNotCheckAutoloadDev(): void
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
        $warnings = $this->checker->check($this->projectRoot, [$this->subPath('src')]);

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itTreatsDotPathAsFullCoverage(): void
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

        // Passing the project root itself models the `qmx check .` invocation
        $warnings = $this->checker->check($this->projectRoot, [$this->projectRoot]);

        self::assertSame([], $warnings);
    }

    #[Test]
    public function itSkipsNonexistentAutoloadPaths(): void
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
        $warnings = $this->checker->check($this->projectRoot, [$this->subPath('src')]);

        self::assertSame([], $warnings);
    }

    private function subPath(string $relative): AbsolutePath
    {
        return $this->projectRoot->joinRelative(RelativePath::fromString($relative));
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

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
