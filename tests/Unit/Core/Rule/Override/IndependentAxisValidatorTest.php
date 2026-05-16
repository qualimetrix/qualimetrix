<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Rule\Override;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\Override\IndependentAxisValidator;

#[CoversClass(IndependentAxisValidator::class)]
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
        self::assertNull($this->validator->validate(90, 5, true));   // typical user override
        self::assertNull($this->validator->validate(50, 80, true));  // W < E — independent metrics
        self::assertNull($this->validator->validate(50, 50, true));  // equal
    }

    #[Test]
    public function itRejectsNegativeValues(): void
    {
        self::assertSame('negative_warning', $this->validator->validate(-1, 5, true)?->code);
        self::assertSame('negative_error', $this->validator->validate(5, -1, true)?->code);
    }

    #[Test]
    public function itAcceptsNullsAsNoOpHalves(): void
    {
        self::assertNull($this->validator->validate(null, null, false));
        self::assertNull($this->validator->validate(90, null, false));
        self::assertNull($this->validator->validate(null, 5, true));
    }

    #[Test]
    public function itIgnoresErrorWasExplicitFlag(): void
    {
        self::assertNull($this->validator->validate(90, 5, true));
        self::assertNull($this->validator->validate(90, 5, false));
    }
}
