<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\DeclarationKey;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(FileDeclarationIndex::class)]
#[CoversClass(DeclarationKey::class)]
#[CoversClass(DeclarationOrdinal::class)]
final class FileDeclarationIndexTest extends TestCase
{
    #[Test]
    public function itRanksPositionsOfOneKeyInSourceOrder(): void
    {
        $index = new FileDeclarationIndex();
        $key = DeclarationKey::forLogical(SymbolPath::forClass('App', 'Greeter'));

        $index->register($key, 40);
        $index->register($key, 900);

        self::assertSame(0, $index->ordinalOf($key, 40)->value);
        self::assertSame(1, $index->ordinalOf($key, 900)->value);
    }

    #[Test]
    public function itKeepsAnAlreadyAnsweredRankWhenALaterDeclarationArrives(): void
    {
        $index = new FileDeclarationIndex();
        $key = DeclarationKey::forLogical(SymbolPath::forMethod('App', 'Greeter', 'greet'));

        $first = $index->ordinalOf($key, 40);
        $index->register($key, 900);

        self::assertSame($first->value, $index->ordinalOf($key, 40)->value);
    }

    #[Test]
    public function itCountsEachKeySeparately(): void
    {
        $index = new FileDeclarationIndex();
        $greeter = DeclarationKey::forLogical(SymbolPath::forClass('App', 'Greeter'));
        $other = DeclarationKey::forLogical(SymbolPath::forClass('App', 'Other'));

        $index->register($greeter, 40);

        self::assertSame(0, $index->ordinalOf($other, 900)->value);
    }

    #[Test]
    public function itNumbersUnnamedClassLikeDeclarationsAsOneFileWideGroup(): void
    {
        $index = new FileDeclarationIndex();
        $unnamed = DeclarationKey::forUnnamedClassLike();

        self::assertSame(0, $index->ordinalOf($unnamed, 40)->value);
        self::assertSame(1, $index->ordinalOf($unnamed, 900)->value);
    }

    #[Test]
    public function itAnswersAnUnregisteredPairInsteadOfRejectingIt(): void
    {
        $index = new FileDeclarationIndex();
        $key = DeclarationKey::forLogical(SymbolPath::forClass('App', 'Greeter'));

        self::assertSame(0, $index->ordinalOf($key, 40)->value);
        self::assertSame(1, $index->ordinalOf($key, 900)->value);
    }

    #[Test]
    public function itRepeatsTheSameAnswerForARepeatedQuestion(): void
    {
        $index = new FileDeclarationIndex();
        $key = DeclarationKey::forLogical(SymbolPath::forClass('App', 'Greeter'));

        self::assertSame($index->ordinalOf($key, 40)->value, $index->ordinalOf($key, 40)->value);
    }

    #[Test]
    public function itRejectsANegativePosition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FileDeclarationIndex())->register(DeclarationKey::forUnnamedClassLike(), -1);
    }

    #[Test]
    public function itSeparatesUnnamedClassLikeDeclarationsFromEveryLogicalIdentity(): void
    {
        $index = new FileDeclarationIndex();

        $index->register(DeclarationKey::forUnnamedClassLike(), 40);

        self::assertSame(0, $index->ordinalOf(DeclarationKey::forLogical(SymbolPath::forClass('App', 'Greeter')), 900)->value);
    }
}
