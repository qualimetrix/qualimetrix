<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\CodeSmell;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Rules\CodeSmell\BooleanArgumentOptions;

#[CoversClass(BooleanArgumentOptions::class)]
final class BooleanArgumentOptionsTest extends TestCase
{
    public function testDefaultsHaveExpectedPrefixes(): void
    {
        $options = new BooleanArgumentOptions();

        self::assertTrue($options->isEnabled());
        self::assertSame(['is', 'has', 'can', 'should', 'will', 'did', 'was'], $options->allowedPrefixes);
    }

    public function testFromArrayEmpty(): void
    {
        $options = BooleanArgumentOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
        self::assertSame(['is', 'has', 'can', 'should', 'will', 'did', 'was'], $options->allowedPrefixes);
    }

    public function testFromArrayWithCustomPrefixes(): void
    {
        $options = BooleanArgumentOptions::fromArray([
            'allowed_prefixes' => ['is', 'has'],
        ]);

        self::assertSame(['is', 'has'], $options->allowedPrefixes);
    }

    public function testFromArrayWithCamelCaseKey(): void
    {
        $options = BooleanArgumentOptions::fromArray([
            'allowedPrefixes' => ['can'],
        ]);

        self::assertSame(['can'], $options->allowedPrefixes);
    }

    public function testFromArrayDisabledWithEmptyPrefixes(): void
    {
        $options = BooleanArgumentOptions::fromArray([
            'allowed_prefixes' => [],
        ]);

        self::assertSame([], $options->allowedPrefixes);
    }

    #[DataProvider('prefixMatchingProvider')]
    public function testIsAllowedPrefix(string $paramName, bool $expected): void
    {
        $options = new BooleanArgumentOptions();

        self::assertSame($expected, $options->isAllowedPrefix($paramName), "Failed for: {$paramName}");
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function prefixMatchingProvider(): iterable
    {
        // camelCase boundary
        yield '$isActive' => ['$isActive', true];
        yield '$hasPermission' => ['$hasPermission', true];
        yield '$canEdit' => ['$canEdit', true];
        yield '$shouldRefresh' => ['$shouldRefresh', true];
        yield '$willUpdate' => ['$willUpdate', true];
        yield '$didComplete' => ['$didComplete', true];
        yield '$wasDeleted' => ['$wasDeleted', true];

        // exact match
        yield '$is' => ['$is', true];
        yield '$has' => ['$has', true];

        // snake_case boundary
        yield '$has_value' => ['$has_value', true];
        yield '$IS_ACTIVE' => ['$IS_ACTIVE', true];
        yield '$is_enabled' => ['$is_enabled', true];

        // should NOT match (no word boundary)
        yield '$island' => ['$island', false];
        yield '$cannon' => ['$cannon', false];
        yield '$disco' => ['$disco', false];
        yield '$dishonest' => ['$dishonest', false];
        yield '$hasMore with prefix has' => ['$hasMore', true];

        // ISLAND edge case (C1 fix)
        yield '$ISLAND' => ['$ISLAND', false];

        // non-matching params
        yield '$overwrite' => ['$overwrite', false];
        yield '$force' => ['$force', false];
        yield '$debug' => ['$debug', false];

        // empty
        yield 'empty' => ['', false];
        yield 'dollar only' => ['$', false];
    }

    public function testEmptyPrefixListMatchesNothing(): void
    {
        $options = new BooleanArgumentOptions(allowedPrefixes: []);

        self::assertFalse($options->isAllowedPrefix('$isActive'));
        self::assertFalse($options->isAllowedPrefix('$anything'));
    }
}
