<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Rule\Override;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\Override\InvertedOverrideValidator;
use Qualimetrix\Core\Rule\Override\OverrideValidationFailure;

#[CoversClass(InvertedOverrideValidator::class)]
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
        self::assertNull($this->validator->validate(40, 20, true));
    }

    #[Test]
    public function itAcceptsWarningEqualsError(): void
    {
        self::assertNull($this->validator->validate(30, 30, true));
    }

    #[Test]
    public function itRejectsWarningBelowError(): void
    {
        $failure = $this->validator->validate(10, 30, true);

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
        self::assertSame('negative_warning', $this->validator->validate(-1, 20, true)?->code);
        self::assertSame('negative_error', $this->validator->validate(40, -1, true)?->code);
    }

    #[Test]
    public function itAcceptsNullsAsNoOpHalves(): void
    {
        self::assertNull($this->validator->validate(null, null, false));
        self::assertNull($this->validator->validate(40, null, false));
        self::assertNull($this->validator->validate(null, 20, true));
    }

    #[Test]
    public function itHandlesFloatThresholds(): void
    {
        self::assertNull($this->validator->validate(0.8, 0.5, true));

        $failure = $this->validator->validate(0.3, 0.5, true);
        self::assertInstanceOf(OverrideValidationFailure::class, $failure);
        self::assertSame('error_exceeds_warning', $failure->code);
    }
}
