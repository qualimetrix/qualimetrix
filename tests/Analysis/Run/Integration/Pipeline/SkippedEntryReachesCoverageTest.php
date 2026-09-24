<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Integration\Pipeline;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyAnalysis;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyDetector;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluator;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MeasurementAggregationService;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkipReportingDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Infrastructure\Profiler\ProfileSession;
use Qualimetrix\Tests\Analysis\Run\Support\Pipeline\TestPipelineBuilder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The last leg: what discovery refused has to survive the pipeline's own
 * invariant, which insists that every discovered path carry exactly one
 * terminal state. A skip that reaches coverage without being counted as
 * discovered would fail that check; a skip that never reaches it leaves the
 * run reporting completeness over a tree it did not read.
 */
#[CoversClass(AnalysisPipeline::class)]
final class SkippedEntryReachesCoverageTest extends TestCase
{
    private string $root;
    private ProfileSession $profiler;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-pipeline-skip-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0755, true);
        file_put_contents($this->root . '/src/Analyzed.php', '<?php class Analyzed {}');
        $this->profiler = new ProfileSession();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    #[Test]
    public function itGivesASkippedEntryATerminalStateAndMarksTheRunIncomplete(): void
    {
        $result = $this->analyze([
            new SkippedEntry(
                AbsolutePath::fromString($this->root . '/src/linked'),
                AnalysisFailureKind::DirectorySymlink,
                'Symbolic link to a directory is not traversed',
            ),
        ]);

        self::assertFalse($result->coverage->isComplete());
        self::assertSame(2, $result->coverage->discoveredFiles());
        self::assertSame(1, $result->coverage->analyzedFilesCount());
        self::assertSame(
            [['src/linked', AnalysisFailureKind::DirectorySymlink]],
            array_map(
                static fn($failure): array => [$failure->path->value(), $failure->kind],
                $result->coverage->failures,
            ),
        );
    }

    #[Test]
    public function itLeavesACleanTreeComplete(): void
    {
        $result = $this->analyze([]);

        self::assertTrue($result->coverage->isComplete());
        self::assertSame(1, $result->coverage->discoveredFiles());
    }

    /** @param list<SkippedEntry> $skips */
    private function analyze(array $skips): AnalysisResult
    {
        $analyzed = new SplFileInfo($this->root . '/src/Analyzed.php');

        $discovery = new class ($analyzed, $skips) implements FileDiscoveryInterface, SkipReportingDiscoveryInterface {
            /** @param list<SkippedEntry> $skips */
            public function __construct(
                private readonly SplFileInfo $file,
                private readonly array $skips,
            ) {}

            /** @return iterable<AbsolutePath, SplFileInfo> */
            public function discover(AbsolutePath|array $paths): iterable
            {
                yield AbsolutePath::fromString($this->file->getPathname()) => $this->file;
            }

            public function skippedEntries(): array
            {
                return $this->skips;
            }
        };

        $root = AbsolutePath::fromString($this->root);
        $orchestrator = self::createStub(CollectionOrchestratorInterface::class);
        $orchestrator->method('collect')->willReturnCallback(
            static fn(array $files): CollectionPhaseOutput => new CollectionPhaseOutput(
                [PathFactory::bestEffortRelative($files[0]->getPathname(), $root)],
                [],
            ),
        );

        $ruleExecutor = self::createStub(RuleExecutionInterface::class);
        $ruleExecutor->method('execute')->willReturn(
            new RuleExecutionResult([], [], new RuleExclusionStats(), LevelActivity::empty()),
        );
        $ruleExecutor->method('publishable')->willReturnCallback(
            static fn(array $findings): array => $findings,
        );

        $pipeline = TestPipelineBuilder::create()
            ->withDefaultDiscovery($discovery)
            ->withCollectionOrchestrator($orchestrator)
            ->withRuleExecution($ruleExecutor)
            ->withMeasurementAggregation(new MeasurementAggregationService(
                [],
                new CompositeCollector([], new DeclarationRegistrarFactory()),
                $this->profiler,
            ))
            ->withComputedMetricEvaluation(self::createStub(ComputedMetricEvaluator::class))
            ->withCircularDependencyPreparation(new CircularDependencyAnalysis(new CircularDependencyDetector()))
            ->withFileSetInspection(new FileSetInspectionComposite(
                [],
                new RuleSelectorProducerGate(new RuleSelector(new InMemoryRuleChannelRegistry())),
                $this->profiler,
            ))
            ->withProfiler($this->profiler)
            ->build();

        return $pipeline->analyze(new RunConfiguration(
            paths: [AbsolutePath::fromString($this->root . '/src')],
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            coversProjectScope: true,
            authoredPathExcludes: [],
        ));
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
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
