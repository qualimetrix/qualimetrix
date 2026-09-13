<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\Refusal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Refusal\ConsoleExitCode;
use ReflectionEnum;

/**
 * The public exit-code vocabulary has two cases: a refusal by user input and
 * an internal error. A third enum case would widen that contract.
 */
#[CoversClass(ConsoleExitCode::class)]
final class ConsoleExitCodeTest extends TestCase
{
    #[Test]
    public function itCarriesExactlyTheTwoPublicExitCodes(): void
    {
        self::assertSame(3, ConsoleExitCode::Refusal->value);
        self::assertSame(1, ConsoleExitCode::InternalError->value);
        self::assertCount(2, (new ReflectionEnum(ConsoleExitCode::class))->getCases());
    }
}
