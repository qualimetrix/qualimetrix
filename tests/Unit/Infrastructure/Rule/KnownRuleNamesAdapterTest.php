<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Rule\KnownRuleNamesAdapter;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Rules\Size\ClassCountRule;

#[CoversClass(KnownRuleNamesAdapter::class)]
final class KnownRuleNamesAdapterTest extends TestCase
{
    #[Test]
    public function extractsNamesFromRuleClasses(): void
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
    public function returnsEmptyArrayForNoRules(): void
    {
        $adapter = new KnownRuleNamesAdapter([]);

        self::assertSame([], $adapter->getKnownRuleNames());
    }
}
