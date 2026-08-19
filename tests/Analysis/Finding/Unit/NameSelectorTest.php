<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;

#[CoversClass(NameSelector::class)]
final class NameSelectorTest extends TestCase
{
    #[Test]
    public function itMatchesOnlyTheExactName(): void
    {
        $selector = NameSelector::tryParse('architecture.coverage');

        self::assertNotNull($selector);
        self::assertTrue($selector->matches('architecture.coverage'));
        self::assertFalse($selector->matches('architecture'));
        self::assertFalse($selector->matches('architecture.coverage.source'));
    }

    #[Test]
    public function itMatchesStrictDescendantsAndNotTheParent(): void
    {
        $selector = NameSelector::tryParse('architecture.coverage.*');

        self::assertNotNull($selector);
        self::assertTrue($selector->matches('architecture.coverage.source'));
        self::assertTrue($selector->matches('architecture.coverage.source.deep'));
        self::assertFalse($selector->matches('architecture.coverage'));
        self::assertFalse($selector->matches('architecture.coverage-ish'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonSelectors(): iterable
    {
        yield 'empty' => [''];
        yield 'lone wildcard' => ['*'];
        yield 'leading wildcard' => ['*.coverage'];
        yield 'inner wildcard' => ['architecture.*.coverage'];
        yield 'partial segment wildcard' => ['architecture.cov*'];
        yield 'wildcard without dot' => ['architecture*'];
        yield 'trailing dot' => ['architecture.'];
        yield 'empty inner segment' => ['architecture..coverage'];
        yield 'bare group suffix' => ['.*'];
    }

    #[Test]
    #[DataProvider('provideNonSelectors')]
    public function itRefusesTextThatIsNotASelector(string $raw): void
    {
        self::assertNull(NameSelector::tryParse($raw));
    }

    #[Test]
    public function itRoundTripsToItsAuthoredForm(): void
    {
        self::assertSame('coupling.cbo', (string) NameSelector::tryParse('coupling.cbo'));
        self::assertSame('coupling.cbo.*', (string) NameSelector::tryParse('coupling.cbo.*'));
    }

    #[Test]
    public function itExposesTheNameHalfAndTheGroupFlag(): void
    {
        $exact = NameSelector::tryParse('coupling.cbo');
        $group = NameSelector::tryParse('coupling.cbo.*');

        self::assertNotNull($exact);
        self::assertNotNull($group);
        self::assertSame('coupling.cbo', $exact->name());
        self::assertSame('coupling.cbo', $group->name());
        self::assertFalse($exact->selectsDescendantsOnly());
        self::assertTrue($group->selectsDescendantsOnly());
    }

    #[Test]
    public function itAnswersAnyMatchAcrossAListAndIgnoresNonSelectors(): void
    {
        self::assertTrue(NameSelector::anyMatch(['size.loc', 'coupling.cbo.*'], 'coupling.cbo.class'));
        self::assertFalse(NameSelector::anyMatch(['*', 'coupling'], 'coupling.cbo.class'));
        self::assertFalse(NameSelector::anyMatch([], 'coupling.cbo'));
    }
}
