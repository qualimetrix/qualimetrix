<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Support;

use AiMessDetector\Rules\Support\ThresholdParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThresholdParserTest extends TestCase
{
    #[Test]
    public function emptyConfigReturnsDefaults(): void
    {
        $result = ThresholdParser::parse([], 'warning', 'error', 10, 20);

        self::assertSame(10, $result['warning']);
        self::assertSame(20, $result['error']);
    }

    #[Test]
    public function thresholdSetsBothValues(): void
    {
        $result = ThresholdParser::parse(['threshold' => 15], 'warning', 'error', 10, 20);

        self::assertSame(15, $result['warning']);
        self::assertSame(15, $result['error']);
    }

    #[Test]
    public function thresholdZeroSetsBothToZero(): void
    {
        $result = ThresholdParser::parse(['threshold' => 0], 'warning', 'error', 10, 20);

        self::assertSame(0, $result['warning']);
        self::assertSame(0, $result['error']);
    }

    #[Test]
    public function thresholdNullFallsBackToDefaults(): void
    {
        $result = ThresholdParser::parse(['threshold' => null], 'warning', 'error', 10, 20);

        self::assertSame(10, $result['warning']);
        self::assertSame(20, $result['error']);
    }

    #[Test]
    public function warningAndErrorParsedExplicitly(): void
    {
        $result = ThresholdParser::parse(['warning' => 5, 'error' => 15], 'warning', 'error', 10, 20);

        self::assertSame(5, $result['warning']);
        self::assertSame(15, $result['error']);
    }

    #[Test]
    public function onlyWarningParsedWithDefaultError(): void
    {
        $result = ThresholdParser::parse(['warning' => 5], 'warning', 'error', 10, 20);

        self::assertSame(5, $result['warning']);
        self::assertSame(20, $result['error']);
    }

    #[Test]
    public function thresholdWithWarningThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot mix "threshold" with "warning"/"error"');

        ThresholdParser::parse(['threshold' => 15, 'warning' => 10], 'warning', 'error', 10, 20);
    }

    #[Test]
    public function thresholdWithErrorThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ThresholdParser::parse(['threshold' => 15, 'error' => 20], 'warning', 'error', 10, 20);
    }

    #[Test]
    public function thresholdWithLegacyWarningKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ThresholdParser::parse(
            ['threshold' => 15, 'warningThreshold' => 10],
            'warning',
            'error',
            10,
            20,
            legacyWarningKeys: ['warningThreshold'],
        );
    }

    #[Test]
    public function thresholdWithLegacyErrorKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ThresholdParser::parse(
            ['threshold' => 15, 'errorThreshold' => 20],
            'warning',
            'error',
            10,
            20,
            legacyErrorKeys: ['errorThreshold'],
        );
    }

    #[Test]
    public function legacyKeysUsedAsFallback(): void
    {
        $result = ThresholdParser::parse(
            ['warningThreshold' => 5, 'errorThreshold' => 15],
            'warning',
            'error',
            10,
            20,
            legacyWarningKeys: ['warningThreshold'],
            legacyErrorKeys: ['errorThreshold'],
        );

        self::assertSame(5, $result['warning']);
        self::assertSame(15, $result['error']);
    }

    #[Test]
    public function primaryKeysTakePrecedenceOverLegacy(): void
    {
        $result = ThresholdParser::parse(
            ['warning' => 7, 'error' => 17, 'warningThreshold' => 5, 'errorThreshold' => 15],
            'warning',
            'error',
            10,
            20,
            legacyWarningKeys: ['warningThreshold'],
            legacyErrorKeys: ['errorThreshold'],
        );

        self::assertSame(7, $result['warning']);
        self::assertSame(17, $result['error']);
    }

    #[Test]
    public function customThresholdKey(): void
    {
        $result = ThresholdParser::parse(
            ['param_threshold' => 70],
            'param_warning',
            'param_error',
            80.0,
            50.0,
            thresholdKey: 'param_threshold',
        );

        self::assertSame(70, $result['warning']);
        self::assertSame(70, $result['error']);
    }

    #[Test]
    public function customKeysWithLegacyFallback(): void
    {
        $result = ThresholdParser::parse(
            ['maxWarning' => 25],
            'max_warning',
            'max_error',
            30,
            50,
            legacyWarningKeys: ['maxWarning'],
            legacyErrorKeys: ['maxError'],
        );

        self::assertSame(25, $result['warning']);
        self::assertSame(50, $result['error']);
    }

    #[Test]
    public function floatThresholds(): void
    {
        $result = ThresholdParser::parse(['threshold' => 0.5], 'warning', 'error', 0.3, 0.7);

        self::assertSame(0.5, $result['warning']);
        self::assertSame(0.5, $result['error']);
    }
}
