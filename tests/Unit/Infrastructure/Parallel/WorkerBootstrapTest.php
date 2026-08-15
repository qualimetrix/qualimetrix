<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Parallel;

use PhpParser\NodeVisitorAbstract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityIndexCollector;
use Qualimetrix\Analysis\Evidence\Size\LocCollector;
use Qualimetrix\Analysis\Policy\Inline\Contract\SourceControlExtractorInterface;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessorInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Ast\CachedFileParser;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use Qualimetrix\Infrastructure\Parallel\WorkerBootstrap;
use ReflectionClass;
use RuntimeException;
use stdClass;

#[CoversClass(WorkerBootstrap::class)]
final class WorkerBootstrapTest extends TestCase
{
    private const string TRAVERSAL_PARTICIPANT_CLASS = 'Qualimetrix\\Analysis\\Evidence\\DependencyModel\\Extraction\\DependencyVisitor';

    private string $tempCacheDir;

    protected function setUp(): void
    {
        WorkerBootstrap::reset();

        // Create temporary directory for cache
        $this->tempCacheDir = sys_get_temp_dir() . '/qmx-test-cache-' . uniqid();
        @mkdir($this->tempCacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        WorkerBootstrap::reset();

        // Remove temporary directory
        if (is_dir($this->tempCacheDir)) {
            $this->removeDirectory($this->tempCacheDir);
        }
    }

    #[Test]
    public function itCreatesFileProcessorOnFirstCall(): void
    {
        $processor = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        self::assertInstanceOf(FileProcessorInterface::class, $processor); // @phpstan-ignore staticMethod.alreadyNarrowedType
        $sourceControlExtractor = (new ReflectionClass($processor))->getProperty('sourceControlExtractor')->getValue($processor);
        self::assertInstanceOf(SourceControlExtractorInterface::class, $sourceControlExtractor);
        self::assertSame(
            'Qualimetrix\\Analysis\\Policy\\Inline\\Extraction\\SourceControlExtractor',
            $sourceControlExtractor::class,
        );
    }

    #[Test]
    public function itCachesProcessorForRepeatedCallsWithSameParameters(): void
    {
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class, LocCollector::class],
            derivedCollectorClasses: [],
            cacheDir: AbsolutePath::fromString($this->tempCacheDir),
        );

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class, LocCollector::class],
            derivedCollectorClasses: [],
            cacheDir: AbsolutePath::fromString($this->tempCacheDir),
        );

        self::assertSame($processor1, $processor2, 'Expected same processor instance for identical parameters');
    }

    #[Test]
    public function itCreatesNewProcessorWhenProjectRootChanges(): void
    {
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project-1'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project-2'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        self::assertNotSame($processor1, $processor2, 'Expected different processor instances for different project roots');
    }

    #[Test]
    public function itCreatesNewProcessorWhenCollectorClassesChange(): void
    {
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class, LocCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        self::assertNotSame($processor1, $processor2, 'Expected different processor instances for different collectors');
    }

    #[Test]
    public function itCreatesNewProcessorWhenDerivedCollectorClassesChange(): void
    {
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [MaintainabilityIndexCollector::class],
            cacheDir: null,
        );

        self::assertNotSame($processor1, $processor2, 'Expected different processor instances for different derived collectors');
    }

    #[Test]
    public function itCreatesNewProcessorWhenCacheDirChanges(): void
    {
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: AbsolutePath::fromString($this->tempCacheDir),
        );

        self::assertNotSame($processor1, $processor2, 'Expected different processor instances for different cache directories');
    }

    #[Test]
    public function itCreatesCachedFileParserWhenCacheDirProvided(): void
    {
        $processor = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: AbsolutePath::fromString($this->tempCacheDir),
        );

        $parser = $this->getParserFromProcessor($processor);

        self::assertInstanceOf(CachedFileParser::class, $parser, 'Expected CachedFileParser when cacheDir is provided');
    }

    #[Test]
    public function itCreatesPlainFileParserWhenCacheDirIsNull(): void
    {
        $processor = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $parser = $this->getParserFromProcessor($processor);

        self::assertInstanceOf(PhpFileParser::class, $parser, 'Expected PhpFileParser when cacheDir is null');
    }

    #[Test]
    public function itResetsStaticState(): void
    {
        // Create processor and cache it
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        // Verify that static cache is populated
        self::assertNotNull($this->getStaticProcessor());
        self::assertNotNull($this->getStaticCacheKey());

        // Reset
        WorkerBootstrap::reset();

        // Verify that static cache is cleared
        self::assertNull($this->getStaticProcessor());
        self::assertNull($this->getStaticCacheKey());
    }

    #[Test]
    public function itCreatesNewProcessorAfterReset(): void
    {
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        WorkerBootstrap::reset();

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        self::assertNotSame($processor1, $processor2, 'Expected different processor instances after reset()');
    }

    #[Test]
    public function itHandlesEmptyCollectorLists(): void
    {
        $processor = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        self::assertInstanceOf(FileProcessorInterface::class, $processor); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function itHandlesMultipleCollectors(): void
    {
        $processor = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [
                CyclomaticComplexityCollector::class,
                LocCollector::class,
            ],
            derivedCollectorClasses: [
                MaintainabilityIndexCollector::class,
            ],
            cacheDir: null,
        );

        self::assertInstanceOf(FileProcessorInterface::class, $processor); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function itCachesProcessorEvenWithComplexConfiguration(): void
    {
        $collectorClasses = [
            CyclomaticComplexityCollector::class,
            LocCollector::class,
        ];
        $derivedCollectorClasses = [
            MaintainabilityIndexCollector::class,
        ];

        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: $collectorClasses,
            derivedCollectorClasses: $derivedCollectorClasses,
            cacheDir: AbsolutePath::fromString($this->tempCacheDir),
        );

        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: $collectorClasses,
            derivedCollectorClasses: $derivedCollectorClasses,
            cacheDir: AbsolutePath::fromString($this->tempCacheDir),
        );

        self::assertSame($processor1, $processor2, 'Expected same processor instance for complex configuration');
    }

    #[Test]
    public function itTreatsCollectorOrderAsIrrelevantInCacheKey(): void
    {
        // The same collector set in a different order must produce the same
        // cache key — DI tag iteration order is deterministic within a process
        // but the cache key should not depend on registration order across
        // processes.
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [CyclomaticComplexityCollector::class, LocCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $cacheKey1 = $this->getStaticCacheKey();

        WorkerBootstrap::reset();

        WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            collectorClasses: [LocCollector::class, CyclomaticComplexityCollector::class],
            derivedCollectorClasses: [],
            cacheDir: null,
        );

        $cacheKey2 = $this->getStaticCacheKey();

        self::assertSame($cacheKey1, $cacheKey2, 'Cache key must be insensitive to collector order — sets are equal');
    }

    #[Test]
    public function itReconstructsTheConfiguredDependencyTraversalParticipantWithoutImportingItsImplementation(): void
    {
        $processor = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: TestDependencyTraversalParticipant::class,
            collectorClasses: [],
        );

        $processorReflection = new ReflectionClass($processor);
        $composite = $processorReflection->getProperty('collector')->getValue($processor);
        $compositeReflection = new ReflectionClass($composite);

        self::assertInstanceOf(
            TestDependencyTraversalParticipant::class,
            $compositeReflection->getProperty('dependencyTraversalParticipant')->getValue($composite),
        );
    }

    #[Test]
    public function itChangesTheCacheKeyWhenTheTraversalParticipantClassChanges(): void
    {
        $processor1 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: TestDependencyTraversalParticipant::class,
            collectorClasses: [],
        );
        $processor2 = WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: AlternateDependencyTraversalParticipant::class,
            collectorClasses: [],
        );

        self::assertNotSame($processor1, $processor2);
    }

    #[Test]
    public function itRejectsAMissingDependencyTraversalParticipantClass(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WorkerBootstrap: dependency traversal participant class must not be empty.');

        WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: '',
            collectorClasses: [],
        );
    }

    #[Test]
    public function itRejectsAClassThatDoesNotImplementDependencyTraversalParticipantInterface(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(\sprintf(
            "WorkerBootstrap: dependency traversal participant class '%s' must implement %s.",
            stdClass::class,
            DependencyTraversalParticipantInterface::class,
        ));

        WorkerBootstrap::getFileProcessor(
            projectRoot: AbsolutePath::fromString('/tmp/test-project'),
            dependencyTraversalParticipantClass: stdClass::class,
            collectorClasses: [],
        );
    }

    /**
     * Extracts parser from FileProcessor via reflection.
     */
    private function getParserFromProcessor(FileProcessorInterface $processor): object
    {
        $reflection = new ReflectionClass($processor);
        $property = $reflection->getProperty('parser');

        return $property->getValue($processor);
    }

    /**
     * Gets the value of the static $processor property via reflection.
     */
    private function getStaticProcessor(): ?FileProcessorInterface
    {
        return $this->getStaticProperty('processor');
    }

    /**
     * Gets the value of the static $cacheKey property via reflection.
     */
    private function getStaticCacheKey(): ?string
    {
        return $this->getStaticProperty('cacheKey');
    }

    /**
     * Gets the value of a static property via reflection.
     */
    private function getStaticProperty(string $propertyName): mixed
    {
        $reflection = new ReflectionClass(WorkerBootstrap::class);
        $property = $reflection->getProperty($propertyName);

        return $property->getValue();
    }

    /**
     * Recursively removes a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff((scandir($dir) !== false ? scandir($dir) : []), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

class TestDependencyTraversalParticipant extends NodeVisitorAbstract implements DependencyTraversalParticipantInterface
{
    public function beginFile(RelativePath $file): void {}

    /** @return list<Dependency> */
    public function dependencies(): array
    {
        return [];
    }
}

final class AlternateDependencyTraversalParticipant extends TestDependencyTraversalParticipant {}
