<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Json;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\Formatter\Json\JsonSanitizer;

#[CoversClass(JsonSanitizer::class)]
final class JsonSanitizerTest extends TestCase
{
    private JsonSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new JsonSanitizer();
    }

    // --- sanitizeFloat ---

    #[Test]
    public function itSanitizesFloatPassesThroughNormalValue(): void
    {
        self::assertSame(3.14, $this->sanitizer->sanitizeFloat(3.14));
    }

    #[Test]
    public function itSanitizesFloatPassesThroughZero(): void
    {
        self::assertSame(0.0, $this->sanitizer->sanitizeFloat(0.0));
    }

    #[Test]
    public function itSanitizesFloatPassesThroughNegative(): void
    {
        self::assertSame(-42.5, $this->sanitizer->sanitizeFloat(-42.5));
    }

    #[Test]
    public function itSanitizesFloatConvertsNanToNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeFloat(\NAN));
    }

    #[Test]
    public function itSanitizesFloatConvertsInfinityToNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeFloat(\INF));
    }

    #[Test]
    public function itSanitizesFloatConvertsNegativeInfinityToNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeFloat(-\INF));
    }

    // --- sanitizeNumeric ---

    #[Test]
    public function itSanitizesNumericPassesThroughInt(): void
    {
        self::assertSame(42, $this->sanitizer->sanitizeNumeric(42));
    }

    #[Test]
    public function itSanitizesNumericPassesThroughFloat(): void
    {
        self::assertSame(1.5, $this->sanitizer->sanitizeNumeric(1.5));
    }

    #[Test]
    public function itSanitizesNumericPassesThroughNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeNumeric(null));
    }

    #[Test]
    public function itSanitizesNumericConvertsNanToNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeNumeric(\NAN));
    }

    #[Test]
    public function itSanitizesNumericConvertsInfinityToNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeNumeric(\INF));
    }

    #[Test]
    public function itSanitizesNumericConvertsNegativeInfinityToNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeNumeric(-\INF));
    }

    // --- sanitizeFloatArray ---

    #[Test]
    public function itSanitizesFloatArrayPassesThroughCleanValues(): void
    {
        $input = ['a' => 1.0, 'b' => 2, 'c' => 3.5];
        $expected = ['a' => 1.0, 'b' => 2, 'c' => 3.5];

        self::assertSame($expected, $this->sanitizer->sanitizeFloatArray($input));
    }

    #[Test]
    public function itSanitizesFloatArrayConvertsNonFiniteValues(): void
    {
        $input = ['ok' => 5.0, 'nan' => \NAN, 'inf' => \INF, 'neg_inf' => -\INF, 'int' => 10];
        $result = $this->sanitizer->sanitizeFloatArray($input);

        self::assertSame(5.0, $result['ok']);
        self::assertNull($result['nan']);
        self::assertNull($result['inf']);
        self::assertNull($result['neg_inf']);
        self::assertSame(10, $result['int']);
    }

    #[Test]
    public function itSanitizesFloatArrayHandlesEmptyArray(): void
    {
        self::assertSame([], $this->sanitizer->sanitizeFloatArray([]));
    }
}
