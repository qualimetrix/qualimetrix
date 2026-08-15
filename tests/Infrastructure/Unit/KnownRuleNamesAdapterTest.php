<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Size\ClassCountRule;
use Qualimetrix\Infrastructure\Rule\KnownRuleNamesAdapter;

#[CoversClass(KnownRuleNamesAdapter::class)]
final class KnownRuleNamesAdapterTest extends TestCase
{
    #[Test]
    public function itExtractsNamesFromRuleClasses(): void
    {
        $adapter = new KnownRuleNamesAdapter([
            ComplexityRule::class,
            ClassCountRule::class,
        ]);

        $names = $adapter->getKnownRuleNames();

        self::assertContains('complexity.cyclomatic', $names);
        self::assertContains('size.class-count', $names);
        self::assertCount(2, $names);
    }

    #[Test]
    public function itReturnsAnEmptyArrayWhenThereAreNoRules(): void
    {
        $adapter = new KnownRuleNamesAdapter([]);

        self::assertSame([], $adapter->getKnownRuleNames());
    }
}
