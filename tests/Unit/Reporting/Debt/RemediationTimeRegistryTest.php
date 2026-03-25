<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Debt;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;

#[CoversClass(RemediationTimeRegistry::class)]
final class RemediationTimeRegistryTest extends TestCase
{
    private RemediationTimeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new RemediationTimeRegistry();
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function knownRulesProvider(): iterable
    {
        yield 'complexity.cyclomatic' => ['complexity.cyclomatic', 30];
        yield 'complexity.cognitive' => ['complexity.cognitive', 30];
        yield 'complexity.npath' => ['complexity.npath', 30];
        yield 'complexity.wmc' => ['complexity.wmc', 30];
        yield 'coupling.cbo' => ['coupling.cbo', 45];
        yield 'coupling.class-rank' => ['coupling.class-rank', 30];
        yield 'coupling.instability' => ['coupling.instability', 30];
        yield 'coupling.distance' => ['coupling.distance', 30];
        yield 'design.inheritance' => ['design.inheritance', 30];
        yield 'design.noc' => ['design.noc', 20];
        yield 'design.type-coverage' => ['design.type-coverage', 15];
        yield 'design.lcom' => ['design.lcom', 45];
        yield 'size.class-count' => ['size.class-count', 30];
        yield 'size.method-count' => ['size.method-count', 20];
        yield 'size.property-count' => ['size.property-count', 15];
        yield 'maintainability.index' => ['maintainability.index', 60];
        yield 'code-smell.boolean-argument' => ['code-smell.boolean-argument', 10];
        yield 'code-smell.debug-code' => ['code-smell.debug-code', 5];
        yield 'code-smell.empty-catch' => ['code-smell.empty-catch', 10];
        yield 'code-smell.eval' => ['code-smell.eval', 15];
        yield 'code-smell.exit' => ['code-smell.exit', 10];
        yield 'code-smell.goto' => ['code-smell.goto', 15];
        yield 'code-smell.superglobals' => ['code-smell.superglobals', 15];
        yield 'code-smell.error-suppression' => ['code-smell.error-suppression', 10];
        yield 'code-smell.count-in-loop' => ['code-smell.count-in-loop', 10];
        yield 'code-smell.long-parameter-list' => ['code-smell.long-parameter-list', 20];
        yield 'code-smell.unreachable-code' => ['code-smell.unreachable-code', 10];
        yield 'security.hardcoded-credentials' => ['security.hardcoded-credentials', 30];
        yield 'security.sql-injection' => ['security.sql-injection', 60];
        yield 'security.xss' => ['security.xss', 45];
        yield 'security.command-injection' => ['security.command-injection', 60];
        yield 'security.sensitive-parameter' => ['security.sensitive-parameter', 10];
        yield 'architecture.circular-dependency' => ['architecture.circular-dependency', 120];
    }

    #[DataProvider('knownRulesProvider')]
    public function testKnownRuleReturnsCorrectBaseMinutes(string $ruleName, int $expectedMinutes): void
    {
        self::assertSame($expectedMinutes, $this->registry->getBaseMinutes($ruleName));
    }

    public function testUnknownRuleReturnsDefault(): void
    {
        self::assertSame(15, $this->registry->getBaseMinutes('unknown.rule'));
    }

    public function testAnotherUnknownRuleReturnsDefault(): void
    {
        self::assertSame(15, $this->registry->getBaseMinutes('custom.my-rule'));
    }

    public function testViolationWithoutMetricValueUsesBaseMinutes(): void
    {
        $violation = $this->createViolation('complexity.cyclomatic');

        self::assertSame(30, $this->registry->getMinutesForViolation($violation));
    }

    public function testViolationWithoutThresholdUsesBaseMinutes(): void
    {
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 25);

        self::assertSame(30, $this->registry->getMinutesForViolation($violation));
    }

    public function testMinorOvershootGetsBaseDebt(): void
    {
        // CCN=21, threshold=20: ln(1.05)=0.049 < 1 → base * max(1, 0.049) = 30 * 1 = 30
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 21, threshold: 20);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(30, $minutes);
    }

    public function testModerateOvershootGetsBaseDebt(): void
    {
        // CCN=50, threshold=20: ln(2.5)=0.916 < 1 → base * max(1, 0.916) = 30 * 1 = 30
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 50, threshold: 20);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(30, $minutes);
    }

    public function testLargeOvershootScalesAboveBase(): void
    {
        // CCN=60, threshold=20: ln(3.0)=1.099 > 1 → 30 * 1.099 ≈ 33
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 60, threshold: 20);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(33, $minutes);
    }

    public function testExtremeOvershootGivesLargeDebt(): void
    {
        // NPath=1000000, threshold=200: ln(5000)=8.517 → 30 * 8.517 ≈ 256
        $violation = $this->createViolation('complexity.npath', metricValue: 1000000, threshold: 200);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(256, $minutes);
    }

    public function testInvertedRuleMaintainabilityIndex(): void
    {
        // MI=30, threshold=50 (inverted): ratio=50/30=1.667, ln(1.667)=0.511, max(1, 0.511)=1 → 60*1=60
        $violation = $this->createViolation('maintainability.index', metricValue: 30, threshold: 50);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(60, $minutes);
    }

    public function testInvertedRuleTypeCoverage(): void
    {
        // Type coverage=40, threshold=80 (inverted): ratio=80/40=2, ln(2)=0.693, max(1, 0.693)=1 → 15*1=15
        $violation = $this->createViolation('design.type-coverage', metricValue: 40, threshold: 80);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(15, $minutes);
    }

    public function testComputedHealthInvertedMetric(): void
    {
        // health score=30 (below threshold=50): ratio=50/30=1.667, ln=0.511, max(1, 0.511)=1 → 15*1=15
        $violation = $this->createViolation('computed.health', metricValue: 30, threshold: 50);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(15, $minutes);
    }

    public function testComputedHealthNormalMetric(): void
    {
        // Normal computed metric value > threshold: ratio=100/50=2, ln(2)=0.693, max(1, 0.693)=1 → 15*1=15
        $violation = $this->createViolation('computed.health', metricValue: 100, threshold: 50);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(15, $minutes);
    }

    public function testZeroThresholdUsesBaseMinutes(): void
    {
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 25, threshold: 0);

        self::assertSame(30, $this->registry->getMinutesForViolation($violation));
    }

    public function testZeroMetricValueUsesBaseMinutes(): void
    {
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 0, threshold: 20);

        self::assertSame(30, $this->registry->getMinutesForViolation($violation));
    }

    public function testMetricAtThresholdUsesBaseMinutes(): void
    {
        // Ratio = 1, ln(1) = 0 → base fallback
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 20, threshold: 20);

        self::assertSame(30, $this->registry->getMinutesForViolation($violation));
    }

    public function testMinorOvershootGetsBaseMinutes(): void
    {
        // Small overshoot: ratio=6/5=1.2, ln(1.2)=0.182, max(1, 0.182)=1 → 5*1=5
        $violation = $this->createViolation('code-smell.debug-code', metricValue: 6, threshold: 5);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(5, $minutes);
    }

    private function createViolation(
        string $ruleName,
        int|float|null $metricValue = null,
        int|float|null $threshold = null,
    ): Violation {
        return new Violation(
            location: new Location('src/Test.php', 1),
            symbolPath: SymbolPath::forClass('App', 'TestClass'),
            ruleName: $ruleName,
            violationCode: $ruleName,
            message: 'Test violation',
            severity: Severity::Warning,
            metricValue: $metricValue,
            threshold: $threshold,
        );
    }
}
