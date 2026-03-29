<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Json;

use PHPUnit\Framework\Attributes\CoversClass;
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

    public function testSanitizeFloatPassesThroughNormalValue(): void
    {
        self::assertSame(3.14, $this->sanitizer->sanitizeFloat(3.14));
    }

    public function testSanitizeFloatPassesThroughZero(): void
    {
        self::assertSame(0.0, $this->sanitizer->sanitizeFloat(0.0));
    }

    public function testSanitizeFloatPassesThroughNegative(): void
    {
        self::assertSame(-42.5, $this->sanitizer->sanitizeFloat(-42.5));
    }

    public function testSanitizeFloatConvertsNanToNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeFloat(\NAN));
    }

    public function testSanitizeFloatConvertsInfinityToNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeFloat(\INF));
    }

    public function testSanitizeFloatConvertsNegativeInfinityToNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeFloat(-\INF));
    }

    // --- sanitizeNumeric ---

    public function testSanitizeNumericPassesThroughInt(): void
    {
        self::assertSame(42, $this->sanitizer->sanitizeNumeric(42));
    }

    public function testSanitizeNumericPassesThroughFloat(): void
    {
        self::assertSame(1.5, $this->sanitizer->sanitizeNumeric(1.5));
    }

    public function testSanitizeNumericPassesThroughNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeNumeric(null));
    }

    public function testSanitizeNumericConvertsNanToNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeNumeric(\NAN));
    }

    public function testSanitizeNumericConvertsInfinityToNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeNumeric(\INF));
    }

    public function testSanitizeNumericConvertsNegativeInfinityToNull(): void
    {
        self::assertNull($this->sanitizer->sanitizeNumeric(-\INF));
    }

    // --- sanitizeFloatArray ---

    public function testSanitizeFloatArrayPassesThroughCleanValues(): void
    {
        $input = ['a' => 1.0, 'b' => 2, 'c' => 3.5];
        $expected = ['a' => 1.0, 'b' => 2, 'c' => 3.5];

        self::assertSame($expected, $this->sanitizer->sanitizeFloatArray($input));
    }

    public function testSanitizeFloatArrayConvertsNonFiniteValues(): void
    {
        $input = ['ok' => 5.0, 'nan' => \NAN, 'inf' => \INF, 'neg_inf' => -\INF, 'int' => 10];
        $result = $this->sanitizer->sanitizeFloatArray($input);

        self::assertSame(5.0, $result['ok']);
        self::assertNull($result['nan']);
        self::assertNull($result['inf']);
        self::assertNull($result['neg_inf']);
        self::assertSame(10, $result['int']);
    }

    public function testSanitizeFloatArrayHandlesEmptyArray(): void
    {
        self::assertSame([], $this->sanitizer->sanitizeFloatArray([]));
    }
}
