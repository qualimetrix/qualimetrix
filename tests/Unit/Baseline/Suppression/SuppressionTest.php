<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Baseline\Suppression;

use AiMessDetector\Baseline\Suppression\Suppression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Suppression::class)]
final class SuppressionTest extends TestCase
{
    public function testMatchesSpecificRule(): void
    {
        $suppression = new Suppression(
            rule: 'complexity',
            reason: 'Legacy code',
            line: 10,
        );

        self::assertTrue($suppression->matches('complexity'));
        self::assertFalse($suppression->matches('coupling'));
    }

    public function testWildcardMatchesAllRules(): void
    {
        $suppression = new Suppression(
            rule: '*',
            reason: 'Ignore all',
            line: 10,
        );

        self::assertTrue($suppression->matches('complexity'));
        self::assertTrue($suppression->matches('coupling'));
        self::assertTrue($suppression->matches('size'));
    }

    public function testConstructorProperties(): void
    {
        $suppression = new Suppression(
            rule: 'complexity',
            reason: 'Complex business logic',
            line: 42,
        );

        self::assertSame('complexity', $suppression->rule);
        self::assertSame('Complex business logic', $suppression->reason);
        self::assertSame(42, $suppression->line);
    }

    public function testConstructorWithNullReason(): void
    {
        $suppression = new Suppression(
            rule: 'complexity',
            reason: null,
            line: 42,
        );

        self::assertNull($suppression->reason);
    }
}
