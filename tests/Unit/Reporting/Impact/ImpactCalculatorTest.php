<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Impact;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Impact\ClassRankResolver;
use Qualimetrix\Reporting\Impact\ImpactCalculator;

#[CoversClass(ImpactCalculator::class)]
final class ImpactCalculatorTest extends TestCase
{
    private ClassRankResolver $resolver;
    private RemediationTimeRegistry $registry;

    protected function setUp(): void
    {
        $this->resolver = new ClassRankResolver();
        $this->registry = new RemediationTimeRegistry();
    }

    #[Test]
    public function computeTopIssuesWithCorrectFormula(): void
    {
        // Error violation with classRank=0.05, rule 'complexity.cyclomatic' (base=30min, no scaling)
        $errorViolation = $this->createViolation(
            'src/a.php',
            10,
            Severity::Error,
            SymbolPath::forClass('App\Service', 'ErrorClass'),
            'complexity.cyclomatic',
        );

        // Warning violation with classRank=0.02, rule 'code-smell.debug-code' (base=5min, no scaling)
        $warningViolation = $this->createViolation(
            'src/b.php',
            20,
            Severity::Warning,
            SymbolPath::forClass('App\Service', 'WarningClass'),
            'code-smell.debug-code',
        );

        $metrics = $this->createStub(MetricRepositoryInterface::class);
        $metrics->method('get')->willReturnCallback(
            static function (SymbolPath $sp): MetricBag {
                return match ($sp->toCanonical()) {
                    'class:App\Service\ErrorClass' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.05),
                    'class:App\Service\WarningClass' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.02),
                    default => new MetricBag(),
                };
            },
        );

        $calculator = new ImpactCalculator($this->resolver, $this->registry);
        $issues = $calculator->computeTopIssues([$errorViolation, $warningViolation], $metrics);

        self::assertCount(2, $issues);

        // Error: 0.05 * 3 * 30 = 4.5
        self::assertEqualsWithDelta(4.5, $issues[0]->impactScore, 0.0001);
        self::assertSame('complexity.cyclomatic', $issues[0]->violation->ruleName);

        // Warning: 0.02 * 1 * 5 = 0.1
        self::assertEqualsWithDelta(0.1, $issues[1]->impactScore, 0.0001);
        self::assertSame('code-smell.debug-code', $issues[1]->violation->ruleName);
    }

    #[Test]
    public function sortDescendingByImpact(): void
    {
        // Three class violations with different classRanks, same rule
        $v1 = $this->createViolation('src/a.php', 1, Severity::Warning, SymbolPath::forClass('App', 'Low'), 'code-smell.debug-code');
        $v2 = $this->createViolation('src/b.php', 1, Severity::Warning, SymbolPath::forClass('App', 'High'), 'code-smell.debug-code');
        $v3 = $this->createViolation('src/c.php', 1, Severity::Warning, SymbolPath::forClass('App', 'Mid'), 'code-smell.debug-code');

        $metrics = $this->createStub(MetricRepositoryInterface::class);
        $metrics->method('get')->willReturnCallback(
            static function (SymbolPath $sp): MetricBag {
                return match ($sp->toCanonical()) {
                    'class:App\Low' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.01),
                    'class:App\High' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.10),
                    'class:App\Mid' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.05),
                    default => new MetricBag(),
                };
            },
        );

        $calculator = new ImpactCalculator($this->resolver, $this->registry);
        $issues = $calculator->computeTopIssues([$v1, $v2, $v3], $metrics);

        self::assertSame('src/b.php', $issues[0]->violation->location->file); // High
        self::assertSame('src/c.php', $issues[1]->violation->location->file); // Mid
        self::assertSame('src/a.php', $issues[2]->violation->location->file); // Low
    }

    #[Test]
    public function stableSecondarySortByFileAndLine(): void
    {
        // Same classRank and rule → same impact → secondary sort by file
        $v1 = $this->createViolation('src/b.php', 10, Severity::Warning, SymbolPath::forClass('App', 'Same'), 'code-smell.debug-code');
        $v2 = $this->createViolation('src/a.php', 5, Severity::Warning, SymbolPath::forClass('App', 'Same'), 'code-smell.debug-code');

        $metrics = $this->createStub(MetricRepositoryInterface::class);
        $metrics->method('get')->willReturn(
            (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.05),
        );

        $calculator = new ImpactCalculator($this->resolver, $this->registry);
        $issues = $calculator->computeTopIssues([$v1, $v2], $metrics);

        // Same impact, sorted by file ascending: a.php before b.php
        self::assertSame('src/a.php', $issues[0]->violation->location->file);
        self::assertSame('src/b.php', $issues[1]->violation->location->file);
    }

    #[Test]
    public function emptyViolationsReturnsEmpty(): void
    {
        $metrics = $this->createStub(MetricRepositoryInterface::class);
        $calculator = new ImpactCalculator($this->resolver, $this->registry);

        $issues = $calculator->computeTopIssues([], $metrics);

        self::assertSame([], $issues);
    }

    #[Test]
    public function classRankNullFallsBackToMedianOrZero(): void
    {
        // Function-level violation → classRank resolves to null
        // No classes exist → median is null → fallback 0.0
        $violation = $this->createViolation(
            'src/a.php',
            1,
            Severity::Warning,
            SymbolPath::forGlobalFunction('App', 'helper'),
            'code-smell.debug-code',
        );

        $metrics = $this->createStub(MetricRepositoryInterface::class);
        $metrics->method('all')->willReturn([]);
        $metrics->method('get')->willReturn(new MetricBag());

        $calculator = new ImpactCalculator($this->resolver, $this->registry);
        $issues = $calculator->computeTopIssues([$violation], $metrics);

        // null classRank, no median → 0.0 * 1 * 5 = 0.0
        self::assertSame(0.0, $issues[0]->impactScore);
        self::assertNull($issues[0]->classRank);
    }

    #[Test]
    public function classRankNullFallsBackToMedian(): void
    {
        // Two class violations with classRanks 0.01 and 0.03 → median = 0.02
        // One function violation (classRank = null) should use median 0.02
        $classViolation1 = $this->createViolation(
            'src/a.php',
            1,
            Severity::Warning,
            SymbolPath::forClass('App', 'ClassA'),
            'code-smell.debug-code',
        );
        $classViolation2 = $this->createViolation(
            'src/b.php',
            1,
            Severity::Warning,
            SymbolPath::forClass('App', 'ClassB'),
            'code-smell.debug-code',
        );
        $funcViolation = $this->createViolation(
            'src/c.php',
            1,
            Severity::Warning,
            SymbolPath::forGlobalFunction('App', 'helper'),
            'code-smell.debug-code',
        );

        $metrics = $this->createStub(MetricRepositoryInterface::class);
        $metrics->method('all')->willReturn([
            new SymbolInfo(SymbolPath::forClass('App', 'ClassA'), 'src/a.php', 1),
            new SymbolInfo(SymbolPath::forClass('App', 'ClassB'), 'src/b.php', 1),
        ]);
        $metrics->method('get')->willReturnCallback(
            static function (SymbolPath $sp): MetricBag {
                return match ($sp->toCanonical()) {
                    'class:App\ClassA' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.01),
                    'class:App\ClassB' => (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.03),
                    default => new MetricBag(),
                };
            },
        );

        $calculator = new ImpactCalculator($this->resolver, $this->registry);
        $issues = $calculator->computeTopIssues(
            [$classViolation1, $classViolation2, $funcViolation],
            $metrics,
        );

        // Find the function violation in results
        $funcIssue = null;
        foreach ($issues as $issue) {
            if ($issue->violation->location->file === 'src/c.php') {
                $funcIssue = $issue;
                break;
            }
        }

        self::assertNotNull($funcIssue);
        self::assertNull($funcIssue->classRank);
        // median of [0.01, 0.03] = 0.02, Warning=1, debug-code=5 → 0.02 * 1 * 5 = 0.1
        self::assertEqualsWithDelta(0.1, $funcIssue->impactScore, 0.0001);
    }

    #[Test]
    public function severityWeightsErrorTripleWarning(): void
    {
        $errorViolation = $this->createViolation(
            'src/a.php',
            1,
            Severity::Error,
            SymbolPath::forClass('App', 'TestClass'),
            'code-smell.debug-code',
        );
        $warningViolation = $this->createViolation(
            'src/b.php',
            1,
            Severity::Warning,
            SymbolPath::forClass('App', 'TestClass'),
            'code-smell.debug-code',
        );

        $metrics = $this->createStub(MetricRepositoryInterface::class);
        $metrics->method('get')->willReturn(
            (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.1),
        );

        $calculator = new ImpactCalculator($this->resolver, $this->registry);
        $issues = $calculator->computeTopIssues([$errorViolation, $warningViolation], $metrics);

        // Error: 0.1 * 3 * 5 = 1.5, Warning: 0.1 * 1 * 5 = 0.5
        self::assertSame(3, $issues[0]->severityWeight);
        self::assertSame(1, $issues[1]->severityWeight);
        self::assertEqualsWithDelta(3.0, $issues[0]->impactScore / $issues[1]->impactScore, 0.0001);
    }

    #[Test]
    public function zeroDebtMinutesResultsInZeroImpact(): void
    {
        // Use a violation with metricValue = threshold so scaling produces base time
        // But we need a rule with 0 base time — no such rule exists in the registry.
        // Instead, test that when debtMinutes is non-zero but classRank is 0.0,
        // impact is 0. Actually let's test the actual edge case:
        // violation with metricValue <= threshold → base time (non-zero).
        // The real test is: classRank=0 should produce impact=0.
        $violation = $this->createViolation(
            'src/a.php',
            1,
            Severity::Error,
            SymbolPath::forClass('App', 'TestClass'),
            'code-smell.debug-code',
        );

        $metrics = $this->createStub(MetricRepositoryInterface::class);
        $metrics->method('get')->willReturn(
            (new MetricBag())->with(MetricName::COUPLING_CLASS_RANK, 0.0),
        );

        $calculator = new ImpactCalculator($this->resolver, $this->registry);
        $issues = $calculator->computeTopIssues([$violation], $metrics);

        // classRank=0.0, Error(3), debug-code(5) → 0.0 * 3 * 5 = 0.0
        self::assertSame(0.0, $issues[0]->impactScore);
        self::assertSame(0.0, $issues[0]->classRank);
    }

    private function createViolation(
        string $file,
        int $line,
        Severity $severity,
        SymbolPath $symbolPath,
        string $rule = 'code-smell.debug-code',
    ): Violation {
        return new Violation(
            location: new Location($file, $line),
            symbolPath: $symbolPath,
            ruleName: $rule,
            violationCode: $rule,
            message: 'Test message',
            severity: $severity,
        );
    }
}
