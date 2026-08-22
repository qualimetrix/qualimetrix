<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Prioritization\Unit\Debt;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

#[CoversClass(RemediationTimeRegistry::class)]
final class RemediationTimeRegistryTest extends TestCase
{
    private RemediationTimeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new RemediationTimeRegistry(
            StubChannelDeclarationRegistry::alwaysHigherMagnitude(),
            StubRemediationMinutes::withRealValues(),
        );
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function knownRulesProvider(): iterable
    {
        foreach (StubRemediationMinutes::withRealValues() as $ruleName => $minutes) {
            yield $ruleName => [$ruleName, $minutes];
        }
    }

    #[Test]
    #[DataProvider('knownRulesProvider')]
    public function itReturnsTheInjectedBaseMinutesForKnownRule(string $ruleName, int $expectedMinutes): void
    {
        self::assertSame($expectedMinutes, $this->registry->getBaseMinutes($ruleName));
    }

    /**
     * No fallback remains: `MINUTES_BY_RULE` and `DEFAULT_MINUTES` are gone,
     * and every registered rule declares its own minutes on its own class
     * (see {@see RuleRemediationMinutesCoverageTest}). A name absent from the
     * injected map is not a legitimately unknown rule to silently default
     * for — it means a violation carries a rule name no rule declared.
     */
    #[Test]
    public function itThrowsForARuleNameNotInTheInjectedMap(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('No remediation minutes declared for rule "unknown.rule"');

        $this->registry->getBaseMinutes('unknown.rule');
    }

    #[Test]
    public function itThrowsWhenTheInjectedMapIsEmpty(): void
    {
        $registry = new RemediationTimeRegistry(StubChannelDeclarationRegistry::alwaysHigherMagnitude());

        self::expectException(LogicException::class);

        $registry->getBaseMinutes('complexity.cyclomatic');
    }

    #[Test]
    public function itUsesBaseMinutesForViolationWithoutMetricValue(): void
    {
        $violation = $this->createViolation('complexity.cyclomatic');

        self::assertSame(30, $this->registry->getMinutesForViolation($violation));
    }

    #[Test]
    public function itUsesBaseMinutesForViolationWithoutThreshold(): void
    {
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 25);

        self::assertSame(30, $this->registry->getMinutesForViolation($violation));
    }

    #[Test]
    public function itGivesBaseDebtForMinorCcnOvershoot(): void
    {
        // CCN=21, threshold=20: ln(1.05)=0.049 < 1 → base * max(1, 0.049) = 30 * 1 = 30
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 21, threshold: 20);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(30, $minutes);
    }

    #[Test]
    public function itGivesBaseDebtForModerateCcnOvershoot(): void
    {
        // CCN=50, threshold=20: ln(2.5)=0.916 < 1 → base * max(1, 0.916) = 30 * 1 = 30
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 50, threshold: 20);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(30, $minutes);
    }

    #[Test]
    public function itScalesDebtAboveBaseForLargeOvershoot(): void
    {
        // CCN=60, threshold=20: ln(3.0)=1.099 > 1 → 30 * 1.099 ≈ 33
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 60, threshold: 20);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(33, $minutes);
    }

    #[Test]
    public function itGivesLargeDebtForExtremeOvershoot(): void
    {
        // NPath=1000000, threshold=200: ln(5000)=8.517 → 30 * 8.517 ≈ 256
        $violation = $this->createViolation('complexity.npath', metricValue: 1000000, threshold: 200);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(256, $minutes);
    }

    #[Test]
    public function itHandlesInvertedRuleForMaintainabilityIndex(): void
    {
        // Direction is read from the declaration, not from a private copy —
        // maintainability.index is declared Lower (a magnitude decreasing
        // below its threshold is worse), so the overshoot ratio flips.
        $registry = new RemediationTimeRegistry(
            new StubChannelDeclarationRegistry([
                'maintainability.index#maintainability.index' => ChannelDeclaration::magnitude(WorseDirection::Lower),
            ]),
            StubRemediationMinutes::withRealValues(),
        );

        // MI=30, threshold=50 (inverted): ratio=50/30=1.667, ln(1.667)=0.511, max(1, 0.511)=1 → 60*1=60
        $violation = $this->createViolation('maintainability.index', metricValue: 30, threshold: 50);

        $minutes = $registry->getMinutesForViolation($violation);

        self::assertSame(60, $minutes);
    }

    #[Test]
    public function itHandlesInvertedRuleForTypeCoverage(): void
    {
        $registry = new RemediationTimeRegistry(
            new StubChannelDeclarationRegistry([
                'design.type-coverage#design.type-coverage' => ChannelDeclaration::magnitude(WorseDirection::Lower),
            ]),
            StubRemediationMinutes::withRealValues(),
        );

        // Type coverage=40, threshold=80 (inverted): ratio=80/40=2, ln(2)=0.693, max(1, 0.693)=1 → 15*1=15
        $violation = $this->createViolation('design.type-coverage', metricValue: 40, threshold: 80);

        $minutes = $registry->getMinutesForViolation($violation);

        self::assertSame(15, $minutes);
    }

    #[Test]
    public function itHandlesComputedHealthWithInvertedMetricReadFromDefinition(): void
    {
        // The computed-metric family resolves its direction from
        // ComputedMetricDefinition::$inverted, not from a metricValue <
        // threshold heuristic — modelled here by declaring the family's
        // channel Lower directly, as the real registry would for an
        // inverted definition.
        $registry = new RemediationTimeRegistry(
            new StubChannelDeclarationRegistry([
                ComputedMetricChannelFamily::PRODUCER_RULE_NAME . '#' . ComputedMetricChannelFamily::PRODUCER_RULE_NAME
                    => ChannelDeclaration::magnitude(WorseDirection::Lower),
            ]),
            StubRemediationMinutes::withRealValues(),
        );

        // health score=30 (below threshold=50): ratio=50/30=1.667, ln=0.511, max(1, 0.511)=1 → 15*1=15
        $violation = $this->createViolation(ComputedMetricChannelFamily::PRODUCER_RULE_NAME, metricValue: 30, threshold: 50);

        $minutes = $registry->getMinutesForViolation($violation);

        self::assertSame(15, $minutes);
    }

    #[Test]
    public function itHandlesComputedHealthWithNormalMetric(): void
    {
        $registry = new RemediationTimeRegistry(
            new StubChannelDeclarationRegistry([
                ComputedMetricChannelFamily::PRODUCER_RULE_NAME . '#' . ComputedMetricChannelFamily::PRODUCER_RULE_NAME
                    => ChannelDeclaration::magnitude(WorseDirection::Higher),
            ]),
            StubRemediationMinutes::withRealValues(),
        );

        // Normal computed metric value > threshold: ratio=100/50=2, ln(2)=0.693, max(1, 0.693)=1 → 15*1=15
        $violation = $this->createViolation(ComputedMetricChannelFamily::PRODUCER_RULE_NAME, metricValue: 100, threshold: 50);

        $minutes = $registry->getMinutesForViolation($violation);

        self::assertSame(15, $minutes);
    }

    #[Test]
    public function itUsesBaseMinutesForZeroThreshold(): void
    {
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 25, threshold: 0);

        self::assertSame(30, $this->registry->getMinutesForViolation($violation));
    }

    #[Test]
    public function itUsesBaseMinutesForZeroMetricValue(): void
    {
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 0, threshold: 20);

        self::assertSame(30, $this->registry->getMinutesForViolation($violation));
    }

    #[Test]
    public function itUsesBaseMinutesWhenMetricEqualsThreshold(): void
    {
        // Ratio = 1, ln(1) = 0 → base fallback
        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 20, threshold: 20);

        self::assertSame(30, $this->registry->getMinutesForViolation($violation));
    }

    #[Test]
    public function itGivesBaseMinutesForMinorOvershootOnSmallRule(): void
    {
        // Small overshoot: ratio=6/5=1.2, ln(1.2)=0.182, max(1, 0.182)=1 → 5*1=5
        $violation = $this->createViolation('code-smell.debug-code', metricValue: 6, threshold: 5);

        $minutes = $this->registry->getMinutesForViolation($violation);

        self::assertSame(5, $minutes);
    }

    /**
     * The fail-closed policy, asserted directly against a synthetic channel
     * whose declaration carries no direction — not by comparing two tables
     * that would agree from birth. A channel this happens to today is
     * `coupling.class-rank` (see the dedicated test below); this one proves
     * the policy itself, independent of any specific rule.
     */
    #[Test]
    public function itDoesNotScaleAViolationOnAChannelWhoseDeclarationCarriesNoDirection(): void
    {
        $registry = new RemediationTimeRegistry(
            new StubChannelDeclarationRegistry([
                'code-smell.goto#code-smell.goto' => ChannelDeclaration::occurrence(),
            ]),
            StubRemediationMinutes::withRealValues(),
        );

        // Base for code-smell.goto is 15. A huge overshoot would scale to
        // far more than 15 if direction were assumed rather than read.
        $violation = $this->createViolation('code-smell.goto', metricValue: 1000, threshold: 1);

        self::assertSame(15, $registry->getMinutesForViolation($violation));
    }

    /**
     * Same policy, on an unregistered channel entirely (no static
     * declaration and not a resolvable computed metric) —
     * {@see ChannelDeclarationRegistryInterface::declarationFor()} returns
     * `null`, which must be treated identically to an `occurrence`
     * declaration: not scaled.
     */
    #[Test]
    public function itDoesNotScaleAViolationOnAChannelWithNoDeclarationAtAll(): void
    {
        $registry = new RemediationTimeRegistry(
            new StubChannelDeclarationRegistry(),
            StubRemediationMinutes::withRealValues(),
        );

        $violation = $this->createViolation('complexity.cyclomatic', metricValue: 1000, threshold: 1);

        self::assertSame(30, $registry->getMinutesForViolation($violation));
    }

    /**
     * `coupling.class-rank` takes the flat base time: its declaration is
     * `occurrence` (a project-wide normalised PageRank rescaled per class
     * count is not comparable across runs — see
     * `ClassRankRule::channelDeclarations()`), so it is one of the channels
     * the fail-closed policy above applies to, even though it emits both a
     * `metricValue` and a `threshold` today.
     */
    #[Test]
    public function itTakesTheFlatBaseTimeForClassRankRegardlessOfMetricAndThreshold(): void
    {
        $registry = new RemediationTimeRegistry(
            new StubChannelDeclarationRegistry([
                'coupling.class-rank#coupling.class-rank' => ChannelDeclaration::occurrence(),
            ]),
            StubRemediationMinutes::withRealValues(),
        );

        $violation = $this->createViolation('coupling.class-rank', metricValue: 0.9, threshold: 0.01);

        self::assertSame(30, $registry->getMinutesForViolation($violation));
    }

    private function createViolation(
        string $ruleName,
        int|float|null $metricValue = null,
        int|float|null $threshold = null,
    ): Violation {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Test.php'), 1),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', 'TestClass'), RelativePath::fromString('src/Test.php'), DeclarationOrdinal::fromRank(0))),
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
