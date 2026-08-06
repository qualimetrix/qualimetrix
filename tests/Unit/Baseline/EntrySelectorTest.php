<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\EntrySelector;

#[CoversClass(EntrySelector::class)]
final class EntrySelectorTest extends TestCase
{
    #[Test]
    public function itComputesTwelveLowercaseHexCharacters(): void
    {
        $selector = EntrySelector::forKey('method:App\Foo::bar');

        self::assertSame(12, \strlen($selector->value));
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $selector->value);
    }

    #[Test]
    public function itIsDeterministicForOneKey(): void
    {
        self::assertSame(
            EntrySelector::forKey('a-key')->value,
            EntrySelector::forKey('a-key')->value,
        );
    }

    #[Test]
    public function itDiffersForDifferentKeys(): void
    {
        self::assertNotSame(
            EntrySelector::forKey('a-key')->value,
            EntrySelector::forKey('another-key')->value,
        );
    }

    /**
     * The digest is printed to users and ends up in their scripts, so it may
     * not depend on which hash extensions the runtime happens to offer. This
     * pins the algorithm, not merely the shape.
     */
    #[Test]
    public function itIsTheTruncatedSha256OfTheKeyRegardlessOfAvailableExtensions(): void
    {
        self::assertSame(
            substr(hash('sha256', 'a-key'), 0, 12),
            EntrySelector::forKey('a-key')->value,
        );
    }

    #[Test]
    public function itParsesASelectorTypedByAUser(): void
    {
        $selector = EntrySelector::fromString('0123456789ab');

        self::assertSame('0123456789ab', $selector->value);
    }

    #[Test]
    public function itNormalizesCaseAndSurroundingWhitespaceWhenParsing(): void
    {
        self::assertSame('0123456789ab', EntrySelector::fromString('  0123456789AB ')->value);
    }

    #[Test]
    public function itRejectsInputThatIsNotASelector(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EntrySelector::fromString('method:App\Foo::bar');
    }

    #[Test]
    public function itRejectsASelectorOfTheWrongLength(): void
    {
        self::assertNull(EntrySelector::tryFromString('0123456789'));
        self::assertNull(EntrySelector::tryFromString('0123456789abc'));
    }

    #[Test]
    public function itRejectsNonHexCharacters(): void
    {
        self::assertNull(EntrySelector::tryFromString('0123456789az'));
    }

    #[Test]
    public function itRoundTripsThroughItsStringForm(): void
    {
        $selector = EntrySelector::forKey('method:App\Foo::bar');

        self::assertTrue($selector->equals(EntrySelector::fromString((string) $selector)));
    }
}
