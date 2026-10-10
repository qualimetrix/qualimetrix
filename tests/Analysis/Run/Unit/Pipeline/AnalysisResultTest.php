<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationBinding;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationReach;
use Qualimetrix\Analysis\Policy\Inline\Contract\DirectiveObservations;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Run\Contract\Pipeline\MeasuredRunResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(AnalysisResult::class)]
#[CoversClass(MeasuredRunResult::class)]
#[CoversClass(DirectiveObservations::class)]
final class AnalysisResultTest extends TestCase
{
    #[Test]
    public function itMergesComputedAbsenceBesideMeasuredResultsAndPreservesEmptyIdentity(): void
    {
        $base = $this->createResult([], filesAnalyzed: 0);
        $subjects = array_map(static fn(string $file): MetricSubject => MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forClass('App', 'Duplicate'),
            RelativePath::fromString($file),
            DeclarationOrdinal::fromRank(0),
        )), ['z.php', 'a.php', 'b.php', 'c.php']);
        $left = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluationSummary([
            new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricValueAbsence('computed.x', SymbolLevel::Class_, 2, 1, ['z', 'a'], [$subjects[0], $subjects[1]]),
        ]);
        $right = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluationSummary([
            new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricValueAbsence('computed.x', SymbolLevel::Class_, 3, 2, ['b', 'a'], [$subjects[2], $subjects[3]]),
        ]);
        $leftResult = AnalysisResult::fromRun($base->measured, $base->directives, null, [], $left);
        $rightResult = AnalysisResult::fromRun($base->measured, $base->directives, null, [], $right);
        self::assertSame($left, $leftResult->computedMetricEvaluation);
        self::assertSame([], $base->computedMetricEvaluation->absences);
        self::assertSame($left, $leftResult->merge($base)->computedMetricEvaluation);
        self::assertSame($left, $base->merge($leftResult)->computedMetricEvaluation);
        $merged = $leftResult->merge($rightResult);
        self::assertSame([], $merged->findings());
        $absence = $merged->computedMetricEvaluation->absences[0];
        self::assertSame(5, $absence->missingKeysCount);
        self::assertSame(3, $absence->noValueCount);
        self::assertSame(['a', 'b', 'z'], $absence->missingKeys);
        self::assertSame(
            ['declaration:class:App\Duplicate@a.php', 'declaration:class:App\Duplicate@b.php', 'declaration:class:App\Duplicate@c.php'],
            array_map(static fn(MetricSubject $subject): string => $subject->toCanonical(), $absence->subjects),
        );
        self::assertSame($merged->computedMetricEvaluation->absences[0]->subjects, $rightResult->merge($leftResult)->computedMetricEvaluation->absences[0]->subjects);
    }

    #[Test]
    public function itHasErrorsWhenErrorFindingPresent(): void
    {
        $result = $this->createResult([
            $this->createFinding(Severity::Error),
        ]);

        self::assertTrue($result->hasErrors());
    }

    #[Test]
    public function itHasNoErrorsWhenOnlyWarnings(): void
    {
        $result = $this->createResult([
            $this->createFinding(Severity::Warning),
        ]);

        self::assertFalse($result->hasErrors());
    }

    #[Test]
    public function itHasNoErrorsWhenEmpty(): void
    {
        $result = $this->createResult([]);

        self::assertFalse($result->hasErrors());
    }

    #[Test]
    public function itHasWarningsWhenWarningFindingPresent(): void
    {
        $result = $this->createResult([
            $this->createFinding(Severity::Warning),
        ]);

        self::assertTrue($result->hasWarnings());
    }

    #[Test]
    public function itHasNoWarningsWhenOnlyErrors(): void
    {
        $result = $this->createResult([
            $this->createFinding(Severity::Error),
        ]);

        self::assertFalse($result->hasWarnings());
    }

    #[Test]
    public function itHasNoWarningsWhenEmpty(): void
    {
        $result = $this->createResult([]);

        self::assertFalse($result->hasWarnings());
    }

    #[Test]
    public function itMergesFindings(): void
    {
        $result1 = $this->createResult([
            $this->createFinding(Severity::Error, 'file1.php'),
        ], filesAnalyzed: 5, filesSkipped: 1, duration: 1.5);

        $result2 = $this->createResult([
            $this->createFinding(Severity::Warning, 'file2.php'),
        ], filesAnalyzed: 3, filesSkipped: 2, duration: 2.0, coveragePrefix: 'other');

        $merged = $result1->merge($result2);

        self::assertCount(2, $merged->findings());
        self::assertSame(8, $merged->measured->coverage->analyzedFilesCount());
        self::assertSame(3, $merged->measured->coverage->skippedFilesCount());
        self::assertSame(2.0, $merged->measured->duration);
    }

    #[Test]
    public function itMergesMetricsFromBothRepositories(): void
    {
        $repo1 = new InMemoryMetricRepository();
        $metrics1 = (new MetricBag())->with('complexity.ccn', 5);
        $repo1->addCallable(new CallableWithMetrics(
            DeclarationPath::of(SymbolPath::forMethod('App', 'ServiceA', 'method1'), RelativePath::fromString('ServiceA.php'), DeclarationOrdinal::fromRank(0)),
            100,
            CallableKind::Method,
            null,
            null,
            DeclarationPath::of(SymbolPath::forClass('App', 'ServiceA'), DeclarationPath::of(SymbolPath::forMethod('App', 'ServiceA', 'method1'), RelativePath::fromString('ServiceA.php'), DeclarationOrdinal::fromRank(0))->file, DeclarationOrdinal::fromRank(0)),
            $metrics1,
        ));

        $repo2 = new InMemoryMetricRepository();
        $metrics2 = (new MetricBag())->with('complexity.ccn', 10);
        $repo2->addCallable(new CallableWithMetrics(
            DeclarationPath::of(SymbolPath::forMethod('App', 'ServiceB', 'method2'), RelativePath::fromString('ServiceB.php'), DeclarationOrdinal::fromRank(0)),
            200,
            CallableKind::Method,
            null,
            null,
            DeclarationPath::of(SymbolPath::forClass('App', 'ServiceB'), DeclarationPath::of(SymbolPath::forMethod('App', 'ServiceB', 'method2'), RelativePath::fromString('ServiceB.php'), DeclarationOrdinal::fromRank(0))->file, DeclarationOrdinal::fromRank(0)),
            $metrics2,
        ));

        $result1 = AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: $repo1,
                coverage: self::coverage(5),
                namespaceTree: null,
                projectScope: null,
                duration: 1.0,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [], []),
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: [],
            ),
            ruleExecution: null,
            latePublished: [],
        );
        $result2 = AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: $repo2,
                coverage: self::coverage(3, prefix: 'other'),
                namespaceTree: null,
                projectScope: null,
                duration: 2.0,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [], []),
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: [],
            ),
            ruleExecution: null,
            latePublished: [],
        );

        $merged = $result1->merge($result2);

        $subjectA = MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forMethod('App', 'ServiceA', 'method1'),
            RelativePath::fromString('ServiceA.php'),
            DeclarationOrdinal::fromRank(0),
        ));
        $subjectB = MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forMethod('App', 'ServiceB', 'method2'),
            RelativePath::fromString('ServiceB.php'),
            DeclarationOrdinal::fromRank(0),
        ));
        self::assertInstanceOf(InMemoryMetricRepository::class, $merged->measured->repository);
        self::assertTrue($merged->measured->repository->hasSubject($subjectA));
        self::assertTrue($merged->measured->repository->hasSubject($subjectB));

        self::assertSame(
            5,
            $merged->measured->repository->getSubject($subjectA)->get('complexity.ccn'),
        );
        self::assertSame(
            10,
            $merged->measured->repository->getSubject($subjectB)->get('complexity.ccn'),
        );
    }

    #[Test]
    public function itKeepsTheLeftRepositoryWhenTheRightRepositoryCannotMerge(): void
    {
        $left = self::createMock(MetricRepositoryInterface::class);
        $right = self::createStub(MetricRepositoryInterface::class);
        $left->expects(self::once())->method('mergedWith')->with($right)->willReturn(null);

        $merged = (AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: $left,
                coverage: self::coverage(1),
                namespaceTree: null,
                projectScope: null,
                duration: 0.1,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [], []),
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: [],
            ),
            ruleExecution: null,
            latePublished: [],
        ))
            ->merge(AnalysisResult::fromRun(
                measured: new MeasuredRunResult(
                    repository: $right,
                    coverage: self::coverage(1, prefix: 'right'),
                    namespaceTree: null,
                    projectScope: null,
                    duration: 0.1,
                    subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [], []),
                ),
                directives: new DirectiveObservations(
                    suppressions: [],
                    thresholdOverrides: [],
                ),
                ruleExecution: null,
                latePublished: [],
            ));

        self::assertSame($left, $merged->measured->repository);
    }

    #[Test]
    public function itSortsFindingsByFileAndLine(): void
    {
        $v1 = $this->createFinding(Severity::Error, 'b.php', 20);
        $v2 = $this->createFinding(Severity::Error, 'a.php', 10);
        $v3 = $this->createFinding(Severity::Warning, 'a.php', 5);
        $v4 = $this->createFinding(Severity::Warning, 'b.php', 10);

        $result = $this->createResult([$v1, $v2, $v3, $v4]);

        $sorted = $result->getSortedFindings();

        self::assertSame('a.php', $sorted[0]->location->pathString());
        self::assertSame(5, $sorted[0]->location->line);

        self::assertSame('a.php', $sorted[1]->location->pathString());
        self::assertSame(10, $sorted[1]->location->line);

        self::assertSame('b.php', $sorted[2]->location->pathString());
        self::assertSame(10, $sorted[2]->location->line);

        self::assertSame('b.php', $sorted[3]->location->pathString());
        self::assertSame(20, $sorted[3]->location->line);
    }

    #[Test]
    public function itSortsFindingsWithNullLines(): void
    {
        $v1 = $this->createFinding(Severity::Error, 'a.php', 10);
        $v2 = $this->createFinding(Severity::Error, 'a.php', null);

        $result = $this->createResult([$v1, $v2]);

        $sorted = $result->getSortedFindings();

        self::assertNull($sorted[0]->location->line);
        self::assertSame(10, $sorted[1]->location->line);
    }

    #[Test]
    public function itCountsFindingsBySeverity(): void
    {
        $result = $this->createResult([
            $this->createFinding(Severity::Error),
            $this->createFinding(Severity::Error),
            $this->createFinding(Severity::Warning),
            $this->createFinding(Severity::Warning),
            $this->createFinding(Severity::Warning),
        ]);

        $counts = $result->getViolationCountBySeverity();

        self::assertSame(2, $counts['errors']);
        self::assertSame(3, $counts['warnings']);
    }

    #[Test]
    public function itMergesSuppressionsForOverlappingFiles(): void
    {
        $sharedSubject = MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'calculate'), RelativePath::fromString('shared.php'), DeclarationOrdinal::fromRank(0)));
        $suppression1 = new Suppression(
            'complexity',
            null,
            10,
            SuppressionType::Symbol,
            position: 0,
            binding: new DeclarationBinding($sharedSubject, ControlScope::Callable, DeclarationReach::whole(null, 'test')),
        );
        $suppression2 = new Suppression('size', null, 20, SuppressionType::NextLine, position: 0, silencedLine: 20 + 1);
        $suppression3 = new Suppression(
            'cohesion.lcom',
            null,
            30,
            SuppressionType::Symbol,
            position: 0,
            binding: new DeclarationBinding(MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'measure'), RelativePath::fromString('shared.php'), DeclarationOrdinal::fromRank(0))), ControlScope::Callable, DeclarationReach::whole(null, 'test')),
        );

        $result1 = AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: self::createStub(MetricRepositoryInterface::class),
                coverage: self::coverage(1),
                namespaceTree: null,
                projectScope: null,
                duration: 0.1,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [], []),
            ),
            directives: new DirectiveObservations(
                suppressions: ['shared.php' => [$suppression1], 'only1.php' => [$suppression2]],
                thresholdOverrides: [],
            ),
            ruleExecution: null,
            latePublished: [],
        );

        $result2 = AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: self::createStub(MetricRepositoryInterface::class),
                coverage: self::coverage(1, prefix: 'other'),
                namespaceTree: null,
                projectScope: null,
                duration: 0.1,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [], []),
            ),
            directives: new DirectiveObservations(
                suppressions: ['shared.php' => [$suppression3], 'only2.php' => [$suppression2]],
                thresholdOverrides: [],
            ),
            ruleExecution: null,
            latePublished: [],
        );

        $merged = $result1->merge($result2);

        // shared.php should have both suppressions combined, not overwritten
        self::assertCount(2, $merged->directives->suppressions['shared.php']);
        self::assertSame($suppression1, $merged->directives->suppressions['shared.php'][0]);
        self::assertSame($suppression3, $merged->directives->suppressions['shared.php'][1]);

        // Non-overlapping files preserved
        self::assertCount(1, $merged->directives->suppressions['only1.php']);
        self::assertCount(1, $merged->directives->suppressions['only2.php']);
    }

    #[Test]
    public function itMergesThresholdOverridesForOverlappingFiles(): void
    {
        $subject = MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'calculate'), RelativePath::fromString('shared.php'), DeclarationOrdinal::fromRank(0)));
        $override1 = new ThresholdOverride('complexity.ccn', 15, 25, 10, $subject, ControlScope::Callable);
        $override2 = new ThresholdOverride('coupling.cbo', 10, 20, 20, $subject, ControlScope::Callable);
        $override3 = new ThresholdOverride('size.method-count', 5, 10, 30, $subject, ControlScope::Callable);

        $result1 = AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: self::createStub(MetricRepositoryInterface::class),
                coverage: self::coverage(1),
                namespaceTree: null,
                projectScope: null,
                duration: 0.1,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [], []),
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: ['shared.php' => [$override1], 'only1.php' => [$override2]],
            ),
            ruleExecution: null,
            latePublished: [],
        );

        $result2 = AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: self::createStub(MetricRepositoryInterface::class),
                coverage: self::coverage(1, prefix: 'other'),
                namespaceTree: null,
                projectScope: null,
                duration: 0.1,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [], []),
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: ['shared.php' => [$override3], 'only2.php' => [$override2]],
            ),
            ruleExecution: null,
            latePublished: [],
        );

        $merged = $result1->merge($result2);

        self::assertCount(2, $merged->directives->thresholdOverrides['shared.php']);
        self::assertSame($override1, $merged->directives->thresholdOverrides['shared.php'][0]);
        self::assertSame($override3, $merged->directives->thresholdOverrides['shared.php'][1]);

        self::assertCount(1, $merged->directives->thresholdOverrides['only1.php']);
        self::assertCount(1, $merged->directives->thresholdOverrides['only2.php']);
    }

    /**
     * A silent "take the first side" would leave `$findings` as the union of
     * both runs while `$ruleExecution` answered for only one of them —
     * internally inconsistent in a way nothing downstream could detect.
     */
    #[Test]
    public function itMergesBothSidesOfRuleExecutionRatherThanKeepingOnlyOne(): void
    {
        $left = $this->createFinding(Severity::Warning);
        $right = $this->createFinding(Severity::Error);
        $leftLate = $this->createFinding(Severity::Info, 'left-late.php');
        $rightLate = $this->createFinding(Severity::Info, 'right-late.php');
        $leftTree = new NamespaceTree(['Left']);
        $rightTree = new NamespaceTree(['Right']);
        $root = AbsolutePath::fromString(sys_get_temp_dir());
        $universe = new ProjectScopeUniverse($root, true, [], [], [], true, []);
        $leftScope = new ProjectScopeMeasurement($universe, [$root], ProjectScopeState::Covered, [], new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement());
        $rightScope = new ProjectScopeMeasurement($universe, [$root], ProjectScopeState::Narrowed, [], new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement());

        $result1 = AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: self::createStub(MetricRepositoryInterface::class),
                coverage: self::coverage(1),
                namespaceTree: $leftTree,
                projectScope: $leftScope,
                duration: 0.1,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [], []),
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: [],
            ),
            ruleExecution: new RuleExecutionResult(
                produced: [$left],
                published: [$left],
                exclusions: new RuleExclusionStats(namespaceExclusionsByRule: ['rule1' => 1]),
                levelActivity: LevelActivity::empty(),
            ),
            latePublished: [$leftLate],
        );

        $result2 = AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: self::createStub(MetricRepositoryInterface::class),
                coverage: self::coverage(1, prefix: 'other'),
                namespaceTree: $rightTree,
                projectScope: $rightScope,
                duration: 0.1,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [], []),
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: [],
            ),
            ruleExecution: new RuleExecutionResult(
                produced: [$right],
                published: [$right],
                exclusions: new RuleExclusionStats(pathExclusionsByRule: ['rule2' => 2]),
                levelActivity: LevelActivity::empty(),
            ),
            latePublished: [$rightLate],
        );

        $merged = $result1->merge($result2);

        self::assertNotNull($merged->ruleExecution);
        self::assertSame([$left, $right], $merged->ruleExecution->produced);
        self::assertSame([$left, $right], $merged->ruleExecution->published);
        self::assertSame(['rule1' => 1], $merged->ruleExecution->exclusions->namespaceExclusionsByRule);
        self::assertSame(['rule2' => 2], $merged->ruleExecution->exclusions->pathExclusionsByRule);
        self::assertSame([$left, $leftLate, $right, $rightLate], $merged->findings());
        self::assertSame($leftTree, $merged->measured->namespaceTree);
        self::assertSame($leftScope, $merged->measured->projectScope);
        $result3 = $this->createResult([$left, $leftLate], coveragePrefix: 'third');
        $expected = [$left, $leftLate, $right, $rightLate, $left, $leftLate];
        self::assertSame($expected, $merged->merge($result3)->findings());
        self::assertSame($expected, $result1->merge($result2->merge($result3))->findings());
    }

    /**
     * Merging two activity records keeps what either side was able to do.
     *
     * Written because the other cases merge two empty records, where every
     * merge rule agrees: `||`, `&&`, and "take the left" are indistinguishable
     * on nothing. This is the only place the choice is observable.
     */
    #[Test]
    public function itKeepsALevelThatRanOnEitherSideOfTheMerge(): void
    {
        $left = LevelActivity::fromMap([
            'complexity.ccn' => ['callable' => true, 'class' => false],
            'coupling.cbo' => ['class' => false],
        ]);
        $right = LevelActivity::fromMap([
            'complexity.ccn' => ['callable' => false, 'class' => false],
            'coupling.cbo' => ['class' => true],
            'design.god-class' => ['class' => true],
        ]);

        $merged = (new RuleExecutionResult([], [], new RuleExclusionStats(), $left))
            ->merge(new RuleExecutionResult([], [], new RuleExclusionStats(), $right))
            ->levelActivity;

        self::assertTrue($merged->ran('complexity.ccn', SymbolLevel::Callable), 'true on the left survives');
        self::assertTrue($merged->ran('coupling.cbo', SymbolLevel::Class_), 'true on the right survives');
        self::assertFalse($merged->ran('complexity.ccn', SymbolLevel::Class_), 'false on both stays false');
        self::assertTrue($merged->declares('design.god-class', SymbolLevel::Class_), 'a producer only one side knew is kept');
    }

    #[Test]
    public function itKeepsTheOtherSidesRuleExecutionWhenOneSideHasNone(): void
    {
        $finding = $this->createFinding(Severity::Warning);
        $late = $this->createFinding(Severity::Info, 'late.php');
        $tree = new NamespaceTree(['App']);
        $root = AbsolutePath::fromString(sys_get_temp_dir());
        $universe = new ProjectScopeUniverse($root, true, [], [], [], true, []);
        $scope = new ProjectScopeMeasurement($universe, [$root], ProjectScopeState::Covered, [], new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement());
        $ruleExecution = new RuleExecutionResult(
            produced: [$finding],
            published: [$finding],
            exclusions: new RuleExclusionStats(),
            levelActivity: LevelActivity::empty(),
        );

        $withRuleExecution = AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: self::createStub(MetricRepositoryInterface::class),
                coverage: self::coverage(1),
                namespaceTree: null,
                projectScope: null,
                duration: 0.1,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [], []),
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: [],
            ),
            ruleExecution: $ruleExecution,
            latePublished: [],
        );

        $withoutRuleExecution = AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: self::createStub(MetricRepositoryInterface::class),
                coverage: self::coverage(1, prefix: 'other'),
                namespaceTree: $tree,
                projectScope: $scope,
                duration: 0.1,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [], []),
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: [],
            ),
            ruleExecution: null,
            latePublished: [$late],
        );

        self::assertSame($ruleExecution, $withRuleExecution->merge($withoutRuleExecution)->ruleExecution);
        self::assertSame($ruleExecution, $withoutRuleExecution->merge($withRuleExecution)->ruleExecution);
        self::assertSame([$finding, $late], $withRuleExecution->merge($withoutRuleExecution)->findings());
        self::assertSame([$late, $finding], $withoutRuleExecution->merge($withRuleExecution)->findings());
        self::assertSame($tree, $withRuleExecution->merge($withoutRuleExecution)->measured->namespaceTree);
        self::assertSame($scope, $withRuleExecution->merge($withoutRuleExecution)->measured->projectScope);
    }

    #[Test]
    public function itCountsZeroWhenNoFindings(): void
    {
        $result = $this->createResult([]);

        $counts = $result->getViolationCountBySeverity();

        self::assertSame(0, $counts['errors']);
        self::assertSame(0, $counts['warnings']);
    }

    /**
     * @param list<Finding> $findings
     */
    private function createResult(
        array $findings,
        int $filesAnalyzed = 1,
        int $filesSkipped = 0,
        float $duration = 0.1,
        string $coveragePrefix = 'result',
    ): AnalysisResult {
        return AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: self::createStub(MetricRepositoryInterface::class),
                coverage: self::coverage($filesAnalyzed, $filesSkipped, $coveragePrefix),
                namespaceTree: null,
                projectScope: null,
                duration: $duration,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [], []),
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: [],
            ),
            ruleExecution: null,
            latePublished: $findings,
        );
    }

    private static function coverage(
        int $analyzed,
        int $failed = 0,
        string $prefix = 'result',
    ): AnalysisCoverage {
        $analyzedFiles = [];
        for ($index = 0; $index < $analyzed; $index++) {
            $analyzedFiles[] = RelativePath::fromString($prefix . '/analyzed-' . $index . '.php');
        }

        $failures = [];
        for ($index = 0; $index < $failed; $index++) {
            $failures[] = new AnalysisFailure(
                RelativePath::fromString($prefix . '/failed-' . $index . '.php'),
                AnalysisFailureKind::Processing,
                'Fixture processing failure',
            );
        }

        return new AnalysisCoverage($analyzedFiles, [], $failures);
    }

    private function createFinding(
        Severity $severity,
        string $file = 'test.php',
        ?int $line = 1,
    ): Finding {
        $relFile = RelativePath::fromString($file);

        return new Finding(
            location: new Location($relFile, $line),
            symbolPath: SymbolPath::forFile($relFile),
            subject: MetricSubject::aggregate(SymbolPath::forFile($relFile)),
            ruleName: 'test-rule',
            code: 'test-rule',
            message: 'Test message',
            severity: $severity,
        );
    }
}
