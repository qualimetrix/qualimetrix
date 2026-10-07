<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Formatter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\Formatter\Prose\GlyphMode;
use Qualimetrix\Reporting\Formatter\Prose\ProseText;

#[CoversClass(ProseText::class)]
final class ProseTextTest extends TestCase
{
    #[Test]
    public function itPreservesLiteralPercentSignsWhenTheBodyContainsAnInvalidByte(): void
    {
        foreach (GlyphMode::cases() as $mode) {
            $report = ProseText::publish("Café K\xFF: 50%, path%FF.php", $mode);
            self::assertSame('Café K%FF: 50%, path%FF.php', $report->body);
            self::assertSame(1, $report->escapedStrings);
            self::assertTrue(mb_check_encoding($report->body, 'UTF-8'));
        }
    }
}
