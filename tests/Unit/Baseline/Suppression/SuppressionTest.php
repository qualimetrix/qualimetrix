<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Baseline\Suppression;

use AiMessDetector\Baseline\Suppression\Suppression;
use AiMessDetector\Baseline\Suppression\SuppressionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Suppression::class)]
final class SuppressionTest extends TestCase
{
    public function testMatchesExactRule(): void
    {
        $suppression = new Suppression(
            rule: 'complexity.cyclomatic',
            reason: 'Legacy code',
            line: 10,
            type: SuppressionType::Symbol,
        );

        self::assertTrue($suppression->matches('complexity.cyclomatic'));
        self::assertFalse($suppression->matches('complexity.cognitive'));
    }

    public function testMatchesPrefixRule(): void
    {
        $suppression = new Suppression(
            rule: 'complexity',
            reason: 'Legacy code',
            line: 10,
            type: SuppressionType::Symbol,
        );

        // Prefix matching: 'complexity' matches all complexity.* violations
        self::assertTrue($suppression->matches('complexity'));
        self::assertTrue($suppression->matches('complexity.cyclomatic'));
        self::assertTrue($suppression->matches('complexity.cyclomatic.method'));
        self::assertFalse($suppression->matches('coupling'));
    }

    public function testWildcardMatchesAllRules(): void
    {
        $suppression = new Suppression(
            rule: '*',
            reason: 'Ignore all',
            line: 10,
            type: SuppressionType::File,
        );

        self::assertTrue($suppression->matches('complexity.cyclomatic'));
        self::assertTrue($suppression->matches('coupling.distance'));
        self::assertTrue($suppression->matches('size.method-count'));
    }

    public function testConstructorProperties(): void
    {
        $suppression = new Suppression(
            rule: 'complexity.cyclomatic',
            reason: 'Complex business logic',
            line: 42,
            type: SuppressionType::NextLine,
        );

        self::assertSame('complexity.cyclomatic', $suppression->rule);
        self::assertSame('Complex business logic', $suppression->reason);
        self::assertSame(42, $suppression->line);
        self::assertSame(SuppressionType::NextLine, $suppression->type);
    }

    public function testConstructorWithNullReason(): void
    {
        $suppression = new Suppression(
            rule: 'complexity',
            reason: null,
            line: 42,
            type: SuppressionType::Symbol,
        );

        self::assertNull($suppression->reason);
    }

    public function testReverseDoesNotMatch(): void
    {
        $suppression = new Suppression(
            rule: 'complexity.cyclomatic.method',
            reason: null,
            line: 10,
            type: SuppressionType::Symbol,
        );

        // More specific pattern does NOT match less specific subject
        self::assertFalse($suppression->matches('complexity.cyclomatic'));
        self::assertFalse($suppression->matches('complexity'));
    }
}
