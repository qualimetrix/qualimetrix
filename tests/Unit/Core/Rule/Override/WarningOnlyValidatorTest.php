<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Rule\Override;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\Override\OverrideValidationFailure;
use Qualimetrix\Core\Rule\Override\WarningOnlyValidator;

#[CoversClass(WarningOnlyValidator::class)]
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
        self::assertNull($this->validator->validate(5, null, false));
        self::assertNull($this->validator->validate(5, null, true));
    }

    #[Test]
    public function itAcceptsShorthandFormEvenThoughErrorEqualsWarning(): void
    {
        // Shorthand `@qmx-threshold X 5` parses as W=5,E=5 with errorWasExplicit=false
        self::assertNull($this->validator->validate(5, 5, false));
    }

    #[Test]
    public function itRejectsExplicitErrorValue(): void
    {
        // `@qmx-threshold X warning=5 error=10` — user explicitly set error, must reject
        $failure = $this->validator->validate(5, 10, true);

        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('error_not_supported', $failure->code);
        self::assertStringContainsString('only honours the warning threshold', $failure->message);
        self::assertNotNull($failure->hint);
    }

    #[Test]
    public function itRejectsExplicitErrorEvenWhenItEqualsWarning(): void
    {
        // `@qmx-threshold X warning=5 error=5` — explicit form still rejected
        $failure = $this->validator->validate(5, 5, true);

        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('error_not_supported', $failure->code);
    }

    #[Test]
    public function itRejectsNegativeWarning(): void
    {
        $failure = $this->validator->validate(-1, null, false);

        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('negative_warning', $failure->code);
    }

    #[Test]
    public function itAcceptsAllNullsAsNoOp(): void
    {
        self::assertNull($this->validator->validate(null, null, false));
    }
}
