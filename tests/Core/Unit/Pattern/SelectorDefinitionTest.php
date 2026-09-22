<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Unit\Pattern;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

#[CoversClass(SelectorDefinition::class)]
#[CoversClass(SelectorKind::class)]
final class SelectorDefinitionTest extends TestCase
{
    #[Test]
    public function itBuildsTheExplicitKindsFromSeparatedAuthoredValues(): void
    {
        $definition = SelectorDefinition::fromKindAndValue('subtree', 'App\\Entity');

        self::assertSame(SelectorKind::Subtree, $definition->kind);
        self::assertSame('App\\Entity', $definition->value);
        self::assertSame('subtree:App\\Entity', $definition->display());
    }

    #[Test]
    public function itRefusesAnUnknownKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown selector kind "glob"');

        SelectorDefinition::fromKindAndValue('glob', 'App\\Entity');
    }

    #[Test]
    public function itPreservesTheAuthoredValueInItsDisplaySpelling(): void
    {
        $definition = new SelectorDefinition(SelectorKind::Regex, '(?i:App\\\\Entity)');

        self::assertSame('regex:(?i:App\\\\Entity)', $definition->display());
    }

    #[Test]
    public function itAllowsALiteralTildeInExactAndSubtreeForms(): void
    {
        self::assertSame('exact:src/~generated.php', (new SelectorDefinition(SelectorKind::Exact, 'src/~generated.php'))->display());
        self::assertSame('subtree:App\\~Generated', (new SelectorDefinition(SelectorKind::Subtree, 'App\\~Generated'))->display());
    }

    #[Test]
    public function itRefusesARawTildeOnlyInARegexFragment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Regex selector fragments must not contain a raw "~" byte');

        new SelectorDefinition(SelectorKind::Regex, 'App~Entity');
    }

    #[Test]
    public function itDoesNotGuessADelimitedLookingRegexFragment(): void
    {
        $definition = new SelectorDefinition(SelectorKind::Regex, '/App/Entity/');

        self::assertSame('/App/Entity/', $definition->value);
        new NamespacePattern($definition);
        self::addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('provideKinds')]
    public function itRefusesAnEmptyValue(SelectorKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        new SelectorDefinition($kind, '');
    }

    #[Test]
    #[DataProvider('provideKinds')]
    public function itRefusesANulByteInEveryForm(SelectorKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain a NUL byte');

        new SelectorDefinition($kind, "App\0Entity");
    }

    #[Test]
    #[DataProvider('provideKinds')]
    public function itRefusesAnOverBudgetValue(SelectorKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not exceed 4096 bytes');

        new SelectorDefinition($kind, str_repeat('a', SelectorDefinition::MAX_PATTERN_LENGTH + 1));
    }

    /**
     * @return iterable<string, array{SelectorKind}>
     */
    public static function provideKinds(): iterable
    {
        yield 'exact' => [SelectorKind::Exact];
        yield 'subtree' => [SelectorKind::Subtree];
        yield 'regex' => [SelectorKind::Regex];
    }
}
