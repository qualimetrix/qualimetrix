<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Integration\Pipeline;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyAnalysis;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyDetector;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluationSummary;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluator;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MeasurementAggregationService;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\DiscoveredProjectFiles;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Run\Discovery\ScopeFacts;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Infrastructure\Profiler\ProfileSession;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
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

        self::assertFalse($result->measured->coverage->isComplete());
        self::assertSame(2, $result->measured->coverage->discoveredFiles());
        self::assertSame(1, $result->measured->coverage->analyzedFilesCount());
        self::assertSame(
            [['src/linked', AnalysisFailureKind::DirectorySymlink]],
            array_map(
                static fn($failure): array => [$failure->path->value(), $failure->kind],
                $result->measured->coverage->failures,
            ),
        );
    }

    #[Test]
    public function itLeavesACleanTreeComplete(): void
    {
        $result = $this->analyze([]);

        self::assertTrue($result->measured->coverage->isComplete());
        self::assertSame(1, $result->measured->coverage->discoveredFiles());
    }

    /** @param list<SkippedEntry> $skips */
    private function analyze(array $skips): AnalysisResult
    {
        $analyzed = new SplFileInfo($this->root . '/src/Analyzed.php');

        $discovery = new class ($analyzed, $skips) implements ProjectFilesInterface {
            /** @param list<SkippedEntry> $skips */
            public function __construct(private readonly SplFileInfo $file, private readonly array $skips) {}

            public function discover(RunConfiguration $configuration): DiscoveredProjectFiles
            {
                return new DiscoveredProjectFiles(
                    [$this->file],
                    [],
                    [],
                    $this->skips,
                    [],
                    new ScopeFacts([], [], [], false),
                    1,
                );
            }
        };

        $root = AbsolutePath::fromString($this->root);
        $orchestrator = self::createStub(CollectionOrchestratorInterface::class);
        $orchestrator->method('collect')->willReturnCallback(
            static fn(array $files): CollectionPhaseOutput => new CollectionPhaseOutput(
                [PathFactory::published(AbsolutePath::fromString($files[0]->getPathname()), $root)],
                [],
                [],
            ),
        );

        $ruleExecutor = self::createStub(RuleExecutionInterface::class);
        $ruleExecutor->method('execute')->willReturn(
            new RuleExecutionResult([], [], new RuleExclusionStats(), LevelActivity::empty()),
        );
        $ruleExecutor->method('publication')->willReturn(new ChannelPublication(new RuleEnablement([], null)));
        $ruleExecutor->method('publishable')->willReturnCallback(
            static fn(array $findings): array => $findings,
        );

        $configuration = new RuleOptionsRegistry();
        $configuration->replace(ResolvedOptionsFixture::ready(FindingConfiguration::none(), []));

        $computed = self::createStub(ComputedMetricEvaluator::class);
        $computed->method('evaluate')->willReturn(new ComputedMetricEvaluationSummary());

        $pipeline = TestPipelineBuilder::create()
            ->withProjectFiles($discovery)
            ->withCollectionOrchestrator($orchestrator)
            ->withRuleExecution($ruleExecutor)
            ->withRuleConfiguration($configuration)
            ->withMeasurementAggregation(new MeasurementAggregationService(
                [],
                new CompositeCollector([], new DeclarationRegistrarFactory()),
                $this->profiler,
            ))
            ->withComputedMetricEvaluation($computed)
            ->withCircularDependencyPreparation(new CircularDependencyAnalysis(new CircularDependencyDetector()))
            ->withFileSetInspection(new FileSetInspectionComposite(
                [],
                new RuleSelectorProducerGate($configuration),
                $this->profiler,
            ))
            ->withProfiler($this->profiler)
            ->build();

        return $pipeline->analyze(new RunConfiguration(
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $root, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [AbsolutePath::fromString($this->root . '/src')], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
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
