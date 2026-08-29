<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Rule\KnownRuleNamesAdapter;

#[CoversClass(KnownRuleNamesAdapter::class)]
final class KnownRuleNamesAdapterTest extends TestCase
{
    /**
     * The adapter hands back exactly what the container assembled, including a
     * producer no rule class declares — deriving the list here again is what
     * used to leave the classless half of the vocabulary unaddressable.
     */
    #[Test]
    public function itHandsBackTheInjectedNamesUnchanged(): void
    {
        $adapter = new KnownRuleNamesAdapter([
            'complexity.cyclomatic',
            'size.class-count',
            'health.cohesion',
        ]);

        self::assertSame(
            ['complexity.cyclomatic', 'size.class-count', 'health.cohesion'],
            $adapter->getKnownRuleNames(),
        );
    }

    #[Test]
    public function itReturnsAnEmptyArrayWhenThereAreNoRules(): void
    {
        $adapter = new KnownRuleNamesAdapter([]);

        self::assertSame([], $adapter->getKnownRuleNames());
    }
}
