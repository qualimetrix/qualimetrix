<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(AnalysisResult::class)]
final class AnalysisResultTest extends TestCase
{
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

        self::assertCount(2, $merged->findings);
        self::assertSame(8, $merged->filesAnalyzed);
        self::assertSame(3, $merged->filesSkipped);
        self::assertSame(2.0, $merged->duration);
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
            new LogicalClassPath(SymbolPath::forClass('App', 'ServiceA')),
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
            new LogicalClassPath(SymbolPath::forClass('App', 'ServiceB')),
            $metrics2,
        ));

        $result1 = new AnalysisResult([], 1.0, $repo1, self::coverage(5));
        $result2 = new AnalysisResult([], 2.0, $repo2, self::coverage(3, prefix: 'other'));

        $merged = $result1->merge($result2);

        // Both metrics should be present in merged result
        self::assertInstanceOf(InMemoryMetricRepository::class, $merged->metrics);
        self::assertTrue($merged->metrics->has(SymbolPath::forMethod('App', 'ServiceA', 'method1')));
        self::assertTrue($merged->metrics->has(SymbolPath::forMethod('App', 'ServiceB', 'method2')));

        self::assertSame(
            5,
            $merged->metrics->get(SymbolPath::forMethod('App', 'ServiceA', 'method1'))->get('complexity.ccn'),
        );
        self::assertSame(
            10,
            $merged->metrics->get(SymbolPath::forMethod('App', 'ServiceB', 'method2'))->get('complexity.ccn'),
        );
    }

    #[Test]
    public function itKeepsTheLeftRepositoryWhenTheRightRepositoryCannotMerge(): void
    {
        $left = self::createMock(MetricRepositoryInterface::class);
        $right = self::createStub(MetricRepositoryInterface::class);
        $left->expects(self::once())->method('mergedWith')->with($right)->willReturn(null);

        $merged = (new AnalysisResult([], 0.1, $left, self::coverage(1)))
            ->merge(new AnalysisResult([], 0.1, $right, self::coverage(1, prefix: 'right')));

        self::assertSame($left, $merged->metrics);
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
            subject: $sharedSubject,
            controlScope: ControlScope::Callable,
        );
        $suppression2 = new Suppression('size', null, 20, SuppressionType::NextLine);
        $suppression3 = new Suppression(
            'cohesion.lcom',
            null,
            30,
            SuppressionType::Symbol,
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'measure'), RelativePath::fromString('shared.php'), DeclarationOrdinal::fromRank(0))),
            controlScope: ControlScope::Callable,
        );

        $result1 = new AnalysisResult(
            findings: [],
            duration: 0.1,
            metrics: self::createStub(MetricRepositoryInterface::class),
            coverage: self::coverage(1),
            suppressions: ['shared.php' => [$suppression1], 'only1.php' => [$suppression2]],
        );

        $result2 = new AnalysisResult(
            findings: [],
            duration: 0.1,
            metrics: self::createStub(MetricRepositoryInterface::class),
            coverage: self::coverage(1, prefix: 'other'),
            suppressions: ['shared.php' => [$suppression3], 'only2.php' => [$suppression2]],
        );

        $merged = $result1->merge($result2);

        // shared.php should have both suppressions combined, not overwritten
        self::assertCount(2, $merged->suppressions['shared.php']);
        self::assertSame($suppression1, $merged->suppressions['shared.php'][0]);
        self::assertSame($suppression3, $merged->suppressions['shared.php'][1]);

        // Non-overlapping files preserved
        self::assertCount(1, $merged->suppressions['only1.php']);
        self::assertCount(1, $merged->suppressions['only2.php']);
    }

    #[Test]
    public function itMergesThresholdOverridesForOverlappingFiles(): void
    {
        $subject = MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'calculate'), RelativePath::fromString('shared.php'), DeclarationOrdinal::fromRank(0)));
        $override1 = new ThresholdOverride('complexity.cyclomatic', 15, 25, 10, $subject, ControlScope::Callable);
        $override2 = new ThresholdOverride('coupling.cbo', 10, 20, 20, $subject, ControlScope::Callable);
        $override3 = new ThresholdOverride('size.method-count', 5, 10, 30, $subject, ControlScope::Callable);

        $result1 = new AnalysisResult(
            findings: [],
            duration: 0.1,
            metrics: self::createStub(MetricRepositoryInterface::class),
            coverage: self::coverage(1),
            thresholdOverrides: ['shared.php' => [$override1], 'only1.php' => [$override2]],
        );

        $result2 = new AnalysisResult(
            findings: [],
            duration: 0.1,
            metrics: self::createStub(MetricRepositoryInterface::class),
            coverage: self::coverage(1, prefix: 'other'),
            thresholdOverrides: ['shared.php' => [$override3], 'only2.php' => [$override2]],
        );

        $merged = $result1->merge($result2);

        self::assertCount(2, $merged->thresholdOverrides['shared.php']);
        self::assertSame($override1, $merged->thresholdOverrides['shared.php'][0]);
        self::assertSame($override3, $merged->thresholdOverrides['shared.php'][1]);

        self::assertCount(1, $merged->thresholdOverrides['only1.php']);
        self::assertCount(1, $merged->thresholdOverrides['only2.php']);
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

        $result1 = new AnalysisResult(
            findings: [$left],
            duration: 0.1,
            metrics: self::createStub(MetricRepositoryInterface::class),
            coverage: self::coverage(1),
            ruleExecution: new RuleExecutionResult(
                produced: [$left],
                published: [$left],
                exclusions: new RuleExclusionStats(namespaceExclusionsByRule: ['rule1' => 1]),
            ),
        );

        $result2 = new AnalysisResult(
            findings: [$right],
            duration: 0.1,
            metrics: self::createStub(MetricRepositoryInterface::class),
            coverage: self::coverage(1, prefix: 'other'),
            ruleExecution: new RuleExecutionResult(
                produced: [$right],
                published: [$right],
                exclusions: new RuleExclusionStats(pathExclusionsByRule: ['rule2' => 2]),
            ),
        );

        $merged = $result1->merge($result2);

        self::assertNotNull($merged->ruleExecution);
        self::assertSame([$left, $right], $merged->ruleExecution->produced);
        self::assertSame([$left, $right], $merged->ruleExecution->published);
        self::assertSame(['rule1' => 1], $merged->ruleExecution->exclusions->namespaceExclusionsByRule);
        self::assertSame(['rule2' => 2], $merged->ruleExecution->exclusions->pathExclusionsByRule);
    }

    #[Test]
    public function itKeepsTheOtherSidesRuleExecutionWhenOneSideHasNone(): void
    {
        $finding = $this->createFinding(Severity::Warning);
        $ruleExecution = new RuleExecutionResult(
            produced: [$finding],
            published: [$finding],
            exclusions: new RuleExclusionStats(),
        );

        $withRuleExecution = new AnalysisResult(
            findings: [$finding],
            duration: 0.1,
            metrics: self::createStub(MetricRepositoryInterface::class),
            coverage: self::coverage(1),
            ruleExecution: $ruleExecution,
        );

        $withoutRuleExecution = new AnalysisResult(
            findings: [],
            duration: 0.1,
            metrics: self::createStub(MetricRepositoryInterface::class),
            coverage: self::coverage(1, prefix: 'other'),
        );

        self::assertSame($ruleExecution, $withRuleExecution->merge($withoutRuleExecution)->ruleExecution);
        self::assertSame($ruleExecution, $withoutRuleExecution->merge($withRuleExecution)->ruleExecution);
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
        return new AnalysisResult(
            findings: $findings,
            duration: $duration,
            metrics: self::createStub(MetricRepositoryInterface::class),
            coverage: self::coverage($filesAnalyzed, $filesSkipped, $coveragePrefix),
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
