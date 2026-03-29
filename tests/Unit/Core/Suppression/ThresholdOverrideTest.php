<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Suppression;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Suppression\ThresholdOverride;

#[CoversClass(ThresholdOverride::class)]
final class ThresholdOverrideTest extends TestCase
{
    public function testMatchesExact(): void
    {
        $override = new ThresholdOverride(
            rulePattern: 'complexity.cyclomatic',
            warning: 15,
            error: 25,
            line: 10,
        );

        self::assertTrue($override->matches('complexity.cyclomatic'));
        self::assertFalse($override->matches('complexity.cognitive'));
        self::assertFalse($override->matches('coupling.cbo'));
    }

    public function testMatchesPrefix(): void
    {
        $override = new ThresholdOverride(
            rulePattern: 'complexity',
            warning: 15,
            error: 25,
            line: 10,
        );

        self::assertTrue($override->matches('complexity.cyclomatic'));
        self::assertTrue($override->matches('complexity.cognitive'));
        self::assertFalse($override->matches('coupling.cbo'));
    }

    public function testMatchesWildcard(): void
    {
        $override = new ThresholdOverride(
            rulePattern: '*',
            warning: 30,
            error: 50,
            line: 10,
        );

        self::assertTrue($override->matches('complexity.cyclomatic'));
        self::assertTrue($override->matches('coupling.cbo'));
        self::assertTrue($override->matches('anything'));
    }

    public function testFieldsAreAccessible(): void
    {
        $override = new ThresholdOverride(
            rulePattern: 'complexity.cyclomatic',
            warning: 15,
            error: 25,
            line: 10,
            endLine: 50,
        );

        self::assertSame('complexity.cyclomatic', $override->rulePattern);
        self::assertSame(15, $override->warning);
        self::assertSame(25, $override->error);
        self::assertSame(10, $override->line);
        self::assertSame(50, $override->endLine);
    }

    public function testNullWarningAndError(): void
    {
        $override = new ThresholdOverride(
            rulePattern: 'complexity.cyclomatic',
            warning: null,
            error: 25,
            line: 10,
        );

        self::assertNull($override->warning);
        self::assertSame(25, $override->error);
    }

    public function testFloatThresholds(): void
    {
        $override = new ThresholdOverride(
            rulePattern: 'coupling.instability',
            warning: 0.7,
            error: 0.9,
            line: 10,
        );

        self::assertSame(0.7, $override->warning);
        self::assertSame(0.9, $override->error);
    }
}
