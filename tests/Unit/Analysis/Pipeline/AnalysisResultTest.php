<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Pipeline;

use AiMessDetector\Analysis\Pipeline\AnalysisResult;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Baseline\Suppression\Suppression;
use AiMessDetector\Baseline\Suppression\SuppressionType;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        ], filesAnalyzed: 3, filesSkipped: 2, duration: 2.0);

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
        $repo1->add(
            SymbolPath::forMethod('App', 'ServiceA', 'method1'),
            $metrics1,
            'ServiceA.php',
            10,
        );

        $repo2 = new InMemoryMetricRepository();
        $metrics2 = (new MetricBag())->with('ccn', 10);
        $repo2->add(
            SymbolPath::forMethod('App', 'ServiceB', 'method2'),
            $metrics2,
            'ServiceB.php',
            20,
        );

        $result1 = new AnalysisResult([], 5, 0, 1.0, $repo1);
        $result2 = new AnalysisResult([], 3, 0, 2.0, $repo2);

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

        self::assertSame('a.php', $sorted[0]->location->file);
        self::assertSame(5, $sorted[0]->location->line);

        self::assertSame('a.php', $sorted[1]->location->file);
        self::assertSame(10, $sorted[1]->location->line);

        self::assertSame('b.php', $sorted[2]->location->file);
        self::assertSame(10, $sorted[2]->location->line);

        self::assertSame('b.php', $sorted[3]->location->file);
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
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.1,
            metrics: $this->createStub(MetricRepositoryInterface::class),
            suppressions: ['shared.php' => [$suppression1], 'only1.php' => [$suppression2]],
        );

        $result2 = new AnalysisResult(
            violations: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.1,
            metrics: $this->createStub(MetricRepositoryInterface::class),
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
    ): AnalysisResult {
        return new AnalysisResult(
            violations: $violations,
            filesAnalyzed: $filesAnalyzed,
            filesSkipped: $filesSkipped,
            duration: $duration,
            metrics: $this->createMock(MetricRepositoryInterface::class),
        );
    }

    private function createViolation(
        Severity $severity,
        string $file = 'test.php',
        ?int $line = 1,
    ): Violation {
        return new Violation(
            location: new Location($file, $line),
            symbolPath: SymbolPath::forFile($file),
            ruleName: 'test-rule',
            violationCode: 'test-rule',
            message: 'Test message',
            severity: $severity,
        );
    }
}
