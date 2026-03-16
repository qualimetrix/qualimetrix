<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Debt;

use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DebtCalculator::class)]
final class DebtCalculatorTest extends TestCase
{
    private DebtCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DebtCalculator(new RemediationTimeRegistry());
    }

    public function testEmptyViolations(): void
    {
        $summary = $this->calculator->calculate([]);

        self::assertSame(0, $summary->totalMinutes);
        self::assertSame([], $summary->perFile);
        self::assertSame([], $summary->perRule);
    }

    public function testSingleViolation(): void
    {
        $violations = [
            $this->createViolation('src/Foo.php', 'complexity.cyclomatic'),
        ];

        $summary = $this->calculator->calculate($violations);

        self::assertSame(30, $summary->totalMinutes);
        self::assertSame(['src/Foo.php' => 30], $summary->perFile);
        self::assertSame(['complexity.cyclomatic' => 30], $summary->perRule);
    }

    public function testMultipleViolationsSameRule(): void
    {
        $violations = [
            $this->createViolation('src/Foo.php', 'complexity.cyclomatic'),
            $this->createViolation('src/Bar.php', 'complexity.cyclomatic'),
            $this->createViolation('src/Foo.php', 'complexity.cyclomatic'),
        ];

        $summary = $this->calculator->calculate($violations);

        self::assertSame(90, $summary->totalMinutes);
        self::assertSame(['src/Foo.php' => 60, 'src/Bar.php' => 30], $summary->perFile);
        self::assertSame(['complexity.cyclomatic' => 90], $summary->perRule);
    }

    public function testMixedRules(): void
    {
        $violations = [
            $this->createViolation('src/Foo.php', 'complexity.cyclomatic'),   // 30
            $this->createViolation('src/Foo.php', 'code-smell.debug-code'),   // 5
            $this->createViolation('src/Bar.php', 'maintainability.index'),   // 60
        ];

        $summary = $this->calculator->calculate($violations);

        self::assertSame(95, $summary->totalMinutes);
        self::assertSame(['src/Foo.php' => 35, 'src/Bar.php' => 60], $summary->perFile);
        self::assertSame([
            'complexity.cyclomatic' => 30,
            'code-smell.debug-code' => 5,
            'maintainability.index' => 60,
        ], $summary->perRule);
    }

    public function testUnknownRuleUsesDefault(): void
    {
        $violations = [
            $this->createViolation('src/Foo.php', 'custom.unknown-rule'),
        ];

        $summary = $this->calculator->calculate($violations);

        self::assertSame(15, $summary->totalMinutes);
        self::assertSame(['src/Foo.php' => 15], $summary->perFile);
        self::assertSame(['custom.unknown-rule' => 15], $summary->perRule);
    }

    public function testViolationWithNoFileIsExcludedFromPerFile(): void
    {
        $violation = new Violation(
            location: Location::none(),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'architecture.circular-dependency',
            violationCode: 'architecture.circular-dependency',
            message: 'Circular dependency detected',
            severity: Severity::Error,
        );

        $summary = $this->calculator->calculate([$violation]);

        self::assertSame(120, $summary->totalMinutes);
        self::assertSame([], $summary->perFile);
        self::assertSame(['architecture.circular-dependency' => 120], $summary->perRule);
    }

    public function testScaledDebtForViolationWithMetricAndThreshold(): void
    {
        // CCN=50, threshold=20: ratio=2.5, ln(2.5)=0.916, max(1, 0.916)=1 → 30*1=30
        $violation = new Violation(
            location: new Location('src/Foo.php', 1),
            symbolPath: SymbolPath::forClass('App', 'TestClass'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Test violation',
            severity: Severity::Warning,
            metricValue: 50,
            threshold: 20,
        );

        $summary = $this->calculator->calculate([$violation]);

        self::assertSame(30, $summary->totalMinutes);
        self::assertSame(['src/Foo.php' => 30], $summary->perFile);
        self::assertSame(['complexity.cyclomatic' => 30], $summary->perRule);
    }

    public function testMixedScaledAndFlatDebt(): void
    {
        $violations = [
            // With metric data: ratio=2.5, max(1, ln(2.5))=1 → 30*1=30
            new Violation(
                location: new Location('src/Foo.php', 1),
                symbolPath: SymbolPath::forClass('App', 'TestClass'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Test',
                severity: Severity::Warning,
                metricValue: 50,
                threshold: 20,
            ),
            // Without metric data: flat 5
            $this->createViolation('src/Bar.php', 'code-smell.debug-code'),
        ];

        $summary = $this->calculator->calculate($violations);

        self::assertSame(35, $summary->totalMinutes); // 30 (scaled) + 5 (flat)
    }

    private function createViolation(string $file, string $ruleName): Violation
    {
        return new Violation(
            location: new Location($file, 1),
            symbolPath: SymbolPath::forClass('App', 'TestClass'),
            ruleName: $ruleName,
            violationCode: $ruleName,
            message: 'Test violation',
            severity: Severity::Warning,
        );
    }
}
