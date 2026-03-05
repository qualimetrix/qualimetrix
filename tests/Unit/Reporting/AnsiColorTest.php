<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting;

use AiMessDetector\Reporting\AnsiColor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnsiColor::class)]
final class AnsiColorTest extends TestCase
{
    public function testEnabledReturnsAnsiWrappedText(): void
    {
        $color = new AnsiColor(true);

        self::assertSame("\e[31mhello\e[0m", $color->red('hello'));
        self::assertSame("\e[33mhello\e[0m", $color->yellow('hello'));
        self::assertSame("\e[32mhello\e[0m", $color->green('hello'));
        self::assertSame("\e[1mhello\e[0m", $color->bold('hello'));
    }

    public function testDisabledReturnsPlainText(): void
    {
        $color = new AnsiColor(false);

        self::assertSame('hello', $color->red('hello'));
        self::assertSame('hello', $color->yellow('hello'));
        self::assertSame('hello', $color->green('hello'));
        self::assertSame('hello', $color->cyan('hello'));
        self::assertSame('hello', $color->bold('hello'));
        self::assertSame('hello', $color->dim('hello'));
        self::assertSame('hello', $color->boldRed('hello'));
        self::assertSame('hello', $color->boldYellow('hello'));
        self::assertSame('hello', $color->boldGreen('hello'));
    }

    public function testCombinedCodes(): void
    {
        $color = new AnsiColor(true);

        self::assertSame("\e[1;31mhello\e[0m", $color->boldRed('hello'));
        self::assertSame("\e[1;33mhello\e[0m", $color->boldYellow('hello'));
        self::assertSame("\e[1;32mhello\e[0m", $color->boldGreen('hello'));
    }
}
