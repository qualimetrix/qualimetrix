<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Baseline;

use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Suppression\ThresholdOverrideExtractor;
use Qualimetrix\Core\Rule\Override\IndependentAxisValidator;
use Qualimetrix\Core\Rule\Override\InvertedOverrideValidator;
use Qualimetrix\Core\Rule\Override\StandardOverrideValidator;
use Qualimetrix\Core\Rule\Override\WarningOnlyValidator;
use Qualimetrix\Rules\Design\DataClassOptions;
use Qualimetrix\Rules\Design\DataClassRule;
use Qualimetrix\Rules\Design\GodClassOptions;
use Qualimetrix\Rules\Design\GodClassRule;
use Qualimetrix\Rules\Design\TypeCoverageOptions;
use Qualimetrix\Rules\Design\TypeCoverageRule;
use Qualimetrix\Rules\Maintainability\MaintainabilityOptions;
use Qualimetrix\Rules\Maintainability\MaintainabilityRule;
use Qualimetrix\Rules\Size\MethodCountRule;

/**
 * End-to-end coverage for the `@qmx-threshold` annotation path that
 * v0.18.0 shipped without: docblock text → ThresholdOverrideExtractor →
 * validator delegation → ThresholdOverride (or diagnostic). Each
 * validator strategy has at least one annotation case exercised through
 * the real parser, not through `Options::withOverride()` directly.
 *
 * The original integration tests for the four Design rules called
 * `withOverride()` from PHP code, which is why the parser-level rejection
 * of inverted or multi-axis values was not caught before release.
 */
#[CoversClass(ThresholdOverrideExtractor::class)]
#[CoversClass(MaintainabilityRule::class)]
#[CoversClass(TypeCoverageRule::class)]
#[CoversClass(DataClassRule::class)]
#[CoversClass(GodClassRule::class)]
#[CoversClass(MethodCountRule::class)]
final class ThresholdAnnotationParserPathTest extends TestCase
{
    #[Test]
    public function standardRuleAcceptsWarningBelowError(): void
    {
        $result = $this->extract(
            ruleName: MethodCountRule::NAME,
            validator: StandardOverrideValidator::instance(),
            docblock: '/** @qmx-threshold size.method-count warning=15 error=25 */',
        );

        self::assertCount(0, $result->diagnostics);
        self::assertCount(1, $result->overrides);
        self::assertSame(15, $result->overrides[0]->warning);
        self::assertSame(25, $result->overrides[0]->error);
    }

    #[Test]
    public function standardRuleRejectsWarningAboveError(): void
    {
        $result = $this->extract(
            ruleName: MethodCountRule::NAME,
            validator: StandardOverrideValidator::instance(),
            docblock: '/** @qmx-threshold size.method-count warning=25 error=15 */',
        );

        self::assertCount(0, $result->overrides);
        self::assertCount(1, $result->diagnostics);
        self::assertSame('warning_exceeds_error', $result->diagnostics[0]->code);
    }

    #[Test]
    public function invertedRuleAcceptsWarningAboveErrorClosingMaintainabilityLatentBug(): void
    {
        // Regression test for the Maintainability bug latent since v0.x:
        // defaults are warning=40, error=20 (W > E natural for inverted rules),
        // so the only sensible user override is also W > E. The pre-v0.19 parser
        // rejected this at extract() time. Now it must pass.
        $result = $this->extract(
            ruleName: MaintainabilityRule::NAME,
            validator: MaintainabilityOptions::getOverrideValidator(),
            docblock: '/** @qmx-threshold maintainability.index warning=50 error=30 */',
        );

        self::assertSame(InvertedOverrideValidator::instance(), MaintainabilityOptions::getOverrideValidator());
        self::assertCount(0, $result->diagnostics);
        self::assertCount(1, $result->overrides);
        self::assertSame(50, $result->overrides[0]->warning);
        self::assertSame(30, $result->overrides[0]->error);
    }

    #[Test]
    public function invertedRuleRejectsWarningBelowError(): void
    {
        $result = $this->extract(
            ruleName: MaintainabilityRule::NAME,
            validator: MaintainabilityOptions::getOverrideValidator(),
            docblock: '/** @qmx-threshold maintainability.index warning=20 error=40 */',
        );

        self::assertCount(0, $result->overrides);
        self::assertCount(1, $result->diagnostics);
        self::assertSame('error_exceeds_warning', $result->diagnostics[0]->code);
    }

    #[Test]
    public function invertedRuleAcceptsTypeCoverageOverride(): void
    {
        $result = $this->extract(
            ruleName: TypeCoverageRule::NAME,
            validator: TypeCoverageOptions::getOverrideValidator(),
            docblock: '/** @qmx-threshold design.type-coverage warning=70 error=40 */',
        );

        self::assertSame(InvertedOverrideValidator::instance(), TypeCoverageOptions::getOverrideValidator());
        self::assertCount(0, $result->diagnostics);
        self::assertCount(1, $result->overrides);
        self::assertSame(70, $result->overrides[0]->warning);
        self::assertSame(40, $result->overrides[0]->error);
    }

    #[Test]
    public function independentAxisRuleAcceptsWocHighWmcLow(): void
    {
        // DataClass: warning -> wocThreshold (high), error -> wmcThreshold (low).
        // Both axes independent; W > E is just as valid as W < E.
        $result = $this->extract(
            ruleName: DataClassRule::NAME,
            validator: DataClassOptions::getOverrideValidator(),
            docblock: '/** @qmx-threshold design.data-class warning=90 error=5 */',
        );

        self::assertSame(IndependentAxisValidator::instance(), DataClassOptions::getOverrideValidator());
        self::assertCount(0, $result->diagnostics);
        self::assertCount(1, $result->overrides);
        self::assertSame(90, $result->overrides[0]->warning);
        self::assertSame(5, $result->overrides[0]->error);
    }

    #[Test]
    public function independentAxisRuleAcceptsArbitraryOrdering(): void
    {
        // W < E should also pass — the two values target different metrics.
        $result = $this->extract(
            ruleName: DataClassRule::NAME,
            validator: DataClassOptions::getOverrideValidator(),
            docblock: '/** @qmx-threshold design.data-class warning=50 error=80 */',
        );

        self::assertCount(0, $result->diagnostics);
        self::assertCount(1, $result->overrides);
    }

    #[Test]
    public function warningOnlyRuleAcceptsShorthandThatExpandsToEqualWarningAndError(): void
    {
        // GodClass: shorthand `@qmx-threshold X N` parses as W=N, E=N with
        // errorWasExplicit=false. WarningOnly must accept this so the
        // shorthand path keeps working — the user did not write `error=N`.
        $result = $this->extract(
            ruleName: GodClassRule::NAME,
            validator: GodClassOptions::getOverrideValidator(),
            docblock: '/** @qmx-threshold design.god-class 2 */',
        );

        self::assertSame(WarningOnlyValidator::instance(), GodClassOptions::getOverrideValidator());
        self::assertCount(0, $result->diagnostics);
        self::assertCount(1, $result->overrides);
        self::assertSame(2, $result->overrides[0]->warning);
        self::assertSame(2, $result->overrides[0]->error);
    }

    #[Test]
    public function warningOnlyRuleAcceptsExplicitWarningOnly(): void
    {
        $result = $this->extract(
            ruleName: GodClassRule::NAME,
            validator: GodClassOptions::getOverrideValidator(),
            docblock: '/** @qmx-threshold design.god-class warning=3 */',
        );

        self::assertCount(0, $result->diagnostics);
        self::assertCount(1, $result->overrides);
        self::assertSame(3, $result->overrides[0]->warning);
        self::assertNull($result->overrides[0]->error);
    }

    #[Test]
    public function warningOnlyRuleRejectsExplicitErrorValue(): void
    {
        $result = $this->extract(
            ruleName: GodClassRule::NAME,
            validator: GodClassOptions::getOverrideValidator(),
            docblock: '/** @qmx-threshold design.god-class warning=3 error=5 */',
        );

        self::assertCount(0, $result->overrides);
        self::assertCount(1, $result->diagnostics);
        self::assertSame('error_not_supported', $result->diagnostics[0]->code);
    }

    #[Test]
    public function wildcardPatternSkipsValidatorLevelChecks(): void
    {
        // Wildcards intentionally skip per-rule validation in v0.19. The
        // existing post-analysis `annotation.unsupported-threshold` check
        // surfaces problems instead. Document the behaviour with a test so
        // a future change is deliberate.
        $result = $this->extract(
            ruleName: MethodCountRule::NAME,
            validator: StandardOverrideValidator::instance(),
            docblock: '/** @qmx-threshold * warning=25 error=15 */',
        );

        self::assertCount(0, $result->diagnostics);
        self::assertCount(1, $result->overrides);
        self::assertSame('*', $result->overrides[0]->rulePattern);
    }

    private function extract(
        string $ruleName,
        \Qualimetrix\Core\Rule\Override\OverrideValidatorInterface $validator,
        string $docblock,
    ): \Qualimetrix\Baseline\Suppression\ThresholdOverrideExtractionResult {
        $node = new Class_('TestClass');
        $node->setDocComment(new Doc($docblock, 10));
        $node->setAttribute('endLine', 50);

        $extractor = new ThresholdOverrideExtractor([$ruleName => $validator]);

        return $extractor->extractWithDiagnostics($node);
    }
}
