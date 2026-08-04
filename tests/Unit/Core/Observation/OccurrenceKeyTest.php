<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Observation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Observation\OccurrenceKey;

#[CoversClass(OccurrenceKey::class)]
final class OccurrenceKeyTest extends TestCase
{
    #[Test]
    public function itWrapsACanonicalValue(): void
    {
        self::assertSame('abc123', OccurrenceKey::of('abc123')->value);
    }

    /**
     * The empty string is not an occurrence key. Absence is expressed by a
     * null `DebtObservation::$occurrenceKey`, which means "this channel offers
     * no stable discriminator" — a property of the channel. An empty key would
     * be a *per-occurrence* absence, which would silently merge one occurrence
     * into the counted bucket while its siblings stayed individually
     * addressed, and the counts would stop adding up.
     */
    #[Test]
    public function itRejectsAnEmptyValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        OccurrenceKey::of('');
    }

    /**
     * `of()` wraps an opaque token rather than composing one, so it cannot
     * apply the composing factories' percent-escaping. Without this guard
     * `of('a|b')` would be byte-identical to `fromParts('a', 'b')` and
     * `of('a%25')` to `fromParts('a%')` — two different findings colliding
     * onto one key, which is exactly what this type's injectivity claim rules
     * out. Two reviewers found this independently.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideReservedCharacterValues(): iterable
    {
        yield 'separator, would alias fromParts("a", "b")' => ['a|b'];
        yield 'escape character, would alias fromParts("a%")' => ['a%25'];
    }

    #[Test]
    #[DataProvider('provideReservedCharacterValues')]
    public function itRejectsAValueContainingAReservedCharacter(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not an opaque single-token identity');

        OccurrenceKey::of($value);
    }

    #[Test]
    public function itRejectsCompositionWithoutParts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one part');

        OccurrenceKey::fromParts();
    }

    #[Test]
    public function itRejectsCompositionFromOnlyEmptyParts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one non-empty part');

        OccurrenceKey::fromParts('', '');
    }

    #[Test]
    public function itJoinsOrderedParts(): void
    {
        self::assertSame('App\\Order|17', OccurrenceKey::fromParts('App\\Order', '17')->value);
    }

    /**
     * Composition must be injective, or two different findings collapse onto
     * one key and the ratchet silently merges them.
     */
    #[Test]
    public function itDistinguishesASeparatorInsideAPartFromAPartBoundary(): void
    {
        $embedded = OccurrenceKey::fromParts('a|b');
        $split = OccurrenceKey::fromParts('a', 'b');

        self::assertNotSame($embedded->value, $split->value);
        self::assertFalse($embedded->equals($split));
    }

    #[Test]
    public function itDistinguishesAnEscapeCharacterInsideAPart(): void
    {
        self::assertSame('a%25', OccurrenceKey::fromParts('a%')->value);
        self::assertNotSame(
            OccurrenceKey::fromParts('a%7Cb')->value,
            OccurrenceKey::fromParts('a', 'b')->value,
        );
    }

    /**
     * Parts are usually fully-qualified PHP names, and a key humans review in
     * a file must stay legible: backslashes pass through untouched.
     */
    #[Test]
    public function itLeavesNamespaceSeparatorsIntact(): void
    {
        self::assertSame(
            'App\\Service\\OrderService|calculate',
            OccurrenceKey::fromParts('App\\Service\\OrderService', 'calculate')->value,
        );
    }

    /**
     * A cycle has no first member, so its key must not depend on where a
     * traversal happened to enter it.
     */
    #[Test]
    public function itMakesUnorderedCompositionIndependentOfInputOrder(): void
    {
        $forward = OccurrenceKey::fromUnorderedParts('App\\A', 'App\\B', 'App\\C');
        $rotated = OccurrenceKey::fromUnorderedParts('App\\C', 'App\\A', 'App\\B');
        $reversed = OccurrenceKey::fromUnorderedParts('App\\C', 'App\\B', 'App\\A');

        self::assertTrue($forward->equals($rotated));
        self::assertTrue($forward->equals($reversed));
    }

    #[Test]
    public function itKeepsOrderedAndUnorderedCompositionDistinctWhereOrderMatters(): void
    {
        self::assertNotSame(
            OccurrenceKey::fromParts('b', 'a')->value,
            OccurrenceKey::fromUnorderedParts('b', 'a')->value,
        );
    }

    #[Test]
    public function itIsNeverEqualToAbsence(): void
    {
        self::assertFalse(OccurrenceKey::of('x')->equals(null));
    }

    #[Test]
    public function itRendersAsItsValue(): void
    {
        self::assertSame('x|y', (string) OccurrenceKey::fromParts('x', 'y'));
    }
}
