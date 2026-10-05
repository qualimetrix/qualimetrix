<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryAudit;
use Qualimetrix\Analysis\Policy\Inline\Contract\DirectiveObservations;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Run\Contract\Pipeline\MeasuredRunResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\MeasuredFindingSet;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeResult;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

/**
 * The seam of ADR 0017: paths in, the set a baseline measures out, with no
 * `InputInterface` anywhere — which is what lets a command that does not
 * declare `check`'s options measure exactly what `check` measures.
 */
#[CoversClass(MeasuredFindingSet::class)]
final class MeasuredFindingSetTest extends TestCase
{
    #[Test]
    public function itLeavesOutWhatTheSourcesOwnIgnoreTagsRemoved(): void
    {
        $ignored = self::finding('src/Legacy/Service.php', 'App\\Legacy', 'Service');
        $reported = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');

        $set = $this->createSet(
            [$ignored, $reported],
            [
                'src/Legacy/Service.php' => [
                    new Suppression(rule: '*', reason: 'Reviewed', line: 1, type: SuppressionType::File, position: 0),
                ],
            ],
        );

        self::assertSame([$reported], $set->forRun($this->configuration()));
    }

    #[Test]
    public function itLeavesOutWhatTheConfiguredExclusionsRemoved(): void
    {
        $excludedByPath = self::finding('generated/Proxy.php', 'App\\Generated', 'Proxy');
        $excludedByNamespace = self::finding('src/Vendor/Thing.php', 'App\\Vendor', 'Thing');
        $reported = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');

        $options = new FindingProjectionOptions(
            suppressPaths: [self::path('generated')],
            suppressNamespaces: [self::namespace('App\\Vendor')],
        );
        $set = $this->createSet(
            [$excludedByPath, $excludedByNamespace, $reported],
        );

        self::assertSame([$reported], $set->forRun(
            $this->configuration(),
            options: $options,
        ));
    }

    /**
     * The set is what configuration and the source say, and nothing else.
     * A narrowing that exists only as a `check` flag is not part of it —
     * otherwise every baseline command would have to replicate `check`'s
     * option surface to agree with it, and the four ADR 0017 commands accept none
     * of those flags.
     */
    #[Test]
    public function itDefinesTheSetFromConfigurationAloneAndNotFromCheckFlags(): void
    {
        $onlyExcludedByAFlag = self::finding('vendor/library/SomeClass.php', 'App\\Vendor', 'SomeClass');

        $set = $this->createSet([$onlyExcludedByAFlag]);

        self::assertSame(
            [$onlyExcludedByAFlag],
            $set->forRun($this->configuration()),
        );

        // The same narrowing, supplied as a flag, does remove it from the
        // run — it just does not redefine what the baseline measures.
        self::assertSame([], $set->forRun(
            $this->configuration(),
            options: new FindingProjectionOptions(suppressPaths: [self::path('vendor')]),
        ));
    }

    #[Test]
    public function itListsOnlyStagesThatDefineTheMeasuredSet(): void
    {
        $set = $this->createSet([]);

        foreach ([FindingFilterStage::Suppression, FindingFilterStage::PathExclusion, FindingFilterStage::NamespaceExclusion] as $stage) {
            self::assertTrue($stage->definesMeasuredSet());
        }
    }

    /**
     * `run()` must return the same measured set `forRun()` does —
     * the two are not two definitions, one is the other with the run kept —
     * and must expose the {@see AnalysisResult} the run itself produced,
     * not a rebuilt or partial one.
     */
    #[Test]
    public function itReturnsTheRunAlongsideTheSameMeasuredSetForPathsReturns(): void
    {
        $ignored = self::finding('src/Legacy/Service.php', 'App\\Legacy', 'Service');
        $reported = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');

        $set = $this->createSet(
            [$ignored, $reported],
            [
                'src/Legacy/Service.php' => [
                    new Suppression(rule: '*', reason: 'Reviewed', line: 1, type: SuppressionType::File, position: 0),
                ],
            ],
        );

        $run = $set->run($this->configuration());

        self::assertSame([$reported], $run->findings);
        self::assertSame([$ignored, $reported], $run->result->findings());
    }

    #[Test]
    public function itPassesTheCapturedRunConfigurationToAnalysisOnce(): void
    {
        $root = AbsolutePath::fromString(sys_get_temp_dir());
        $configuration = new RunConfiguration(
            pathExcludes: array_map(
                static fn(string $value): PathPattern => new PathPattern(new SelectorDefinition(SelectorKind::Subtree, $value)),
                ['vendor', 'node_modules', '.git', 'generated'],
            ),
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $root, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [$root], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        );
        $analyzer = self::createMock(AnalysisPipelineInterface::class);
        $analyzer->expects(self::once())
            ->method('analyze')
            ->with(self::identicalTo($configuration))
            ->willReturn(self::analysisResult([]));

        $this->createSet([], analyzer: $analyzer)
            ->run($configuration);
    }

    /**
     * @param list<Finding> $findings
     * @param array<string, list<Suppression>> $suppressions
     */
    private function createSet(
        array $findings,
        array $suppressions = [],
        ?AnalysisPipelineInterface $analyzer = null,
    ): MeasuredFindingSet {
        if ($analyzer === null) {
            $analyzer = self::createStub(AnalysisPipelineInterface::class);
            $analyzer->method('analyze')->willReturn(self::analysisResult($findings, $suppressions));
        }

        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $projector = new FindingProjector(
            new SuppressionFilter(),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            $declarations,
            new class implements GitScopeQueryInterface {
                public function resolve(GitScopeRequest $request): GitScopeResult
                {
                    return new GitScopeResult([], []);
                }
            },
            unusedEntryAudit: new UnusedEntryAudit((function () {
                $execution = self::createStub(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class);
                $execution->method('publishable')->willReturnCallback(static fn(array $findings): array => $findings);

                return $execution;
            })()),
        );

        return new MeasuredFindingSet($analyzer, $projector);
    }

    /**
     * @param list<Finding> $findings
     * @param array<string, list<Suppression>> $suppressions
     */
    private static function analysisResult(array $findings, array $suppressions = []): AnalysisResult
    {
        return AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: self::createStub(MetricRepositoryInterface::class),
                coverage: new AnalysisCoverage([RelativePath::fromString('Fixture.php')], [], []),
                namespaceTree: null,
                projectScope: null,
                duration: 0.1,
            ),
            directives: new DirectiveObservations(
                suppressions: $suppressions,
                thresholdOverrides: [],
            ),
            ruleExecution: null,
            latePublished: $findings,
        );
    }

    private function configuration(): RunConfiguration
    {
        $root = AbsolutePath::fromString(sys_get_temp_dir());

        return new RunConfiguration(
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $root, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [$root], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        );
    }

    private static function finding(string $file, string $namespace, string $class): Finding
    {
        $path = RelativePath::fromString($file);
        $symbol = SymbolPath::forClass($namespace, $class);

        return new Finding(
            location: new Location($path, 10),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, $path, DeclarationOrdinal::fromRank(0))),
            symbolPath: $symbol,
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: 'CCN too high',
            severity: Severity::Warning,
            metricValue: 25,
        );
    }

    private static function path(string $value): PathPattern
    {
        return new PathPattern(SelectorDefinition::fromKindAndValue(SelectorKind::Subtree->value, $value));
    }

    private static function namespace(string $value): NamespacePattern
    {
        return new NamespacePattern(SelectorDefinition::fromKindAndValue(SelectorKind::Subtree->value, $value));
    }
}
