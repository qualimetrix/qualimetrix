<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\SuppressionType;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

#[CoversClass(AnalysisResult::class)]
final class AnalysisResultTest extends TestCase
{
    #[Test]
    public function itHasErrorsWhenErrorViolationPresent(): void
    {
        $result = $this->createResult([
            $this->createViolation(Severity::Error),
        ]);

        self::assertTrue($result->hasErrors());
    }

    #[Test]
    public function itHasNoErrorsWhenOnlyWarnings(): void
    {
        $result = $this->createResult([
            $this->createViolation(Severity::Warning),
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
    public function itHasWarningsWhenWarningViolationPresent(): void
    {
        $result = $this->createResult([
            $this->createViolation(Severity::Warning),
        ]);

        self::assertTrue($result->hasWarnings());
    }

    #[Test]
    public function itHasNoWarningsWhenOnlyErrors(): void
    {
        $result = $this->createResult([
            $this->createViolation(Severity::Error),
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
    public function itReturnsExitCode0WhenNoViolations(): void
    {
        $result = $this->createResult([]);

        self::assertSame(0, $result->getExitCode());
    }

    #[Test]
    public function itReturnsExitCode1WhenOnlyWarnings(): void
    {
        $result = $this->createResult([
            $this->createViolation(Severity::Warning),
            $this->createViolation(Severity::Warning),
        ]);

        self::assertSame(1, $result->getExitCode());
    }

    #[Test]
    public function itReturnsExitCode2WhenErrorsPresent(): void
    {
        $result = $this->createResult([
            $this->createViolation(Severity::Warning),
            $this->createViolation(Severity::Error),
        ]);

        self::assertSame(2, $result->getExitCode());
    }

    #[Test]
    public function itReturnsExitCode2WhenOnlyErrors(): void
    {
        $result = $this->createResult([
            $this->createViolation(Severity::Error),
        ]);

        self::assertSame(2, $result->getExitCode());
    }

    #[Test]
    public function itMergesViolations(): void
    {
        $result1 = $this->createResult([
            $this->createViolation(Severity::Error, 'file1.php'),
        ], filesAnalyzed: 5, filesSkipped: 1, duration: 1.5);

        $result2 = $this->createResult([
            $this->createViolation(Severity::Warning, 'file2.php'),
        ], filesAnalyzed: 3, filesSkipped: 2, duration: 2.0, coveragePrefix: 'other');

        $merged = $result1->merge($result2);

        self::assertCount(2, $merged->violations);
        self::assertSame(8, $merged->filesAnalyzed);
        self::assertSame(3, $merged->filesSkipped);
        self::assertSame(2.0, $merged->duration);
    }

    #[Test]
    public function itMergesMetricsFromBothRepositories(): void
    {
        $repo1 = new InMemoryMetricRepository();
        $metrics1 = (new MetricBag())->with('ccn', 5);
        $repo1->addCallable(new CallableWithMetrics(
            new DeclarationPath(SymbolPath::forMethod('App', 'ServiceA', 'method1'), RelativePath::fromString('ServiceA.php'), 100),
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath(SymbolPath::forClass('App', 'ServiceA')),
            $metrics1,
        ));

        $repo2 = new InMemoryMetricRepository();
        $metrics2 = (new MetricBag())->with('ccn', 10);
        $repo2->addCallable(new CallableWithMetrics(
            new DeclarationPath(SymbolPath::forMethod('App', 'ServiceB', 'method2'), RelativePath::fromString('ServiceB.php'), 200),
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
            $merged->metrics->get(SymbolPath::forMethod('App', 'ServiceA', 'method1'))->get('ccn'),
        );
        self::assertSame(
            10,
            $merged->metrics->get(SymbolPath::forMethod('App', 'ServiceB', 'method2'))->get('ccn'),
        );
    }

    #[Test]
    public function itSortsViolationsByFileAndLine(): void
    {
        $v1 = $this->createViolation(Severity::Error, 'b.php', 20);
        $v2 = $this->createViolation(Severity::Error, 'a.php', 10);
        $v3 = $this->createViolation(Severity::Warning, 'a.php', 5);
        $v4 = $this->createViolation(Severity::Warning, 'b.php', 10);

        $result = $this->createResult([$v1, $v2, $v3, $v4]);

        $sorted = $result->getSortedViolations();

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
    public function itSortsViolationsWithNullLines(): void
    {
        $v1 = $this->createViolation(Severity::Error, 'a.php', 10);
        $v2 = $this->createViolation(Severity::Error, 'a.php', null);

        $result = $this->createResult([$v1, $v2]);

        $sorted = $result->getSortedViolations();

        self::assertNull($sorted[0]->location->line);
        self::assertSame(10, $sorted[1]->location->line);
    }

    #[Test]
    public function itCountsViolationsBySeverity(): void
    {
        $result = $this->createResult([
            $this->createViolation(Severity::Error),
            $this->createViolation(Severity::Error),
            $this->createViolation(Severity::Warning),
            $this->createViolation(Severity::Warning),
            $this->createViolation(Severity::Warning),
        ]);

        $counts = $result->getViolationCountBySeverity();

        self::assertSame(2, $counts['errors']);
        self::assertSame(3, $counts['warnings']);
    }

    #[Test]
    public function itMergesSuppressionsForOverlappingFiles(): void
    {
        $suppression1 = new Suppression('complexity', null, 10, SuppressionType::Symbol);
        $suppression2 = new Suppression('size', null, 20, SuppressionType::NextLine);
        $suppression3 = new Suppression('lcom', null, 30, SuppressionType::Symbol);

        $result1 = new AnalysisResult(
            violations: [],
            duration: 0.1,
            metrics: self::createStub(MetricRepositoryInterface::class),
            coverage: self::coverage(1),
            suppressions: ['shared.php' => [$suppression1], 'only1.php' => [$suppression2]],
        );

        $result2 = new AnalysisResult(
            violations: [],
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
        $override1 = new ThresholdOverride('complexity.cyclomatic', 15, 25, 10);
        $override2 = new ThresholdOverride('coupling.cbo', 10, 20, 20);
        $override3 = new ThresholdOverride('size.method-count', 5, 10, 30);

        $result1 = new AnalysisResult(
            violations: [],
            duration: 0.1,
            metrics: self::createStub(MetricRepositoryInterface::class),
            coverage: self::coverage(1),
            thresholdOverrides: ['shared.php' => [$override1], 'only1.php' => [$override2]],
        );

        $result2 = new AnalysisResult(
            violations: [],
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

    #[Test]
    public function itCountsZeroWhenNoViolations(): void
    {
        $result = $this->createResult([]);

        $counts = $result->getViolationCountBySeverity();

        self::assertSame(0, $counts['errors']);
        self::assertSame(0, $counts['warnings']);
    }

    /**
     * @param list<Violation> $violations
     */
    private function createResult(
        array $violations,
        int $filesAnalyzed = 1,
        int $filesSkipped = 0,
        float $duration = 0.1,
        string $coveragePrefix = 'result',
    ): AnalysisResult {
        return new AnalysisResult(
            violations: $violations,
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

    private function createViolation(
        Severity $severity,
        string $file = 'test.php',
        ?int $line = 1,
    ): Violation {
        $relFile = RelativePath::fromString($file);

        return new Violation(
            location: new Location($relFile, $line),
            symbolPath: SymbolPath::forFile($relFile),
            ruleName: 'test-rule',
            violationCode: 'test-rule',
            message: 'Test message',
            severity: $severity,
        );
    }
}
