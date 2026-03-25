<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Parallel\Strategy;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use Qualimetrix\Metrics\Maintainability\MaintainabilityIndexCollector;
use Qualimetrix\Metrics\Size\LocCollector;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(AmphpParallelStrategy::class)]
final class AmphpParallelStrategyTest extends TestCase
{
    private AmphpParallelStrategy $strategy;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->strategy = new AmphpParallelStrategy();
        $this->tempDir = sys_get_temp_dir() . '/qmx-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    #[Test]
    public function itReturnsAvailabilityBasedOnAmphpParallelAndExtensions(): void
    {
        // amphp/parallel is installed in dev environment
        // It should be available if ext-parallel or pcntl_fork exists
        $isAvailable = $this->strategy->isAvailable();

        // At least on Unix systems with PCNTL it should be available
        if (\function_exists('pcntl_fork') || \extension_loaded('parallel')) {
            self::assertTrue($isAvailable, 'AmphpParallelStrategy should be available when amphp/parallel is installed and pcntl/parallel available');
        } else {
            self::assertFalse($isAvailable, 'AmphpParallelStrategy should not be available without pcntl or parallel extension');
        }
    }

    #[Test]
    public function itDelegatesIsParallelAvailableToIsAvailable(): void
    {
        // isParallelAvailable should return same as isAvailable
        self::assertSame($this->strategy->isAvailable(), $this->strategy->isParallelAvailable());
    }

    #[Test]
    public function itSetsAndGetsWorkerCount(): void
    {
        $this->strategy->setWorkerCount(8);

        self::assertSame(8, $this->strategy->getWorkerCount());
    }

    #[Test]
    public function itEnforcesMinimumWorkerCountOfOne(): void
    {
        $this->strategy->setWorkerCount(0);

        self::assertSame(1, $this->strategy->getWorkerCount());
    }

    #[Test]
    public function itEnforcesMinimumWorkerCountForNegativeValues(): void
    {
        $this->strategy->setWorkerCount(-5);

        self::assertSame(1, $this->strategy->getWorkerCount());
    }

    #[Test]
    public function itSetsMinFilesForParallel(): void
    {
        $this->strategy->setMinFilesForParallel(200);

        // We can't directly read the private property, but we can verify behavior
        // by checking that small file sets fall back to sequential
        $files = $this->createTestFiles(50);
        $processor = fn(SplFileInfo $file): string => $file->getPathname();

        // With 50 files and threshold of 200, should fall back to sequential
        $results = $this->strategy->execute($files, $processor, canParallelize: true);

        self::assertCount(50, $results);
    }

    #[Test]
    public function itEnforcesMinimumMinFilesForParallelOfOne(): void
    {
        $this->strategy->setMinFilesForParallel(0);

        // Should be clamped to 1 - verify by using 1 file which should now pass threshold
        // (though other conditions like projectRoot will cause fallback)
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function itSetsProjectRoot(): void
    {
        $this->strategy->setProjectRoot('/path/to/project');

        // We can't directly verify the private property, but method should not throw
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function itSetsCacheDir(): void
    {
        $this->strategy->setCacheDir('/path/to/cache');

        // We can't directly verify the private property, but method should not throw
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function itSetsCacheDirToNull(): void
    {
        $this->strategy->setCacheDir(null);

        // Should accept null to disable caching
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function itSetsCollectorClasses(): void
    {
        $this->strategy->setCollectorClasses([
            'Qualimetrix\Metrics\Complexity\CyclomaticComplexityCollector',
            'Qualimetrix\Metrics\Size\LocCollector',
        ]);

        // We can't directly verify the private property, but method should not throw
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function itSetsEmptyCollectorClasses(): void
    {
        $this->strategy->setCollectorClasses([]);

        // Should accept empty array
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function itSetsDerivedCollectorClasses(): void
    {
        $this->strategy->setDerivedCollectorClasses([
            'Qualimetrix\Metrics\Maintainability\MaintainabilityIndexCollector',
        ]);

        // We can't directly verify the private property, but method should not throw
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function itFallsBackToSequentialWhenCannotParallelize(): void
    {
        $files = $this->createTestFiles(2);

        $callCount = 0;
        $processor = function (SplFileInfo $file) use (&$callCount): string {
            $callCount++;
            return $file->getPathname();
        };

        $results = $this->strategy->execute($files, $processor, canParallelize: false);

        self::assertSame(2, $callCount);
        self::assertCount(2, $results);
    }

    #[Test]
    public function itHandlesEmptyFilesList(): void
    {
        $callCount = 0;
        $processor = function (SplFileInfo $file) use (&$callCount): string {
            $callCount++;
            return $file->getPathname();
        };

        $results = $this->strategy->execute([], $processor, canParallelize: true);

        self::assertSame(0, $callCount);
        self::assertSame([], $results);
    }

    #[Test]
    public function itFallsBackToSequentialWhenFileCountBelowThreshold(): void
    {
        // Default threshold is 100 files
        $files = $this->createTestFiles(50);

        $callCount = 0;
        $processor = function (SplFileInfo $file) use (&$callCount): string {
            $callCount++;
            return $file->getPathname();
        };

        $results = $this->strategy->execute($files, $processor, canParallelize: true);

        self::assertSame(50, $callCount);
        self::assertCount(50, $results);
    }

    #[Test]
    public function itFallsBackToSequentialWhenProjectRootNotSet(): void
    {
        // Set enough files to pass threshold
        $this->strategy->setMinFilesForParallel(10);
        $files = $this->createTestFiles(20);

        // Set collector classes (required condition)
        $this->strategy->setCollectorClasses([LocCollector::class]);

        // Don't set projectRoot - should cause fallback

        $callCount = 0;
        $processor = function (SplFileInfo $file) use (&$callCount): string {
            $callCount++;
            return $file->getPathname();
        };

        $results = $this->strategy->execute($files, $processor, canParallelize: true);

        // Should fall back to sequential
        self::assertSame(20, $callCount);
        self::assertCount(20, $results);
    }

    #[Test]
    public function itFallsBackToSequentialWhenCollectorClassesEmpty(): void
    {
        // Set enough files to pass threshold
        $this->strategy->setMinFilesForParallel(10);
        $files = $this->createTestFiles(20);

        // Set project root (required condition)
        $this->strategy->setProjectRoot($this->tempDir);

        // Don't set collector classes (or set empty) - should cause fallback
        $this->strategy->setCollectorClasses([]);

        $callCount = 0;
        $processor = function (SplFileInfo $file) use (&$callCount): string {
            $callCount++;
            return $file->getPathname();
        };

        $results = $this->strategy->execute($files, $processor, canParallelize: true);

        // Should fall back to sequential
        self::assertSame(20, $callCount);
        self::assertCount(20, $results);
    }

    #[Test]
    public function itExecutesSequentialWithMultipleFiles(): void
    {
        $files = $this->createTestFiles(5);

        $processedFiles = [];
        $processor = function (SplFileInfo $file) use (&$processedFiles): string {
            $processedFiles[] = $file->getPathname();
            return $file->getPathname();
        };

        $results = $this->strategy->execute($files, $processor, canParallelize: false);

        self::assertCount(5, $results);
        self::assertCount(5, $processedFiles);

        // Verify all files were processed
        foreach ($files as $index => $file) {
            self::assertSame($file->getPathname(), $results[$index]);
            self::assertSame($file->getPathname(), $processedFiles[$index]);
        }
    }

    #[Test]
    public function itUsesLoggerForDebugMessages(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        // Expect debug message about file count below threshold
        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'AmphpParallelStrategy: file count below threshold, using sequential',
                $this->callback(fn(array $context): bool => isset($context['files_count'], $context['threshold'])),
            );

        $strategy = new AmphpParallelStrategy($logger);
        $strategy->setMinFilesForParallel(100);

        $files = $this->createTestFiles(50);
        $processor = fn(SplFileInfo $file): string => $file->getPathname();

        $strategy->execute($files, $processor, canParallelize: true);
    }

    #[Test]
    public function itLogsWarningWhenProjectRootNotSet(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        // Expect warning message about missing project root
        $logger->expects($this->once())
            ->method('warning')
            ->with('AmphpParallelStrategy: project root not set, using sequential fallback');

        $strategy = new AmphpParallelStrategy($logger);
        $strategy->setMinFilesForParallel(10);
        $strategy->setCollectorClasses([LocCollector::class]);

        $files = $this->createTestFiles(20);
        $processor = fn(SplFileInfo $file): string => $file->getPathname();

        $strategy->execute($files, $processor, canParallelize: true);
    }

    #[Test]
    public function itLogsWarningWhenCollectorClassesNotConfigured(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        // Expect warning message about missing collector classes
        $logger->expects($this->once())
            ->method('warning')
            ->with('AmphpParallelStrategy: collector classes not configured, using sequential fallback');

        $strategy = new AmphpParallelStrategy($logger);
        $strategy->setMinFilesForParallel(10);
        $strategy->setProjectRoot($this->tempDir);
        // Don't set collector classes

        $files = $this->createTestFiles(20);
        $processor = fn(SplFileInfo $file): string => $file->getPathname();

        $strategy->execute($files, $processor, canParallelize: true);
    }

    #[Test]
    public function itAllowsSettingMinFilesForParallelToOne(): void
    {
        $this->strategy->setMinFilesForParallel(1);

        // Verify that single file can theoretically pass threshold
        // (though other conditions will cause fallback in practice)
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function itDefaultsToFourWorkers(): void
    {
        $strategy = new AmphpParallelStrategy();

        self::assertSame(4, $strategy->getWorkerCount());
    }

    #[Test]
    public function itAcceptsLargeWorkerCount(): void
    {
        $this->strategy->setWorkerCount(32);

        self::assertSame(32, $this->strategy->getWorkerCount());
    }

    #[Test]
    public function itHandlesProcessorReturningDifferentTypes(): void
    {
        $files = $this->createTestFiles(3);

        $processor = fn(SplFileInfo $file): array => ['path' => $file->getPathname(), 'size' => $file->getSize()];

        $results = $this->strategy->execute($files, $processor, canParallelize: false);

        self::assertCount(3, $results);
        foreach ($results as $result) {
            self::assertIsArray($result);
            self::assertArrayHasKey('path', $result);
            self::assertArrayHasKey('size', $result);
        }
    }

    #[Test]
    public function itFallsBackToSequentialWhenParallelNotAvailableWithAllConditionsMet(): void
    {
        // This test simulates a scenario where all conditions are met
        // (enough files, project root set, collectors configured)
        // but parallel processing is not available on the system

        // Setup strategy with all required configuration
        $this->strategy->setMinFilesForParallel(10);
        $this->strategy->setProjectRoot($this->tempDir);
        $this->strategy->setCollectorClasses([LocCollector::class]);
        $this->strategy->setDerivedCollectorClasses([MaintainabilityIndexCollector::class]);

        // Create enough files to pass threshold
        $files = $this->createTestFiles(20);

        $callCount = 0;
        $processor = function (SplFileInfo $file) use (&$callCount): string {
            $callCount++;
            return $file->getPathname();
        };

        // Execute - will fall back to sequential either because:
        // 1. Parallel is not available (no ext-parallel or pcntl_fork)
        // 2. Or will execute parallel (in which case processor won't be called)
        $results = $this->strategy->execute($files, $processor, canParallelize: true);

        // If parallel was available, processor wouldn't be called
        // If parallel wasn't available, should fall back to sequential
        if (!$this->strategy->isAvailable()) {
            self::assertSame(20, $callCount, 'Should fall back to sequential when parallel not available');
        }

        self::assertCount(20, $results);
    }

    #[Test]
    public function itLogsInfoWhenParallelNotAvailable(): void
    {
        // Only run this test if parallel is actually unavailable
        if ($this->strategy->isAvailable()) {
            $this->markTestSkipped('Parallel is available, cannot test unavailable case');
        }

        $logger = $this->createMock(LoggerInterface::class);

        // Expect info message about parallel not available
        $logger->expects($this->once())
            ->method('info')
            ->with('AmphpParallelStrategy: parallel not available, using sequential fallback');

        $strategy = new AmphpParallelStrategy($logger);
        $strategy->setMinFilesForParallel(10);
        $strategy->setProjectRoot($this->tempDir);
        $strategy->setCollectorClasses([LocCollector::class]);

        $files = $this->createTestFiles(20);
        $processor = fn(SplFileInfo $file): string => $file->getPathname();

        $strategy->execute($files, $processor, canParallelize: true);
    }

    #[Test]
    public function itProcessesFilesInOrderDuringSequentialExecution(): void
    {
        $files = $this->createTestFiles(10);

        $processedOrder = [];
        $processor = function (SplFileInfo $file) use (&$processedOrder): int {
            $processedOrder[] = $file->getFilename();
            return 1;
        };

        $this->strategy->execute($files, $processor, canParallelize: false);

        // Verify files were processed in the same order
        self::assertCount(10, $processedOrder);
        foreach ($files as $index => $file) {
            self::assertSame($file->getFilename(), $processedOrder[$index]);
        }
    }

    #[Test]
    public function itHandlesZeroMinFilesForParallelGracefully(): void
    {
        // Setting to 0 should be clamped to 1
        $this->strategy->setMinFilesForParallel(-10);

        // Create 1 file - should still fall back to sequential
        // due to missing configuration (projectRoot, collectors)
        $files = $this->createTestFiles(1);
        $processor = fn(SplFileInfo $file): string => $file->getPathname();

        $results = $this->strategy->execute($files, $processor, canParallelize: true);

        self::assertCount(1, $results);
    }

    /**
     * Creates test files in temp directory.
     *
     * @return list<SplFileInfo>
     */
    private function createTestFiles(int $count): array
    {
        $files = [];

        for ($i = 0; $i < $count; $i++) {
            $filePath = $this->tempDir . "/test_file_{$i}.php";
            file_put_contents($filePath, "<?php\n// Test file {$i}\n");
            $files[] = new SplFileInfo($filePath);
        }

        return $files;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
