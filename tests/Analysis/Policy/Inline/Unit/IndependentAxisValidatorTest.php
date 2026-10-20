<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\IndependentAxisValidator;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideAxis;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideSyntax;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\ThresholdOverrideRequest;

#[CoversClass(IndependentAxisValidator::class)]
#[CoversClass(ThresholdOverrideRequest::class)]
final class IndependentAxisValidatorTest extends TestCase
{
    private IndependentAxisValidator $validator;

    protected function setUp(): void
    {
        $this->validator = IndependentAxisValidator::instance();
    }

    #[Test]
    public function itIsASharedSingleton(): void
    {
        self::assertSame(IndependentAxisValidator::instance(), IndependentAxisValidator::instance());
    }

    #[Test]
    public function itAcceptsArbitraryOrdering(): void
    {
        // DataClass: warning -> wocThreshold (high), error -> wmcThreshold (low)
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(90, 5, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error])));   // typical user override
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(50, 80, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error])));  // W < E — independent metrics
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(50, 50, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error])));  // equal
    }

    #[Test]
    public function itRejectsNegativeValues(): void
    {
        self::assertSame('negative_warning', $this->validator->validate(new ThresholdOverrideRequest(-1, 5, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error]))?->code);
        self::assertSame('negative_error', $this->validator->validate(new ThresholdOverrideRequest(5, -1, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error]))?->code);
    }

    #[Test]
    public function itAcceptsPartialAxesAndRefusesAnAbsentOverride(): void
    {
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(90, null, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning])));
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(null, 5, OverrideSyntax::ExplicitAxes, [OverrideAxis::Error])));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('equal non-null values');
        new ThresholdOverrideRequest(null, null, OverrideSyntax::Shorthand, []);
    }

    #[Test]
    public function itUsesTheSameNumericalOrderingForBothAuthoredPairs(): void
    {
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(90, 5, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error])));
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(90, 5, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error])));
    }
}
