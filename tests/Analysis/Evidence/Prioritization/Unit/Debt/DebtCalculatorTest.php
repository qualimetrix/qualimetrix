<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Prioritization\Unit\Debt;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

#[CoversClass(DebtCalculator::class)]
final class DebtCalculatorTest extends TestCase
{
    private DebtCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DebtCalculator(new RemediationTimeRegistry(
            StubChannelDeclarationRegistry::alwaysHigherMagnitude(),
            StubRemediationMinutes::withRealValues(),
        ));
    }

    #[Test]
    public function itCalculatesZeroDebtForEmptyFindings(): void
    {
        $summary = $this->calculator->calculate([]);

        self::assertSame(0, $summary->totalMinutes);
        self::assertSame([], $summary->perFile);
        self::assertSame([], $summary->perRule);
    }

    #[Test]
    public function itCalculatesDebtForSingleFinding(): void
    {
        $findings = [
            $this->createFinding('src/Foo.php', 'complexity.cyclomatic'),
        ];

        $summary = $this->calculator->calculate($findings);

        self::assertSame(30, $summary->totalMinutes);
        self::assertSame(['src/Foo.php' => 30], $summary->perFile);
        self::assertSame(['complexity.cyclomatic' => 30], $summary->perRule);
    }

    #[Test]
    public function itAccumulatesDebtForMultipleFindingsSameRule(): void
    {
        $findings = [
            $this->createFinding('src/Foo.php', 'complexity.cyclomatic'),
            $this->createFinding('src/Bar.php', 'complexity.cyclomatic'),
            $this->createFinding('src/Foo.php', 'complexity.cyclomatic'),
        ];

        $summary = $this->calculator->calculate($findings);

        self::assertSame(90, $summary->totalMinutes);
        self::assertSame(['src/Foo.php' => 60, 'src/Bar.php' => 30], $summary->perFile);
        self::assertSame(['complexity.cyclomatic' => 90], $summary->perRule);
    }

    #[Test]
    public function itCalculatesDebtForMixedRules(): void
    {
        $findings = [
            $this->createFinding('src/Foo.php', 'complexity.cyclomatic'),   // 30
            $this->createFinding('src/Foo.php', 'code-smell.debug-code'),   // 5
            $this->createFinding('src/Bar.php', 'maintainability.index'),   // 60
        ];

        $summary = $this->calculator->calculate($findings);

        self::assertSame(95, $summary->totalMinutes);
        self::assertSame(['src/Foo.php' => 35, 'src/Bar.php' => 60], $summary->perFile);
        self::assertSame([
            'complexity.cyclomatic' => 30,
            'code-smell.debug-code' => 5,
            'maintainability.index' => 60,
        ], $summary->perRule);
    }

    /**
     * No `DEFAULT_MINUTES` fallback remains: every registered rule declares
     * its own minutes, so a name absent from the injected map is not a
     * legitimately unknown rule to fall back for — it is a bug (a finding
     * carrying a rule name no rule declared).
     */
    #[Test]
    public function itThrowsForARuleNameNoRuleDeclares(): void
    {
        $findings = [
            $this->createFinding('src/Foo.php', 'custom.unknown-rule'),
        ];

        self::expectException(LogicException::class);
        self::expectExceptionMessage('No remediation minutes declared for rule "custom.unknown-rule"');

        $this->calculator->calculate($findings);
    }

    #[Test]
    public function itExcludesFindingWithNoFileFromPerFile(): void
    {
        $finding = new Finding(
            location: Location::none(),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', 'Foo'), RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'architecture.circular-dependency',
            code: 'architecture.circular-dependency',
            message: 'Circular dependency detected',
            severity: Severity::Error,
        );

        $summary = $this->calculator->calculate([$finding]);

        self::assertSame(120, $summary->totalMinutes);
        self::assertSame([], $summary->perFile);
        self::assertSame(['architecture.circular-dependency' => 120], $summary->perRule);
    }

    #[Test]
    public function itScalesDebtForFindingWithMetricAndThreshold(): void
    {
        // CCN=50, threshold=20: ratio=2.5, ln(2.5)=0.916, max(1, 0.916)=1 → 30*1=30
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 1),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', 'TestClass'), RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forClass('App', 'TestClass'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Test violation',
            severity: Severity::Warning,
            metricValue: 50,
            threshold: 20,
        );

        $summary = $this->calculator->calculate([$finding]);

        self::assertSame(30, $summary->totalMinutes);
        self::assertSame(['src/Foo.php' => 30], $summary->perFile);
        self::assertSame(['complexity.cyclomatic' => 30], $summary->perRule);
    }

    #[Test]
    public function itCombinesScaledAndFlatDebt(): void
    {
        $findings = [
            // With metric data: ratio=2.5, max(1, ln(2.5))=1 → 30*1=30
            new Finding(
                location: new Location(RelativePath::fromString('src/Foo.php'), 1),
                subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', 'TestClass'), RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
                symbolPath: SymbolPath::forClass('App', 'TestClass'),
                ruleName: 'complexity.cyclomatic',
                code: 'complexity.cyclomatic',
                message: 'Test',
                severity: Severity::Warning,
                metricValue: 50,
                threshold: 20,
            ),
            // Without metric data: flat 5
            $this->createFinding('src/Bar.php', 'code-smell.debug-code'),
        ];

        $summary = $this->calculator->calculate($findings);

        self::assertSame(35, $summary->totalMinutes); // 30 (scaled) + 5 (flat)
    }

    private function createFinding(string $file, string $ruleName): Finding
    {
        return new Finding(
            location: new Location(RelativePath::fromString($file), 1),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', 'TestClass'), RelativePath::fromString($file), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forClass('App', 'TestClass'),
            ruleName: $ruleName,
            code: $ruleName,
            message: 'Test violation',
            severity: Severity::Warning,
        );
    }
}
