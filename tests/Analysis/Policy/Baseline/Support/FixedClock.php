<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Support;

use DateTimeImmutable;
use Qualimetrix\Core\Time\ClockInterface;

/**
 * A clock that never moves.
 *
 * Every assertion about a written baseline's bytes depends on this: with the
 * wall clock, `generated` differs between two writes of the same analysis
 * and byte stability cannot be stated at all.
 */
final readonly class FixedClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $moment = '2026-08-05T12:00:00+03:00')
    {
        $this->now = new DateTimeImmutable($moment);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
