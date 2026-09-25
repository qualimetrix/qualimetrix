<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Contract\Refusal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

#[CoversClass(RefusedPosition::class)]
final class RefusedPositionTest extends TestCase
{
    #[Test]
    public function itBuildsAClosedPositionWithItsAcceptedSpellings(): void
    {
        $position = RefusedPosition::closed(['rules', 'complexity'], 'treshold', ['threshold']);

        self::assertSame(['rules', 'complexity'], $position->segments());
        self::assertSame('treshold', $position->written());
        self::assertSame(['threshold'], $position->accepted());
        self::assertTrue($position->isClosed());
    }

    /**
     * A vocabulary drawn from the document can be empty — no layer declared —
     * and the author's input is still what is refused, not a product defect.
     */
    #[Test]
    public function itBuildsAClosedPositionThatAcceptsNothing(): void
    {
        $position = RefusedPosition::closed(['architecture', 'allow', 'infra'], 'infra', []);

        self::assertSame([], $position->accepted());
        self::assertTrue($position->isClosed());
    }

    #[Test]
    public function itBuildsAnOpenPositionWithoutAnAcceptedList(): void
    {
        $position = RefusedPosition::open(['computedMetrics', 'health'], 'not-a-number');

        self::assertSame(['computedMetrics', 'health'], $position->segments());
        self::assertSame('not-a-number', $position->written());
        self::assertSame([], $position->accepted());
        self::assertFalse($position->isClosed());
    }
}
