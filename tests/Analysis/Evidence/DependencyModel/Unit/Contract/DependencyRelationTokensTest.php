<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\DependencyModel\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyRelationTokens;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;

#[CoversClass(DependencyRelationTokens::class)]
final class DependencyRelationTokensTest extends TestCase
{
    /**
     * @param list<DependencyType> $expected
     */
    #[Test]
    #[DataProvider('aliasCases')]
    public function itExpandsAnAliasToItsMembersInDeclaredOrder(string $alias, array $expected): void
    {
        self::assertSame($expected, DependencyRelationTokens::expand($alias));
    }

    /**
     * @return iterable<string, array{string, list<DependencyType>}>
     */
    public static function aliasCases(): iterable
    {
        yield 'inheritance' => ['inheritance', [DependencyType::Extends, DependencyType::Implements, DependencyType::TraitUse]];
        yield 'static_access' => ['static_access', [DependencyType::StaticCall, DependencyType::StaticPropertyFetch, DependencyType::ClassConstFetch]];
        yield 'type_reference' => ['type_reference', [DependencyType::TypeHint, DependencyType::PropertyType, DependencyType::ConstantType]];
        yield 'runtime_check' => ['runtime_check', [DependencyType::Catch_, DependencyType::Instanceof_]];
    }

    #[Test]
    #[DataProvider('standaloneKinds')]
    public function itKeepsAttributeKindsOutOfEveryAlias(DependencyType $kind): void
    {
        self::assertSame([$kind], DependencyRelationTokens::expand($kind->value));

        foreach (DependencyRelationTokens::ALIASES as $alias => $members) {
            self::assertNotContains($kind, $members, "alias '{$alias}' must not absorb {$kind->value}");
        }
    }

    /**
     * @return iterable<string, array{DependencyType}>
     */
    public static function standaloneKinds(): iterable
    {
        yield 'attribute' => [DependencyType::Attribute];
        yield 'attribute_argument' => [DependencyType::AttributeArgument];
    }

    #[Test]
    public function itReturnsNullForAnUnknownToken(): void
    {
        self::assertNull(DependencyRelationTokens::expand('tipes'));
        self::assertNull(DependencyRelationTokens::expand(''));
    }

    #[Test]
    public function itListsEveryKindAndAliasAsAcceptedSorted(): void
    {
        $accepted = DependencyRelationTokens::acceptedTokens();

        foreach (DependencyType::cases() as $case) {
            self::assertContains($case->value, $accepted);
        }
        foreach (array_keys(DependencyRelationTokens::ALIASES) as $alias) {
            self::assertContains($alias, $accepted);
        }
        self::assertContains('attribute_argument', $accepted);

        $sorted = $accepted;
        sort($sorted);
        self::assertSame($sorted, $accepted);
        self::assertSame($accepted, array_values(array_unique($accepted)));
    }
}
