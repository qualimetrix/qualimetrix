<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Rule\Override;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\Override\OverrideValidationFailure;
use Qualimetrix\Core\Rule\Override\StandardOverrideValidator;

#[CoversClass(StandardOverrideValidator::class)]
#[CoversClass(OverrideValidationFailure::class)]
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
        self::assertNull($this->validator->validate(10, 20, true));
    }

    #[Test]
    public function itAcceptsWarningEqualsError(): void
    {
        self::assertNull($this->validator->validate(15, 15, true));
    }

    #[Test]
    public function itRejectsWarningAboveError(): void
    {
        $failure = $this->validator->validate(25, 10, true);

        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('warning_exceeds_error', $failure->code);
        self::assertStringContainsString('warning threshold (25) must not exceed error threshold (10)', $failure->message);
    }

    #[Test]
    public function itRejectsNegativeWarning(): void
    {
        $failure = $this->validator->validate(-5, 10, true);

        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('negative_warning', $failure->code);
    }

    #[Test]
    public function itRejectsNegativeError(): void
    {
        $failure = $this->validator->validate(5, -10, true);

        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('negative_error', $failure->code);
    }

    #[Test]
    public function itAcceptsNullsAsNoOpHalves(): void
    {
        self::assertNull($this->validator->validate(null, null, false));
        self::assertNull($this->validator->validate(10, null, false));
        self::assertNull($this->validator->validate(null, 20, true));
    }

    #[Test]
    public function itIgnoresErrorWasExplicitFlag(): void
    {
        self::assertNull($this->validator->validate(10, 20, true));
        self::assertNull($this->validator->validate(10, 20, false));
    }

    #[Test]
    public function itHandlesFloatThresholds(): void
    {
        self::assertNull($this->validator->validate(0.3, 0.5, true));

        $failure = $this->validator->validate(0.5, 0.3, true);
        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('warning_exceeds_error', $failure->code);
    }
}
