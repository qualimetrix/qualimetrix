<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit\Matching;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateContentMerger;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenStream;

#[CoversClass(DuplicateContentMerger::class)]
final class DuplicateContentMergerTest extends TestCase
{
    #[Test]
    public function itHashesInvalidTokenBytesWithoutLosingTheirIdentity(): void
    {
        $first = DuplicateContentMerger::contentHash(self::tokens("my\xFFfn"), 0, 1);
        $second = DuplicateContentMerger::contentHash(self::tokens("my\xFEfn"), 0, 1);
        $literal = DuplicateContentMerger::contentHash(self::tokens('my%FFfn'), 0, 1);
        self::assertNotSame($first, $second);
        self::assertNotSame($first, $literal);
        self::assertSame($first, DuplicateContentMerger::contentHash(self::tokens("my\xFFfn"), 0, 1));
    }

    #[Test]
    public function itPreservesTheHashOfValidPercentTokens(): void
    {
        self::assertSame('9234c0afeb9d179d4d332c6da2f5922c6a3a040579ad368e12a58008cb31c971', DuplicateContentMerger::contentHash(self::tokens('my%fn'), 0, 1));
    }

    private static function tokens(string $value): TokenStream
    {
        return new TokenStream([$value], 'CCCCC' . str_repeat("\0", 5), '0');
    }
}
