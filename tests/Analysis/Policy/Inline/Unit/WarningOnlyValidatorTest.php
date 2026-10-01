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
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\WarningOnlyValidator;
use Qualimetrix\Analysis\Finding\Rule\Override\OverrideValidationFailure;

#[CoversClass(WarningOnlyValidator::class)]
#[CoversClass(ThresholdOverrideRequest::class)]
final class WarningOnlyValidatorTest extends TestCase
{
    private WarningOnlyValidator $validator;

    protected function setUp(): void
    {
        $this->validator = WarningOnlyValidator::instance();
    }

    #[Test]
    public function itIsASharedSingleton(): void
    {
        self::assertSame(WarningOnlyValidator::instance(), WarningOnlyValidator::instance());
    }

    #[Test]
    public function itAcceptsWarningOnly(): void
    {
        // Explicit warning-only form: `@qmx-threshold X warning=5`
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(5, null, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning])));
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(5, null, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning])));
    }

    #[Test]
    public function itAcceptsShorthandFormEvenThoughErrorEqualsWarning(): void
    {
        // Shorthand `@qmx-threshold X 5` carries equal values without authored axes
        self::assertNull($this->validator->validate(new ThresholdOverrideRequest(5, 5, OverrideSyntax::Shorthand, [])));
    }

    #[Test]
    public function itRejectsExplicitErrorValue(): void
    {
        // `@qmx-threshold X warning=5 error=10` — user explicitly set error, must reject
        $failure = $this->validator->validate(new ThresholdOverrideRequest(5, 10, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error]));

        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('error_not_supported', $failure->code);
        self::assertStringContainsString('only honours the warning threshold', $failure->message);
        self::assertNotNull($failure->hint);
    }

    #[Test]
    public function itRejectsExplicitErrorEvenWhenItEqualsWarning(): void
    {
        // `@qmx-threshold X warning=5 error=5` — explicit form still rejected
        $failure = $this->validator->validate(new ThresholdOverrideRequest(5, 5, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning, OverrideAxis::Error]));

        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('error_not_supported', $failure->code);
    }

    #[Test]
    public function itRejectsNegativeWarning(): void
    {
        $failure = $this->validator->validate(new ThresholdOverrideRequest(-1, null, OverrideSyntax::ExplicitAxes, [OverrideAxis::Warning]));

        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('negative_warning', $failure->code);
    }

    #[Test]
    public function itRefusesAnAbsentOverride(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('equal non-null values');
        new ThresholdOverrideRequest(null, null, OverrideSyntax::Shorthand, []);
    }
}
