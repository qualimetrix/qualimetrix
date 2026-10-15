<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit\Strategy;

use FilesystemIterator;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\Cohesion\Runtime\LcomCollectionConfigurationStore;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\Size\LocCollector;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDocumentForms;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
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
        $this->strategy = $this->createStrategy();
        $this->tempDir = sys_get_temp_dir() . '/qmx-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    #[Test]
    public function itDeclaresProcessParallelCapabilityWithoutGuessingMachineAvailability(): void
    {
        self::assertTrue($this->strategy->isParallelAvailable());
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
    public function itForwardsTheDependencyTraversalParticipantClassToEverySerializedTask(): void
    {
        if (\Qualimetrix\Infrastructure\Parallel\WorkerPool::unavailableCapability() !== null) {
            self::markTestSkipped('Parallel worker transport is unavailable.');
        }

        $this->strategy->setMinFilesForParallel(1);
        $this->strategy->setWorkerCount(2);
        $this->strategy->setProjectRoot(AbsolutePath::fromString($this->tempDir));

        $results = $this->strategy->execute(
            $this->createTestFiles(2),
            static fn(): never => throw new LogicException('Parallel execution must not use the fallback.'),
        );

        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertInstanceOf(FileProcessingResult::class, $result);
            self::assertTrue($result->isSuccessful());
        }
    }

    #[Test]
    public function itPublishesTheNamedLinkInAWorkerForSuccessAndReadFailure(): void
    {
        if (\Qualimetrix\Infrastructure\Parallel\WorkerPool::unavailableCapability() !== null) {
            self::markTestSkipped('Parallel worker transport is unavailable.');
        }

        $outside = $this->tempDir . '-outside';
        mkdir($this->tempDir . '/src');
        mkdir($outside);
        file_put_contents($outside . '/Actual.php', '<?php class Actual {}');
        symlink($outside . '/Actual.php', $this->tempDir . '/src/Named.php');
        symlink($outside . '/Missing.php', $this->tempDir . '/src/Missing.php');

        try {
            $this->strategy->setProjectRoot(AbsolutePath::fromString($this->tempDir));
            $this->strategy->setMinFilesForParallel(1);
            $this->strategy->setWorkerCount(2);

            $results = $this->strategy->execute(
                [new SplFileInfo($this->tempDir . '/src/Named.php'), new SplFileInfo($this->tempDir . '/src/Missing.php')],
                static fn(): never => throw new LogicException('Parallel execution must not use the fallback.'),
            );

            self::assertCount(2, $results);
            self::assertInstanceOf(FileProcessingResult::class, $results[0]);
            self::assertTrue($results[0]->isSuccessful());
            self::assertSame('src/Named.php', $results[0]->filePath->value());
            self::assertInstanceOf(FileProcessingResult::class, $results[1]);
            self::assertFalse($results[1]->isSuccessful());
            self::assertSame('src/Missing.php', $results[1]->filePath->value());
        } finally {
            unlink($outside . '/Actual.php');
            rmdir($outside);
        }
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
        $files = $this->createTestFiles(20);

        $this->strategy = $this->createStrategy([]);
        $this->strategy->setMinFilesForParallel(10);
        $this->strategy->setProjectRoot(AbsolutePath::fromString($this->tempDir));

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
                self::callback(fn(array $context): bool => isset($context['files_count'], $context['threshold'])),
            );

        $strategy = $this->createStrategy(logger: $logger);
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
        $logger->expects($this->once())->method('warning')->with('AmphpParallelStrategy: project root not set, using sequential fallback');

        $strategy = $this->createStrategy(logger: $logger);
        $strategy->setMinFilesForParallel(10);

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

        $strategy = $this->createStrategy([], $logger);
        $strategy->setMinFilesForParallel(10);
        $strategy->setProjectRoot(AbsolutePath::fromString($this->tempDir));
        // Don't set collector classes

        $files = $this->createTestFiles(20);
        $processor = fn(SplFileInfo $file): string => $file->getPathname();

        $strategy->execute($files, $processor, canParallelize: true);
    }

    #[Test]
    public function itDefaultsToFourWorkers(): void
    {
        $strategy = $this->createStrategy();

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
    public function itRefusesAnExplicitRequestWhenProcOpenIsDisabled(): void
    {
        $run = $this->withoutProcOpen(true);
        self::assertSame(3, $run['code']);
        self::assertStringContainsString('proc_open', $run['message']);
        self::assertStringContainsString('--workers=2', $run['message']);
        self::assertStringContainsString('--workers=0', $run['message']);
        self::assertSame([], $run['warnings']);
    }

    #[Test]
    public function itWarnsOnceAndProfilesEachFileForAnUnavailableAutomaticRequest(): void
    {
        $run = $this->withoutProcOpen(false);
        self::assertSame(0, $run['code']);
        self::assertSame(20, $run['processed']);
        self::assertCount(1, $run['warnings']);
        self::assertStringContainsString('proc_open', $run['warnings'][0]);
        self::assertSame(20, $run['profile']['collection.file']['count']);
    }

    /** @return array{code: int, message: string, processed: int, warnings: list<string>, profile: array<string, array{count: int}>} */
    private function withoutProcOpen(bool $explicit): array
    {
        $script = <<<'PHP'
require $argv[1];
$profiler = new \Qualimetrix\Infrastructure\Profiler\ProfileSession();
$profiler->enable();
$logger = new class extends \Psr\Log\AbstractLogger {
    public array $warnings = [];
    public function log($level, string|\Stringable $message, array $context = []): void {
        if ($level === 'warning') $this->warnings[] = (string) $message;
    }
};
$factory = new \Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory(new \Qualimetrix\Analysis\Evidence\Cohesion\Runtime\LcomCollectionConfigurationStore(),new \Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDocumentForms(),\Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor::class,[\Qualimetrix\Analysis\Evidence\Size\LocCollector::class]);
$strategy = new \Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy($factory,new \Qualimetrix\Infrastructure\Parallel\Strategy\SequentialStrategy($profiler),$profiler,new \Qualimetrix\Infrastructure\Console\ErrorStream(),$logger);
$strategy->setWorkerRequest(new \Qualimetrix\Infrastructure\Parallel\WorkerRequest(2,$argv[3] === '1' ? '--workers=2' : null));
$strategy->setProjectRoot(\Qualimetrix\Core\Path\AbsolutePath::fromString($argv[2]));
$strategy->setMinFilesForParallel(1);
$files = array_fill(0,20,new \SplFileInfo($argv[2] . '/file.php'));
$processed = 0;
try {
    $strategy->execute($files,static function() use (&$processed): int { return ++$processed; });
    $code = 0;$message = '';
} catch (\Qualimetrix\Infrastructure\Parallel\WorkerStartFailure $failure) {
    $code = 3;$message = $failure->getMessage();
}
echo json_encode(['code'=>$code,'message'=>$message,'processed'=>$processed,'warnings'=>$logger->warnings,'profile'=>$profiler->summary()->spans],JSON_THROW_ON_ERROR);
PHP;
        $process = new \Symfony\Component\Process\Process([\PHP_BINARY, '-d', 'disable_functions=proc_open', '-r', $script, \dirname(__DIR__, 5) . '/vendor/autoload.php', $this->tempDir, $explicit ? '1' : '0']);
        $process->mustRun();
        return json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
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

    /**
     * @param list<class-string<\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface>> $collectorClasses
     * @param list<class-string<\Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface>> $derivedCollectorClasses
     */
    private function createStrategy(
        array $collectorClasses = [LocCollector::class],
        LoggerInterface $logger = new NullLogger(),
        array $derivedCollectorClasses = [],
    ): AmphpParallelStrategy {
        return new AmphpParallelStrategy(
            new FileProcessingTaskFactory(
                new LcomCollectionConfigurationStore(),
                new RuleOptionDocumentForms(),
                DependencyVisitor::class,
                $collectorClasses,
                $derivedCollectorClasses,
            ),
            new \Qualimetrix\Infrastructure\Parallel\Strategy\SequentialStrategy(new \Qualimetrix\Infrastructure\Profiler\ProfileSession()),
            new \Qualimetrix\Infrastructure\Profiler\ProfileSession(),
            new \Qualimetrix\Infrastructure\Console\ErrorStream(),
            $logger,
        );
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
