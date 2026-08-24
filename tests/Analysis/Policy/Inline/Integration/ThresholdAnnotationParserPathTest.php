<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Integration;

use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Design\DataClassOptions;
use Qualimetrix\Analysis\Evidence\Design\DataClassRule;
use Qualimetrix\Analysis\Evidence\Design\GodClassOptions;
use Qualimetrix\Analysis\Evidence\Design\GodClassRule;
use Qualimetrix\Analysis\Evidence\Design\ParamTypeCoverageRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverageOptions;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityOptions;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityRule;
use Qualimetrix\Analysis\Evidence\Size\MethodCountRule;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\IndependentAxisValidator;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\InvertedOverrideValidator;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\WarningOnlyValidator;
use Qualimetrix\Analysis\Finding\Rule\Override\StandardOverrideValidator;
use Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

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
#[CoversClass(ParamTypeCoverageRule::class)]
#[CoversClass(DataClassRule::class)]
#[CoversClass(GodClassRule::class)]
#[CoversClass(MethodCountRule::class)]
#[CoversClass(ComplexityRule::class)]
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
            ruleName: ParamTypeCoverageRule::NAME,
            validator: TypeCoverageOptions::getOverrideValidator(),
            docblock: '/** @qmx-threshold design.param-type-coverage warning=70 error=40 */',
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
    public function hierarchicalRuleValidationDelegatesToLevelOptions(): void
    {
        // Regression test for the v0.19 bug class: ComplexityOptions is a
        // HierarchicalRuleOptionsInterface (not directly ThresholdAware), but
        // its level Options (MethodComplexityOptions, ClassComplexityOptions)
        // are Standard ThresholdAware. The factory now walks levels so the
        // parser actually receives a validator for complexity.cyclomatic
        // instead of silently skipping it.
        $rootOptions = ComplexityOptions::fromArray([]);
        $levelOptions = $rootOptions->forLevel(\Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel::Callable);
        self::assertInstanceOf(\Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface::class, $levelOptions);
        $validator = $levelOptions::getOverrideValidator();
        self::assertSame(StandardOverrideValidator::instance(), $validator);

        $result = $this->extract(
            ruleName: ComplexityRule::NAME,
            validator: $validator,
            docblock: '/** @qmx-threshold complexity.cyclomatic warning=25 error=15 */',
        );

        self::assertCount(0, $result->overrides);
        self::assertCount(1, $result->diagnostics);
        self::assertSame('warning_exceeds_error', $result->diagnostics[0]->code);
    }

    #[Test]
    public function wildcardPatternParsesValidValueSyntax(): void
    {
        // Wildcards intentionally skip per-rule validator checks. This only
        // covers the valid parser form, not an invalid W > E pair that would
        // be rejected for a concrete standard rule.
        $result = $this->extract(
            ruleName: MethodCountRule::NAME,
            validator: StandardOverrideValidator::instance(),
            docblock: '/** @qmx-threshold * warning=15 error=25 */',
        );

        self::assertCount(0, $result->diagnostics);
        self::assertCount(1, $result->overrides);
        self::assertSame('*', $result->overrides[0]->rulePattern);
    }

    private function extract(
        string $ruleName,
        \Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface $validator,
        string $docblock,
    ): \Qualimetrix\Analysis\Policy\Inline\ThresholdOverrideExtractionResult {
        $node = new Class_('TestClass');
        $node->setDocComment(new Doc($docblock, 10));
        $node->setAttribute('endLine', 50);

        $extractor = new ThresholdOverrideExtractor([$ruleName => $validator]);

        return $extractor->extractWithDiagnostics(
            $node,
            MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/TestClass.php'))),
            ControlScope::Class_,
        );
    }
}
