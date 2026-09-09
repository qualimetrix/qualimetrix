<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Contract\Refusal;

use LogicException;
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
        self::assertSame('rules.complexity', $position->display());
        self::assertSame('treshold', $position->written());
        self::assertSame(['threshold'], $position->accepted());
        self::assertTrue($position->isClosed());
    }

    #[Test]
    public function itRefusesToBuildAClosedPositionWithNoAcceptedSpelling(): void
    {
        $this->expectException(LogicException::class);

        RefusedPosition::closed(['rules'], 'anything', []);
    }

    #[Test]
    public function itBuildsAnOpenPositionWithoutAnAcceptedList(): void
    {
        $position = RefusedPosition::open(['computedMetrics', 'health'], 'not-a-number');

        self::assertSame(['computedMetrics', 'health'], $position->segments());
        self::assertSame('computedMetrics.health', $position->display());
        self::assertSame('not-a-number', $position->written());
        self::assertSame([], $position->accepted());
        self::assertFalse($position->isClosed());
    }
}
