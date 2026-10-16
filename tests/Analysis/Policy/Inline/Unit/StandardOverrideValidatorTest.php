<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideAxis;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideSyntax;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\ThresholdOverrideRequest;
use Qualimetrix\Analysis\Finding\Rule\Override\OverrideValidationFailure;
use Qualimetrix\Analysis\Finding\Rule\Override\StandardOverrideValidator;

#[CoversClass(StandardOverrideValidator::class)]
#[CoversClass(OverrideValidationFailure::class)]
#[CoversClass(ThresholdOverrideRequest::class)]
final class StandardOverrideValidatorTest extends TestCase
{
    private StandardOverrideValidator $validator;

    protected function setUp(): void
    {
        $this->validator = StandardOverrideValidator::instance();
    }

    #[Test]
    public function itIsASharedSingleton(): void
    {
        self::assertSame(StandardOverrideValidator::instance(), StandardOverrideValidator::instance());
    }

    #[Test]
    public function itAcceptsWarningBelowError(): void
    {
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(10, 20, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error])));
    }

    #[Test]
    public function itAcceptsWarningEqualsError(): void
    {
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(15, 15, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error])));
    }

    #[Test]
    public function itAcceptsNumericallyEqualShorthandValuesAcrossNumberKinds(): void
    {
        try {
            $request = new ThresholdOverrideRequest(5, 5.0, OverrideSyntax::Shorthand, []);
        } catch (LogicException) {
            self::fail('Numerically equal shorthand values must be accepted across integer and float kinds.');
        }

        self::assertSame(5, $request->warning);
        self::assertSame(5.0, $request->error);
        self::assertNull($this->validator->validate($request));
    }

    #[Test]
    public function itRejectsWarningAboveError(): void
    {
        $failure = $this->validator->validate(new ThresholdOverrideRequest(25, 10, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error]));

        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('warning_exceeds_error', $failure->code);
        self::assertStringContainsString('warning threshold (25) must not exceed error threshold (10)', $failure->message);
    }

    #[Test]
    public function itRejectsNegativeWarning(): void
    {
        $failure = $this->validator->validate(new ThresholdOverrideRequest(-5, 10, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error]));

        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('negative_warning', $failure->code);
    }

    #[Test]
    public function itRejectsNegativeError(): void
    {
        $failure = $this->validator->validate(new ThresholdOverrideRequest(5, -10, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error]));

        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('negative_error', $failure->code);
    }

    #[Test]
    public function itAcceptsPartialAxesAndRefusesAnAbsentOverride(): void
    {
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(10, null, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning])));
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(null, 20, OverrideSyntax::ExplicitAxes, [OverrideAxis::Error])));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('equal non-null values');
        new ThresholdOverrideRequest(null, null, OverrideSyntax::Shorthand, []);
    }

    #[Test]
    public function itUsesTheSameNumericalOrderingForBothAuthoredPairs(): void
    {
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(10, 20, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error])));
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(10, 20, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error])));
    }

    #[Test]
    public function itHandlesFloatThresholds(): void
    {
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(0.3, 0.5, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error])));

        $failure = $this->validator->validate(new ThresholdOverrideRequest(0.5, 0.3, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error]));
        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('warning_exceeds_error', $failure->code);
    }

    #[Test]
    public function itRefusesEmptyExplicitAxes(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('at least one authored axis');
        new ThresholdOverrideRequest(null, null, OverrideSyntax::ExplicitAxes, []);
    }

    #[Test]
    public function itRefusesAxesThatDoNotCorrespondToTheirValues(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('correspond exactly');
        new ThresholdOverrideRequest(5, null, OverrideSyntax::ExplicitAxes, [OverrideAxis::Error]);
    }

    #[Test]
    public function itRefusesARepeatedAuthoredAxis(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be authored twice');
        new ThresholdOverrideRequest(5, null, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Warning]);
    }

    #[Test]
    public function itRefusesUnequalShorthandValues(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('equal non-null values');
        new ThresholdOverrideRequest(5, 50, OverrideSyntax::Shorthand, []);
    }
}
