<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Suppression;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\SuppressionType;

#[CoversClass(Suppression::class)]
final class SuppressionTest extends TestCase
{
    #[Test]
    public function itMatchesExactRule(): void
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

    #[Test]
    public function itMatchesPrefixRule(): void
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

    #[Test]
    public function itWildcardMatchesAllRules(): void
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

    #[Test]
    public function itConstructorProperties(): void
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

    #[Test]
    public function itConstructorWithNullReason(): void
    {
        $suppression = new Suppression(
            rule: 'complexity',
            reason: null,
            line: 42,
            type: SuppressionType::Symbol,
        );

        self::assertNull($suppression->reason);
    }

    #[Test]
    public function itReverseDoesNotMatch(): void
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
