<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\Refusal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Refusal\ConsoleExitCode;
use ReflectionEnum;

/**
 * The public terminal vocabulary distinguishes refusal, incomplete analysis
 * and internal error.
 */
#[CoversClass(ConsoleExitCode::class)]
final class ConsoleExitCodeTest extends TestCase
{
    #[Test]
    public function itCarriesThePublicTerminalExitCodes(): void
    {
        self::assertSame(3, ConsoleExitCode::Refusal->value);
        self::assertSame(4, ConsoleExitCode::AnalysisIncomplete->value);
        self::assertSame(5, ConsoleExitCode::InternalError->value);
        self::assertCount(3, (new ReflectionEnum(ConsoleExitCode::class))->getCases());
    }
}
