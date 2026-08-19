<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionTarget;

#[CoversClass(SuppressionTarget::class)]
final class SuppressionTargetTest extends TestCase
{
    /**
     * `@qmx-ignore *` and a bare `@qmx-ignore-file` are documented, tested
     * spellings that must keep working after the wildcard stops being a
     * selector — because they never selected anything: they declare that the
     * directive has no rule filter.
     */
    #[Test]
    public function itKeepsTheNoRuleFilterFormWorking(): void
    {
        $target = SuppressionTarget::fromAnnotation(SuppressionTarget::NO_RULE_FILTER);

        self::assertTrue($target->appliesToEveryChannel());
        self::assertTrue($target->matches('complexity.cyclomatic.callable'));
        self::assertTrue($target->matches('anything.at.all'));
        self::assertSame('*', (string) $target);
    }

    #[Test]
    public function itFiltersOnAnExactChannel(): void
    {
        $target = SuppressionTarget::fromAnnotation('coupling.instability.class');

        self::assertFalse($target->appliesToEveryChannel());
        self::assertTrue($target->matches('coupling.instability.class'));
        self::assertFalse($target->matches('coupling.instability'));
        self::assertFalse($target->matches('coupling.instability.namespace'));
    }

    #[Test]
    public function itFiltersOnAGroupOfDescendants(): void
    {
        $target = SuppressionTarget::fromAnnotation('coupling.instability.*');

        self::assertTrue($target->matches('coupling.instability.class'));
        self::assertTrue($target->matches('coupling.instability.namespace'));
        self::assertFalse($target->matches('coupling.instability'));
    }

    #[Test]
    public function itFiltersNothingWhenTheTextIsNotASelector(): void
    {
        $target = SuppressionTarget::fromAnnotation('coupling.*.class');

        self::assertFalse($target->appliesToEveryChannel());
        self::assertFalse($target->matches('coupling.instability.class'));
        self::assertSame('coupling.*.class', (string) $target);
    }
}
