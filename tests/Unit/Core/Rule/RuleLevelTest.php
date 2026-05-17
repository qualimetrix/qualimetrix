<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\RuleLevel;

#[CoversClass(RuleLevel::class)]
final class RuleLevelTest extends TestCase
{
    #[Test]
    public function itEnumValues(): void
    {
        self::assertSame('method', RuleLevel::Method->value);
        self::assertSame('class', RuleLevel::Class_->value);
        self::assertSame('namespace', RuleLevel::Namespace_->value);
    }

    #[Test]
    public function itAllCases(): void
    {
        $cases = RuleLevel::cases();

        self::assertCount(3, $cases);
        self::assertContains(RuleLevel::Method, $cases);
        self::assertContains(RuleLevel::Class_, $cases);
        self::assertContains(RuleLevel::Namespace_, $cases);
    }

    #[DataProvider('displayNameProvider')]
    #[Test]
    public function itDisplayName(RuleLevel $level, string $expected): void
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

    #[Test]
    public function itFromString(): void
    {
        self::assertSame(RuleLevel::Method, RuleLevel::from('method'));
        self::assertSame(RuleLevel::Class_, RuleLevel::from('class'));
        self::assertSame(RuleLevel::Namespace_, RuleLevel::from('namespace'));
    }

    #[Test]
    public function itTryFromWithInvalidValue(): void
    {
        self::assertNull(RuleLevel::tryFrom('invalid'));
        self::assertNull(RuleLevel::tryFrom('function'));
    }
}
