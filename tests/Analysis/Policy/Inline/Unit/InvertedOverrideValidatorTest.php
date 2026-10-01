<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\InvertedOverrideValidator;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideAxis;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideSyntax;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\ThresholdOverrideRequest;
use Qualimetrix\Analysis\Finding\Rule\Override\OverrideValidationFailure;

#[CoversClass(InvertedOverrideValidator::class)]
#[CoversClass(ThresholdOverrideRequest::class)]
final class InvertedOverrideValidatorTest extends TestCase
{
    private InvertedOverrideValidator $validator;

    protected function setUp(): void
    {
        $this->validator = InvertedOverrideValidator::instance();
    }

    #[Test]
    public function itIsASharedSingleton(): void
    {
        self::assertSame(InvertedOverrideValidator::instance(), InvertedOverrideValidator::instance());
    }

    #[Test]
    public function itAcceptsWarningAboveError(): void
    {
        // Maintainability defaults: warning=40, error=20 — natural state for inverted rules
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(40, 20, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error])));
    }

    #[Test]
    public function itAcceptsWarningEqualsError(): void
    {
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(30, 30, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error])));
    }

    #[Test]
    public function itRejectsWarningBelowError(): void
    {
        $failure = $this->validator->validate(new ThresholdOverrideRequest(10, 30, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error]));

        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('error_exceeds_warning', $failure->code);
        self::assertStringContainsString('warning threshold (10) must not be below error threshold (30)', $failure->message);
        self::assertSame(
            'inverted-threshold rules require warning >= error (e.g. maintainability warns at MI=40, errors at MI=20)',
            $failure->hint,
        );
    }

    #[Test]
    public function itRejectsNegativeValues(): void
    {
        self::assertSame('negative_warning', $this->validator->validate(new ThresholdOverrideRequest(-1, 20, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error]))?->code);
        self::assertSame('negative_error', $this->validator->validate(new ThresholdOverrideRequest(40, -1, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error]))?->code);
    }

    #[Test]
    public function itAcceptsPartialAxesAndRefusesAnAbsentOverride(): void
    {
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(40, null, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning])));
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(null, 20, OverrideSyntax::ExplicitAxes, [OverrideAxis::Error])));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('equal non-null values');
        new ThresholdOverrideRequest(null, null, OverrideSyntax::Shorthand, []);
    }

    #[Test]
    public function itHandlesFloatThresholds(): void
    {
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(0.8, 0.5, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error])));

        $failure = $this->validator->validate(new ThresholdOverrideRequest(0.3, 0.5, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error]));
        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('error_exceeds_warning', $failure->code);
    }
}
