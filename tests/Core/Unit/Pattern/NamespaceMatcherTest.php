<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Unit\Pattern;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PatternMatch;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

#[CoversClass(NamespacePattern::class)]
#[CoversClass(NamespaceMatcher::class)]
#[CoversClass(PatternMatch::class)]
final class NamespaceMatcherTest extends TestCase
{
    #[Test]
    public function itMatchesANamespaceSubtreeWithItsOwnSeparator(): void
    {
        $pattern = self::namespace(SelectorKind::Subtree, 'App\\Entity');

        self::assertTrue($pattern->matches('App\\Entity'));
        self::assertTrue($pattern->matches('App\\Entity\\Model\\User'));
        self::assertFalse($pattern->matches('App\\EntityManager\\User'));
        self::assertSame(
            '~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=1000)\\A(?:App\\\\Entity(?:\\\\.+)?)\\z~',
            $pattern->rendered(),
        );
    }

    #[Test]
    public function itRefusesPathSeparatorsAndNonCanonicalNamespaceSeparators(): void
    {
        foreach (['App/Entity', '\\App\\Entity', 'App\\Entity\\', 'App\\\\Entity'] as $value) {
            try {
                self::namespace(SelectorKind::Exact, $value);
                self::fail(\sprintf('Expected "%s" to be refused', $value));
            } catch (InvalidArgumentException) {
            }
        }

        self::addToAssertionCount(1);
    }

    #[Test]
    public function itTreatsMetacharactersAsLiteralInAnExactNamespace(): void
    {
        $pattern = self::namespace(SelectorKind::Exact, 'App\\Thing[0]~Name');

        self::assertTrue($pattern->matches('App\\Thing[0]~Name'));
        self::assertFalse($pattern->matches('App\\Thing0~Name'));
    }

    #[Test]
    public function itMatchesAnExplicitNamespaceRegex(): void
    {
        $pattern = self::namespace(SelectorKind::Regex, 'App\\\\(?:Entity|Dto)(?:\\\\[^\\\\]+)*');

        self::assertTrue($pattern->matches('App\\Entity'));
        self::assertTrue($pattern->matches('App\\Dto\\Input'));
        self::assertFalse($pattern->matches('App\\Model\\Input'));
    }

    #[Test]
    public function itReturnsTheFirstMatchedDefinition(): void
    {
        $first = new SelectorDefinition(SelectorKind::Regex, 'App\\\\.*');
        $second = new SelectorDefinition(SelectorKind::Subtree, 'App\\Entity');
        $matcher = new NamespaceMatcher([new NamespacePattern($first), new NamespacePattern($second)]);

        $match = $matcher->matches('App\\Entity\\User');

        self::assertInstanceOf(PatternMatch::class, $match);
        self::assertSame($first, $match->definition);
    }

    #[Test]
    public function itReturnsNoMatchForAnEmptyMatcherOrAnUnmatchedNamespace(): void
    {
        self::assertTrue((new NamespaceMatcher([]))->isEmpty());
        self::assertNull((new NamespaceMatcher([]))->matches('App\\Entity'));
        self::assertNull((new NamespaceMatcher([self::namespace(SelectorKind::Exact, 'App\\Dto')]))->matches('App\\Entity'));
    }

    private static function namespace(SelectorKind $kind, string $value): NamespacePattern
    {
        return new NamespacePattern(new SelectorDefinition($kind, $value));
    }
}
