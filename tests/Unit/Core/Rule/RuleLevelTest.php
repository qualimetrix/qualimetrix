<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\RuleLevel;

#[CoversClass(RuleLevel::class)]
final class RuleLevelTest extends TestCase
{
    public function testEnumValues(): void
    {
        self::assertSame('method', RuleLevel::Method->value);
        self::assertSame('class', RuleLevel::Class_->value);
        self::assertSame('namespace', RuleLevel::Namespace_->value);
    }

    public function testAllCases(): void
    {
        $cases = RuleLevel::cases();

        self::assertCount(3, $cases);
        self::assertContains(RuleLevel::Method, $cases);
        self::assertContains(RuleLevel::Class_, $cases);
        self::assertContains(RuleLevel::Namespace_, $cases);
    }

    #[DataProvider('displayNameProvider')]
    public function testDisplayName(RuleLevel $level, string $expected): void
    {
        self::assertSame($expected, $level->displayName());
    }

    /**
     * @return iterable<string, array{RuleLevel, string}>
     */
    public static function displayNameProvider(): iterable
    {
        yield 'method' => [RuleLevel::Method, 'Method'];
        yield 'class' => [RuleLevel::Class_, 'Class'];
        yield 'namespace' => [RuleLevel::Namespace_, 'Namespace'];
    }

    public function testFromString(): void
    {
        self::assertSame(RuleLevel::Method, RuleLevel::from('method'));
        self::assertSame(RuleLevel::Class_, RuleLevel::from('class'));
        self::assertSame(RuleLevel::Namespace_, RuleLevel::from('namespace'));
    }

    public function testTryFromWithInvalidValue(): void
    {
        self::assertNull(RuleLevel::tryFrom('invalid'));
        self::assertNull(RuleLevel::tryFrom('function'));
    }
}
