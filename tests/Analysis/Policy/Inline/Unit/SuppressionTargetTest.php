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
     * The rule half of a channel, for the spellings that do not read it: a
     * one-part selector filters on the finding code alone, so any producer
     * name proves the same thing.
     */
    private const string ANY_RULE = 'any.producer';

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
        self::assertTrue($target->matches(self::ANY_RULE, 'complexity.cyclomatic.callable'));
        self::assertTrue($target->matches(self::ANY_RULE, 'anything.at.all'));
        self::assertSame('*', (string) $target);
    }

    #[Test]
    public function itFiltersOnAnExactChannel(): void
    {
        $target = SuppressionTarget::fromAnnotation('coupling.instability.class');

        self::assertFalse($target->appliesToEveryChannel());
        self::assertTrue($target->matches(self::ANY_RULE, 'coupling.instability.class'));
        self::assertFalse($target->matches(self::ANY_RULE, 'coupling.instability'));
        self::assertFalse($target->matches(self::ANY_RULE, 'coupling.instability.namespace'));
    }

    #[Test]
    public function itFiltersOnAGroupOfDescendants(): void
    {
        $target = SuppressionTarget::fromAnnotation('coupling.instability.*');

        self::assertTrue($target->matches(self::ANY_RULE, 'coupling.instability.class'));
        self::assertTrue($target->matches(self::ANY_RULE, 'coupling.instability.namespace'));
        self::assertFalse($target->matches(self::ANY_RULE, 'coupling.instability'));
    }

    #[Test]
    public function itFiltersOnAnExplicitChannelPair(): void
    {
        $target = SuppressionTarget::fromAnnotation('coupling.instability#coupling.instability.class');

        self::assertFalse($target->appliesToEveryChannel());
        self::assertTrue($target->matches('coupling.instability', 'coupling.instability.class'));
        // The rule half is exact too: the same code under another rule is a
        // different channel, which is the whole reason this form exists.
        self::assertFalse($target->matches('coupling.cbo', 'coupling.instability.class'));
        self::assertFalse($target->matches('coupling.instability', 'coupling.instability.namespace'));
    }

    /** No group is admitted inside the pair — the one-part form already says that. */
    #[Test]
    public function itRefusesAWildcardInsideTheExplicitPair(): void
    {
        $target = SuppressionTarget::fromAnnotation('coupling.instability#coupling.instability.*');

        self::assertNull($target->exactChannel());
        self::assertTrue($target->looksLikeChannelPair());
        self::assertFalse($target->matches('coupling.instability', 'coupling.instability.class'));
    }

    #[Test]
    public function itRefusesAPairWithMoreThanTwoHalves(): void
    {
        $target = SuppressionTarget::fromAnnotation('a#b#c');

        self::assertNull($target->exactChannel());
        self::assertFalse($target->matches('a', 'b#c'));
    }

    #[Test]
    public function itFiltersNothingWhenTheTextIsNotASelector(): void
    {
        $target = SuppressionTarget::fromAnnotation('coupling.*.class');

        self::assertFalse($target->appliesToEveryChannel());
        self::assertFalse($target->matches(self::ANY_RULE, 'coupling.instability.class'));
        self::assertSame('coupling.*.class', (string) $target);
    }
}
