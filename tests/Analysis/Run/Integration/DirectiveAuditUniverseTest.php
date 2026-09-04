<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluator;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Directive\Audit\DirectiveUsage;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectivePolicy;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use Qualimetrix\Tests\Analysis\Run\Support\Pipeline\TestPipelineBuilder;
use SplFileInfo;

/**
 * Which set of findings the run hands the directive-usage audit.
 *
 * The audit must be asked about what the rules **produced**, not about what
 * the report **published**. The two differ by the per-rule exclusion ledger and
 * the per-finding channel selection, and both are decisions about what a report
 * shows: judging an annotation by them makes a suppression that covered an
 * excluded finding look as though it silenced nothing.
 *
 * This project's own `src` cannot witness the difference — the channel reports
 * nothing there either way — so these two cases are the only witnesses, and the
 * pair is what makes them one. The first fails against the published universe;
 * the second proves the first is not passing because the fixture is inert.
 */
#[CoversClass(AnalysisPipeline::class)]
final class DirectiveAuditUniverseTest extends TestCase
{
    private const string FILE = 'src/Foo.php';

    private const string CHANNEL = 'coupling.cbo';

    #[Test]
    public function itJudgesASuppressionByTheFindingsRulesProducedRatherThanThosePublished(): void
    {
        $findings = self::runWith(produced: [self::excludedFinding()], published: []);

        self::assertSame([], self::unusedDirectiveMessages($findings));
    }

    /**
     * The control for the case above: with nothing produced at all, the very
     * same suppression is stale and the very same run says so. Without it, a
     * detector that never reports anything would pass the first case too.
     */
    #[Test]
    public function itStillReportsASuppressionThatCoveredNothingProduced(): void
    {
        $findings = self::runWith(produced: [], published: []);

        self::assertCount(1, self::unusedDirectiveMessages($findings));
    }

    /**
     * @param list<Finding> $produced
     * @param list<Finding> $published
     *
     * @return list<Finding>
     */
    private static function runWith(array $produced, array $published): array
    {
        $root = AbsolutePath::fromString(\dirname(__DIR__, 4));
        $relative = PathFactory::bestEffortRelative(__FILE__, $root);

        $policy = new InlineDirectivePolicy(new DirectiveUsage(
            self::productionUniverse(),
            new RuleSelector(new InMemoryRuleChannelRegistry()),
            new RuleOptionsRegistry(),
            self::productionUniverse(),
        ));

        $discovery = self::createStub(FileDiscoveryInterface::class);
        $discovery->method('discover')->willReturn([new SplFileInfo(__FILE__)]);

        $collection = self::createStub(CollectionOrchestratorInterface::class);
        $collection->method('collect')->willReturn(new CollectionPhaseOutput(
            [$relative],
            [],
            [self::FILE => [new Suppression(self::CHANNEL, 'reason', 3, SuppressionType::File)]],
        ));

        // Stands in for UnusedDirectiveRule, whose only job is to arm the
        // report as it runs. Without the arming the policy answers nothing and
        // both cases would pass for the wrong reason.
        $rules = self::createStub(RuleExecutionInterface::class);
        $rules->method('execute')->willReturnCallback(
            static function (AnalysisContext $context) use ($policy, $produced, $published): RuleExecutionResult {
                $policy->enableUsageReporting(Severity::Warning);

                return new RuleExecutionResult($produced, $published, new RuleExclusionStats(), LevelActivity::empty());
            },
        );
        $rules->method('allRules')->willReturn([]);

        // The pipeline asks the executor which of the late findings this run's
        // selection publishes. Nothing here selects anything, so the honest
        // stub answer is the argument — and it must be written down: a stub's
        // default empty array would drop the very finding both cases are about
        // and make the pair pass and fail for reasons that are not the subject.
        $rules->method('publishable')->willReturnArgument(0);

        $profiler = self::createStub(ProfilerInterface::class);
        $aggregation = self::createStub(MeasurementAggregationInterface::class);
        $aggregation->method('aggregate')->willReturn(new NamespaceTree([]));
        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturn([]);
        $graphBuilder = self::createStub(DependencyGraphBuilderInterface::class);
        $graphBuilder->method('build')->willReturn(AdjacencyGraphBuilder::empty());
        $repositoryFactory = self::createStub(MetricRepositoryFactoryInterface::class);
        $repositoryFactory->method('create')->willReturn(new InMemoryMetricRepository());

        $pipeline = TestPipelineBuilder::create()
            ->withDefaultDiscovery($discovery)
            ->withCollectionOrchestrator($collection)
            ->withRuleExecution($rules)
            ->withInlineDirectivePolicy($policy)
            ->withCircularDependencyPreparation(self::createStub(CircularDependencyPreparationInterface::class))
            ->withFileSetInspection(new FileSetInspectionComposite(
                [],
                new RuleSelectorProducerGate(new RuleSelector(new InMemoryRuleChannelRegistry())),
                $profiler,
            ))
            ->withMeasurementAggregation($aggregation)
            ->withComputedMetricEvaluation(new ComputedMetricEvaluator($catalog, $profiler))
            ->withGraphBuilder($graphBuilder)
            ->withRepositoryFactory($repositoryFactory)
            ->withProfiler($profiler)
            ->build();

        return $pipeline->analyze(new RunConfiguration([$root], [], $root, GeneratedFilePolicy::Include))->findings;
    }

    /**
     * A finding the per-rule exclusion ledger would drop: present in
     * `produced`, absent from `published`.
     */
    private static function excludedFinding(): Finding
    {
        $subject = MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString(self::FILE)));

        return new Finding(
            location: new Location(RelativePath::fromString(self::FILE), 10),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: self::CHANNEL,
            code: self::CHANNEL,
            message: 'CBO: 30 (threshold: 25)',
            severity: Severity::Warning,
        );
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private static function unusedDirectiveMessages(array $findings): array
    {
        $messages = [];
        foreach ($findings as $finding) {
            if ($finding->code === InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME) {
                $messages[] = $finding->message;
            }
        }

        return $messages;
    }

    private static ?RuleChannelSnapshotFactoryInterface $snapshotFactory = null;

    private static function productionUniverse(): ChannelUniverseInterface
    {
        if (self::$snapshotFactory === null) {
            $universe = (new ContainerFactory())->create()->get(ChannelUniverseInterface::class);
            \assert($universe instanceof RuleChannelSnapshotFactoryInterface);
            self::$snapshotFactory = $universe;
        }

        return self::$snapshotFactory->snapshot(new ResolvedComputedMetricDefinitions([]));
    }
}
