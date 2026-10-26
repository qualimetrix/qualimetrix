<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Contract\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\RefusedName;

#[CoversClass(NameVocabulary::class)]
final class NameVocabularyTest extends TestCase
{
    #[Test]
    public function itAdmitsEveryMemberOfAClosedVocabulary(): void
    {
        $names = NameVocabulary::closed(['alpha', 'beta'], static fn(string $name): RefusedName => RefusedName::open('no ' . $name));

        self::assertNull($names->refuse('alpha'));
        self::assertNull($names->refuse('beta'));
    }

    #[Test]
    public function itRefusesANonMemberWithTheRefusalTheOwnerWorded(): void
    {
        $refusal = RefusedName::open('Unknown name.');
        $received = [];
        $names = NameVocabulary::closed(['alpha'], static function (string $name) use (&$received, $refusal): RefusedName {
            $received[] = $name;

            return $refusal;
        });

        self::assertSame($refusal, $names->refuse('gamma'));
        self::assertSame(['gamma'], $received);
        self::assertNotNull($names->refuse('Alpha'));
    }

    #[Test]
    public function itKeepsAClosedVocabularyAPredicateForTheEngine(): void
    {
        $names = NameVocabulary::closed(['alpha'], static fn(string $name): RefusedName => RefusedName::open('no'));

        self::assertTrue($names->isPredicate());
        self::assertFalse($names->isFixed());
        self::assertFalse($names->isFromSibling());
    }

    #[Test]
    public function itExposesTheNamesOfAClosedVocabularyOnly(): void
    {
        $closed = NameVocabulary::closed(['alpha', 'beta'], static fn(string $name): RefusedName => RefusedName::open('no'));

        self::assertSame(['alpha', 'beta'], $closed->closedNames());
        self::assertNull(NameVocabulary::fixed(['alpha'])->closedNames());
        self::assertNull(NameVocabulary::predicate(static fn(string $name): ?RefusedName => null)->closedNames());
        self::assertNull(NameVocabulary::fromSibling('layers', static fn(mixed $layers): array => [])->closedNames());
    }
}
