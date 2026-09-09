<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\Refusal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Refusal\ConsoleExitCode;
use ReflectionEnum;

/**
 * The round owns exactly two codes (`00-overview.md`): a refusal by user
 * input, and an internal error. A third case here would be a third code the
 * overview never granted.
 */
#[CoversClass(ConsoleExitCode::class)]
final class ConsoleExitCodeTest extends TestCase
{
    #[Test]
    public function itCarriesExactlyTheTwoCodesTheRoundOwns(): void
    {
        self::assertSame(3, ConsoleExitCode::Refusal->value);
        self::assertSame(1, ConsoleExitCode::InternalError->value);
        self::assertCount(2, (new ReflectionEnum(ConsoleExitCode::class))->getCases());
    }
}
